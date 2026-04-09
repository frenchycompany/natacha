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
    // Skip for AJAX/API requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'json')) ||
        str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        return;
    }

    // Skip the lock page itself
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', 'app_lock.php')) {
        return;
    }

    $user = currentUser();
    if (empty($user)) return;

    // Check if user has coffre-fort PIN
    $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $pin = $stmt->fetchColumn();

    // No PIN set → redirect to set one
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

    // Need to unlock
    $_SESSION['app_lock_return'] = $_SERVER['REQUEST_URI'] ?? BASE_URL.'/couple.php';
    header('Location: '.BASE_URL.'/app_lock.php');
    exit;
}
