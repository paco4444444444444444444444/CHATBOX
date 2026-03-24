<?php
// chatbase/api.php — Bot config + source ingestion API
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function api_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? '';

// ── GET /api.php?action=config&bot=ID ─────────────────────────────────────
if ($action === 'config') {
    $id = $_GET['bot'] ?? '';
    if (!$id) api_err('bot required');
    $s = cb_db()->prepare("SELECT name, welcome, placeholder, color FROM bots WHERE id=?");
    $s->execute([$id]);
    $bot = $s->fetch();
    if (!$bot) api_err('Bot not found', 404);
    echo json_encode($bot, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST actions (require form data or JSON) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('Method not allowed', 405);

// ── POST /api.php?action=delete_source ────────────────────────────────────
if ($action === 'delete_source') {
    $raw = json_decode(file_get_contents('php://input'), true);
    $sid = (int)($raw['id'] ?? 0);
    if (!$sid) api_err('id required');
    cb_db()->prepare("DELETE FROM sources WHERE id=?")->execute([$sid]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST /api.php?action=add_source ───────────────────────────────────────
if ($action === 'add_source') {
    $bot_id = $_POST['bot_id'] ?? '';
    $type   = $_POST['type']   ?? '';
    $name   = trim($_POST['name'] ?? '');

    if (!$bot_id || !$type || !$name) api_err('bot_id, type and name required');

    $content = '';

    if ($type === 'text' || $type === 'faq') {
        $content = trim($_POST['content'] ?? '');
        if (!$content) api_err('content required');
    }

    if ($type === 'url') {
        $url = trim($_POST['url'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) api_err('Invalid URL');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'ChatbaseBot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) api_err('Could not fetch URL');

        // Extract readable text from HTML
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        // Remove scripts and styles
        foreach (['script', 'style', 'nav', 'footer', 'header', 'noscript'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $content = trim(preg_replace('/\s{3,}/', "\n\n", $dom->textContent));
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$content) api_err('Could not extract text from URL');
    }

    if ($type === 'pdf') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            api_err('PDF file upload failed');
        }
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        if ($mime !== 'application/pdf') api_err('File must be a PDF');

        $tmp = $_FILES['file']['tmp_name'];
        $out = shell_exec('pdftotext -q ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        if ($out === null || trim($out) === '') {
            // Fallback: store a note that binary content was uploaded
            api_err('pdftotext not available on this server. Install poppler-utils.');
        }
        $content = trim($out);
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$name || $name === 'Nuevo PDF') $name = $_FILES['file']['name'];
    }

    $chars = mb_strlen($content);
    cb_db()->prepare("INSERT INTO sources (bot_id,type,name,content,chars) VALUES (?,?,?,?,?)")
        ->execute([$bot_id, $type, $name, $content, $chars]);

    echo json_encode(['ok' => true, 'chars' => $chars], JSON_UNESCAPED_UNICODE);
    exit;
}

api_err('Unknown action');
