<?php
/**
 * App lock screen — PIN protection
 * Include this after requireLogin() on protected pages
 *
 * Flow:
 * 1. User has no PIN set → redirect to set PIN
 * 2. User has PIN, not unlocked → show PIN entry
 * 3. User has PIN, unlocked recently → pass through
 */

define('APP_LOCK_TIMEOUT', 300); // 5 minutes of inactivity → re-lock

function checkAppLock(): void {
    // Skip for AJAX/API requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'json')) ||
        str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        return;
    }

    $user = currentUser();
    if (empty($user)) return;

    // Check if user has a PIN set
    try {
        db()->query("SELECT app_pin FROM users LIMIT 1");
    } catch (Exception $e) {
        db()->exec("ALTER TABLE users ADD COLUMN app_pin VARCHAR(255) DEFAULT NULL");
        return; // First time, no PIN yet
    }

    $stmt = db()->prepare("SELECT app_pin FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $pin = $stmt->fetchColumn();

    // No PIN set → let them set one (unless they're on the PIN setup page)
    if (!$pin) {
        if (!str_contains($_SERVER['REQUEST_URI'] ?? '', 'app_lock.php')) {
            header('Location: '.BASE_URL.'/app_lock.php?setup=1');
            exit;
        }
        return;
    }

    // PIN exists — check if recently unlocked
    $lastUnlock = $_SESSION['app_unlocked_at'] ?? 0;
    if (time() - $lastUnlock < APP_LOCK_TIMEOUT) {
        $_SESSION['app_unlocked_at'] = time(); // Refresh on activity
        return;
    }

    // Need to unlock — redirect to lock screen
    if (!str_contains($_SERVER['REQUEST_URI'] ?? '', 'app_lock.php')) {
        $_SESSION['app_lock_return'] = $_SERVER['REQUEST_URI'] ?? BASE_URL.'/couple.php';
        header('Location: '.BASE_URL.'/app_lock.php');
        exit;
    }
}
