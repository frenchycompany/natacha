<?php
/**
 * API: Subscribe/unsubscribe to push notifications
 * POST { action: 'subscribe', subscription: {...} }
 * POST { action: 'unsubscribe' }
 */
require_once __DIR__.'/../config.php';
requireLogin();
header('Content-Type: application/json');

$user = currentUser();

// Ensure table
try { db()->query("SELECT 1 FROM push_subscriptions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_endpoint (user_id, endpoint(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'subscribe') {
    $sub = $input['subscription'] ?? null;
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid subscription']); exit;
    }
    // Upsert
    db()->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth), created_at=NOW()")
        ->execute([$user['id'], $sub['endpoint'], $sub['keys']['p256dh'], $sub['keys']['auth']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unsubscribe') {
    db()->prepare("DELETE FROM push_subscriptions WHERE user_id=?")->execute([$user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false]);
