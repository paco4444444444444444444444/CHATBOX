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
function searchCatalog($query, $catalog) {
    $q = mb_strtolower($query);
    // Palabras a ignorar
    $stop = ['curso','cursos','hay','hay','sobre','para','el','la','los','las','de','que','información','informacion','dame','quiero','saber','ver'];
    $words = array_filter(explode(' ', preg_replace('/[^a-z0-9áéíóúüñ ]/u', ' ', $q)), function($w) use ($stop) {
        return mb_strlen($w) > 2 && !in_array($w, $stop);
    });
    if (empty($words)) return null;

    $matches = [];
    foreach ($catalog as $c) {
        $name = mb_strtolower($c['nombre']);
        foreach ($words as $w) {
            if (mb_strpos($name, $w) !== false) {
                $matches[] = $c;
                break;
            }
        }
    }
    return count($matches) > 0 ? $matches : null;
}

function formatCourseList($courses) {
    if (count($courses) === 1) {
        $c = $courses[0];
        return "**{$c['nombre']}**\n- Horas: {$c['h']}h\n- Dirigido a: {$c['pub']}\n- Modalidad: {$c['mod']}\n- Fechas: {$c['ini']} – {$c['fin']}\n- Días: {$c['dias']}, {$c['hor']}\n- Preinscripción: https://formacionfeval.com/index.php/cursos-feval";
    }
    $lines = [];
    foreach ($courses as $c) {
        $lines[] = "**{$c['nombre']}** ({$c['h']}h, {$c['pub']}, {$c['mod']}, {$c['ini']}–{$c['fin']}, {$c['dias']} {$c['hor']})";
    }
    return implode("\n", $lines) . "\n\nPreinscripción: https://formacionfeval.com/index.php/cursos-feval";
}

// Detectar si la pregunta es sobre cursos específicos y responder directamente
$last_user_msg = '';
foreach (array_reverse($clean_messages) as $m) {
    if ($m['role'] === 'user') { $last_user_msg = $m['content']; break; }
}

$direct_matches = searchCatalog($last_user_msg, $DIRECT_CATALOG);
if ($direct_matches !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['reply' => formatCourseList($direct_matches)]);
    exit;
}

// ─── Scraping de fechas y horarios en tiempo real ─────────────────────────────

function curlGet($url, $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FevalChatbot/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => 'utf-8',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body && $code === 200) ? $body : null;
}

/**
 * Extrae cursos de una página DOM de listado de Joomla.
 * Devuelve array de ['titulo' => string, 'info' => string, 'url' => string]
 */
function extractCoursesFromPage($html, $baseUrl = 'https://formacionfeval.com') {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Eliminar ruido
    foreach ($xpath->query('//script|//style|//nav|//header|//footer|//noscript|//form') as $node) {
        $node->parentNode->removeChild($node);
    }

    $courses = [];

    // Joomla blog layout: artículos individuales
    $articles = $xpath->query(
        '//article | //div[contains(@class,"item-page")] | ' .
        '//div[contains(@class,"items-row")]//div[contains(@class,"item")] | ' .
        '//div[contains(@class,"blog")]//div[contains(@class,"item")]'
    );

    foreach ($articles as $article) {
        // Título del curso
        $titleNode = $xpath->query(
            './/h1[not(ancestor::nav)] | .//h2[not(ancestor::nav)] | ' .
            './/h3[not(ancestor::nav)] | .//a[contains(@class,"item-title")]',
            $article
        )->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

        // URL del curso
        $linkNode = $xpath->query('.//h1//a | .//h2//a | .//h3//a | .//a[contains(@class,"item-title")]', $article)->item(0);
        $url = '';
        if ($linkNode && $linkNode->hasAttribute('href')) {
            $href = $linkNode->getAttribute('href');
            $url = (strpos($href, 'http') === 0) ? $href : $baseUrl . $href;
        }

        // Línea de resumen tipo: | PRESENCIAL | DESEMPLEADOS | Lunes a Jueves | 17:00-20:00 | 4 Horas | 28/09/2026 a 08/10/2026 |
        $text = preg_replace('/\s+/', ' ', trim($article->textContent));
        $infoLine = '';

        if (preg_match('/\|\s*(PRESENCIAL|ONLINE|WEBINAR)[^|]*(?:\|[^|]*){3,}\|\s*\d{2}[\/\-]\d{2}[\/\-]\d{4}/i', $text, $m)) {
            $infoLine = trim($m[0]);
        } elseif (preg_match('/(?:PRESENCIAL|ONLINE|WEBINAR)[^\n]*\d{2}[\/\-]\d{2}[\/\-]\d{4}/i', $text, $m)) {
            $infoLine = trim($m[0]);
        }

        if ($title && $infoLine) {
            $courses[] = ['titulo' => $title, 'info' => $infoLine, 'url' => $url];
        }
    }

    // Fallback: si no hay artículos detectados, buscar bloques con patrón de pipe
    if (empty($courses)) {
        $bodyNode = $xpath->query('//body')->item(0);
        if ($bodyNode) {
            $fullText = $bodyNode->textContent;
            // Buscar pares: línea de título seguida de línea con fechas/pipes
            $lines = array_map('trim', explode("\n", preg_replace('/[ \t]+/', ' ', $fullText)));
            $lines = array_values(array_filter($lines, function($l) { return strlen($l) > 3; }));
            for ($i = 0; $i < count($lines) - 1; $i++) {
                $next = $lines[$i + 1] ?? '';
                if (
                    strlen($lines[$i]) > 10 && strlen($lines[$i]) < 200 &&
                    !preg_match('/\d{2}[\/\-]\d{2}/', $lines[$i]) &&
                    preg_match('/\d{2}[\/\-]\d{2}[\/\-]\d{4}/', $next)
                ) {
                    $courses[] = ['titulo' => $lines[$i], 'info' => $next, 'url' => ''];
                }
            }
        }
    }

    return $courses;
}

/**
 * Detecta si hay página siguiente en el listado de Joomla y devuelve su URL.
 */
function getNextPageUrl($html, $currentUrl) {
    if (!preg_match('/<a[^>]+rel=["\']next["\'][^>]*href=["\']([^"\']+)["\']|<a[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']next["\']/i', $html, $m)) {
        // Intentar buscar enlace "Siguiente" o ">" en paginador
        if (!preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(?:\s*(?:Siguiente|›|&rsaquo;|&gt;|»)\s*)<\/a>/i', $html, $m)) {
            return null;
        }
    }
    $href = $m[1] ?: $m[2];
    if (empty($href) || $href === '#') return null;
    if (strpos($href, 'http') === 0) return $href;
    $base = parse_url($currentUrl, PHP_URL_SCHEME) . '://' . parse_url($currentUrl, PHP_URL_HOST);
    return $base . $href;
}

/**
 * Extrae el texto limpio del contenido principal de una página de curso individual.
 * Funciona con Event Booking, artículos Joomla y cualquier otro layout.
 */
function extractCourseDetail($html, $baseUrl = 'https://formacionfeval.com') {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Eliminar ruido
    foreach ($xpath->query('//script|//style|//nav|//header|//footer|//noscript|//form|//aside|//*[contains(@class,"menu")]|//*[contains(@class,"breadcrumb")]|//*[contains(@id,"menu")]') as $node) {
        if ($node->parentNode) $node->parentNode->removeChild($node);
    }

    // Intentar encontrar el contenido principal
    $selectors = [
        '//*[contains(@class,"eb_event_description")]',
        '//*[contains(@class,"event-description")]',
        '//*[contains(@class,"item-page")]',
        '//*[contains(@class,"article-content")]',
        '//*[contains(@class,"com_eventbooking")]',
        '//main',
        '//*[@id="content"]',
        '//*[contains(@class,"content")]',
        '//article',
    ];

    $contentNode = null;
    foreach ($selectors as $sel) {
        $nodes = $xpath->query($sel);
        if ($nodes->length > 0) {
            $contentNode = $nodes->item(0);
            break;
        }
    }

    if (!$contentNode) {
        $contentNode = $xpath->query('//body')->item(0);
    }
    if (!$contentNode) return '';

    // Limpiar texto: colapsar espacios, preservar saltos de línea significativos
    $text = $contentNode->textContent;
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);

    return mb_substr($text, 0, 1500); // máx 1500 chars por curso
}

function fetchCourseData() {
    $cacheFile = sys_get_temp_dir() . '/feval_courses_v3_cache.txt';
    $cacheTTL  = 3600; // refresca cada hora

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 50) return $cached;
    }

    $startUrl = 'https://formacionfeval.com/index.php/cursos-feval';
    $allCourses = [];
    $visited    = [];
    $url        = $startUrl;
    $maxPages   = 8;

    // 1. Recopilar todos los cursos del listado (título + URL básica)
    for ($page = 0; $page < $maxPages; $page++) {
        if (isset($visited[$url])) break;
        $visited[$url] = true;

        $html = curlGet($url);
        if (!$html) break;

        $courses = extractCoursesFromPage($html);
        foreach ($courses as $c) {
            $key = md5($c['titulo']);
            if (!isset($allCourses[$key])) {
                $allCourses[$key] = $c;
            }
        }

        $next = getNextPageUrl($html, $url);
        if (!$next || $next === $url) break;
        $url = $next;
    }

    if (empty($allCourses)) return null;

    // 2. Para cada curso con URL, entrar en su página individual y extraer detalle
    $lines = [];
    foreach ($allCourses as $c) {
        $line = '### ' . $c['titulo'];
        if ($c['info']) $line .= "\n" . $c['info'];

        if (!empty($c['url'])) {
            $detailHtml = curlGet($c['url']);
            if ($detailHtml) {
                $detail = extractCourseDetail($detailHtml);
                if ($detail) {
                    $line .= "\n" . $detail;
                }
            }
            $line .= "\nURL: " . $c['url'];
        }

        $lines[] = $line;
    }

    $result = implode("\n\n---\n\n", $lines);
    $result = mb_substr($result, 0, 12000); // máx 12.000 chars

    file_put_contents($cacheFile, $result);
    return $result;
}

// ─── Catálogo de cursos con filtrado inteligente ──────────────────────────────

$CATALOG = [
  'ia' => [
    'keywords' => ['ia','inteligencia artificial','chatgpt','generativa','azure ai','aws ai','ai-900','ai practitioner','gpt','llm','machine learning'],
    'data' => "-- INTELIGENCIA ARTIFICIAL --\nItinerario EMPLEADOS (168h): 1.Fundamentos IA|36h|16/03-09/04/2026|lun-jue 17-20h\n2.IA avanzada|24h|20/04-30/04/2026|lun-jue 17-20h\n3.IA Generativa CCS|36h|11/05-28/05/2026|lun-jue 17-20h\n4.Azure AI-900|36h|05/10-23/10/2026|lun-jue 17-20h\n5.AWS AI Practitioner|36h|09/11-26/11/2026|lun-jue 17-20h\nItinerario DESEMPLEADOS (144h): 6.Introducción a la IA|36h|13/04-30/04/2026|lun-jue 17-20h\n7.ChatGPT: IA para Textos y Reuniones|36h|11/05-28/05/2026|lun-jue 17-20h\n8.IA para Imágenes y Sonido|36h|28/09-16/10/2026|lun-jue 17-20h\n9.Análisis de Datos con IA|36h|26/10-13/11/2026|lun-jue 17-20h"
  ],
  'ciber' => [
    'keywords' => ['ciberseguridad','hacking','hacker','ccna','cisco','redes','networking','forense','firewall','seguridad','ciberseguridad','ccst','incidentes'],
    'data' => "-- CIBERSEGURIDAD Y REDES --\nItinerario EMPLEADOS (180h): 10.Fundamentos redes CCST|48h|16/03-16/04/2026|lun-jue 17-20h\n11.Ciberseguridad básica CCST|48h|04/05-28/05/2026|lun-jue 17-20h\n12.Hacking Ético EC Council|48h|26/10-03/12/2026|lun-mié 16-20h\n13.Análisis Forense EC Council|36h|14/09-14/10/2026|lun-mié 16-20h\nCertificado CISCO CCNA (120h): 14.CCNA Intro to Networks|24h|14/09-24/09/2026|lun-jue 17-20h\n15.CCNA Switching Routing Wireless|48h|05/10-29/10/2026|lun-jue 17-20h\n16.CCNA Enterprise Networking|48h|09/11-03/12/2026|lun-jue 17-20h\nItinerario DESEMPLEADOS (144h): 17.Fundamentos redes CCST|36h|09/02-12/03/2026|lun-jue 17-20h\n18.Ciberseguridad básica CCST|36h|23/03-23/04/2026|lun-jue 17-20h\n19.Hacking Ético intro|36h|04/05-21/05/2026|lun-jue 17-20h\n20.Gestión Incidentes Seguridad|36h|21/09-08/10/2026|lun-jue 17-20h"
  ],
  'cloud' => [
    'keywords' => ['cloud','azure','aws','amazon','google cloud','nube','az-900','dp-900','cloud practitioner','gcp'],
    'data' => "-- CLOUD COMPUTING --\nItinerario EMPLEADOS (144h): 21.Cloud Computing e IA en la nube|36h|13/04-30/04/2026|lun-jue 17-20h\n22.Azure Fundamentals AZ-900|36h|11/05-28/05/2026|lun-jue 17-20h\n23.AWS Cloud Practitioner|36h|28/09-16/10/2026|lun-jue 17-20h\n24.Google Cloud Associate|36h|26/10-13/11/2026|lun-jue 17-20h"
  ],
  'software' => [
    'keywords' => ['javascript','python','programacion','programación','bases de datos','sql','desarrollo','software','código','dp-900'],
    'data' => "-- DESARROLLO DE SOFTWARE --\nItinerario DESEMPLEADOS (168h): 25.Javascript|36h|02/02-26/02/2026|lun-jue 17-20h\n26.Python básico|36h|09/03-26/03/2026|lun-jue 17-20h\n27.Bases de datos|36h|13/04-30/04/2026|lun-jue 17-20h\n28.Azure Data DP-900|24h|05/10-16/10/2026|lun-jue 17-20h\n29.IA para programadores|36h|26/10-13/11/2026|lun-jue 17-20h"
  ],
  'videojuegos' => [
    'keywords' => ['videojuego','unity','game','juego','concept art','vr','realidad virtual','animacion','animación','arte','illustrator'],
    'data' => "-- VIDEOJUEGOS --\nItinerario DESEMPLEADOS (168h): 30.Game Designer|36h|09/03-26/03/2026|lun-jue 17-20h\n31.Concept Art 2D/3D|24h|13/04-23/04/2026|lun-jue 17-20h\n32.Arte y animación con Unity|24h|04/05-14/05/2026|lun-jue 17-20h\n33.Unity Certified User|48h|05/10-30/10/2026|lun-jue 17-20h\n34.Desarrollador VR Unity|36h|09/11-26/11/2026|lun-jue 17-20h"
  ],
  'proyectos' => [
    'keywords' => ['proyecto','proyectos','scrum','pmi','agile','agil','gestión de proyecto'],
    'data' => "-- GESTIÓN DE PROYECTOS --\nItinerario EMPLEADOS (108h): 35.Gestión proyectos PMI Ready|48h|16/03-16/04/2026|lun-jue 17-20h\n36.SCRUM MASTER + Certificación|24h|04/05-14/05/2026|lun-jue 17-20h\n37.IA para gestión de proyectos|36h|28/09-16/10/2026|lun-jue 17-20h"
  ],
  'sistemas' => [
    'keywords' => ['windows server','linux','lpic','soporte','sistemas','it support','ccst it','servidor'],
    'data' => "-- SISTEMAS Y SOPORTE --\nItinerario DESEMPLEADOS (132h): 38.Soporte TIC CCST IT Support|36h|13/04-30/04/2026|lun-jue 17-20h\n39.Windows Server Hybrid Admin|48h|11/05-04/06/2026|lun-jue 17-20h\n40.Linux LPIC1|48h|19/10-13/11/2026|lun-jue 17-20h"
  ],
  'analitica' => [
    'keywords' => ['excel','power bi','bi','analisis de datos','análisis de datos','python avanzado','pandas','analitica','analítica','business intelligence'],
    'data' => "-- ANALÍTICA DE DATOS Y BI --\nItinerario EMPLEADOS Python (144h): 41.Python básico|36h|09/02-05/03/2026|lun-jue 17-20h\n42.Python avanzado|36h|09/03-26/03/2026|lun-jue 17-20h\n43.Análisis datos Python|36h|13/04-30/04/2026|lun-jue 17-20h\n44.Análisis datos avanzado Python|36h|11/05-28/05/2026|lun-jue 17-20h\nItinerario DESEMPLEADOS BI (144h): 45.Excel para análisis de datos|36h|14/09-01/10/2026|lun-jue 17-20h\n46.Excel Avanzado|36h|05/10-23/10/2026|lun-jue 17-20h\n47.Power BI intro|36h|26/10-13/11/2026|lun-jue 17-20h\n48.Power BI avanzado|36h|16/11-03/12/2026|lun-jue 17-20h"
  ],
  'agricultura' => [
    'keywords' => ['agricultura','dron','drone','sig','fotogrametria','fotogrametría','teledeteccion','teledetección','precision','precisión','sts','piloto'],
    'data' => "-- AGRICULTURA 4.0 Y DRÓNICA --\nItinerario EMPLEADOS ED1 (144h): 49.Agricultura Precisión+Teledetección ED1|24h|19/01-04/02/2026|lun-mié 16-20h\n50.SIG Agricultura ED1|24h|09/02-04/03/2026|lun-mié 16-20h\n51.Fotogrametría+sensorización ED1|32h|09/03-08/04/2026|lun-mié 16-20h\n52.Certif.piloto drones A1/A3+A2+STS ED1|64h|20/04-10/06/2026|lun-mié 16-20h SEMIPRESENCIAL\n53-55.Actualización STS Ed1-3|4h c/u|abr-may 2026|PRESENCIAL\nItinerario DESEMPLEADOS ED2: 56.Agricultura+Teledetección ED2|24h|20/01-05/02/2026|mar-jue 16-20h\n57.SIG ED2|24h|10/02-05/03/2026\n58.Fotogrametría ED2|32h|10/03-09/04/2026\n59.Certif.drones ED2|64h|21/04-11/06/2026 SEMIPRESENCIAL\n60-62.Actualización STS Ed4-6|sep-oct 2026 PRESENCIAL\nDocentes: 63-65.Teledetección+SIG+Fotogrametría|abr-jun 2026"
  ],
  'diseno' => [
    'keywords' => ['photoshop','illustrator','premiere','diseño','diseño grafico','diseño gráfico','video','vídeo','adobe','creadores'],
    'data' => "-- DISEÑO GRÁFICO --\nItinerario EMPLEADOS (144h): 66.Photoshop|36h|26/01-12/02/2026|lun-jue 17-20h\n67.Illustrator|36h|23/02-12/03/2026|lun-jue 17-20h\n68.Edición vídeo Premiere|36h|16/03-09/04/2026|lun-jue 17-20h\n69.IA para Diseño y Creadores|36h|20/04-07/05/2026|lun-jue 17-20h"
  ],
  'marketing' => [
    'keywords' => ['marketing','community manager','redes sociales','meta','instagram','facebook','social media','growth'],
    'data' => "-- MARKETING DIGITAL --\nItinerario DESEMPLEADOS (156h): 70.Community Manager|36h|23/02-12/03/2026|lun-jue 17-20h\n71.Marketing digital y redes sociales|36h|16/03-09/04/2026|lun-jue 17-20h\n72.IA en Marketing Digital|36h|20/04-07/05/2026|lun-jue 17-20h\n73.Meta Instagram/Facebook Certif.M100-101|48h|18/05-11/06/2026|lun-jue 17-20h"
  ],
];

// Detectar área relevante en la última pregunta del usuario e inyectar solo esos cursos
$last_user_msg = '';
foreach (array_reverse($clean_messages) as $m) {
    if ($m['role'] === 'user') { $last_user_msg = mb_strtolower($m['content']); break; }
}

$matched_areas = [];
// Si pregunta por todos los cursos o áreas generales, inyectar resumen de todas las áreas
$general_keywords = ['todos','todo','cursos disponibles','que cursos','qué cursos','lista','listado','áreas','areas','que hay','qué hay','oferta','disponibles','cuáles','cuales'];
$is_general = false;
foreach ($general_keywords as $gk) {
    if (strpos($last_user_msg, $gk) !== false) { $is_general = true; break; }
}

if ($is_general) {
    $summary = "-- RESUMEN ÁREAS Y Nº CURSOS 2026 --\n";
    $summary .= "IA: 9 cursos (empl+desempl) | Ciberseguridad/Redes: 11 cursos (empl+desempl) | Cloud: 4 cursos (empl) | Desarrollo Software: 5 cursos (desempl) | Videojuegos: 5 cursos (desempl) | Gestión Proyectos: 3 cursos (empl) | Sistemas/Soporte: 3 cursos (desempl) | Analítica Datos/BI: 8 cursos (empl+desempl) | Agricultura/Drones: 17 cursos (empl+desempl+docentes) | Diseño Gráfico: 4 cursos (empl) | Marketing Digital: 4 cursos (desempl)\n";
    $summary .= "Total: 73 acciones formativas. Todos ONLINE salvo algunos presenciales/semipresenciales en agricultura y drones.\n";
    $summary .= "Ver catálogo completo: https://formacionfeval.com/index.php/cursos-feval";
    $system_prompt .= "\n\n=== CURSOS DISPONIBLES ===\n" . $summary;
} else {
    foreach ($CATALOG as $area => $info) {
        foreach ($info['keywords'] as $kw) {
            if (strpos($last_user_msg, $kw) !== false) {
                $matched_areas[$area] = $info['data'];
                break;
            }
        }
    }
    if (!empty($matched_areas)) {
        $system_prompt .= "\n\n=== CURSOS RELACIONADOS CON LA PREGUNTA ===\n" . implode("\n\n", $matched_areas);
    }
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
