<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    'natacha');
define('DB_USER',    'root');
define('DB_PASS',    '**Baycpq25**');
define('DB_CHARSET', 'utf8mb4');
define('SESSION_NAME', 'natacha_session');
define('SESSION_LIFETIME', 7200);
define('BASE_URL', '/natacha');

// Mail settings (for send.php candidature)
define('MAIL_FROM', 'noreply@natacha.app');
define('MAIL_TO',   'raphael@natacha.app');

// Admin panel settings
define('ADMIN_SESSION_NAME', 'natacha_admin_session');
define('ADMIN_SESSION_LIFETIME', 3600);

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function startSession() {
    session_name(SESSION_NAME);
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function requireLogin() {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: '.BASE_URL.'/login.php');
        exit;
    }
    if (time() - ($_SESSION['last_active'] ?? 0) > SESSION_LIFETIME) {
        session_destroy();
        header('Location: '.BASE_URL.'/login.php?expired=1');
        exit;
    }
    $_SESSION['last_active'] = time();
}

function currentUser(): array {
    return $_SESSION['user'] ?? [];
}

function t(string $fr, string $ru): string {
    $lang = $_SESSION['user']['lang'] ?? 'fr';
    return $lang === 'ru' ? $ru : $fr;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
