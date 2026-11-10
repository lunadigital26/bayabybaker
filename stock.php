<?php
// Baya By Baker: reads live stock and prices from the owner's Google Sheet ("Item names").
// The sheet must be shared as "Anyone with the link: Viewer". Results are cached for 2 minutes.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const SHEET_ID = '1-1-ePEVE7hI7xp7_qg5AOZvxdKXditDx_DzLtla8Ato';
const CACHE_SECONDS = 120;

$cache = sys_get_temp_dir() . '/bbb_stock_' . md5(SHEET_ID) . '.json';
if (is_file($cache) && (time() - filemtime($cache)) < CACHE_SECONDS) { readfile($cache); exit; }

function fetch_csv($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'BayaByBaker-Stock/1.0']);
        $body = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return ($code === 200 && $body) ? $body : null;
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
    return $body ?: null;
}

$rows = [];
// Preferred: the private Apps Script (lets the sheet be private and gives "In stock now" after website sales).
$hookFile = __DIR__ . '/.sheets-hook.php';
if (is_file($hookFile)) {
    $hc = include $hookFile;
    if (is_array($hc) && !empty($hc['url'])) {
        $j = json_decode((string)fetch_csv($hc['url'] . '?t=' . rawurlencode($hc['token'])), true);
        if (is_array($j) && !empty($j['ok']) && is_array($j['rows'] ?? null)) {
            foreach ($j['rows'] as $r) $rows[] = ['name' => mb_substr((string)($r['name'] ?? ''), 0, 80), 'price' => isset($r['price']) && is_numeric($r['price']) ? (float)$r['price'] : null, 'stock' => isset($r['stock']) && is_numeric($r['stock']) ? (int)$r['stock'] : null];
        }
    }
}
$csv = $rows ? null : fetch_csv('https://docs.google.com/spreadsheets/d/' . SHEET_ID . '/gviz/tq?tqx=out:csv');
if ($csv && stripos($csv, '<html') === false) {
    $lines = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));
    $hi = -1; $ni = $pi = $si = -1;
    foreach ($lines as $i => $cells) {
        foreach ($cells as $j => $v) {
            $v = strtolower(trim($v));
            if ($v === 'name' || $v === 'item' || $v === 'item name') { $hi = $i; $ni = $j; }
        }
        if ($hi === $i) {
            foreach ($cells as $j => $v) {
                $v = strtolower(trim($v));
                if (strpos($v, 'price') !== false) $pi = $j;
                if ((strpos($v, 'stock') !== false || strpos($v, 'qty') !== false || strpos($v, 'quantity') !== false) && ($si < 0 || strpos($v, 'now') !== false)) $si = $j;
            }
            break;
        }
    }
    if ($hi >= 0) {
        for ($i = $hi + 1; $i < count($lines); $i++) {
            $c = $lines[$i];
            $name = trim($c[$ni] ?? '');
            if ($name === '') continue;
            $price = $pi >= 0 ? preg_replace('/[^\d.]/', '', $c[$pi] ?? '') : '';
            $stock = $si >= 0 ? preg_replace('/[^\d]/', '', $c[$si] ?? '') : '';
            $rows[] = ['name' => mb_substr($name, 0, 80), 'price' => $price === '' ? null : (float)$price, 'stock' => $stock === '' ? null : (int)$stock];
        }
    }
}

if ($rows) {
    $out = json_encode(['ok' => true, 'updated' => gmdate('c'), 'rows' => $rows]);
    @file_put_contents($cache, $out);
    echo $out;
} elseif (is_file($cache)) {
    readfile($cache); // Google unreachable: keep serving the last good copy
} else {
    echo json_encode(['ok' => false, 'message' => 'Sheet not readable. Share it as "Anyone with the link: Viewer".']);
}
