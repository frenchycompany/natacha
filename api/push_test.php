<?php
/**
 * Test push notification — sends a test push to the current user
 */
require_once __DIR__.'/../config.php';
requireLogin();
header('Content-Type: application/json');

$user = currentUser();

// Check vendor autoload exists
if (!file_exists(__DIR__.'/../vendor/autoload.php')) {
    echo json_encode(['ok' => false, 'error' => 'vendor/autoload.php missing — run: composer install --no-dev']);
    exit;
}

// Check push_subscriptions table
try {
    $count = db()->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?");
    $count->execute([$user['id']]);
    $subCount = (int)$count->fetchColumn();
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Table push_subscriptions missing', 'detail' => $e->getMessage()]);
    exit;
}

if ($subCount === 0) {
    echo json_encode(['ok' => false, 'error' => 'No push subscription for user ' . $user['display_name'] . '. Click "Enable notifications" first.', 'subscriptions' => 0]);
    exit;
}

// Try sending
try {
    require_once __DIR__.'/../includes/push_helper.php';
    $lang = $user['lang'] ?? 'fr';
    $msg = $lang === 'ru' ? 'Тест уведомлений работает! 🎉' : 'Test de notification réussi ! 🎉';
    sendPushToUser($user['id'], 'Natacha 💌', $msg, '/natacha/profil.php');
    echo json_encode(['ok' => true, 'message' => 'Push sent to ' . $subCount . ' device(s)', 'subscriptions' => $subCount]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'subscriptions' => $subCount]);
}
