<?php
/**
 * test-scraper.php – Diagnóstico del scraper de cursos
 * Accede a: https://TU-URL/test-scraper.php
 * Borralo cuando hayas verificado que todo funciona.
 */

// Seguridad básica: solo desde localhost o con clave
$key = $_GET['key'] ?? '';
if ($key !== 'feval2026' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
    http_response_code(403); die('Acceso denegado. Usa ?key=feval2026');
}

header('Content-Type: text/plain; charset=utf-8');

// ─── curlGet ────────────────────────────────────────────────────────────────
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
    $err  = curl_error($ch);
    curl_close($ch);
    echo "  [curl] {$url} → HTTP {$code}" . ($err ? " ERROR: {$err}" : '') . "\n";
    return ($body && $code === 200) ? $body : null;
}

echo "=== TEST SCRAPER FEVAL ===\n\n";

// PASO 1: Página principal de cursos
echo "PASO 1: Cargando página principal...\n";
$mainHtml = curlGet('http://localhost/index.php/cursos-feval');
if (!$mainHtml) {
    die("ERROR: No se pudo cargar la página principal.\n");
}
echo "  Tamaño: " . strlen($mainHtml) . " bytes\n\n";

// PASO 2: Buscar links de categorías con varios selectores
echo "PASO 2: Buscando links de categorías...\n";

// Selector actual
preg_match_all(
    '/<a\s[^>]*class="[^"]*eb-category-title-link[^"]*"[^>]*href="([^"]+)"|<a\s[^>]*href="([^"]+)"[^>]*class="[^"]*eb-category-title-link[^"]*"/i',
    $mainHtml, $m
);
$catPaths = array_unique(array_filter(array_merge($m[1] ?? [], $m[2] ?? [])));
echo "  Selector eb-category-title-link: " . count($catPaths) . " encontrados\n";

// Fallback: buscar cualquier link que contenga "cursos" y "area-de"
if (empty($catPaths)) {
    preg_match_all('/href="([^"]*(?:cursos|area)[^"]*)"/i', $mainHtml, $m2);
    $catPaths = array_unique(array_filter($m2[1] ?? [], function($p) {
        return strpos($p, 'cursos') !== false && strpos($p, 'index.php') !== false;
    }));
    echo "  Fallback (href con 'cursos'): " . count($catPaths) . " encontrados\n";
}

// Buscar con DOMXPath también
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $mainHtml);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$nodes = $xpath->query('//a[contains(@class,"eb-category")]');
echo "  XPath eb-category (cualquier clase): " . $nodes->length . "\n";

$nodes2 = $xpath->query('//a[contains(@href,"cursos")]');
echo "  XPath href con 'cursos': " . $nodes2->length . "\n";
foreach ($nodes2 as $n) {
    echo "    → " . $n->getAttribute('href') . "\n";
}

if (empty($catPaths)) {
    echo "\n  !! Ningún selector funcionó. Mostrando primeros 2000 chars del HTML:\n";
    echo substr(strip_tags($mainHtml), 0, 2000) . "\n";
    die();
}

echo "\n  Links de categorías encontrados:\n";
foreach ($catPaths as $p) echo "    → {$p}\n";

// PASO 3: Primera categoría
echo "\nPASO 3: Cargando primera categoría...\n";
$firstPath = reset($catPaths);
$catHtml = curlGet('http://localhost' . $firstPath);
if (!$catHtml) die("ERROR: No se pudo cargar la categoría {$firstPath}\n");
echo "  Tamaño: " . strlen($catHtml) . " bytes\n";

// Cursos en esa categoría
preg_match_all('/<a[^>]+class="[^"]*eb-event-link[^"]*"[^>]*>(.*?)<\/a>/is', $catHtml, $em);
echo "  Cursos encontrados (eb-event-link): " . count($em[1]) . "\n";
foreach (array_slice($em[1], 0, 5) as $t) {
    echo "    · " . trim(strip_tags($t)) . "\n";
}

// Fechas
preg_match_all('/data-content="Inicio"[^>]*>(.*?)<\/td>/is', $catHtml, $dm);
echo "  Fechas encontradas (data-content=Inicio): " . count($dm[1]) . "\n";
foreach (array_slice($dm[1], 0, 5) as $d) {
    echo "    · " . trim(strip_tags($d)) . "\n";
}

echo "\n=== FIN DEL TEST ===\n";
