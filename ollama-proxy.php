<?php
/**
 * FEVAL Chatbot — Proxy PHP para Ollama / Groq
 * =============================================
 * CONFIGURACIÓN PARA TU ENTORNO ESPECÍFICO:
 *   - VirtualBox en modo Red Puente (bridged)
 *   - Windows 10/11 como sistema anfitrión
 *   - 16 GB RAM + GPU NVIDIA/AMD → modelo llama3.1:8b recomendado
 *   - Uso local (solo tú accedes al sitio)
 *
 * PASOS PREVIOS OBLIGATORIOS EN WINDOWS (hacer UNA sola vez):
 * ────────────────────────────────────────────────────────────
 * 1. Instala Ollama desde: https://ollama.com/download
 *
 * 2. Configura Ollama para escuchar en TODAS las interfaces
 *    (por defecto solo escucha en localhost, la VM no puede verlo):
 *    - Abre: Panel de Control → Sistema → Variables de entorno → Variables del sistema
 *    - Añade nueva variable:
 *        Nombre:  OLLAMA_HOST
 *        Valor:   0.0.0.0:11434
 *    - Reinicia el servicio Ollama (o reinicia el PC)
 *
 * 3. Abre el Firewall de Windows para permitir Ollama:
 *    - Busca "Firewall de Windows con seguridad avanzada"
 *    - Reglas de entrada → Nueva regla → Puerto → TCP → 11434 → Permitir
 *    - Nombre: "Ollama API"
 *
 * 4. Descarga el modelo recomendado (con tu GPU se ejecutará muy rápido):
 *    Abre PowerShell y ejecuta:
 *        ollama pull llama3.1:8b
 *    (o si tienes GPU con 4GB VRAM: ollama pull llama3.2:3b)
 *
 * 5. Encuentra la IP de tu PC Windows en la red local:
 *    Abre PowerShell → escribe: ipconfig
 *    Busca "Adaptador Ethernet" o "Wi-Fi" → "Dirección IPv4"
 *    Normalmente es algo como: 192.168.1.X o 192.168.0.X
 *    Pon esa IP en $OLLAMA_HOST abajo.
 *
 * 6. Verifica desde dentro de la VM (abre terminal en tu Linux/Windows de VirtualBox):
 *        curl http://TU_IP_WINDOWS:11434/api/tags
 *    Debe responder con la lista de modelos. Si no responde, revisa el firewall.
 *
 * INSTALACIÓN EN JOOMLA:
 * ─────────────────────
 * - Sube este archivo a la raíz de Joomla (junto a index.php)
 * - Sube feval-chatbot.js donde ya lo tenías
 * - En cada página de Joomla añade (Extensiones → Módulos → HTML Personalizado):
 *     <script>window.FEVAL_CHATBOT_BACKEND = 'proxy';</script>
 *     <script>window.FEVAL_CHATBOT_PROXY_URL = '/ollama-proxy.php';</script>
 *     <script src="/ruta/a/feval-chatbot.js"></script>
 *
 * BACKENDS SOPORTADOS:
 *   'ollama' → Tu PC Windows con Ollama (GRATIS, ILIMITADO) ← RECOMENDADO
 *   'groq'   → Nube Groq (GRATIS hasta 14.400 req/día, rapidísimo, sin GPU)
 *   'claude' → Anthropic Claude (de pago)
 */

// ─── CONFIGURACIÓN — EDITA SOLO ESTA SECCIÓN ─────────────────────────────────

// Backend a usar
$BACKEND = 'ollama'; // ← 'ollama' para tu PC, 'groq' para la nube gratis

// ── Ollama en tu PC Windows (Red Puente) ──────────────────────────────────────
// Pon aquí la IP de tu PC Windows (ver Paso 5 arriba)
// Ejemplo: '192.168.1.45' o '192.168.0.12'
// IMPORTANTE: No pongas 'localhost' ni '127.0.0.1' — con Red Puente no funciona
$OLLAMA_HOST  = '192.168.14.31';  // IP de tu PC Windows en la red local
$OLLAMA_PORT  = 11434;

// Modelo recomendado para tu hardware (16GB RAM + GPU):
//   llama3.1:8b       → mejor calidad/velocidad, requiere GPU 6GB+ VRAM
//   llama3.2:3b       → más rápido, bueno con GPU 4GB VRAM
//   qwen2.5:14b       → muy bueno en español, requiere GPU 10GB+ VRAM
//   mistral:7b        → alternativa rápida y equilibrada
$OLLAMA_MODEL = 'llama3.1:8b';

// ── Groq — Alternativa nube gratuita (sin GPU necesaria) ──────────────────────
// Si prefieres no depender de que Ollama esté corriendo:
// 1. Regístrate gratis en: https://console.groq.com
// 2. Crea una API Key y pégala aquí
// 3. Cambia $BACKEND = 'groq' arriba
$GROQ_API_KEY = 'gsk_TU_API_KEY_DE_GROQ_AQUI';
$GROQ_MODEL   = 'llama-3.3-70b-versatile'; // Gratis, muy potente, < 1s respuesta

// ── Claude / Anthropic (de pago, solo si lo necesitas) ────────────────────────
$CLAUDE_API_KEY = 'sk-ant-TU_API_KEY_AQUI';
$CLAUDE_MODEL   = 'claude-haiku-4-5-20251001';

// ── Seguridad: dominios que pueden usar este proxy ────────────────────────────
// En uso local puedes dejar la lista vacía para permitir cualquier origen,
// o añadir la IP/dominio de tu Joomla local.
$ALLOWED_ORIGINS = []; // Uso local → sin restricción de origen

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
        'options'  => ['num_predict' => $max_tokens, 'temperature' => 0.1],
        'messages' => array_merge(
            $system_prompt ? [['role' => 'system', 'content' => $system_prompt]] : [],
            $clean_messages
        ),
        'think' => false
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
        'temperature' => 0.1,
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
