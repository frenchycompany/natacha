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

    // Skip coffre-fort and galerie — they have their own PIN system
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, 'coffre_fort.php') || str_contains($uri, 'galerie.php') || str_contains($uri, 'coffre_fort_viewer.php')) {
        return;
    }

    $user = currentUser();
    if (empty($user)) return;

    // Check if user has coffre-fort PIN
    $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $pin = $stmt->fetchColumn();

    // No PIN set → don't block, just let them use the app
    if (!$pin) {
        return;
    }

    // PIN exists — check if recently unlocked
    $lastUnlock = $_SESSION['app_unlocked_at'] ?? 0;
    if (time() - $lastUnlock < APP_LOCK_TIMEOUT) {
        $_SESSION['app_unlocked_at'] = time();
        return;
    }

    // Need to unlock
    $_SESSION['app_lock_return'] = $uri ?: BASE_URL.'/couple.php';
    header('Location: '.BASE_URL.'/app_lock.php');
    exit;
}
