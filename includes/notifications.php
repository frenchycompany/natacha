<?php
/**
 * Notification helpers for Natacha (2-user couple app).
 */

require_once __DIR__ . '/../config.php';

/**
 * Create a notification for the OTHER user.
 * Since there are only 2 users, we find every user_id != $fromUserId.
 */
function notifyOtherUser(int $fromUserId, string $type, string $messageFr, string $messageRu, ?string $link = null): void
{
    $stmt = db()->prepare("SELECT id FROM users WHERE id != ?");
    $stmt->execute([$fromUserId]);
    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = db()->prepare(
        "INSERT INTO notifications (user_id, type, message_fr, message_ru, link) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($recipients as $recipientId) {
        $ins->execute([$recipientId, $type, $messageFr, $messageRu, $link]);

        // Send real push notification
        try {
            require_once __DIR__ . '/push_helper.php';
            // Detect recipient's language
            $rlang = db()->prepare("SELECT lang FROM users WHERE id=?");
            $rlang->execute([$recipientId]);
            $rLang = $rlang->fetchColumn() ?: 'fr';
            $pushMsg = $rLang === 'ru' ? $messageRu : $messageFr;
            sendPushToUser($recipientId, 'Natacha 💌', $pushMsg, $link ?? '');
        } catch (Exception $e) {}
    }
}

/**
 * Count unread notifications for a user.
 */
function getUnreadCount(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Fetch recent notifications for a user (newest first).
 */
function getNotifications(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare(
        "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Mark a single notification as read (only if it belongs to $userId).
 */
function markAsRead(int $notificationId, int $userId): void
{
    $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
}

/**
 * Mark all notifications as read for a user.
 */
function markAllAsRead(int $userId): void
{
    $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
}
