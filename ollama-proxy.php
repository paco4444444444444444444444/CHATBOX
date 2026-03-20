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

// URL pública del sitio (la que ven los usuarios en los enlaces)
// Cámbiala si tu dominio/túnel cambia
$PUBLIC_URL = 'https://blog-tired-vitamins-flu.trycloudflare.com';

// URL interna para que el script acceda a Joomla SIN pasar por el túnel.
// Normalmente 'http://127.0.0.1' funciona si Apache escucha en el puerto 80.
// Si no funciona, ponlo igual que $PUBLIC_URL y el scraping irá por Cloudflare.
$JOOMLA_INTERNAL_URL = 'http://127.0.0.1';

// ── Ollama en tu PC Windows (Red Puente) ──────────────────────────────────────
// Pon aquí la IP de tu PC Windows (ver Paso 5 arriba)
// Ejemplo: '192.168.1.45' o '192.168.0.12'
// IMPORTANTE: No pongas 'localhost' ni '127.0.0.1' — con Red Puente no funciona
$OLLAMA_HOST  = '192.168.14.39';  // IP de tu PC Windows en la red local
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

// ── Endpoint de diagnóstico (GET ?debug_scrape=1) — SOLO para depuración ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['debug_scrape'])) {
    header('Content-Type: application/json; charset=utf-8');
    $internal_test = curlGet(rtrim($JOOMLA_INTERNAL_URL, '/') . '/index.php/cursos-feval', 8);
    $public_test   = curlGet(rtrim($PUBLIC_URL, '/') . '/index.php/cursos-feval', 8);
    $events        = getAllJoomlaEvents();
    echo json_encode([
        'internal_url'     => $JOOMLA_INTERNAL_URL,
        'internal_ok'      => $internal_test !== null,
        'internal_len'     => $internal_test ? strlen($internal_test) : 0,
        'public_ok'        => $public_test !== null,
        'public_len'       => $public_test ? strlen($public_test) : 0,
        'events_found'     => count($events),
        'all_titles'       => array_column($events, 'title'),
        'categories_found' => array_unique(array_map(fn($e) => preg_replace('/\/[^\/]+$/', '', $e['href']), $events)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

// ─── Respuesta directa sin pasar por el modelo ────────────────────────────────
// Para preguntas sobre cursos concretos, el PHP responde directamente
// con datos exactos, evitando que el modelo invente información.

$DIRECT_CATALOG = [
  ['n'=>1,  'nombre'=>'Fundamentos de la Inteligencia Artificial',                    'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'16/03/2026','fin'=>'09/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>2,  'nombre'=>'IA avanzada: Arquitecturas, Modelos y Despliegue',             'h'=>24,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'20/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>3,  'nombre'=>'Fundamentos de IA Generativa - CCS',                           'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'11/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>4,  'nombre'=>'Microsoft Azure AI - AI-900',                                  'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'05/10/2026','fin'=>'23/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>5,  'nombre'=>'AWS AI Practitioner',                                          'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/11/2026','fin'=>'26/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>6,  'nombre'=>'Introducción a la Inteligencia Artificial',                    'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>7,  'nombre'=>'Introducción a ChatGPT: IA para Textos y Reuniones',           'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'11/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>8,  'nombre'=>'Herramientas de IA para Imágenes y Sonido',                   'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'28/09/2026','fin'=>'16/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>9,  'nombre'=>'Análisis de Datos con IA y Aplicaciones Avanzadas',           'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'26/10/2026','fin'=>'13/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>10, 'nombre'=>'Fundamentos de redes - CCST Networking',                       'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'16/03/2026','fin'=>'16/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>11, 'nombre'=>'Ciberseguridad básica - CCST Cybersecurity',                   'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'04/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>12, 'nombre'=>'Hacking Ético - Certificación EC Council',                     'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'26/10/2026','fin'=>'03/12/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>13, 'nombre'=>'Análisis Forense - EC Council DFE',                            'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'14/09/2026','fin'=>'14/10/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>14, 'nombre'=>'CCNA: Introduction to Networks',                               'h'=>24,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'14/09/2026','fin'=>'24/09/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>15, 'nombre'=>'CCNA: Switching, Routing and Wireless Essentials',             'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'05/10/2026','fin'=>'29/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>16, 'nombre'=>'CCNA: Enterprise Networking, Security and Automation',         'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/11/2026','fin'=>'03/12/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>17, 'nombre'=>'Fundamentos de redes - CCST Networking',                       'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'09/02/2026','fin'=>'12/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>18, 'nombre'=>'Ciberseguridad básica - CCST Cybersecurity',                   'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'23/03/2026','fin'=>'23/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>19, 'nombre'=>'Introducción al Hacking Ético',                                'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'04/05/2026','fin'=>'21/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>20, 'nombre'=>'Gestión de Incidentes de Seguridad',                           'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'21/09/2026','fin'=>'08/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>21, 'nombre'=>'Introducción al Cloud Computing e IA en la nube',              'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>22, 'nombre'=>'Microsoft Azure Fundamentals AZ-900',                          'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'11/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>23, 'nombre'=>'Cloud con AWS - Certificación AWS Cloud Practitioner',         'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'28/09/2026','fin'=>'16/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>24, 'nombre'=>'Cloud con Google - Certificación Google Cloud Associate',      'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'26/10/2026','fin'=>'13/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>25, 'nombre'=>'Fundamentos de programación en Javascript',                    'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'02/02/2026','fin'=>'26/02/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>26, 'nombre'=>'Fundamentos de Programación en Python',                        'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'09/03/2026','fin'=>'26/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>27, 'nombre'=>'Fundamentos de bases de datos',                                'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>28, 'nombre'=>'Microsoft Azure Data DP-900',                                  'h'=>24,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'05/10/2026','fin'=>'16/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>29, 'nombre'=>'IA para programadores',                                        'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'26/10/2026','fin'=>'13/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>30, 'nombre'=>'Game Designer',                                                'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'09/03/2026','fin'=>'26/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>31, 'nombre'=>'Concept Art 2D y 3D para videojuegos',                         'h'=>24,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'23/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>32, 'nombre'=>'Arte y animación 2D/3D con Unity',                             'h'=>24,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'04/05/2026','fin'=>'14/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>33, 'nombre'=>'Unity Certified User: Programación de videojuegos',            'h'=>48,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'05/10/2026','fin'=>'30/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>34, 'nombre'=>'Desarrollador VR con Unity',                                   'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'09/11/2026','fin'=>'26/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>35, 'nombre'=>'Gestión de proyectos - Certificación PMI Ready',               'h'=>48,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'16/03/2026','fin'=>'16/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>36, 'nombre'=>'SCRUM MASTER + Certificación',                                 'h'=>24,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'04/05/2026','fin'=>'14/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>37, 'nombre'=>'Herramientas IA para gestión de proyectos',                    'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'28/09/2026','fin'=>'16/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>38, 'nombre'=>'Soporte y operaciones TIC - CCST IT Support',                  'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>39, 'nombre'=>'Windows Server Hybrid Administrator Associate',                'h'=>48,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'11/05/2026','fin'=>'04/06/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>40, 'nombre'=>'Linux LPIC1 + Certificación',                                  'h'=>48,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'19/10/2026','fin'=>'13/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>41, 'nombre'=>'Fundamentos de Python',                                        'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/02/2026','fin'=>'05/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>42, 'nombre'=>'Python avanzado',                                              'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/03/2026','fin'=>'26/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>43, 'nombre'=>'Fundamentos de análisis de datos con Python',                  'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'13/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>44, 'nombre'=>'Análisis de datos avanzado con Python',                        'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'11/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>45, 'nombre'=>'Fundamentos de Excel para análisis de datos',                  'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'14/09/2026','fin'=>'01/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>46, 'nombre'=>'Excel Avanzado para análisis de datos',                        'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'05/10/2026','fin'=>'23/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>47, 'nombre'=>'Introducción al Business Intelligence con Power BI',            'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'26/10/2026','fin'=>'13/11/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>48, 'nombre'=>'Análisis Avanzado y Publicación de Informes con Power BI',     'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'16/11/2026','fin'=>'03/12/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>49, 'nombre'=>'Agricultura de Precisión y Teledetección ED1',                 'h'=>24,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'19/01/2026','fin'=>'04/02/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>50, 'nombre'=>'SIG en Agricultura 4.0 ED1',                                   'h'=>24,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/02/2026','fin'=>'04/03/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>51, 'nombre'=>'Fotogrametría y sensorización agrícola ED1',                   'h'=>32,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'09/03/2026','fin'=>'08/04/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>52, 'nombre'=>'Certificación piloto drones A1/A3+A2+STS01/STS02 ED1',        'h'=>64,  'pub'=>'EMPLEADOS',   'mod'=>'SEMIPRESENCIAL','ini'=>'20/04/2026','fin'=>'10/06/2026','dias'=>'lun-mié','hor'=>'16-20h'],
  ['n'=>53, 'nombre'=>'Actualización piloto drones STS Ed1',                          'h'=>4,   'pub'=>'EMPLEADOS',   'mod'=>'PRESENCIAL',    'ini'=>'20/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>54, 'nombre'=>'Actualización piloto drones STS Ed2',                          'h'=>4,   'pub'=>'EMPLEADOS',   'mod'=>'PRESENCIAL',    'ini'=>'04/05/2026','fin'=>'14/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>55, 'nombre'=>'Actualización piloto drones STS Ed3',                          'h'=>4,   'pub'=>'EMPLEADOS',   'mod'=>'PRESENCIAL',    'ini'=>'18/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>56, 'nombre'=>'Agricultura de Precisión y Teledetección ED2',                 'h'=>24,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'20/01/2026','fin'=>'05/02/2026','dias'=>'mar-jue','hor'=>'16-20h'],
  ['n'=>57, 'nombre'=>'SIG en Agricultura 4.0 ED2',                                   'h'=>24,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'10/02/2026','fin'=>'05/03/2026','dias'=>'mar-jue','hor'=>'16-20h'],
  ['n'=>58, 'nombre'=>'Fotogrametría y sensorización agrícola ED2',                   'h'=>32,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'10/03/2026','fin'=>'09/04/2026','dias'=>'mar-jue','hor'=>'16-20h'],
  ['n'=>59, 'nombre'=>'Certificación piloto drones A1/A3+A2+STS01/STS02 ED2',        'h'=>64,  'pub'=>'DESEMPLEADOS','mod'=>'SEMIPRESENCIAL','ini'=>'21/04/2026','fin'=>'11/06/2026','dias'=>'mar-jue','hor'=>'16-20h'],
  ['n'=>60, 'nombre'=>'Actualización piloto drones STS Ed4',                          'h'=>4,   'pub'=>'DESEMPLEADOS','mod'=>'PRESENCIAL',    'ini'=>'14/09/2026','fin'=>'24/09/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>61, 'nombre'=>'Actualización piloto drones STS Ed5',                          'h'=>4,   'pub'=>'DESEMPLEADOS','mod'=>'PRESENCIAL',    'ini'=>'28/09/2026','fin'=>'08/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>62, 'nombre'=>'Actualización piloto drones STS Ed6',                          'h'=>4,   'pub'=>'DESEMPLEADOS','mod'=>'PRESENCIAL',    'ini'=>'13/10/2026','fin'=>'23/10/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>63, 'nombre'=>'Teledetección satelital y terrestre ED Docentes',              'h'=>24,  'pub'=>'DOCENTES',    'mod'=>'ONLINE',        'ini'=>'20/04/2026','fin'=>'30/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>64, 'nombre'=>'SIG Básico ED Docentes',                                       'h'=>48,  'pub'=>'DOCENTES',    'mod'=>'ONLINE',        'ini'=>'04/05/2026','fin'=>'28/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>65, 'nombre'=>'Fotogrametría con drones ED Docentes',                         'h'=>24,  'pub'=>'DOCENTES',    'mod'=>'ONLINE',        'ini'=>'01/06/2026','fin'=>'11/06/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>66, 'nombre'=>'Iniciación a Photoshop',                                       'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'26/01/2026','fin'=>'12/02/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>67, 'nombre'=>'Iniciación a Illustrator',                                     'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'23/02/2026','fin'=>'12/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>68, 'nombre'=>'Edición de vídeo con Adobe Premiere',                          'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'16/03/2026','fin'=>'09/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>69, 'nombre'=>'IA: Herramientas para Diseño y Creadores',                     'h'=>36,  'pub'=>'EMPLEADOS',   'mod'=>'ONLINE',        'ini'=>'20/04/2026','fin'=>'07/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>70, 'nombre'=>'Community Manager',                                            'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'23/02/2026','fin'=>'12/03/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>71, 'nombre'=>'Marketing digital y redes sociales - Social Media Plan',       'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'16/03/2026','fin'=>'09/04/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>72, 'nombre'=>'IA aplicada al Growth y Digital Marketing',                    'h'=>36,  'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'20/04/2026','fin'=>'07/05/2026','dias'=>'lun-jue','hor'=>'17-20h'],
  ['n'=>73, 'nombre'=>'Marketing en Meta (Instagram/Facebook/Whatsapp) Certif.M100-101','h'=>48,'pub'=>'DESEMPLEADOS','mod'=>'ONLINE',        'ini'=>'18/05/2026','fin'=>'11/06/2026','dias'=>'lun-jue','hor'=>'17-20h'],
];

/**
 * Busca cursos que coincidan con palabras clave del mensaje.
 * Devuelve array de cursos o null si no hay coincidencia directa.
 */
/**
 * @param bool $strict  true = todas las palabras deben coincidir (AND); false = basta una (OR)
 */
function searchCatalog($query, $catalog, $strict = false) {
    $q = mb_strtolower($query);

    // ── Paso 1: coincidencia exacta de nombre completo ────────────────────────
    $exactMatches = [];
    foreach ($catalog as $c) {
        if (mb_strpos($q, mb_strtolower($c['nombre'])) !== false) {
            $exactMatches[] = $c;
        }
    }
    if (!empty($exactMatches)) return $exactMatches;

    // ── Paso 2: búsqueda por palabras clave (AND o OR según $strict) ──────────
    $stop = ['curso','cursos','hay','sobre','para','el','la','los','las','de','que','del','al','un','una','en','con','por',
             'información','informacion','dame','quiero','saber','ver','dime','datos','dato','cuáles','cuales',
             'tienes','tiene','puedes','puedo','algún','algun','más','mas'];
    $words = array_values(array_filter(
        explode(' ', preg_replace('/[^a-z0-9áéíóúüñ ]/u', ' ', $q)),
        fn($w) => mb_strlen($w) > 2 && !in_array($w, $stop)
    ));
    if (empty($words)) return null;

    $matches = [];
    foreach ($catalog as $c) {
        $name = mb_strtolower($c['nombre']);
        if ($strict) {
            // Todas las palabras deben aparecer en el nombre
            $allMatch = true;
            foreach ($words as $w) {
                if (mb_strpos($name, $w) === false) { $allMatch = false; break; }
            }
            if ($allMatch) $matches[] = $c;
        } else {
            // Basta con que aparezca una palabra
            foreach ($words as $w) {
                if (mb_strpos($name, $w) !== false) { $matches[] = $c; break; }
            }
        }
    }
    return count($matches) > 0 ? $matches : null;
}

/** Extrae texto de un nodo DOM preservando párrafos y listas. */
function extractNodeText($node) {
    $text = '';
    foreach ($node->childNodes as $child) {
        $tag = strtolower($child->nodeName ?? '');
        if (in_array($tag, ['ul','ol'])) {
            foreach ($child->childNodes as $li) {
                if (strtolower($li->nodeName) === 'li') {
                    $t = trim(strip_tags($li->textContent));
                    if ($t) $text .= "• {$t}\n";
                }
            }
            $text .= "\n";
        } elseif (in_array($tag, ['p','h2','h3','h4','h5','div'])) {
            $t = trim(strip_tags($child->textContent));
            if ($t) $text .= "{$t}\n\n";
        }
    }
    $text = trim(preg_replace('/\n{3,}/', "\n\n", $text));
    if (mb_strlen($text) < 10) {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($node->textContent)));
    }
    return $text;
}

/**
 * Carga TODOS los eventos de Joomla de una vez y los cachea 1 hora.
 * Devuelve array de ['title'=>string, 'href'=>string].
 */
function getAllJoomlaEvents() {
    $cacheFile = sys_get_temp_dir() . '/feval_all_events.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (!empty($data)) return $data;
    }

    $mainHtml = joomlaGet('/index.php/cursos-feval');
    if (!$mainHtml) return [];

    // Obtener todas las subcategorías
    preg_match_all(
        '/<a\s[^>]*class="[^"]*eb-category-title-link[^"]*"[^>]*href="([^"]+)"|<a\s[^>]*href="([^"]+)"[^>]*class="[^"]*eb-category-title-link[^"]*"/i',
        $mainHtml, $m
    );
    $catPaths = array_unique(array_filter(array_merge($m[1] ?? [], $m[2] ?? [])));

    // Para cada categoría, cargar TODAS las páginas con limit=200 para evitar paginación
    $pagesToScan = ['/index.php/cursos-feval'];
    foreach ($catPaths as $path) {
        $pagesToScan[] = $path;
        $pagesToScan[] = $path . '?limitstart=0&limit=200'; // forzar todos los eventos
    }
    $pagesToScan = array_unique($pagesToScan);

    $events   = [];
    $seen     = [];
    $queue    = $pagesToScan;
    $visited  = [];

    while (!empty($queue)) {
        $path = array_shift($queue);
        if (isset($visited[$path])) continue;
        $visited[$path] = true;

        $html = ($path === '/index.php/cursos-feval') ? $mainHtml : joomlaGet($path);
        if (!$html) continue;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // Recoger eventos de esta página
        foreach ($xp->query('//a[contains(@class,"eb-event-link")]') as $link) {
            $t = trim(preg_replace('/\s+/', ' ', $link->textContent));
            $h = $link->getAttribute('href');
            if ($t && $h && !isset($seen[$h])) {
                $seen[$h]  = true;
                $events[]  = ['title' => $t, 'href' => $h];
            }
        }

        // Seguir links de paginación (siguiente página de la misma categoría)
        foreach ($xp->query('//a[contains(@class,"pagenav") or contains(@class,"next") or contains(@rel,"next")]') as $pgLink) {
            $pgHref = $pgLink->getAttribute('href');
            if ($pgHref && !isset($visited[$pgHref])) $queue[] = $pgHref;
        }
        // También buscar paginación por patrón ?start=N en los hrefs de la página
        preg_match_all('/href="([^"]*\?[^"]*(?:start|limitstart)=\d+[^"]*)"/i', $html, $pgM);
        foreach (array_unique($pgM[1] ?? []) as $pgPath) {
            if (!isset($visited[$pgPath])) $queue[] = $pgPath;
        }

        // Subcategorías anidadas
        preg_match_all(
            '/<a\s[^>]*class="[^"]*eb-category-title-link[^"]*"[^>]*href="([^"]+)"|<a\s[^>]*href="([^"]+)"[^>]*class="[^"]*eb-category-title-link[^"]*"/i',
            $html, $sub
        );
        foreach (array_unique(array_filter(array_merge($sub[1] ?? [], $sub[2] ?? []))) as $subPath) {
            if (!isset($visited[$subPath])) $queue[] = $subPath;
        }
    }

    if (!empty($events)) file_put_contents($cacheFile, json_encode($events));
    return $events;
}

/** Elimina acentos y normaliza a minúsculas para comparación robusta. */
function normStr($s) {
    $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    $from = ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù','â','ê','î','ô','û'];
    $to   = ['a','e','i','o','u','u','n','a','e','i','o','u','a','e','i','o','u'];
    return str_replace($from, $to, $s);
}

/**
 * Encuentra el href del evento de Joomla que mejor coincide con el nombre del curso.
 * Compara sin acentos para que "Introducción" == "Introduccion", etc.
 */
function findEventHref($courseName, $allEvents) {
    $nameN = normStr($courseName);
    $kwStop = ['de','del','la','el','los','las','en','con','para','por','un','una','y','e','o','a','al','su'];
    $keywords = array_values(array_filter(
        explode(' ', preg_replace('/[^a-z0-9 ]/u', ' ', $nameN)),
        fn($w) => mb_strlen($w) >= 3 && !in_array($w, $kwStop)
    ));

    $bestHref  = '';
    $bestScore = 0;

    foreach ($allEvents as $ev) {
        $evN = normStr($ev['title']);
        if ($evN === $nameN) return $ev['href']; // exacto sin acentos
        if (empty($keywords)) continue;
        $hits = 0;
        foreach ($keywords as $kw) {
            if (mb_strpos($evN, $kw) !== false) $hits++;
        }
        // Tolera 1 keyword que no coincida (para nombres ligeramente distintos en Joomla)
        $minHits = max(1, count($keywords) - 1);
        if ($hits >= $minHits && $hits > $bestScore) {
            $bestScore = $hits;
            $bestHref  = $ev['href'];
        }
    }
    return $bestHref;
}

/**
 * Devuelve ['url' => string, 'text' => string] o null.
 */
function fetchCourseDetails($courseName) {
    global $PUBLIC_URL;
    $detailCache = sys_get_temp_dir() . '/feval_detail3_' . md5($courseName) . '.json';
    if (file_exists($detailCache) && (time() - filemtime($detailCache)) < 3600) {
        $cached = json_decode(file_get_contents($detailCache), true);
        if ($cached !== null) return $cached;
    }

    $allEvents  = getAllJoomlaEvents();
    $courseHref = findEventHref($courseName, $allEvents);
    if (!$courseHref) return null;

    $courseUrl  = rtrim($PUBLIC_URL, '/') . $courseHref;
    $courseHtml = joomlaGet($courseHref);
    if (!$courseHtml) {
        $result = ['url' => $courseUrl, 'text' => ''];
        file_put_contents($detailCache, json_encode($result));
        return $result;
    }

    libxml_use_internal_errors(true);
    $cdom = new DOMDocument();
    $cdom->loadHTML('<?xml encoding="utf-8" ?>' . $courseHtml);
    libxml_clear_errors();
    $cxp = new DOMXPath($cdom);

    // Verificar que la página visitada corresponde al curso (usando normStr sin acentos)
    $pageHeading = $cxp->query('//*[contains(@class,"eb-page-heading")]')->item(0);
    if ($pageHeading) {
        $kwStop   = ['de','del','la','el','los','las','en','con','para','por','un','una','y','e','o','a','al','su'];
        $nameN    = normStr($courseName);
        $kwCourse = array_values(array_filter(
            explode(' ', preg_replace('/[^a-z0-9 ]/u', ' ', $nameN)),
            fn($w) => mb_strlen($w) >= 3 && !in_array($w, $kwStop)
        ));
        $pageN = normStr($pageHeading->textContent);
        $hits  = 0;
        foreach ($kwCourse as $kw) { if (mb_strpos($pageN, $kw) !== false) $hits++; }
        if (!empty($kwCourse) && $hits < ceil(count($kwCourse) * 0.6)) {
            $result = ['url' => $courseUrl, 'text' => ''];
            file_put_contents($detailCache, json_encode($result));
            return $result;
        }
    }

    $fullText = '';
    $descNode = $cxp->query('//*[contains(concat(" ",normalize-space(@class)," ")," eb-description ")]')->item(0);
    if ($descNode) {
        // Eliminar nodos que contienen metadatos de Joomla (tabla de fechas, formulario de inscripción)
        $removePatterns = ['eb-event-registration','eb-custom-field','eb-event-date','eb-location',
                           'register','login','preinscri','identif','volver','inicio','clausura','cierre'];
        foreach ($cxp->query('.//*', $descNode) as $node) {
            $cls  = mb_strtolower($node->getAttribute('class') ?? '');
            $txt  = mb_strtolower(trim($node->textContent ?? ''));
            foreach ($removePatterns as $pat) {
                if (mb_strpos($cls, $pat) !== false || ($node->nodeName === 'p' && mb_strpos($txt, $pat) !== false)) {
                    $node->parentNode->removeChild($node);
                    break;
                }
            }
        }
        // Eliminar celdas de tabla con solo metadatos (| ONLINE | DESEMPLEADOS | ...)
        foreach ($cxp->query('.//table', $descNode) as $table) {
            $table->parentNode->removeChild($table);
        }
        $raw = extractNodeText($descNode);
        // Eliminar líneas con formato de tabla Joomla (| ONLINE | DESEMPLEADOS | ...)
        $raw = preg_replace('/\n\s*\|[^\n]+/', '', $raw);
        // Cortar al primer bloque de metadatos del formulario de inscripción
        foreach (['Por favor', 'Descripci', 'Inicio\n', 'Cierre', 'identif', 'Volver'] as $cut) {
            $pos = mb_strpos($raw, $cut);
            if ($pos !== false) $raw = mb_substr($raw, 0, $pos);
        }
        $fullText = mb_substr(trim($raw), 0, 2500);
    }

    $result = ['url' => $courseUrl, 'text' => $fullText];
    file_put_contents($detailCache, json_encode($result));
    return $result;
}

function formatCourseList($courses) {
    global $PUBLIC_URL;
    $catalogUrl = rtrim($PUBLIC_URL, '/') . '/index.php/cursos-feval';
    if (count($courses) === 1) {
        $c       = $courses[0];
        $details = fetchCourseDetails($c['nombre']);
        $out = "**{$c['nombre']}**\n- Horas: {$c['h']}h\n- Dirigido a: {$c['pub']}\n- Modalidad: {$c['mod']}\n- Fechas: {$c['ini']} – {$c['fin']}\n- Días: {$c['dias']}, {$c['hor']}";
        if ($details && !empty($details['url'])) {
            $out .= "\n- Preinscripción: {$details['url']}";
        } else {
            $out .= "\n- Preinscripción: {$catalogUrl}";
        }
        if ($details && !empty($details['text'])) {
            $out .= "\n\n" . $details['text'];
        }
        return $out;
    }
    $lines = [];
    foreach ($courses as $c) {
        $lines[] = "**{$c['nombre']}** ({$c['h']}h, {$c['pub']}, {$c['mod']}, {$c['ini']}–{$c['fin']}, {$c['dias']} {$c['hor']})";
    }
    return implode("\n", $lines) . "\n\nPreinscripción: {$catalogUrl}";
}

// ─── Extraer último mensaje del usuario y contexto de toda la conversación ────

$last_user_msg = '';
foreach (array_reverse($clean_messages) as $m) {
    if ($m['role'] === 'user') { $last_user_msg = $m['content']; break; }
}

/**
 * Recorre TODOS los mensajes del asistente en la conversación y devuelve
 * los cursos del catálogo mencionados en **negrita**, sin duplicados.
 * Orden: los más recientes primero.
 */
function coursesInConversation($messages, $catalog) {
    $found = [];
    $foundNames = [];
    foreach (array_reverse($messages) as $m) {
        if ($m['role'] !== 'assistant') continue;
        preg_match_all('/\*\*([^*\n]+)\*\*/', $m['content'], $mt);
        foreach (($mt[1] ?? []) as $title) {
            $tl = mb_strtolower(trim($title));
            foreach ($catalog as $c) {
                if (mb_strtolower($c['nombre']) === $tl && !in_array($c['nombre'], $foundNames)) {
                    $found[]      = $c;
                    $foundNames[] = $c['nombre'];
                }
            }
        }
    }
    return $found;
}

// ── Búsqueda con contexto conversacional completo ────────────────────────────
$context_catalog = coursesInConversation($clean_messages, $DIRECT_CATALOG);

if (!empty($context_catalog)) {
    // AND estricto: todas las palabras del query deben estar en el nombre
    $ctx_matches = searchCatalog($last_user_msg, $context_catalog, true);

    // Solo usar contexto si resuelve a MENOS cursos que el contexto completo
    // (evita mostrar contexto cuando el usuario pregunta algo nuevo)
    if ($ctx_matches !== null && count($ctx_matches) < count($context_catalog)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['content' => [['type' => 'text', 'text' => formatCourseList($ctx_matches)]]]);
        exit;
    }
}

// ── Búsqueda normal en catálogo completo ─────────────────────────────────────
$direct_matches = searchCatalog($last_user_msg, $DIRECT_CATALOG);
if ($direct_matches !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['content' => [['type' => 'text', 'text' => formatCourseList($direct_matches)]]]);
    exit;
}

// ─── Scraping en tiempo real desde localhost ──────────────────────────────────

function curlGet($url, $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FevalChatbot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'utf-8',
        CURLOPT_PROXY          => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body && $code === 200) ? $body : null;
}

/**
 * Fetch de una ruta Joomla (/index.php/...) de forma interna.
 * Prueba primero $JOOMLA_INTERNAL_URL (127.0.0.1), luego la URL pública.
 * Así funciona tanto si Apache escucha en localhost como si no.
 */
function joomlaGet($path, $timeout = 15) {
    global $JOOMLA_INTERNAL_URL, $PUBLIC_URL;
    $internal = rtrim($JOOMLA_INTERNAL_URL, '/') . $path;
    $result   = curlGet($internal, $timeout);
    if ($result) return $result;
    // Fallback a URL pública (más lento, pasa por Cloudflare, pero siempre funciona)
    $external = rtrim($PUBLIC_URL, '/') . $path;
    return curlGet($external, $timeout);
}

/**
 * Scraping en tiempo real desde localhost (evita el 403 del acceso externo).
 * - Obtiene las categorías de la página principal.
 * - Por cada categoría, extrae los cursos de la tabla Event Booking.
 * - Cachea el resultado 1 hora para no ralentizar cada petición.
 */
function scrapeCoursesFromWeb($publicUrl) {
    $cacheFile = sys_get_temp_dir() . '/feval_live_v1.txt';
    $cacheTTL  = 3600; // 1 hora

    // Servir desde caché si es reciente
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 100) return $cached;
    }

    // Servir caché antigua mientras se refresca (evita bloquear la petición)
    $staleCache = (file_exists($cacheFile)) ? file_get_contents($cacheFile) : null;

    // Paso 1: obtener categorías de la página principal via localhost
    $mainHtml = joomlaGet('/index.php/cursos-feval');
    if (!$mainHtml) return $staleCache;

    // Extraer rutas de categorías (soporta href antes o después de class)
    preg_match_all(
        '/<a\s[^>]*class="[^"]*eb-category-title-link[^"]*"[^>]*href="([^"]+)"|<a\s[^>]*href="([^"]+)"[^>]*class="[^"]*eb-category-title-link[^"]*"/i',
        $mainHtml, $m
    );
    $catPaths = array_unique(array_filter(array_merge($m[1] ?? [], $m[2] ?? [])));
    if (empty($catPaths)) return $staleCache;

    $sections = [];

    // Paso 2: recorrer cada categoría y extraer cursos
    foreach ($catPaths as $path) {
        $catHtml = joomlaGet($path);
        if (!$catHtml) continue;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $catHtml);
        libxml_clear_errors();
        libxml_use_internal_errors(true); // mantener activo para los sub-DOMs
        $xpath = new DOMXPath($dom);

        // Nombre de la categoría desde el heading de la página
        $headingNode = $xpath->query('//*[contains(@class,"eb-page-heading")]')->item(0);
        $catName = $headingNode ? trim(strip_tags($headingNode->textContent)) : basename($path);

        // Cursos en la tabla de Event Booking
        $eventLinks = $xpath->query('//a[contains(@class,"eb-event-link")]');
        if ($eventLinks->length === 0) continue;

        $events = [];
        foreach ($eventLinks as $link) {
            $title = trim($link->textContent);
            $href  = $link->getAttribute('href');

            // Subir al <tr> para leer la celda de fecha de inicio
            $node = $link;
            while ($node && strtolower($node->nodeName) !== 'tr') {
                $node = $node->parentNode;
            }
            $date = '';
            if ($node) {
                $dateTd = $xpath->query('.//td[@data-content="Inicio"]', $node)->item(0);
                if ($dateTd) {
                    $date = trim(preg_replace('/\s+/', ' ', $dateTd->textContent));
                }
            }

            // Visitar la página del curso para obtener descripción/contenido
            $description = '';
            if ($href) {
                $courseHtml = joomlaGet($href);
                if ($courseHtml) {
                    $cdom = new DOMDocument();
                    $cdom->loadHTML('<?xml encoding="utf-8" ?>' . $courseHtml);
                    libxml_clear_errors();
                    $cxpath = new DOMXPath($cdom);

                    // Selector exacto: eb-description (no eb-description-details)
                    $dn = $cxpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," eb-description ")]')->item(0);
                    if ($dn) {
                        $t = trim(preg_replace('/\s+/', ' ', strip_tags($dn->textContent)));
                        if (mb_strlen($t) > 10) $description = mb_substr($t, 0, 600);
                    }
                }
            }

            $fullUrl = rtrim($publicUrl, '/') . $href;
            $entry   = "- {$title}";
            if ($date) $entry .= " | Inicio: {$date}";
            if ($description) $entry .= "\n  Descripción: {$description}";
            $entry .= "\n  Preinscripción: {$fullUrl}";
            $events[] = $entry;
        }

        if (!empty($events)) {
            $sections[] = "**{$catName}**\n" . implode("\n", $events);
        }
    }

    if (empty($sections)) return $staleCache;

    $result  = "=== CURSOS FEVAL 2026 (datos en tiempo real) ===\n\n";
    $result .= implode("\n\n", $sections);
    $result  = mb_substr($result, 0, 14000);

    file_put_contents($cacheFile, $result);
    return $result;
}

// ─── Inyectar catálogo en tiempo real en el contexto ─────────────────────────

$live_catalog = scrapeCoursesFromWeb($PUBLIC_URL);
if ($live_catalog) {
    $system_prompt .= "\n\n" . $live_catalog;
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
