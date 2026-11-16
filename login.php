<?php
ini_set('display_errors', '0');          // peringatan PHP tidak boleh merusak keluaran JSON
ob_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

/* Sandi awal. Setelah Anda mengganti sandi dari menu admin (kartu "Ganti Sandi"),
   sandi baru tersimpan sebagai hash di admin_auth.php dan nilai di bawah ini tidak dipakai lagi.
   Lupa sandi? Hapus admin_auth.php lewat File Manager, lalu pakai sandi awal ini. */
$PASSWORD_RAHASIA = 'GANTI_DENGAN_SANDI_BARU_YANG_PANJANG';
$AUTH_FILE = __DIR__ . '/admin_auth.php';

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
}
session_start();

function out($code, $status, $msg, $extra = []) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode(array_merge(['status' => $status, 'message' => $msg], $extra));
    exit;
}
function load_hash($file) {
    clearstatcache(true, $file);                          // file yang baru dihapus lewat File Manager jangan dianggap masih ada
    if (!is_file($file) || !is_readable($file)) return null;
    $a = @include $file;
    return (is_array($a) && isset($a['hash']) && is_string($a['hash']) && $a['hash'] !== '') ? $a['hash'] : null;
}
function check_password($input, $hash, $plain) {
    if ($hash !== null) return password_verify($input, $hash);
    return hash_equals($plain, $input);
}

/* Batasi tebakan sandi: 5 kali salah dari satu IP = terkunci 5 menit */
function rl_file() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
    return sys_get_temp_dir() . '/arc_login_' . md5($ip) . '.json';
}
function rl_state() {
    $f = rl_file();
    $r = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $r = is_array($r) && isset($r['n'], $r['t']) ? $r : ['n' => 0, 't' => 0];
    if (time() - $r['t'] >= 300) $r = ['n' => 0, 't' => 0];
    return $r;
}
function rl_blocked() { $r = rl_state(); return $r['n'] >= 5; }
function rl_fail() { $r = rl_state(); $r['n']++; $r['t'] = time(); @file_put_contents(rl_file(), json_encode($r), LOCK_EX); sleep(1); }
function rl_clear() { @unlink(rl_file()); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, 'error', 'Metode tidak diizinkan');

$data   = json_decode(file_get_contents('php://input'), true);
$data   = is_array($data) ? $data : [];
$action = isset($data['action']) ? $data['action'] : 'login';
$hash   = load_hash($AUTH_FILE);

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    out(200, 'success', 'Logout berhasil');
}
if ($action === 'check') {
    if (!empty($_SESSION['is_admin'])) out(200, 'success', 'Sesi admin aktif');
    out(401, 'error', 'Sesi admin berakhir');
}

if ($action === 'change_password') {
    if (empty($_SESSION['is_admin'])) out(401, 'error', 'Sesi admin berakhir, silakan login lagi', ['code' => 'session']);
    if (rl_blocked()) out(429, 'error', 'Terlalu banyak percobaan salah. Tunggu sekitar 5 menit lalu coba lagi.');
    $cur = isset($data['current']) && is_string($data['current']) ? $data['current'] : '';
    $new = isset($data['new']) && is_string($data['new']) ? $data['new'] : '';
    if (!check_password($cur, $hash, $PASSWORD_RAHASIA)) { rl_fail(); out(401, 'error', 'Sandi saat ini salah', ['code' => 'wrong']); }
    $len = function_exists('mb_strlen') ? mb_strlen($new) : strlen($new);
    if ($len < 8)    out(400, 'error', 'Sandi baru minimal 8 karakter');
    if ($len > 200)  out(400, 'error', 'Sandi baru terlalu panjang');
    if ($new === $cur) out(400, 'error', 'Sandi baru harus berbeda dari sandi lama');
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $code = "<?php\n// Dibuat otomatis oleh login.php. Jangan dibagikan.\nreturn " . var_export(['hash' => $newHash, 'updated' => time()], true) . ";\n";
    if (@file_put_contents($AUTH_FILE, $code, LOCK_EX) === false) {
        out(500, 'error', 'Tidak bisa menulis admin_auth.php. Pastikan folder web bisa ditulis oleh PHP.');
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($AUTH_FILE, true);
    rl_clear();
    session_regenerate_id(true);
    out(200, 'success', 'Sandi berhasil diganti');
}

/* Login */
if (rl_blocked()) out(429, 'error', 'Terlalu banyak percobaan salah. Tunggu sekitar 5 menit lalu coba lagi.');
$input = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
if (check_password($input, $hash, $PASSWORD_RAHASIA)) {
    rl_clear();
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    out(200, 'success', 'Login Berhasil');
}
rl_fail();
out(401, 'error', 'Password Salah');