<?php
/**
 * FEVAL Auto-Deploy Webhook
 * =========================
 * GitHub llama a este script automáticamente en cada push.
 * El script descarga los archivos actualizados desde GitHub.
 *
 * INSTALACIÓN:
 * 1. Sube este archivo al servidor: /var/www/html/formacion/feval-deploy.php
 * 2. En GitHub → Settings → Webhooks → Add webhook:
 *    - Payload URL: https://formacionfeval.com/feval-deploy.php
 *    - Content type: application/json
 *    - Secret: (el mismo valor que $SECRET abajo)
 *    - Events: Just the push event
 * 3. Cambia $SECRET por una cadena segura de tu elección
 */

// ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────
$SECRET = 'CAMBIA_ESTO_POR_UNA_CLAVE_SECRETA'; // ← ponle cualquier contraseña larga
$BRANCH = 'claude/ai-chatbox-joomla-876Tk';     // rama que dispara el deploy
$REPO   = 'paco4444444444444444444444/CHATBOX';  // usuario/repositorio de GitHub

$FILES  = [
    'feval-chatbot.js'  => __DIR__ . '/feval-chatbot.js',
    'ollama-proxy.php'  => __DIR__ . '/ollama-proxy.php',
];
// ─── FIN CONFIGURACIÓN ────────────────────────────────────────────────────────

header('Content-Type: application/json');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Verificar firma de GitHub
$expected = 'sha256=' . hash_hmac('sha256', $payload, $SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Comprobar que el push es de la rama correcta
$pushed_branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
if ($pushed_branch !== $BRANCH) {
    echo json_encode(['skipped' => true, 'reason' => 'Branch ' . $pushed_branch . ' ignored']);
    exit;
}

// Descargar cada archivo desde GitHub raw
$results = [];
foreach ($FILES as $filename => $dest_path) {
    $url = "https://raw.githubusercontent.com/{$REPO}/{$BRANCH}/{$filename}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $content  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200 || empty($content)) {
        $results[$filename] = 'ERROR: ' . ($error ?: "HTTP $httpCode");
        continue;
    }

    if (file_put_contents($dest_path, $content) === false) {
        $results[$filename] = 'ERROR: no se pudo escribir el archivo (permisos?)';
        continue;
    }

    $results[$filename] = 'OK';
}

$all_ok = !in_array(false, array_map(fn($r) => str_starts_with($r, 'OK'), $results), true);

http_response_code($all_ok ? 200 : 500);
echo json_encode([
    'deployed' => true,
    'branch'   => $pushed_branch,
    'files'    => $results,
    'time'     => date('Y-m-d H:i:s'),
]);
