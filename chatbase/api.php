<?php
// chatbase/api.php — Bot config + source ingestion API
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ob_start();

require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(204); exit; }

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Server error: ' . $err['message']));
    } else {
        ob_end_flush();
    }
});

function api_err($msg, $code = 400) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $msg));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ── GET /api.php?action=config&bot=ID ─────────────────────────────────────
if ($action === 'config') {
    $id = isset($_GET['bot']) ? $_GET['bot'] : '';
    if (!$id) api_err('bot required');
    $s = cb_db()->prepare("SELECT name, welcome, placeholder, color FROM bots WHERE id=?");
    $s->execute(array($id));
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
    $sid = (int)(isset($raw['id']) ? $raw['id'] : 0);
    if (!$sid) api_err('id required');
    cb_db()->prepare("DELETE FROM sources WHERE id=?")->execute(array($sid));
    echo json_encode(array('ok' => true));
    exit;
}

// ── POST /api.php?action=add_source ───────────────────────────────────────
if ($action === 'add_source') {
    $bot_id = isset($_POST['bot_id']) ? $_POST['bot_id'] : '';
    $type   = isset($_POST['type'])   ? $_POST['type']   : '';
    $name   = trim(isset($_POST['name']) ? $_POST['name'] : '');

    if (!$bot_id || !$type || !$name) api_err('bot_id, type and name required');

    $content = '';

    if ($type === 'text' || $type === 'faq') {
        $content = trim(isset($_POST['content']) ? $_POST['content'] : '');
        if (!$content) api_err('content required');
    }

    if ($type === 'url') {
        $url = trim(isset($_POST['url']) ? $_POST['url'] : '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) api_err('Invalid URL');
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'ChatbaseBot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) api_err('Could not fetch URL');

        // Extract readable text from HTML
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        // Remove scripts and styles
        foreach (array('script', 'style', 'nav', 'footer', 'header', 'noscript') as $tag) {
            $nodes = array();
            foreach ($dom->getElementsByTagName($tag) as $node) { $nodes[] = $node; }
            foreach ($nodes as $node) { if ($node->parentNode) $node->parentNode->removeChild($node); }
        }
        $content = trim(preg_replace('/\s{3,}/', "\n\n", $dom->textContent));
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$content) api_err('No se pudo extraer texto de la URL');
    }

    if ($type === 'pdf') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            api_err('PDF file upload failed');
        }
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        if ($mime !== 'application/pdf') api_err('File must be a PDF');

        $tmp = $_FILES['file']['tmp_name'];

        // Try pdftotext first
        $out = shell_exec('pdftotext -q ' . escapeshellarg($tmp) . ' - 2>/dev/null');

        // Fallback: pure-PHP PDF text extractor
        if ($out === null || trim($out) === '') {
            $raw = file_get_contents($tmp);
            $out = '';
            // Extract text from PDF content streams
            if (preg_match_all('/BT[\s\S]*?ET/', $raw, $blocks)) {
                foreach ($blocks[0] as $block) {
                    // Tj and TJ operators
                    preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/', $block, $m1);
                    foreach ($m1[1] as $t) {
                        $out .= stripcslashes($t) . ' ';
                    }
                    preg_match_all('/\[([^\]]*)\]\s*TJ/', $block, $m2);
                    foreach ($m2[1] as $t) {
                        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $t, $m3);
                        foreach ($m3[1] as $w) {
                            $out .= stripcslashes($w);
                        }
                        $out .= ' ';
                    }
                }
            }
            // Also extract plain text fragments
            preg_match_all('/\(([^\x00-\x08\x0e-\x1f)\\\\]{4,})\)/', $raw, $mraw);
            foreach ($mraw[1] as $t) {
                $decoded = stripcslashes($t);
                if (preg_match('/[\p{L}\p{N}]{3,}/u', $decoded)) {
                    $out .= ' ' . $decoded;
                }
            }
            $out = trim(preg_replace('/\s{2,}/', ' ', $out));
        }

        if (trim($out) === '') api_err('No se pudo extraer texto del PDF. Comprueba que no sea un PDF escaneado (imagen).');
        $content = trim($out);
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$name || $name === 'Nuevo PDF') $name = $_FILES['file']['name'];
    }

    $chars = mb_strlen($content);
    cb_db()->prepare("INSERT INTO sources (bot_id,type,name,content,chars) VALUES (?,?,?,?,?)")
        ->execute(array($bot_id, $type, $name, $content, $chars));

    echo json_encode(array('ok' => true, 'chars' => $chars), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST /api.php?action=discover_site ────────────────────────────────────
if ($action === 'discover_site') {
    set_time_limit(120);
    $raw = json_decode(file_get_contents('php://input'), true);
    $url = trim(isset($raw['url']) ? $raw['url'] : '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) api_err('Invalid URL');

    // Helper closure: extract same-domain links from HTML
    $extract_links = function($html, $base, $host) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $skip = '/\.(jpg|jpeg|png|gif|pdf|zip|doc|xls|css|js|xml|ico|svg|woff|ttf|mp4|mp3|webp)(\?|$)/i';
        $links = array();
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if (!$href) continue;
            $h0 = strlen($href) ? $href[0] : '';
            if ($h0 === '#') continue;
            if (strpos($href,'mailto:')===0 || strpos($href,'tel:')===0) continue;
            if ($h0 === '/') $href = $base . $href;
            elseif (strpos($href,'http') !== 0) continue;
            $href = strtok($href,'#');
            $hp = parse_url($href);
            if ((isset($hp['host']) ? $hp['host'] : '') !== $host) continue;
            if (preg_match($skip, $href)) continue;
            $text = trim($a->textContent);
            $hp_path = isset($hp['path']) ? $hp['path'] : '';
            if (!$text) $text = basename($hp_path) ? basename($hp_path) : $href;
            $links[$href] = mb_substr($text, 0, 80);
        }
        $tl = $dom->getElementsByTagName('title');
        $title = $tl->length ? trim($tl->item(0)->textContent) : '';
        return array('links' => $links, 'title' => $title);
    };

    // Fetch root
    $ch = curl_init($url);
    curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'ChatbaseBot/1.0',CURLOPT_SSL_VERIFYPEER=>false));
    $html      = curl_exec($ch);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if (!$html) api_err('Could not fetch URL');

    $parsed = parse_url($final_url);
    $base   = $parsed['scheme'] . '://' . $parsed['host'];
    $host   = $parsed['host'];

    $r0      = $extract_links($html, $base, $host);
    $seen    = array($final_url => true);
    $pages   = array(array('url' => $final_url, 'text' => $r0['title'] ? $r0['title'] : 'Pagina principal'));

    // Level-1 links
    $l1_urls = array();
    foreach ($r0['links'] as $href => $text) {
        if (!isset($seen[$href])) {
            $seen[$href] = true;
            $pages[]  = array('url' => $href, 'text' => $text);
            $l1_urls[] = $href;
        }
        if (count($l1_urls) >= 80) break;
    }

    // Helper: fetch a batch of URLs in parallel and return html results
    $fetch_batch = function($urls) {
        $mh = curl_multi_init();
        $handles = array();
        foreach ($urls as $i => $u) {
            $c = curl_init($u);
            curl_setopt_array($c,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>7,CURLOPT_USERAGENT=>'ChatbaseBot/1.0',CURLOPT_SSL_VERIFYPEER=>false));
            curl_multi_add_handle($mh,$c);
            $handles[$i] = array('ch'=>$c,'url'=>$u);
        }
        do { curl_multi_exec($mh,$running); curl_multi_select($mh,1); } while ($running>0);
        $results = array();
        foreach ($handles as $i => $h) {
            $results[$h['url']] = curl_multi_getcontent($h['ch']);
            curl_multi_remove_handle($mh,$h['ch']); curl_close($h['ch']);
        }
        curl_multi_close($mh);
        return $results;
    };

    // Level-2: fetch level-1 pages in parallel, collect new links
    $l2_urls = array();
    if (!empty($l1_urls)) {
        $bodies = $fetch_batch($l1_urls);
        foreach ($bodies as $body) {
            if (!$body) continue;
            $r = $extract_links($body, $base, $host);
            foreach ($r['links'] as $href => $text) {
                if (!isset($seen[$href]) && count($pages) < 400) {
                    $seen[$href] = true;
                    $pages[]  = array('url' => $href, 'text' => $text);
                    $l2_urls[] = $href;
                }
            }
        }
    }

    // Level-3: fetch level-2 pages in parallel to discover course-level pages
    if (!empty($l2_urls)) {
        foreach (array_chunk($l2_urls, 30) as $chunk) {
            $bodies = $fetch_batch($chunk);
            foreach ($bodies as $body) {
                if (!$body) continue;
                $r = $extract_links($body, $base, $host);
                foreach ($r['links'] as $href => $text) {
                    if (!isset($seen[$href]) && count($pages) < 400) {
                        $seen[$href] = true;
                        $pages[] = array('url' => $href, 'text' => $text);
                    }
                }
            }
        }
    }

    echo json_encode(array('ok' => true, 'pages' => $pages), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST /api.php?action=crawl_pages ──────────────────────────────────────
if ($action === 'crawl_pages') {
    $raw    = json_decode(file_get_contents('php://input'), true);
    $bot_id = isset($raw['bot_id']) ? $raw['bot_id'] : '';
    $urls   = isset($raw['urls'])   ? $raw['urls']   : array();
    if (!$bot_id || !$urls) api_err('bot_id and urls required');
    if (count($urls) > 40)  api_err('Max 40 pages per crawl');

    $added  = 0;
    $failed = 0;

    $stmt = cb_db()->prepare("INSERT INTO sources (bot_id,type,name,content,chars) VALUES (?,?,?,?,?)");

    foreach ($urls as $item) {
        $page_url  = isset($item['url'])  ? $item['url']  : '';
        $page_name_raw = trim(isset($item['name']) ? $item['name'] : '');
        $page_name = $page_name_raw ? $page_name_raw : $page_url;
        if (!filter_var($page_url, FILTER_VALIDATE_URL)) { $failed++; continue; }

        $ch = curl_init($page_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_USERAGENT      => 'ChatbaseBot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) { $failed++; continue; }

        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        foreach (array('script','style','nav','footer','header','noscript') as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));
            foreach ($nodes as $node) { if ($node->parentNode) $node->parentNode->removeChild($node); }
        }
        $content = trim(preg_replace('/\s{3,}/', "\n\n", $dom->textContent));
        if (mb_strlen($content) > 80000) $content = mb_substr($content, 0, 80000);
        if (!$content) { $failed++; continue; }

        $stmt->execute(array($bot_id, 'url', mb_substr($page_name, 0, 120), $content, mb_strlen($content)));
        $added++;
    }

    echo json_encode(array('ok' => true, 'added' => $added, 'failed' => $failed), JSON_UNESCAPED_UNICODE);
    exit;
}

api_err('Unknown action');
