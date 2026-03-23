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

// Coffre-fort encryption
define('COFFRE_KEY', 'NtCh2025!SecureVault#AES256KeyX'); // 32 bytes for AES-256
define('COFFRE_STORAGE', __DIR__ . '/storage/coffre');
define('COFFRE_SESSION_DURATION', 900); // 15 minutes
define('COFFRE_MAX_FILE_SIZE', 200 * 1024 * 1024); // 200 Mo

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

/**
 * Traduit un texte via MyMemory API (gratuit, sans clé)
 * $from/$to : 'fr' ou 'ru'
 */
function translateText(string $text, string $from, string $to): string {
    if (!$text) return '';
    $langPair = $from . '|' . $to;
    $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
        'q'        => mb_substr($text, 0, 4500),
        'langpair' => $langPair,
        'de'       => 'natacha@natacha.app',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return '';
    $data = json_decode($response, true);
    if (!$data || ($data['responseStatus'] ?? 0) != 200) return '';
    return $data['responseData']['translatedText'] ?? '';
}
