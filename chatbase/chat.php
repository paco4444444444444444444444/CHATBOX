<?php
// chatbase/chat.php — Chat API endpoint (multi-bot)
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function cb_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

$bot_id  = trim($req['bot_id'] ?? '');
$history = $req['messages'] ?? [];
$session = $req['session_id'] ?? bin2hex(random_bytes(8));

if (!$bot_id) cb_err('bot_id required');

$bot = cb_db()->prepare("SELECT * FROM bots WHERE id=?")->execute([$bot_id])
    ? cb_db()->prepare("SELECT * FROM bots WHERE id=?")->execute([$bot_id]) && false
    : null;
$s = cb_db()->prepare("SELECT * FROM bots WHERE id=?");
$s->execute([$bot_id]);
$bot = $s->fetch();
if (!$bot) cb_err('Bot not found', 404);

// Load all sources for this bot
$src = cb_db()->prepare("SELECT type, name, content FROM sources WHERE bot_id=? ORDER BY created_at");
$src->execute([$bot_id]);
$sources = $src->fetchAll();

// ── RAG: score each source by relevance to the user's last question ──────
$user_query = '';
foreach (array_reverse($valid) as $m) {
    if ($m['role'] === 'user') { $user_query = mb_strtolower($m['content']); break; }
}

function rag_score($query, $source) {
    $haystack = mb_strtolower($source['name'] . ' ' . $source['content']);
    $all_words = preg_split('/\s+/', $query);
    $words = array();
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

// Sort sources by relevance
$user_query_ref = $user_query;
usort($sources, function($a, $b) use ($user_query_ref) {
    return rag_score($user_query_ref, $b) - rag_score($user_query_ref, $a);
});

// Build knowledge block with top relevant sources (limit 60k chars)
$knowledge = '';
$knowledge_limit = 60000;
$knowledge_used  = 0;
foreach ($sources as $s) {
    $label = strtoupper($s['type']);
    $chunk = "\n\n=== {$label}: {$s['name']} ===\n{$s['content']}";
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

// Build system prompt
$system = "Eres un asistente virtual de IA llamado \"{$bot['name']}\". "
    . ($bot['description'] ? "Descripción: {$bot['description']}. " : '')
    . "Responde siempre de forma clara, concisa y útil.\n";

if ($bot['instructions']) {
    $system .= "\n=== INSTRUCCIONES ===\n{$bot['instructions']}\n";
}
if ($knowledge) {
    $system .= "\n=== BASE DE CONOCIMIENTO ===\n{$knowledge}\n\n"
        . "Responde SOLO basándote en la información anterior. Si no encuentras la respuesta, dilo claramente.";
}

// Validate history
$valid = [];
foreach ($history as $m) {
    if (in_array($m['role'] ?? '', ['user', 'assistant']) && isset($m['content'])) {
        $valid[] = ['role' => $m['role'], 'content' => (string)$m['content']];
    }
}
if (empty($valid)) cb_err('No messages');

// Save user message
$last = end($valid);
if ($last['role'] === 'user') {
    cb_db()->prepare("INSERT INTO messages (bot_id,session_id,role,content) VALUES (?,?,?,?)")
        ->execute([$bot_id, $session, 'user', $last['content']]);
}

// ── Call LLM backend ──────────────────────────────────────────────────────
$backend = $bot['backend'];
$bot_model = $bot['model'] ?? '';
$response_text = '';

if ($backend === 'groq') {
    // Support multiple keys separated by commas or newlines → rotate on 429
    $raw_keys = cb_cfg('groq_key');
    $keys = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw_keys))));
    if (!$keys) cb_err('Groq API key not configured', 503);

    $payload = [
        'model'      => $bot_model ?: 'llama-3.3-70b-versatile',
        'messages'   => array_merge([['role' => 'system', 'content' => $system]], $valid),
        'max_tokens' => 2048,
    ];

    $data = null;
    $last_error = '';
    foreach ($keys as $key) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res, true);
        if ($http === 429) {
            // Rate limited — try next key
            $last_error = 'rate_limit';
            $data = null;
            continue;
        }
        break; // success or non-429 error
    }
    if ($data === null) cb_err('Todas las API keys de Groq han alcanzado el límite. Inténtalo en unos minutos.', 429);
    if (isset($data['error'])) cb_err('Groq error: ' . ($data['error']['message'] ?? json_encode($data['error'])), 502);
    $response_text = $data['choices'][0]['message']['content'] ?? '';
    if (!$response_text) cb_err('Groq respuesta vacía. Respuesta completa: ' . json_encode($data), 502);

} elseif ($backend === 'claude') {
    $key = cb_cfg('claude_key');
    if (!$key) cb_err('Claude API key not configured', 503);

    $payload = [
        'model'      => $bot_model ?: 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'system'     => $system,
        'messages'   => $valid,
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (isset($data['error'])) cb_err('Claude error: ' . ($data['error']['message'] ?? json_encode($data['error'])), 502);
    $response_text = $data['content'][0]['text'] ?? '';
    if (!$response_text) cb_err('Claude respuesta vacía. Respuesta completa: ' . json_encode($data), 502);

} elseif ($backend === 'ollama') {
    $ollama_url = rtrim(cb_cfg('ollama_url'), '/');
    $model      = $bot_model ?: cb_cfg('ollama_model') ?: 'qwen2.5:7b';

    $msgs = array_merge([['role' => 'system', 'content' => $system]], $valid);
    $payload = ['model' => $model, 'messages' => $msgs, 'stream' => false];
    $ch = curl_init($ollama_url . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (isset($data['error'])) cb_err('Ollama error: ' . ($data['error'] ?? json_encode($data['error'])), 502);
    $response_text = $data['message']['content'] ?? '';
} else {
    cb_err('Unknown backend: ' . $backend, 503);
}

if (!$response_text) cb_err('Empty response from LLM', 502);

// Save assistant message
cb_db()->prepare("INSERT INTO messages (bot_id,session_id,role,content) VALUES (?,?,?,?)")
    ->execute([$bot_id, $session, 'assistant', $response_text]);

echo json_encode([
    'content'    => [['type' => 'text', 'text' => $response_text]],
    'session_id' => $session,
], JSON_UNESCAPED_UNICODE);
