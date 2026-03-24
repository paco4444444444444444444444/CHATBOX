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

// ── POST /api.php?action=discover_site ────────────────────────────────────
if ($action === 'discover_site') {
    set_time_limit(120);
    $raw = json_decode(file_get_contents('php://input'), true);
    $url = trim($raw['url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) api_err('Invalid URL');

    // Helper closure: extract same-domain links from HTML
    $extract_links = function(string $html, string $base, string $host): array {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $skip = '/\.(jpg|jpeg|png|gif|pdf|zip|doc|xls|css|js|xml|ico|svg|woff|ttf|mp4|mp3|webp)(\?|$)/i';
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (!$href) continue;
            $h0 = $href[0] ?? '';
            if ($h0 === '#') continue;
            if (strpos($href,'mailto:')===0 || strpos($href,'tel:')===0) continue;
            if ($h0 === '/') $href = $base . $href;
            elseif (strpos($href,'http') !== 0) continue;
            $href = strtok($href,'#');
            $hp = parse_url($href);
            if (($hp['host'] ?? '') !== $host) continue;
            if (preg_match($skip, $href)) continue;
            $text = trim($a->textContent);
            if (!$text) $text = basename($hp['path'] ?? '') ?: $href;
            $links[$href] = mb_substr($text, 0, 80);
        }
        $tl = $dom->getElementsByTagName('title');
        $title = $tl->length ? trim($tl->item(0)->textContent) : '';
        return ['links' => $links, 'title' => $title];
    };

    // Fetch root
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'ChatbaseBot/1.0',CURLOPT_SSL_VERIFYPEER=>false]);
    $html      = curl_exec($ch);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if (!$html) api_err('Could not fetch URL');

    $parsed = parse_url($final_url);
    $base   = $parsed['scheme'] . '://' . $parsed['host'];
    $host   = $parsed['host'];

    $r0      = $extract_links($html, $base, $host);
    $seen    = [$final_url => true];
    $pages   = [['url' => $final_url, 'text' => $r0['title'] ?: 'Página principal']];

    // Level-1 links
    $l1_urls = [];
    foreach ($r0['links'] as $href => $text) {
        if (!isset($seen[$href])) {
            $seen[$href] = true;
            $pages[]  = ['url' => $href, 'text' => $text];
            $l1_urls[] = $href;
        }
        if (count($l1_urls) >= 80) break;
    }

    // Level-2: fetch all level-1 pages in parallel
    if (!empty($l1_urls)) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($l1_urls as $i => $u) {
            $c = curl_init($u);
            curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>7,CURLOPT_USERAGENT=>'ChatbaseBot/1.0',CURLOPT_SSL_VERIFYPEER=>false]);
            curl_multi_add_handle($mh,$c);
            $handles[$i] = $c;
        }
        do { curl_multi_exec($mh,$running); curl_multi_select($mh,1); } while ($running>0);
        foreach ($handles as $c) {
            $body = curl_multi_getcontent($c);
            curl_multi_remove_handle($mh,$c); curl_close($c);
            if (!$body) continue;
            $r = $extract_links($body, $base, $host);
            foreach ($r['links'] as $href => $text) {
                if (!isset($seen[$href]) && count($pages) < 300) {
                    $seen[$href] = true;
                    $pages[] = ['url' => $href, 'text' => $text];
                }
            }
        }
        curl_multi_close($mh);
    }

    echo json_encode(['ok' => true, 'pages' => $pages], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST /api.php?action=crawl_pages ──────────────────────────────────────
if ($action === 'crawl_pages') {
    $raw    = json_decode(file_get_contents('php://input'), true);
    $bot_id = $raw['bot_id'] ?? '';
    $urls   = $raw['urls']   ?? [];
    if (!$bot_id || !$urls) api_err('bot_id and urls required');
    if (count($urls) > 40)  api_err('Max 40 pages per crawl');

    $added  = 0;
    $failed = 0;

    $stmt = cb_db()->prepare("INSERT INTO sources (bot_id,type,name,content,chars) VALUES (?,?,?,?,?)");

    foreach ($urls as $item) {
        $page_url  = $item['url']  ?? '';
        $page_name = trim($item['name'] ?? '') ?: $page_url;
        if (!filter_var($page_url, FILTER_VALIDATE_URL)) { $failed++; continue; }

        $ch = curl_init($page_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => 'ChatbaseBot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) { $failed++; continue; }

        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        foreach (['script','style','nav','footer','header','noscript'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $content = trim(preg_replace('/\s{3,}/', "\n\n", $dom->textContent));
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$content) { $failed++; continue; }

        $stmt->execute([$bot_id, 'url', mb_substr($page_name, 0, 120), $content, mb_strlen($content)]);
        $added++;
    }

    echo json_encode(['ok' => true, 'added' => $added, 'failed' => $failed], JSON_UNESCAPED_UNICODE);
    exit;
}

api_err('Unknown action');
