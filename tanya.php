<?php
/* tanya.php (jangan dinamai chat.php: InfinityFree memblokir kata "chat" di URL).
   Jembatan antara obrolan di index.html dan Gemini (Google), untuk mode terang maupun gelap.
   Kunci API disimpan di gemini_config.php (di server), TIDAK pernah dikirim ke browser. */
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($code, $status, $msg, $extra = []) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode(array_merge(['status' => $status, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, 'error', 'Metode tidak diizinkan');

/* Hanya boleh dipanggil dari web sendiri */
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '') {
    $oh = (string) parse_url($origin, PHP_URL_HOST);
    $hh = preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    if (strcasecmp($oh, $hh) !== 0) out(403, 'error', 'Asal permintaan ditolak');
}

/* Konfigurasi */
$cfgFile = __DIR__ . '/gemini_config.php';
$cfg = is_file($cfgFile) ? @include $cfgFile : [];
$cfg = is_array($cfg) ? $cfg : [];
function cfg_key($cfg, $name, $env) {
    $v = isset($cfg[$name]) ? trim((string) $cfg[$name]) : '';
    if ($v === '') $v = trim((string) getenv($env));
    return (strpos($v, 'MASUKKAN_') === 0) ? '' : $v;
}

/* Opsional: hanya admin yang boleh mengobrol (melindungi kuota) */
if (!empty($cfg['admin_only'])) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    $ok = !empty($_SESSION['is_admin']);
    session_write_close();
    if (!$ok) out(401, 'error', 'Obrolan hanya untuk admin. Masuk dulu lewat menu.');
}

/* Batas laju: 12 pesan per menit per IP */
$ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
$rlf = sys_get_temp_dir() . '/arc_chat_' . md5($ip) . '.json';
$now = time();
$hits = is_file($rlf) ? json_decode((string) @file_get_contents($rlf), true) : [];
$hits = is_array($hits) ? array_values(array_filter($hits, function ($t) use ($now) { return is_int($t) && $now - $t < 60; })) : [];
if (count($hits) >= 12) out(429, 'error', 'Terlalu cepat. Tunggu sebentar lalu coba lagi.');
$hits[] = $now;
@file_put_contents($rlf, json_encode($hits), LOCK_EX);

/* Batas harian untuk seluruh pengunjung (daily_limit di gemini_config.php, 0 = tanpa batas) */
$dayLimit = isset($cfg['daily_limit']) ? max(0, (int) $cfg['daily_limit']) : 300;
if ($dayLimit > 0) {
    $df = sys_get_temp_dir() . '/arc_chat_day_' . date('Ymd') . '.txt';
    $used = is_file($df) ? (int) @file_get_contents($df) : 0;
    if ($used >= $dayLimit) out(429, 'error', 'Batas obrolan hari ini sudah tercapai. Coba lagi besok.');
    @file_put_contents($df, (string) ($used + 1), LOCK_EX);
}

/* Validasi pesan */
$raw = file_get_contents('php://input', false, null, 0, 100001);
if ($raw === false || $raw === '' || strlen($raw) > 100000) out(400, 'error', 'Data tidak valid atau terlalu besar');
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['messages']) || !is_array($in['messages'])) out(400, 'error', 'Format pesan tidak valid');

$brief = !empty($in['brief']);   // true = jawaban pendek untuk balon ucapan penyihir

$msgs = [];   // [['role' => 'user'|'model', 'text' => ...]]
foreach (array_slice($in['messages'], -16) as $m) {
    if (!is_array($m) || !isset($m['role'], $m['text']) || !is_string($m['text'])) continue;
    $role = $m['role'] === 'model' ? 'model' : 'user';
    $text = trim($m['text']);
    if ($text === '') continue;
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 2000) : substr($text, 0, 2000);
    if (!$msgs && $role !== 'user') continue;                      // harus mulai dari pesan pengguna
    $msgs[] = ['role' => $role, 'text' => $text];
}
if (!$msgs || end($msgs)['role'] !== 'user') out(400, 'error', 'Tidak ada pesan untuk dijawab');

$basePersona = "Kamu adalah ARC, penyihir kristal bijak penjaga portal tautan di web ARC. "
    . "Gaya bicaramu hangat, anggun, dan sedikit magis, tetapi jawabanmu tetap jelas, akurat, dan berguna. "
    . "Jawab dalam bahasa yang dipakai pengguna (umumnya Bahasa Indonesia). Singkat dan padat kecuali pengguna meminta detail. "
    . "Jangan mengarang fakta; bila tidak tahu, katakan terus terang. ";

function http_json($url, $headers, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $err];
}
function net_fail($err) {
    global $cfg;
    error_log('ARC chat curl: ' . $err);
    $why = !empty($cfg['debug']) ? ' Rincian: ' . $err : '';
    out(502, 'error', 'Server tidak bisa menghubungi layanan AI.' . $why);
}
function api_detail($data) {
    return is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : '';
}

/* ====================== GEMINI ====================== */
$key = cfg_key($cfg, 'api_key', 'GEMINI_API_KEY');
if ($key === '') out(500, 'error', 'Kunci API Gemini belum dipasang. Isi api_key di gemini_config.php.');
$model = !empty($cfg['model']) ? (string) $cfg['model'] : 'gemini-3.1-flash-lite';

$contents = [];
foreach ($msgs as $m) $contents[] = ['role' => $m['role'], 'parts' => [['text' => $m['text']]]];
$payload = [
    'system_instruction' => ['parts' => [['text' => $basePersona . 'Kamu ditenagai oleh Gemini, model AI buatan Google. Jika ditanya, akui hal itu dengan jujur.'
        . ($brief ? ' PENTING: jawabanmu akan ditampilkan di balon ucapan kecil, jadi jawab sangat ringkas: maksimal 3 kalimat pendek (di bawah 280 karakter). Tulis sebagai ucapan lisan, tanpa markdown, tanpa daftar, tanpa emoji.' : '')]]],
    'contents' => $contents,
    'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => $brief ? 1024 : 2048],
];

/* Coba model pilihan; bila tidak ditemukan (404), coba model cadangan */
$code = 0; $body = false; $err = '';
foreach (array_values(array_unique([$model, 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest'])) as $mdl) {
    list($code, $body, $err) = http_json(
        'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($mdl) . ':generateContent',
        ['x-goog-api-key: ' . $key], $payload);
    if ($code !== 404) break;
}
if ($body === false || $code === 0) net_fail($err);
$data = json_decode($body, true);
if ($code !== 200 || !is_array($data)) {
    $detail = api_detail($data);
    error_log('ARC chat Gemini ' . $code . ': ' . $detail);
    if ($code === 429) out(429, 'error', 'Kuota Gemini sedang habis. Coba lagi beberapa saat.');
    if ($code === 400 && stripos($detail, 'API key') !== false) out(502, 'error', 'Kunci API Gemini tidak valid.');
    if ($code === 401 || $code === 403) out(502, 'error', 'Kunci API Gemini ditolak atau tidak punya izin.');
    if ($code === 404) out(502, 'error', 'Model Gemini tidak ditemukan. Ubah "model" di gemini_config.php.');
    out(502, 'error', 'Gemini sedang bermasalah (kode ' . $code . ').');
}
$reply = '';
if (isset($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
    foreach ($data['candidates'][0]['content']['parts'] as $p) {
        if (isset($p['text']) && empty($p['thought'])) $reply .= $p['text'];
    }
}
$reply = trim($reply);
if ($reply === '') {
    $blocked = isset($data['promptFeedback']['blockReason']) || (isset($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === 'SAFETY');
    out(200, 'error', $blocked ? 'Pesan ini tidak dapat dijawab karena pembatasan keamanan.' : 'Sang penyihir diam saja. Coba tanyakan dengan cara lain.');
}
out(200, 'success', 'OK', ['reply' => $reply]);