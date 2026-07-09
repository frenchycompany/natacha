<?php
/**
 * App lock screen — uses the same PIN as coffre-fort
 *
 * Flow:
 * 1. User has no PIN set → redirect to set PIN
 * 2. User has PIN, not unlocked → show PIN entry
 * 3. User has PIN, unlocked recently → pass through
 */

define('APP_LOCK_TIMEOUT', 300); // 5 minutes of inactivity → re-lock

function checkAppLock(): void {
    // Parse PATH only — never match against the query string (bypass via ?x=/api/)
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $file = basename($path); // e.g. "dashboard.php"

    // Skip AJAX/API requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'json')) ||
        str_contains($path, '/api/')) {
        return;
    }

    // Pages that manage their own access (exact filename match on the path)
    $selfManaged = ['app_lock.php', 'coffre_fort.php', 'galerie.php', 'coffre_fort_viewer.php'];
    if (in_array($file, $selfManaged, true)) {
        return;
    }

    $user = currentUser();
    if (empty($user)) return;

    // PIN exists — check if recently unlocked (fast path, no DB)
    $lastUnlock = $_SESSION['app_unlocked_at'] ?? 0;
    if (time() - $lastUnlock < APP_LOCK_TIMEOUT) {
        $_SESSION['app_unlocked_at'] = time();
        return;
    }

    // Check if user has coffre-fort PIN — cache in session to avoid DB query every load
    $hasPinCacheKey = 'app_lock_has_pin';
    $hasPinCacheTime = 'app_lock_has_pin_at';
    $pinCacheTtl = 60; // re-check DB every 60 seconds at most

    if (isset($_SESSION[$hasPinCacheKey]) && isset($_SESSION[$hasPinCacheTime])
        && (time() - $_SESSION[$hasPinCacheTime]) < $pinCacheTtl) {
        $hasPin = $_SESSION[$hasPinCacheKey];
    } else {
        $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $hasPin = !empty($stmt->fetchColumn());
        $_SESSION[$hasPinCacheKey] = $hasPin;
        $_SESSION[$hasPinCacheTime] = time();
    }

    // No PIN set → don't block, just let them use the app
    if (!$hasPin) {
        return;
    }

    // Need to unlock
    $_SESSION['app_lock_return'] = $uri ?: BASE_URL.'/couple.php';
    header('Location: '.BASE_URL.'/app_lock.php');
    exit;
}
