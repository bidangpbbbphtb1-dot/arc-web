<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
function clip($v, $max) {
    if (!is_string($v)) return '';
    $v = trim($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function http_url($v) {
    $v = clip($v, 2000);
    $p = parse_url($v);
    if ($p && !empty($p['host']) && isset($p['scheme']) && in_array(strtolower($p['scheme']), ['http', 'https'], true)) return $v;
    return null;
}
function safe_id($v, $prefix) {
    return (is_string($v) && preg_match('/^[\w\-]{1,60}$/', $v)) ? $v : $prefix . uniqid();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Metode tidak diizinkan');
if (empty($_SESSION['is_admin'])) fail(401, 'Belum login admin');
session_write_close();

$raw = file_get_contents('php://input', false, null, 0, 200001);
if ($raw === false || $raw === '') fail(400, 'Tidak ada data yang dikirim');
if (strlen($raw) > 200000) fail(413, 'Data terlalu besar');

$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['userLinks']) || !is_array($in['userLinks'])) fail(400, 'Format data tidak valid');

/* Tautan */
$links = [];
foreach (array_slice($in['userLinks'], 0, 100) as $l) {
    if (!is_array($l)) continue;
    $title = clip(isset($l['title']) ? $l['title'] : '', 40);
    $url   = http_url(isset($l['url']) ? $l['url'] : '');
    if ($title === '' || $url === null) continue;
    $icon = (isset($l['icon']) && is_string($l['icon']) && preg_match('/^[a-z0-9\- ]{2,60}$/i', $l['icon'])) ? $l['icon'] : 'fas fa-link';
    $links[] = [
        'id'    => safe_id(isset($l['id']) ? $l['id'] : null, 'card_'),
        'title' => $title,
        'desc'  => clip(isset($l['desc']) ? $l['desc'] : '', 60),
        'url'   => $url,
        'icon'  => $icon,
    ];
}

/* Playlist YouTube */
$playlist = [];
$rawList  = (isset($in['playlist']) && is_array($in['playlist'])) ? $in['playlist'] : [];
foreach (array_slice($rawList, 0, 50) as $t) {
    if (!is_array($t) || !isset($t['vid']) || !is_string($t['vid']) || !preg_match('/^[\w\-]{11}$/', $t['vid'])) continue;
    $playlist[] = [
        'id'    => safe_id(isset($t['id']) ? $t['id'] : null, 'yt_'),
        'vid'   => $t['vid'],
        'title' => clip(isset($t['title']) ? $t['title'] : '', 80),
    ];
}

$vol = isset($in['ytVolume']) && is_numeric($in['ytVolume']) ? (int) round($in['ytVolume']) : 50;
$vol = max(0, min(100, $vol));

$json = json_encode(['userLinks' => $links, 'playlist' => $playlist, 'ytVolume' => $vol],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if (file_put_contents(__DIR__ . '/dashboard_data.json', $json, LOCK_EX) === false) fail(500, 'Gagal menulis file di server');
echo json_encode(['status' => 'success', 'message' => 'Data berhasil disimpan di server']);