<?php
// chatbase/chat.php — Chat API endpoint (multi-bot)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ob_start();

require_once __DIR__ . '/db.php';

// Cargar keys privadas desde config.php (no está en git)
$_cfg = dirname(__DIR__) . '/config.php';
if (file_exists($_cfg)) require $_cfg;
// $GEMINI_API_KEYS y $GROQ_API_KEY quedan disponibles si config.php existe

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(204); exit; }

// Catch fatal errors and return JSON instead of HTML
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Server error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']));
    } else {
        ob_end_flush();
    }
});

function cb_err($msg, $code = 400) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $msg));
    exit;
}

// 1. Parse request
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) cb_err('Invalid JSON');

$bot_id  = trim(isset($req['bot_id'])   ? $req['bot_id']   : '');
$history = isset($req['messages'])      ? $req['messages']  : array();
$session = isset($req['session_id'])    ? $req['session_id']: bin2hex(random_bytes(8));

if (!$bot_id) cb_err('bot_id required');

// 2. Load bot
$stmt = cb_db()->prepare("SELECT * FROM bots WHERE id=?");
$stmt->execute(array($bot_id));
$bot = $stmt->fetch();
if (!$bot) cb_err('Bot not found', 404);

// 3. Load sources
$src = cb_db()->prepare("SELECT type, name, content FROM sources WHERE bot_id=? ORDER BY created_at");
$src->execute(array($bot_id));
$sources = $src->fetchAll();

// 4. Validate history
$valid = array();
foreach ($history as $m) {
    $role = isset($m['role']) ? $m['role'] : '';
    if (in_array($role, array('user', 'assistant')) && isset($m['content'])) {
        $valid[] = array('role' => $role, 'content' => (string)$m['content']);
    }
}
if (empty($valid)) cb_err('No messages');

// 5. RAG: score sources by relevance to user's last question
$user_query = '';
$valid_reversed = array_reverse($valid);
foreach ($valid_reversed as $m) {
    if ($m['role'] === 'user') {
        $user_query = mb_strtolower($m['content']);
        break;
    }
}

function rag_score($query, $source) {
    $haystack  = mb_strtolower($source['name'] . ' ' . $source['content']);
    $all_words = preg_split('/\s+/', $query);
    $words     = array();
    foreach ($all_words as $w) {
        if (mb_strlen($w) > 3) $words[] = $w;
    }
    $score = 0;
    if ($source['type'] === 'faq')  $score += 30;
    if ($source['type'] === 'pdf')  $score += 20;
    if ($source['type'] === 'text') $score += 10;
    foreach ($words as $w) {
        $score += substr_count($haystack, $w) * 2;
        if (mb_strpos(mb_strtolower($source['name']), $w) !== false) $score += 5;
    }
    return $score;
}

$uq = $user_query;
usort($sources, function($a, $b) use ($uq) {
    return rag_score($uq, $b) - rag_score($uq, $a);
});

// Build knowledge block — máximo real por ventana de contexto de cada modelo
// Groq llama-3.3-70b: 128k tokens → ~400k chars
// Gemini Flash:       1M tokens   → ~2M chars  (ventana enorme!)
// Claude:             200k tokens → ~700k chars
// Ollama:             131k tokens → ~500k chars
$knowledge       = '';
if ($backend === 'groq')        $knowledge_limit = 400000;
elseif ($backend === 'gemini')  $knowledge_limit = 2000000;
elseif ($backend === 'claude')  $knowledge_limit = 700000;
elseif ($backend === 'ollama')  $knowledge_limit = 500000;
else                            $knowledge_limit = 400000;
$knowledge_used  = 0;
foreach ($sources as $src_item) {
    $label     = strtoupper($src_item['type']);
    $chunk     = "\n\n=== {$label}: {$src_item['name']} ===\n{$src_item['content']}";
    $chunk_len = mb_strlen($chunk);
    if ($knowledge_used + $chunk_len > $knowledge_limit) {
        $remaining = $knowledge_limit - $knowledge_used;
        if ($remaining > 500) {
            $knowledge .= mb_substr($chunk, 0, $remaining);
        }
        break;
    }
    $knowledge .= $chunk;
    $knowledge_used += $chunk_len;
}

// 6. Build system prompt (AFTER knowledge is ready)
$system = "Eres un asistente virtual de IA llamado \"{$bot['name']}\". "
    . ($bot['description'] ? "Descripcion: {$bot['description']}. " : '')
    . "Responde siempre de forma clara, concisa y util.\n";

if ($bot['instructions']) {
    $system .= "\n=== INSTRUCCIONES ===\n{$bot['instructions']}\n";
}
if ($knowledge) {
    $system .= "\n=== BASE DE CONOCIMIENTO ===\n{$knowledge}\n\n"
        . "Responde SOLO basandote en la informacion anterior. Si no encuentras la respuesta, dilo claramente.";
}

// 7. Save user message
$last = end($valid);
if ($last['role'] === 'user') {
    cb_db()->prepare("INSERT INTO messages (bot_id,session_id,role,content) VALUES (?,?,?,?)")
        ->execute(array($bot_id, $session, 'user', $last['content']));
}

// 8. Call LLM backend
$backend   = $bot['backend'];
$bot_model = isset($bot['model']) ? $bot['model'] : '';
$response_text = '';

// Helper: llamar a Groq con rotación de keys (reutilizado por Gemini como fallback)
function call_groq($system, $valid, $bot_model) {
    global $GROQ_API_KEY;
    // Prioridad: config.php → panel admin
    $raw_keys = (!empty($GROQ_API_KEY) ? $GROQ_API_KEY . "\n" : '') . cb_cfg('groq_key');
    $keys = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw_keys))));
    if (!$keys) return null;
    $messages = array_merge([['role' => 'system', 'content' => $system]], $valid);
    $payload = [
        'model'      => $bot_model ? $bot_model : 'llama-3.3-70b-versatile',
        'messages'   => $messages,
        'max_tokens' => 32768,
    ];
    foreach ($keys as $key) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT        => 120,
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res, true);
        if ($http === 429) continue;
        return $data;
    }
    return null;
}

if ($backend === 'gemini') {
    global $GEMINI_API_KEYS;
    // Prioridad: config.php → panel admin
    $cfg_keys = !empty($GEMINI_API_KEYS) ? $GEMINI_API_KEYS : [];
    $db_keys  = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', cb_cfg('gemini_key')))));
    $keys     = array_values(array_unique(array_merge($cfg_keys, $db_keys)));
    if (!$keys) cb_err('Gemini API key not configured', 503);

    $model = $bot_model ? $bot_model : 'gemini-1.5-flash';

    // Convertir historial al formato nativo de Gemini
    $contents = [];
    foreach ($valid as $m) {
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => $contents,
        'generationConfig'  => ['maxOutputTokens' => 8192],
    ];

    $data = null;
    foreach ($keys as $key) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($key);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res, true);
        if ($http === 429) { $data = null; continue; }
        break;
    }

    // Fallback automático a Groq si Gemini está agotado
    if ($data === null) {
        $data = call_groq($system, $valid, '');
        if ($data === null) cb_err('Gemini y Groq en rate limit. Intentalo en unos minutos.', 429);
        $response_text = $data['choices'][0]['message']['content'] ?? '';
        if (!$response_text) cb_err('Groq fallback respuesta vacia: ' . json_encode($data), 502);
    } else {
        if (isset($data['error'])) cb_err('Gemini error: ' . ($data['error']['message'] ?? json_encode($data['error'])), 502);
        $response_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!$response_text) cb_err('Gemini respuesta vacia: ' . json_encode($data), 502);
    }

} elseif ($backend === 'groq') {
    if (!cb_cfg('groq_key')) cb_err('Groq API key not configured', 503);
    $data = call_groq($system, $valid, $bot_model);
    if ($data === null) cb_err('Groq rate limit alcanzado. Intentalo en unos minutos.', 429);
    if (isset($data['error'])) cb_err('Groq error: ' . ($data['error']['message'] ?? json_encode($data['error'])), 502);
    $response_text = $data['choices'][0]['message']['content'] ?? '';
    if (!$response_text) cb_err('Groq respuesta vacia: ' . json_encode($data), 502);

} elseif ($backend === 'claude') {
    $raw_keys = cb_cfg('claude_key');
    $keys = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw_keys))));
    if (!$keys) cb_err('Claude API key not configured', 503);

    $model   = $bot_model ? $bot_model : 'claude-haiku-4-5-20251001';
    $max_out = (strpos($model, 'haiku') !== false) ? 8192 : 64000;
    $payload = array(
        'model'      => $model,
        'max_tokens' => $max_out,
        'system'     => $system,
        'messages'   => $valid,
    );

    $data = null;
    foreach ($keys as $key) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
            ),
            CURLOPT_TIMEOUT => 120,
        ));
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res, true);
        if ($http === 429) { $data = null; continue; }
        break;
    }
    if ($data === null) cb_err('Claude rate limit alcanzado. Intentalo en unos minutos.', 429);
    if (isset($data['error'])) cb_err('Claude error: ' . (isset($data['error']['message']) ? $data['error']['message'] : json_encode($data['error'])), 502);
    $response_text = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
    if (!$response_text) cb_err('Claude respuesta vacia: ' . json_encode($data), 502);

} elseif ($backend === 'ollama') {
    $ollama_url = rtrim(cb_cfg('ollama_url'), '/');
    $model      = $bot_model ? $bot_model : (cb_cfg('ollama_model') ? cb_cfg('ollama_model') : 'qwen2.5:7b');
    $msgs       = array_merge(array(array('role' => 'system', 'content' => $system)), $valid);
    $payload    = array('model' => $model, 'messages' => $msgs, 'stream' => false);
    $ch = curl_init($ollama_url . '/api/chat');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT        => 120,
    ));
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (isset($data['error'])) cb_err('Ollama error: ' . json_encode($data['error']), 502);
    $response_text = isset($data['message']['content']) ? $data['message']['content'] : '';
} else {
    cb_err('Unknown backend: ' . $backend, 503);
}

if (!$response_text) cb_err('Empty response from LLM', 502);

// 9. Save assistant message and respond
cb_db()->prepare("INSERT INTO messages (bot_id,session_id,role,content) VALUES (?,?,?,?)")
    ->execute(array($bot_id, $session, 'assistant', $response_text));

echo json_encode(array(
    'content'    => array(array('type' => 'text', 'text' => $response_text)),
    'session_id' => $session,
), JSON_UNESCAPED_UNICODE);
