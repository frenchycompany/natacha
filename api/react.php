<?php
require_once __DIR__.'/../config.php';
requireLogin();
header('Content-Type: application/json');

$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfVerify()) {
    echo json_encode(['ok' => false]); exit;
}

// Ensure table
try { db()->query("SELECT 1 FROM reactions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS reactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        item_type ENUM('gratitude','mot_du_jour','histoire','livre_secret') NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_item (user_id, item_type, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$itemType = $_POST['item_type'] ?? '';
$itemId = (int)($_POST['item_id'] ?? 0);
$validTypes = ['gratitude','mot_du_jour','histoire','livre_secret'];

if (!in_array($itemType, $validTypes) || !$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid params']); exit;
}

// Get couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}

// Toggle: if already liked, remove; otherwise add
$existing = db()->prepare("SELECT id FROM reactions WHERE user_id=? AND item_type=? AND item_id=?");
$existing->execute([$user['id'], $itemType, $itemId]);

if ($existing->fetch()) {
    db()->prepare("DELETE FROM reactions WHERE user_id=? AND item_type=? AND item_id=?")->execute([$user['id'], $itemType, $itemId]);
    $liked = false;
} else {
    db()->prepare("INSERT INTO reactions (user_id, item_type, item_id) VALUES (?,?,?)")->execute([$user['id'], $itemType, $itemId]);
    $liked = true;

    // Feed couple avatar: +3 XP, boost tenderness & complicity
    if ($coupleId) {
        try {
            require_once __DIR__.'/../includes/couple_helper.php';
            $ce = new CoupleEntity(db());
            // Small XP reward for reacting (max 5 per day to avoid spam)
            $todayReacts = db()->prepare("SELECT COUNT(*) FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='reaction' AND DATE(created_at)=CURDATE()");
            $todayReacts->execute([$coupleId, $user['id']]);
            if ((int)$todayReacts->fetchColumn() < 5) {
                $ce->recordActivity($coupleId, $user['id'], 'reaction',
                    $user['display_name'].' a aimé un contenu',
                    $user['display_name'].' поставил(а) сердечко');
            }
        } catch (Exception $e) {}
    }

    // Notify content owner
    try {
        require_once __DIR__.'/../includes/notifications.php';
        notifyOtherUser($user['id'], 'reaction',
            $user['display_name'].' a aimé votre contenu ❤️',
            $user['display_name'].' поставил(а) сердечко ❤️',
            BASE_URL.'/couple.php');
    } catch (Exception $e) {}
}

// Get total count
$count = db()->prepare("SELECT COUNT(*) FROM reactions WHERE item_type=? AND item_id=?");
$count->execute([$itemType, $itemId]);

echo json_encode(['ok' => true, 'liked' => $liked, 'count' => (int)$count->fetchColumn()]);
