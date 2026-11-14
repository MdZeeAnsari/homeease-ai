<?php
// ------------------------------------------------------------
// Database config. Local (XAMPP) vs live (InfinityFree) switch.
// Fill in the LIVE values from your InfinityFree control panel.
// ------------------------------------------------------------
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
$IS_LOCAL = in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)
    || preg_match('/^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\./', $host) // laptop's LAN IP, for phones on the same Wi-Fi
    || str_ends_with($host, '.ngrok-free.app')
    || str_ends_with($host, '.ngrok-free.dev')
    || str_ends_with($host, '.ngrok.app')
    || str_ends_with($host, '.ngrok.io')
    || php_sapi_name() === 'cli';

if ($IS_LOCAL) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'homeease');
} else {
    define('DB_HOST', 'sqlXXX.infinityfree.com');   // MySQL Hostname
    define('DB_USER', 'if0_XXXXXXXX');              // MySQL Username
    define('DB_PASS', 'YOUR_VPANEL_PASSWORD');      // MySQL Password
    define('DB_NAME', 'if0_XXXXXXXX_homeease');     // MySQL DB Name
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Connects with the configured details first; on a server where they were not filled in,
// falls back to the usual XAMPP-style defaults and creates the database if allowed.
function db(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;
    $tries = [];
    if (!str_contains(DB_HOST . DB_USER, 'XXX')) $tries[] = [DB_HOST, DB_USER, DB_PASS, DB_NAME];
    foreach (['localhost', '127.0.0.1'] as $h) $tries[] = [$h, 'root', '', 'homeease'];
    // XAMPP: reuse the root password phpMyAdmin already uses (xampp\phpMyAdmin\config.inc.php), so no manual edit is needed
    foreach ([dirname(__DIR__, 3) . '/phpMyAdmin/config.inc.php', dirname(__DIR__, 3) . '/phpmyadmin/config.inc.php'] as $f) {
        if (@is_readable($f) && preg_match("/\\['password'\\]\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) @file_get_contents($f), $m) && $m[1] !== '') {
            $tries[] = ['localhost', 'root', stripcslashes($m[1]), 'homeease'];
            break;
        }
    }
    $last = '';
    foreach ($tries as [$h, $u, $p, $name]) {
        try {
            $c = new mysqli($h, $u, $p);
            try { $c->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); } catch (Throwable $e) { /* no permission: database must already exist */ }
            $c->select_db($name);
            $c->set_charset('utf8mb4');
            $c->query("SET time_zone = '+05:30'");
            return $conn = $c;
        } catch (Throwable $e) { $last = $e->getMessage(); }
    }
    http_response_code(500);
    exit("<div style='font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.6'><h2>🏠 HomeEase AI: database not connected</h2>
        <p>The app could not reach MySQL on this server.</p><p><b>Fix:</b> open <code>config/db.php</code> and put this server's MySQL host, user, password and database name in the <code>else</code> block, then open <code>setup.php</code> once.</p>
        <p style='color:#888;font-size:13px'>Server said: " . htmlspecialchars($last) . "</p></div>");
}

date_default_timezone_set('Asia/Kolkata');
