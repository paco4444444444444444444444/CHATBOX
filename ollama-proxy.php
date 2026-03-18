<?php
/**
 * FEVAL Chatbot — Proxy PHP para Ollama / Groq
 * =============================================
 * Coloca este archivo en: /components/com_feval/ o en la raíz de Joomla
 * y configura $BACKEND según tu entorno.
 *
 * INSTRUCCIONES DE INSTALACIÓN EN JOOMLA:
 * 1. Sube este archivo a la raíz de tu Joomla (junto a index.php)
 *    o en /components/com_feval/ollama-proxy.php
 * 2. Configura la sección "CONFIGURACIÓN" abajo
 * 3. En feval-chatbot.js pon: window.FEVAL_CHATBOT_BACKEND = 'proxy'
 *    y: window.FEVAL_CHATBOT_PROXY_URL = '/ollama-proxy.php'
 *
 * BACKENDS SOPORTADOS:
 *   'ollama' → Ollama corriendo en tu PC (gratis, ilimitado)
 *   'groq'   → API de Groq (gratis hasta 14.400 req/día, muy rápido)
 *   'claude' → Anthropic Claude (de pago)
 */

// ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────

// Backend a usar: 'ollama', 'groq' o 'claude'
$BACKEND = 'ollama';

// ── Ollama (local en tu PC) ──
// Si Joomla corre en VirtualBox con NAT:     usa '10.0.2.2'
// Si Joomla corre en VirtualBox host-only:   usa '192.168.56.1' (IP del host)
// Si Ollama y Joomla están en el mismo PC:   usa '127.0.0.1'
$OLLAMA_HOST  = '10.0.2.2';   // Cambia esto según tu configuración VirtualBox
$OLLAMA_PORT  = 11434;
$OLLAMA_MODEL = 'llama3.2';   // O: 'mistral', 'qwen2.5', 'phi3'

// ── Groq (nube gratuita - 14.400 req/día) ──
// Obtén tu API key gratis en: https://console.groq.com
$GROQ_API_KEY = 'gsk_TU_API_KEY_DE_GROQ_AQUI';
$GROQ_MODEL   = 'llama-3.3-70b-versatile'; // Muy potente y gratis

// ── Claude / Anthropic (de pago) ──
$CLAUDE_API_KEY = 'sk-ant-TU_API_KEY_AQUI';
$CLAUDE_MODEL   = 'claude-haiku-4-5-20251001'; // El más barato de Claude

// ── Seguridad: dominios permitidos (deja vacío para permitir todos) ──
$ALLOWED_ORIGINS = [
    'https://formacionfeval.com',
    'http://localhost',
    'http://127.0.0.1',
];

// ─── FIN CONFIGURACIÓN ────────────────────────────────────────────────────────

// Headers de seguridad
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (empty($ALLOWED_ORIGINS) || in_array($origin, $ALLOWED_ORIGINS)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Origen no permitido']);
    exit;
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Leer body
$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Cuerpo vacío']);
    exit;
}

$body = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

// Validar campos básicos
$messages      = $body['messages']      ?? [];
$system_prompt = $body['system']        ?? '';
$max_tokens    = (int)($body['max_tokens'] ?? 800);
$max_tokens    = max(100, min($max_tokens, 2000));

if (empty($messages) || !is_array($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'messages requerido']);
    exit;
}

// Sanitizar mensajes (solo permitir role user/assistant y content string)
$clean_messages = [];
foreach ($messages as $msg) {
    if (!isset($msg['role'], $msg['content'])) continue;
    if (!in_array($msg['role'], ['user', 'assistant'])) continue;
    $clean_messages[] = [
        'role'    => $msg['role'],
        'content' => mb_substr((string)$msg['content'], 0, 4000)
    ];
}

if (empty($clean_messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'No hay mensajes válidos']);
    exit;
}

// ─── Llamar al backend ────────────────────────────────────────────────────────

function curlPost($url, $headers, $payload, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['body' => $response, 'code' => $httpCode, 'error' => $error];
}

$reply_text = '';

// ══════════════════════════════════════════════════════
// BACKEND: OLLAMA (gratis, local, ilimitado)
// ══════════════════════════════════════════════════════
if ($BACKEND === 'ollama') {

    // Construir prompt para Ollama (formato OpenAI-compatible)
    $ollama_url     = "http://{$OLLAMA_HOST}:{$OLLAMA_PORT}/api/chat";
    $ollama_payload = [
        'model'    => $OLLAMA_MODEL,
        'stream'   => false,
        'options'  => ['num_predict' => $max_tokens, 'temperature' => 0.7],
        'messages' => array_merge(
            $system_prompt ? [['role' => 'system', 'content' => $system_prompt]] : [],
            $clean_messages
        )
    ];

    $result = curlPost($ollama_url, ['Content-Type: application/json'], $ollama_payload, 60);

    if ($result['error']) {
        http_response_code(502);
        echo json_encode([
            'error' => 'No se pudo conectar con Ollama. ¿Está Ollama ejecutándose? Error: ' . $result['error']
        ]);
        exit;
    }

    $data = json_decode($result['body'], true);
    if ($result['code'] !== 200 || !isset($data['message']['content'])) {
        http_response_code(502);
        echo json_encode(['error' => 'Respuesta inesperada de Ollama', 'detail' => $result['body']]);
        exit;
    }

    $reply_text = $data['message']['content'];

// ══════════════════════════════════════════════════════
// BACKEND: GROQ (nube gratuita, muy rápido)
// ══════════════════════════════════════════════════════
} elseif ($BACKEND === 'groq') {

    $groq_payload = [
        'model'       => $GROQ_MODEL,
        'max_tokens'  => $max_tokens,
        'temperature' => 0.7,
        'messages'    => array_merge(
            $system_prompt ? [['role' => 'system', 'content' => $system_prompt]] : [],
            $clean_messages
        )
    ];

    $result = curlPost(
        'https://api.groq.com/openai/v1/chat/completions',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $GROQ_API_KEY
        ],
        $groq_payload
    );

    if ($result['error']) {
        http_response_code(502);
        echo json_encode(['error' => 'Error conectando con Groq: ' . $result['error']]);
        exit;
    }

    $data = json_decode($result['body'], true);
    if ($result['code'] !== 200 || !isset($data['choices'][0]['message']['content'])) {
        http_response_code($result['code'] ?: 502);
        $detail = $data['error']['message'] ?? $result['body'];
        echo json_encode(['error' => 'Error de Groq: ' . $detail]);
        exit;
    }

    $reply_text = $data['choices'][0]['message']['content'];

// ══════════════════════════════════════════════════════
// BACKEND: CLAUDE / ANTHROPIC (de pago)
// ══════════════════════════════════════════════════════
} elseif ($BACKEND === 'claude') {

    $claude_payload = [
        'model'      => $CLAUDE_MODEL,
        'max_tokens' => $max_tokens,
        'messages'   => $clean_messages
    ];
    if ($system_prompt) {
        $claude_payload['system'] = $system_prompt;
    }

    $result = curlPost(
        'https://api.anthropic.com/v1/messages',
        [
            'Content-Type: application/json',
            'x-api-key: ' . $CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        $claude_payload
    );

    if ($result['error']) {
        http_response_code(502);
        echo json_encode(['error' => 'Error conectando con Claude: ' . $result['error']]);
        exit;
    }

    $data = json_decode($result['body'], true);
    if ($result['code'] !== 200 || !isset($data['content'][0]['text'])) {
        http_response_code($result['code'] ?: 502);
        $detail = $data['error']['message'] ?? $result['body'];
        echo json_encode(['error' => 'Error de Claude: ' . $detail]);
        exit;
    }

    $reply_text = $data['content'][0]['text'];

} else {
    http_response_code(500);
    echo json_encode(['error' => 'Backend no configurado']);
    exit;
}

// ─── Respuesta unificada ──────────────────────────────────────────────────────
echo json_encode([
    'content' => [['type' => 'text', 'text' => $reply_text]]
], JSON_UNESCAPED_UNICODE);
