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

// Build knowledge block
$knowledge = '';
foreach ($sources as $s) {
    $label = strtoupper($s['type']);
    $knowledge .= "\n\n=== {$label}: {$s['name']} ===\n{$s['content']}";
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
$response_text = '';

if ($backend === 'groq') {
    $key = cb_cfg('groq_key');
    if (!$key) cb_err('Groq API key not configured', 503);

    $payload = [
        'model'    => 'llama-3.3-70b-versatile',
        'messages' => array_merge(
            [['role' => 'system', 'content' => $system]],
            $valid
        ),
        'max_tokens' => 1024,
    ];
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
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    $response_text = $data['choices'][0]['message']['content'] ?? '';

} elseif ($backend === 'claude') {
    $key = cb_cfg('claude_key');
    if (!$key) cb_err('Claude API key not configured', 503);

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
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
    $response_text = $data['content'][0]['text'] ?? '';

} elseif ($backend === 'ollama') {
    $ollama_url = rtrim(cb_cfg('ollama_url'), '/');
    $model      = cb_cfg('ollama_model') ?: 'qwen2.5:7b';

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
