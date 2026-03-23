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
// 'groq_ollama' → Groq como principal + Ollama local como respaldo automático (RECOMENDADO)
// 'groq'        → Solo Groq nube
// 'ollama'      → Solo Ollama local
$BACKEND = 'groq_ollama';

// URL pública del sitio (la que ven los usuarios en los enlaces)
// Cámbiala si tu dominio/túnel cambia
$PUBLIC_URL = 'https://fly-distributions-reserves-dinner.trycloudflare.com';

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
$OLLAMA_MODEL = 'qwen2.5:7b';

// ── Groq — Principal nube gratuita (sin GPU necesaria) ────────────────────────
// Registro gratis en: https://console.groq.com → API Keys → Create API Key
// Pega aquí tu API key (empieza por gsk_...)
$GROQ_API_KEY = 'gsk_REMOVED';
$GROQ_MODEL   = 'llama-3.3-70b-versatile'; // Gratis, muy potente, < 1s respuesta

// ── Claude / Anthropic (de pago, solo si lo necesitas) ────────────────────────
$CLAUDE_API_KEY = 'sk-ant-TU_API_KEY_AQUI';
$CLAUDE_MODEL   = 'claude-haiku-4-5-20251001';

// ── Seguridad: dominios que pueden usar este proxy ────────────────────────────
// En uso local puedes dejar la lista vacía para permitir cualquier origen,
// o añadir la IP/dominio de tu Joomla local.
$ALLOWED_ORIGINS = []; // Uso local → sin restricción de origen

// ─── FIN CONFIGURACIÓN ────────────────────────────────────────────────────────

set_time_limit(120); // Dar tiempo suficiente a modelos grandes como qwen2.5:14b

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

    // Test de paginación para todas las categorías
    $mainHtmlDbg = curlGet(rtrim($JOOMLA_INTERNAL_URL, '/') . '/index.php/cursos-feval', 8) ?? '';
    preg_match_all(
        '/<a\s[^>]*class="[^"]*eb-category-title-link[^"]*"[^>]*href="([^"]+)"|<a\s[^>]*href="([^"]+)"[^>]*class="[^"]*eb-category-title-link[^"]*"/i',
        $mainHtmlDbg, $mDbg
    );
    $catPathsDbg = array_unique(array_filter(array_merge($mDbg[1] ?? [], $mDbg[2] ?? [])));
    $pagTest = [];
    foreach ($catPathsDbg as $catPath) {
        $catKey = basename($catPath);
        $pagTest[$catKey] = [];
        $seenHrefs = [];
        for ($s = 0; $s <= 50; $s += 5) {
            $testUrl = rtrim($JOOMLA_INTERNAL_URL, '/') . $catPath . ($s > 0 ? '?start=' . $s : '');
            $html    = curlGet($testUrl, 8);
            preg_match_all('/eb-event-link[^>]*href="([^"]+)"|href="([^"]+)"[^>]*eb-event-link/', $html ?? '', $mx);
            $hrefs   = array_unique(array_filter(array_merge($mx[1] ?? [], $mx[2] ?? [])));
            $newOnes = count(array_diff($hrefs, $seenHrefs));
            $pagTest[$catKey]['start_' . $s] = ['links' => count($hrefs), 'new' => $newOnes];
            foreach ($hrefs as $h) $seenHrefs[] = $h;
            if ($newOnes === 0) break;
        }
    }

    $events = getAllJoomlaEvents();
    echo json_encode([
        'internal_url'     => $JOOMLA_INTERNAL_URL,
        'internal_ok'      => $internal_test !== null,
        'internal_len'     => $internal_test ? strlen($internal_test) : 0,
        'public_ok'        => $public_test !== null,
        'public_len'       => $public_test ? strlen($public_test) : 0,
        'pagination_test'  => $pagTest,
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

$AREAS_CATALOG = [
  'Cloud, IA y Análisis de Datos' => [1,2,3,4,5,6,7,8,9,21,22,23,24],
  'Ciberseguridad y Redes'        => [10,11,12,13,14,15,16,17,18,19,20],
  'Analítica de Datos y BI'       => [41,42,43,44,45,46,47,48],
  'Desarrollo de Software'        => [25,26,27,28,29],
  'Videojuegos'                   => [30,31,32,33,34],
  'Gestión de Proyectos'          => [35,36,37],
  'Sistemas y Soporte TIC'        => [38,39,40],
  'Diseño Gráfico y Marketing'    => [66,67,68,69,70,71,72,73],
  'Agricultura 4.0 y Drónica'     => [49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65],
];

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
             'tienes','tiene','puedes','puedo','algún','algun','más','mas',
             // Verbos/palabras cortas españolas que coinciden como subcadena en nombres en inglés
             // 'ser' → User, Server | 'ver' → Server | 'des' → Desarrollador, Designer, Despliegue, redes
             'ser','son','nos','fue','van','sus','mis','tus','les','use','has','sin','asi','eso','ese','esa',
             // 'des' → subcadena en "redes", "Desarrollador", "Designer", "Despliegue"
             'des',
             // Palabras irrelevantes que pasarían el filtro de longitud
             'hacer','poder','tener','querer','busco','busca','buscar','quiero','quier',
             'tipo','algo','otra','otro','bien','aqui','aquí','hola'];
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
    }
    $pagesToScan = array_unique($pagesToScan);

    $events  = [];
    $seen    = [];

    // Para cada categoría, paginar en pasos de 5 (?start=0,5,10...) hasta que no haya eventos
    foreach ($pagesToScan as $catPath) {
        $start = 0;
        while (true) {
            $path = ($start === 0) ? $catPath : $catPath . '?start=' . $start;
            $html = ($catPath === '/index.php/cursos-feval' && $start === 0) ? $mainHtml : joomlaGet($path);
            if (!$html) break;

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            $xp = new DOMXPath($dom);

            $newInPage = 0;
            foreach ($xp->query('//a[contains(@class,"eb-event-link")]') as $link) {
                $t = trim(preg_replace('/\s+/', ' ', $link->textContent));
                $h = $link->getAttribute('href');
                if ($t && $h && !isset($seen[$h])) {
                    $seen[$h] = true;
                    $events[] = ['title' => $t, 'href' => $h];
                    $newInPage++;
                }
            }

            // Si esta página no aportó eventos nuevos, no hay más páginas
            if ($newInPage === 0) break;
            $start += 5;
            if ($start > 200) break; // seguridad: máximo 40 páginas por categoría
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

function courseStarted($ini) {
    $d = strtotime(str_replace('/', '-', $ini));
    return $d !== false && $d <= time();
}

function formatCourseList($courses) {
    global $PUBLIC_URL;
    $catalogUrl = rtrim($PUBLIC_URL, '/') . '/index.php/cursos-feval';
    if (count($courses) === 1) {
        $c       = $courses[0];
        $details = fetchCourseDetails($c['nombre']);
        $out = "**{$c['nombre']}**\n- Horas: {$c['h']}h\n- Dirigido a: {$c['pub']}\n- Modalidad: {$c['mod']}\n- Fechas: {$c['ini']} – {$c['fin']}\n- Días: {$c['dias']}, {$c['hor']}";
        if (courseStarted($c['ini'])) {
            $out .= "\n\n⚠️ Este curso ya ha comenzado. Consulta el catálogo por si hubiera nuevas ediciones: {$catalogUrl}";
        } else {
            if ($details && !empty($details['url'])) {
                $out .= "\n- Preinscripción: {$details['url']}";
            } else {
                $out .= "\n- Preinscripción: {$catalogUrl}";
            }
        }
        if ($details && !empty($details['text'])) {
            $out .= "\n\n" . $details['text'];
        }
        return $out;
    }
    $lines = [];
    foreach ($courses as $c) {
        $started = courseStarted($c['ini']) ? ' ⚠️ ya iniciado' : '';
        $lines[] = "**{$c['nombre']}** ({$c['h']}h, {$c['pub']}, {$c['mod']}, {$c['ini']}–{$c['fin']}, {$c['dias']} {$c['hor']}{$started})";
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

// ── Listado completo de cursos por área (intercepta antes del LLM) ───────────
$list_keys = ['todos los cursos','qué cursos hay','que cursos hay','lista de cursos','ver todos los cursos',
               'cuántos cursos','cuantos cursos','todas las áreas','todas las areas','qué áreas','que areas',
               'qué ofertas','que ofertas','cursos disponibles','ver el catálogo','ver el catalogo',
               'qué formaciones','que formaciones','cuáles son los cursos','cuales son los cursos',
               'qué cursos tenéis','que cursos teneis','qué cursos tiene','que cursos tiene',
               'formaciones disponibles','catálogo completo','catalogo completo',
               'listado de cursos','listado de formaciones','qué ofrecéis','que ofreceis',
               'qué hay disponible','que hay disponible','mostrar cursos','ver cursos',
               'cuál es el catálogo','cual es el catalogo','todo el catálogo','todo el catalogo',
               'qué se imparte','que se imparte','qué se enseña','que se enseña',
               'qué formación hay','que formacion hay','cursos que tenéis','cursos que teneis',
               'cursos desempleados','cursos para desempleados','cursos de desempleados','cursos para desemple',
               'cursos empleados','cursos para empleados','cursos de empleados',
               'cursos ocupados','cursos para ocupados','cursos de ocupados',
               'cursos para trabajadores','cursos para parados','cursos para trabajar'];
$is_listing = false;
foreach ($list_keys as $lk) {
    if (mb_strpos(mb_strtolower($last_user_msg), $lk) !== false) { $is_listing = true; break; }
}
if ($is_listing) {
    global $AREAS_CATALOG, $DIRECT_CATALOG;
    $byN = [];
    foreach ($DIRECT_CATALOG as $c) $byN[$c['n']] = $c;
    $out = "Estos son los cursos disponibles en 2026, organizados por área. Todos son **GRATUITOS**:\n\n";
    foreach ($AREAS_CATALOG as $areaName => $ns) {
        $out .= "**" . $areaName . "**\n";
        foreach ($ns as $n) {
            if (!isset($byN[$n])) continue;
            $c    = $byN[$n];
            $started = courseStarted($c['ini']) ? ' ⚠️ ya iniciado' : '';
            $out .= "- {$c['nombre']} ({$c['pub']}, {$c['ini']}{$started})\n";
        }
        $out .= "\n";
    }
    $out .= "¿Te interesa algún área o curso en concreto? Puedo darte todos los detalles.";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['content' => [['type' => 'text', 'text' => $out]]]);
    exit;
}

// ── Listado de áreas (solo nombres, sin cursos) ───────────────────────────────
$area_list_keys = [
    'dime las areas','dime las áreas','las areas solo','las áreas solo',
    'solo las areas','solo las áreas','que areas hay','qué areas hay',
    'qué áreas hay','que áreas hay','cuales son las areas','cuáles son las áreas',
    'cuales son las áreas','cuáles son las areas','areas disponibles',
    'áreas disponibles','areas de formacion','áreas de formación',
    'areas que teneis','áreas que tenéis','que areas teneis','qué áreas tenéis',
    'mostrar areas','mostrar áreas','ver areas','ver áreas','lista de areas','lista de áreas',
];
$is_area_list = false;
foreach ($area_list_keys as $alk) {
    if (mb_strpos(mb_strtolower($last_user_msg), $alk) !== false) { $is_area_list = true; break; }
}
if ($is_area_list) {
    $area_names = array_keys($AREAS_CATALOG);
    $out = "Las áreas de formación disponibles en FEVAL son:\n\n";
    foreach ($area_names as $a) {
        $out .= "* **{$a}**\n";
    }
    $out .= "\n¿Quieres ver los cursos de alguna área en concreto?";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['content' => [['type' => 'text', 'text' => $out]]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Búsqueda por área cuando el usuario nombra el área (antes de FAQ) ─────────
// Mapa: keywords de usuario → clave exacta en $AREAS_CATALOG
$AREA_KEYS = [
    // Diseño primero para que 'redes sociales' no colisione con 'redes' de Ciberseguridad
    'Diseño Gráfico y Marketing'    => ['diseño gráfico','diseño grafico','redes sociales','photoshop','illustrator','premiere','marketing','community manager','instagram','facebook','whatsapp'],
    'Cloud, IA y Análisis de Datos' => ['inteligencia artificial','ia generativa','chatgpt','machine learning','llm','cloud','nube','azure','aws','google cloud'],
    'Ciberseguridad y Redes'        => ['ciberseguridad','hacking','seguridad informática','seguridad informatica','redes cisco','redes ccna','ccna','ccst','forense','incidentes de seguridad'],
    'Analítica de Datos y BI'       => ['analítica','analitica','business intelligence','power bi','cursos de bi','curso de bi','excel','análisis de datos','analisis de datos'],
    'Desarrollo de Software'        => ['programaci','javascript','python','bases de datos','desarrollo de software'],
    'Videojuegos'                   => ['videojuego','unity','game design','concept art','realidad virtual','animaci'],
    'Gestión de Proyectos'          => ['gestión de proyectos','gestion de proyectos','pmi','scrum','agile'],
    'Sistemas y Soporte TIC'        => ['soporte tic','windows server','linux','lpic','it support'],
    'Agricultura 4.0 y Drónica'    => ['agricultura','dron','drónica','dronica','teledetección','teledeteccion','fotogrametría','fotogrametria','precision'],
];
$area_match_key = null;
$lmsg_lower = mb_strtolower(trim($last_user_msg));
foreach ($AREA_KEYS as $catKey => $keywords) {
    foreach ($keywords as $kw) {
        if (mb_strpos($lmsg_lower, $kw) !== false) {
            $area_match_key = $catKey;
            break 2;
        }
    }
}
// No buscar por área si el mensaje coincide exactamente con el nombre de un curso
// Comprueba ambas direcciones: mensaje contiene nombre de curso O nombre contiene el mensaje (≥10 chars)
$exact_course_hit = false;
foreach ($DIRECT_CATALOG as $_c) {
    $_nl = mb_strtolower($_c['nombre']);
    if (mb_strpos($lmsg_lower, $_nl) !== false ||
        (mb_strlen($lmsg_lower) >= 10 && mb_strpos($_nl, $lmsg_lower) !== false)) {
        $exact_course_hit = true;
        break;
    }
}
if ($area_match_key && isset($AREAS_CATALOG[$area_match_key]) && !$is_listing && !$exact_course_hit) {
    $byN = [];
    foreach ($DIRECT_CATALOG as $c) $byN[$c['n']] = $c;
    $areaCourses = [];
    foreach ($AREAS_CATALOG[$area_match_key] as $n) {
        if (isset($byN[$n])) $areaCourses[] = $byN[$n];
    }
    if (!empty($areaCourses)) {
        $catalogUrl = rtrim($PUBLIC_URL, '/') . '/index.php/cursos-feval';
        $out  = "Cursos del área **{$area_match_key}** en 2026 (todos **GRATUITOS**):\n\n";
        foreach ($areaCourses as $c) {
            $started = courseStarted($c['ini']) ? ' ⚠️ ya iniciado' : '';
            $out .= "- **{$c['nombre']}** ({$c['pub']}, {$c['ini']}–{$c['fin']}{$started})\n";
        }
        $out .= "\n¿Te interesa algún curso en concreto? Dime el nombre y te doy todos los detalles.";
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['content' => [['type' => 'text', 'text' => $out]]]);
        exit;
    }
}

// ── FAQ directas: respuestas fijas para preguntas frecuentes ─────────────────
$faq_q = mb_strtolower(trim($last_user_msg));
// Normalizar sinónimos frecuentes
$faq_q = str_replace(['ocupado','ocupados','trabajador','trabajadores'], ['empleado','empleados','empleado','empleados'], $faq_q);
$faq_q = str_replace(['en paro','parado','parada'], ['desempleado','desempleado','desempleado'], $faq_q);
$faq_responses = [
    // Gratuidad
    ['keys' => ['gratis','gratuito','gratuita','precio','coste','cuesta','pagar','pago','financiaci','cuánto cuesta','cuanto cuesta','tiene coste','tiene algún coste','cobran algo','cobras algo'],
     'answer' => "Sí, todos los cursos son completamente **GRATUITOS**, incluyendo el examen oficial de certificación cuando el curso lo incluya. No hay ningún coste para el alumno. Están financiados por la Junta de Extremadura (Consejería de Economía, Empleo y Transformación Digital) y el **SEXPE**."],

    // SEXPE
    ['keys' => ['sexpe'],
     'answer' => "El **SEXPE** (Servicio Extremeño Público de Empleo) es el organismo de la Junta de Extremadura que co-financia estos cursos gratuitos. Para consultas sobre tu situación laboral, prestaciones o subsidios, contacta con tu Centro de Empleo más cercano — FEVAL no tiene acceso a tus datos en el SEXPE."],

    // FEVAL / contacto
    ['keys' => ['feval','quién organiza','quien organiza'],
     'answer' => "**FEVAL Formación** es la plataforma de formación TIC de la Institución Ferial de Extremadura. Ofrecemos más de 70 cursos TIC gratuitos en 2026, financiados por la Junta de Extremadura y el SEXPE.\n\nContacto: formacion@feval.com | 924 829 100 | 618 457 790"],

    // Recoger diplomas / horario sede
    ['keys' => ['recoger el diploma','recoger mi diploma','recoger el certificado','recoger mi certificado','recoger el título','recoger los diplomas','recoger los certificados','recoger los títulos','recogida de diplomas','recogida de certificados','recogida de títulos','recogida','ir a recoger','horario diploma','horario feval','horario sede','horario oficina','horario de visita','horario de atenci','horario de las oficinas','hora abren','a que hora abren','cuando abren','visitar las instalaciones','visita presencial','puedo visitar','visita feval','instalaciones feval','don benito','paseo de feval'],
     'answer' => "Puedes recoger los diplomas de **lunes a viernes de 08:00h a 15:00h** en las instalaciones del Centro Tecnológico de FEVAL, sito en el Paseo de FEVAL s/n, Don Benito (Badajoz)."],

    // Cuándo llega el diploma
    ['keys' => ['cuándo llega','cuando llega','cuándo me llega','cuando me llega','me llegará','llegará el diploma','llegará el título','recibiré el diploma','recibire el diploma','cuándo tendré','cuando tendré','tardará','tardara','tiempo diploma','plazo del diploma'],
     'answer' => "El diploma de aprovechamiento debe ser firmado por el SEXPE, un trámite que suele tardar **varios meses**. Sin embargo, desde FEVAL podemos emitirte un **certificado de aprovechamiento** firmado que acredita que has realizado la formación mientras llega el diploma oficial. Contacta en formacion@feval.com o 924 829 100."],

    // Tipos de diploma / certificado de profesionalidad
    ['keys' => ['tipos de diploma','tipo de diploma','certificado de profesionalidad','profesionalidad'],
     'answer' => "Cada curso incluye un **diploma de aprovechamiento** expedido por el SEXPE. Los cursos con certificación oficial (Cisco, Microsoft, EC-Council, AWS, etc.) incluyen también el examen oficial sin coste adicional.\n\n⚠️ Ninguno de los cursos cuenta con **certificado de profesionalidad**, al tratarse de formación no reglada. Sí cuentan con certificaciones profesionales de fabricante altamente demandadas en el mercado laboral."],

    // Diploma / certificado (genérico)
    ['keys' => ['diploma','certificado','título','titulo','acreditaci'],
     'answer' => "Cada curso incluye un **diploma de aprovechamiento** expedido por el SEXPE al superar el curso. En los cursos con certificación oficial el examen está incluido sin coste adicional. La firma oficial del diploma SEXPE puede tardar varios meses; mientras tanto FEVAL puede emitir un certificado provisional.\n\nPara recoger diplomas: lunes a viernes 08:00-15:00h en Paseo de FEVAL s/n, Don Benito (Badajoz)."],

    // Baremación / selección
    ['keys' => ['baremaci','selección','seleccion','criterios','puntuaci','75 preinscripciones','más de 75','plazas libres','cómo se selecciona','como se selecciona'],
     'answer' => "Cuando un curso supera las **75 preinscripciones** se cierra automáticamente y se aplica baremación:\n- **Desempleados**: criterios oficiales del SEXPE (consultables en la web).\n- **Empleados**: criterios propios de FEVAL (consultables en la web).\n\nSe publica una resolución con los DNI y puntuaciones ordenadas, ofreciendo plaza hasta completar **16 alumnos**. En cursos con menos de 75 preinscripciones se llama por orden de inscripción, por lo que conviene inscribirse cuanto antes."],

    // Requisitos para acceder
    ['keys' => ['requisito','requisitos','para acceder al curso','cómo acceder al curso','como acceder al curso','quién puede','quien puede'],
     'answer' => "Los requisitos son mínimos:\n- **Empleados**: que tu puesto de trabajo esté en Extremadura.\n- **Desempleados**: ser demandante de empleo en cualquier Centro de Empleo de Extremadura.\n\nPara cursos avanzados se recomienda haber realizado previamente los cursos de nivel básico del mismo itinerario."],

    // Extremadura / fuera de la comunidad
    ['keys' => ['fuera de extremadura','fuera de la comunidad','otra comunidad','no resido en extremadura','no vivo en extremadura','teletrabaj','soy de madrid','soy de barcelona','soy de sevilla','soy de otro'],
     'answer' => "Los cursos son exclusivos para personas que **residan en Extremadura** o que, sin residir, **teletrabajen para una empresa con domicilio social en Extremadura**. Es un requisito del SEXPE al ser una iniciativa extremeña."],

    // Empleado/desempleado intercambio de plazas
    ['keys' => ['curso de empleado','curso de ocupado','siendo desempleado','desempleado puedo hacer','desempleado puedo acceder','desempleado puedo entrar','desempleado puedo realizar','curso para empleado','curso para ocupado','puedo hacer un curso de empleado','puedo hacer un curso de ocupado','acceder a un curso de empleado','acceder a un curso de ocupado','empleados puedo','ocupados puedo','30%','trabajo en una empresa extremeña','trabajo y quiero apuntarme'],
     'answer' => "Sí puedes. Los cursos son **preferentemente** para empleados o desempleados, pero si quedan plazas libres:\n- Hasta un **30% de plazas** de un curso de empleados/ocupados puede asignarse a desempleados.\n- Y viceversa (desempleados → empleados).\n\nSiempre sujeto a disponibilidad de plazas."],

    // Faltas / asistencia
    ['keys' => ['faltar','faltas','cuántas faltas','cuantas faltas','asistencia','ausencia','puedo faltar','máximo de faltas','maximo de faltas'],
     'answer' => "Se puede faltar como máximo el **25% de las clases** (p.ej. en un curso de 12 clases, máximo 3 faltas). Al menos **una falta** debe justificarse con justificante oficial (urgencia médica, deber público, etc.).\n\nCada clase dura 180 minutos; para que compute como asistencia hay que estar al menos **150 minutos**. Si se está menos tiempo, cuenta como falta."],

    // Abandono / baja
    ['keys' => ['abandonar','abandono','darme de baja del curso','baja del curso','darme de baja de la formaci','me repercute','penalizaci','sancion','sanciones'],
     'answer' => "Abandonar un curso **no te repercute en nada** ni genera sanciones para futuras formaciones con FEVAL o SEXPE. Simplemente no obtendrás el diploma de aprovechamiento de ese curso.\n\nSi no vas a poder realizarlo, comunícalo cuanto antes a formacion@feval.com o 924 829 100 para que tu plaza pueda asignarse a otro alumno."],

    // Prestaciones / subsidio / demanda de empleo
    ['keys' => ['prestaci','subsidio','demanda de empleo','intermediaci','estoy en paro','cobro el paro','me quitan','me suspenden','me afecta el curso','afecta al paro','afecta a la prestaci'],
     'answer' => "Realizar un curso **no suspende tu demanda de empleo** y se mantiene la intermediación (salvo que tú renuncies a ella). Sin embargo, si tienes dudas sobre prestaciones o subsidios concretos, contacta con tu **Centro de Empleo más cercano**, ya que FEVAL no tiene acceso a tus datos en el SEXPE."],

    // Online / clases en directo / grabaciones
    ['keys' => ['online','directo','grabaci','grabad','ritmo','obligatorio asistir','hay que conectarse','clases online','cómo son las clases','como son las clases','es presencial','clases presenciales','se graba','no se graba'],
     'answer' => "Todos los cursos son **online con clases en directo** en horario fijo (no se graban). Debes conectarte en el horario indicado. La excepción son algunos cursos de drones, que tienen una pequeña parte presencial.\n\nNo es formación a tu propio ritmo: hay que asistir a las clases en el horario programado."],

    // Requisitos técnicos
    ['keys' => ['requisitos técnicos','requisitos tecnicos','necesito ordenador','necesito un ordenador','sin ordenador','qué necesito','que necesito','necesito internet'],
     'answer' => "Solo necesitas **ordenador con conexión a internet** y nociones básicas de informática. Para cursos avanzados se recomienda haber realizado primero los cursos de nivel básico del mismo itinerario."],

    // Formulario / datos preinscripción
    ['keys' => ['formulario','datos personales','largo formulario','rellenar datos','rellenar formulario'],
     'answer' => "El formulario completo solo se rellena **la primera vez** que te preinscribes. En siguientes preinscripciones el sistema recuerda tus datos, y puedes modificarlos si es necesario."],

    // Contraseña olvidada / recuperar acceso
    ['keys' => ['contraseña','contrasena','password','passwd','olvide la','olvidé la','olvidado la','recuperar acceso','no recuerdo'],
     'answer' => "Si has olvidado tu contraseña, ve a la página de inicio de sesión y haz clic en **\"¿Olvidaste tu contraseña?\"**. Te enviarán un enlace de recuperación al email con el que te registraste (revisa también el Spam).\n\nSi sigues sin acceder, contacta: formacion@feval.com | 924 829 100 | 618 457 790"],

    // Enlace aula virtual / no llega acceso al curso
    ['keys' => ['aula virtual','enlace del curso','link del curso','acceso al curso','acceso al aula','enlace de acceso','no tengo acceso al curso','no me llega el enlace'],
     'answer' => "Si te han confirmado la matrícula pero **no has recibido el enlace de acceso** al aula virtual, contacta urgentemente:\n- **Email**: formacion@feval.com\n- **Teléfono**: 924 829 100 | 618 457 790\n\nNo esperes al día del inicio del curso."],

    // Cuenta bloqueada / registro
    ['keys' => ['cuenta bloqueada','bloqueada','bloquead','confirmar.*correo','confirmar.*email','no recibo.*correo','no me llega el correo','no me llega el email','no me llega la confirmaci','spam'],
     'answer' => "Al crear tu cuenta recibirás un **correo de confirmación** (revisa también la carpeta de Spam). Debes confirmar el registro haciendo clic en el enlace del correo — hasta entonces la cuenta aparecerá como bloqueada.\n\nSi no recibes el correo, contacta: formacion@feval.com | 924 829 100 | 618 457 790"],

    // Error al crear cuenta / usuario en uso
    ['keys' => ['error al crear','me sale un error','me da error','no me deja','nombre de usuario','usuario en uso','no puedo crear cuenta','no puedo registrarme','error al registrar','fallo al crear'],
     'answer' => "El error más habitual es que el **nombre de usuario ya está en uso**. Prueba con uno diferente. Si el problema persiste, contacta con formacion@feval.com | 924 829 100 | 618 457 790"],

    // No sé si estoy seleccionado / no sé nada
    ['keys' => ['estoy dentro','estoy seleccionado','soy seleccionado','he sido seleccionado','no sé nada','no se nada del curso','me han seleccionado'],
     'answer' => "Solo contactaremos con las personas **seleccionadas** para el curso. Si se acerca la fecha y no has recibido noticias, es posible que no hayas sido seleccionado en esta edición. Puedes preinscribirte en otras ediciones o cursos similares.\n\nSi te han confirmado la matrícula pero no recibes el enlace de acceso, contacta: formacion@feval.com | 924 829 100 | 618 457 790"],

    // No puedo asistir / renunciar a plaza
    ['keys' => ['no puedo asistir','no podré asistir','no podré realizarlo','renunciar a la plaza','cedo mi plaza','liberar plaza'],
     'answer' => "Comunícalo cuanto antes a **formacion@feval.com** o al 924 829 100 / 618 457 790. Es importante para poder asignar tu plaza a otro alumno y que no quede libre cuando hay personas interesadas."],

    // Preferencia itinerario
    ['keys' => ['preferencia','itinerario','siguiente curso','continuar el itinerario','prioridad'],
     'answer' => "Si ya has realizado un curso del itinerario y quieres continuar con el siguiente, tienes **preferencia en la asignación de plaza** respecto a nuevos alumnos, ya que el objetivo es que completes el itinerario completo."],

    // Sugerencia / contacto
    ['keys' => ['sugerencia','contacto','contactar','email','correo feval','correo formacion','teléfono','telefono','comunicar','cómo os contacto','como os contacto','cómo contacto','como contacto'],
     'answer' => "Puedes contactar con FEVAL Formación por:\n- **Email**: formacion@feval.com\n- **Teléfono**: 924 829 100 | 618 457 790\n- **Horario**: lunes a viernes de 08:00h a 15:00h"],

    // Preinscripción (genérico)
    ['keys' => ['preinscripci','preinscribirme','preinscribirte','preinscribirse','preinscribir','inscripci','inscribirme','inscribirte','inscribirse','apuntar','apuntarme','solicitar','registro'],
     'answer' => "Para preinscribirte entra en el curso que te interese desde el catálogo:\n" . rtrim($PUBLIC_URL, '/') . "/index.php/cursos-feval\n\nEl formulario completo solo se rellena la primera vez. Solo contactaremos con los seleccionados."],
];
foreach ($faq_responses as $faq) {
    foreach ($faq['keys'] as $key) {
        if (mb_strpos($faq_q, $key) !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['content' => [['type' => 'text', 'text' => $faq['answer']]]]);
            exit;
        }
    }
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

// ── Guardarrail de tema: bloquear en PHP antes de llamar al LLM ──────────────
// Si la consulta no contiene ninguna palabra relacionada con FEVAL y tiene más
// de 2 palabras significativas, devolver respuesta off-topic sin llamar al LLM.
function isFevalRelated($msg) {
    $q = mb_strtolower($msg);

    // Mensajes muy cortos (≤2 palabras) pueden ser respuestas de seguimiento ("sí", "ok", "cuándo")
    $words = array_filter(
        explode(' ', preg_replace('/[^a-záéíóúüñ0-9 ]/u', ' ', $q)),
        fn($w) => mb_strlen($w) > 2
    );
    if (count($words) <= 2) return true;

    // Palabras clave relacionadas con FEVAL (si aparece alguna → es on-topic)
    $feval_keywords = [
        // Proceso/gestión
        'preinscri','inscri','baremo','diploma','certificad','certifi',
        'plaza','selecci','asistenci','falta','faltar','aula','acceso',
        'enlace','contraseña','contrasena','cuenta','registr','matricul',
        'bloqueada','bloqueo','confirmaci','spam',
        // FEVAL/institución
        'feval','sexpe','extremadura','formaci','curso','clase',
        // Tecnología que imparte FEVAL
        'python','java','javascript','hacking','hack','cibersegur','azure',
        'aws','cisco','ccna','ccst','linux','scrum','pmi','cloud',
        'inteligencia','artificial','drone','dron','agricultura','power',
        'photoshop','illustrator','premiere','unity','videojuego','game',
        'marketing','programaci','programar','desarrollo','desarrollar',
        'analisis','análisis','seguridad','redes','sql','html','css',
        'diseño','disenyo','excel','office','illustr','machine','learning',
        // Elegibilidad/logística
        'empleado','desempleado','paro','empleo','trabajador','docente',
        'gratis','gratuito','precio','coste','horario','fecha','duraci',
        'online','presencial','semipresencial','requisito','aprender',
        'titulaci','certificacion','certificación','diploma',
        // Navegación / preguntas de catálogo
        'area','areas','área','áreas','modalidad','modalidades',
        'disponible','disponibles','listado','catálogo','catalogo',
        'opciones','opcion','opción','tipos','tipo','que hay','cuales','cuáles',
    ];

    // Temas/oficios que FEVAL NO cubre — si aparecen, bloquear aunque "curso" esté presente
    $non_feval_topics = [
        // Oficios de construcción/mantenimiento
        'fontanero','fontanería','fontaneria','electricista','carpintero','carpintería',
        'carpinteria','albañil','soldador','soldadura','pintor de','cerrajero',
        // Hostelería/alimentación
        'cocinero','cocina','chef','repostero','pastelero','camarero','hostelería','hosteleria',
        // Sanidad
        'médico','medico','medicina','enfermero','enfermería','farmacéutico','farmacia',
        'veterinario','veterinaria','fisioterapeuta','óptico',
        // Jurídico/financiero
        'abogado','derecho jurídico','contable','contabilidad','fiscal','asesor fiscal',
        // Automoción
        'mecánico','mecanico','automoción','chófer','chofer','camionero','conductor de',
        // Belleza/bienestar
        'peluquero','peluquería','peluqueria','esteticista','estética','tatuador','yoga','pilates',
        // Jardinería/medio ambiente
        'jardinero','jardinería','jardineria',
        // Idiomas
        'inglés','ingles','francés','frances','alemán','aleman','italiano','chino','árabe',
        'idioma','idiomas',
        // Deporte/música
        'deporte','fitness','gimnasio','músico','musico','guitarra','piano','baile','danza',
        // Redes sociales (uso personal, no marketing profesional)
        'tiktok','youtube','twitch','streaming','influencer',
        // Otras profesiones no TIC
        'agricultor','ganadero','veterinari',
    ];

    foreach ($non_feval_topics as $neg) {
        if (mb_strpos($q, $neg) !== false) return false;
    }

    foreach ($feval_keywords as $kw) {
        if (mb_strpos($q, $kw) !== false) return true;
    }
    return false;
}

$OFF_TOPIC_MSG = "Solo puedo ayudarte con información sobre los cursos y servicios de FEVAL Formación. ¿Hay algún curso o área de formación en la que pueda ayudarte?";

// Si ya hay conversación en curso (≥3 mensajes) el contexto es claramente FEVAL → no bloquear
$conversation_active = count($clean_messages) >= 3;

if (!$conversation_active && !isFevalRelated($last_user_msg)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['content' => [['type' => 'text', 'text' => $OFF_TOPIC_MSG]]]);
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
    $system_prompt .= "\n\n=== CATÁLOGO EN TIEMPO REAL (REFERENCIA INTERNA — NO LISTAR) ===\n";
    $system_prompt .= "ATENCIÓN: Estos datos son SOLO para responder consultas ESPECÍFICAS sobre un curso concreto (fechas, descripción, enlace). NUNCA hagas un listado completo de todos los cursos ni de todas las áreas aunque el usuario te lo pida. Si el usuario pide ver todos los cursos o el catálogo, responde ÚNICAMENTE: \"Puedes consultar el catálogo completo en: https://formacionfeval.com/index.php/cursos-feval\". NUNCA menciones ni reproduzcas las URLs de preinscripción que aparecen en estos datos — en su lugar usa siempre: https://formacionfeval.com/index.php/cursos-feval\n\n";
    $system_prompt .= $live_catalog;
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

// ── Helper: llamar a Ollama y devolver texto o null en caso de error ──────────
function callOllama($system_prompt, $clean_messages, $max_tokens) {
    global $OLLAMA_HOST, $OLLAMA_PORT, $OLLAMA_MODEL;
    $payload = [
        'model'    => $OLLAMA_MODEL,
        'stream'   => false,
        'options'  => ['num_predict' => $max_tokens, 'temperature' => 0.1, 'num_ctx' => 8192],
        'messages' => array_merge(
            $system_prompt ? [['role' => 'system', 'content' => $system_prompt]] : [],
            $clean_messages
        ),
        'think' => false
    ];
    $result = curlPost(
        "http://{$OLLAMA_HOST}:{$OLLAMA_PORT}/api/chat",
        ['Content-Type: application/json'],
        $payload,
        110
    );
    if ($result['error']) return null;
    $data = json_decode($result['body'], true);
    if ($result['code'] !== 200 || !isset($data['message']['content'])) return null;
    return $data['message']['content'];
}

// ══════════════════════════════════════════════════════
// BACKEND: OLLAMA (gratis, local, ilimitado)
// ══════════════════════════════════════════════════════
if ($BACKEND === 'ollama') {

    $reply_text = callOllama($system_prompt, $clean_messages, $max_tokens);
    if ($reply_text === null) {
        http_response_code(502);
        echo json_encode(['error' => 'No se pudo conectar con Ollama. ¿Está Ollama ejecutándose?']);
        exit;
    }

// ══════════════════════════════════════════════════════
// BACKEND: GROQ (nube gratuita, muy rápido)
// ══════════════════════════════════════════════════════
} elseif ($BACKEND === 'groq' || $BACKEND === 'groq_ollama') {

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

    $groq_ok = !$result['error'] && $result['code'] === 200;
    $data    = $groq_ok ? json_decode($result['body'], true) : null;
    $groq_ok = $groq_ok && isset($data['choices'][0]['message']['content']);

    if ($groq_ok) {
        $reply_text = $data['choices'][0]['message']['content'];
    } elseif ($BACKEND === 'groq_ollama') {
        // Groq falló → intentar Ollama como respaldo
        $reply_text = callOllama($system_prompt, $clean_messages, $max_tokens);
        if ($reply_text === null) {
            http_response_code(502);
            echo json_encode(['error' => 'Groq y Ollama no disponibles. Inténtalo más tarde.']);
            exit;
        }
    } else {
        http_response_code($result['code'] ?: 502);
        $detail = isset($data['error']['message']) ? $data['error']['message'] : $result['body'];
        echo json_encode(['error' => 'Error de Groq: ' . $detail]);
        exit;
    }

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
