<?php
/**
 * Notification helpers for Natacha (2-user couple app).
 */

require_once __DIR__ . '/../config.php';

/**
 * Create a notification for the partner(s) in the SAME couple only.
 * $fromUserId may be 0 (system events like badges) — in that case pass
 * the couple context explicitly via the actor's couple_id lookup below.
 */
function notifyOtherUser(int $fromUserId, string $type, string $messageFr, string $messageRu, ?string $link = null): void
{
    // Resolve the couple of the actor so we never notify other couples.
    $coupleId = null;
    if ($fromUserId > 0) {
        $c = db()->prepare("SELECT couple_id FROM users WHERE id=?");
        $c->execute([$fromUserId]);
        $coupleId = $c->fetchColumn() ?: null;
    }

    if ($coupleId) {
        // Partner(s) in the same couple, excluding the sender.
        $stmt = db()->prepare("SELECT id FROM users WHERE couple_id = ? AND id != ?");
        $stmt->execute([$coupleId, $fromUserId]);
    } else {
        // No couple context (fromUserId=0 or user without couple): fall back
        // to the sender's partner set is impossible, so notify nobody rather
        // than broadcasting to the whole platform.
        $stmt = db()->prepare("SELECT id FROM users WHERE 1=0");
        $stmt->execute();
    }
    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = db()->prepare(
        "INSERT INTO notifications (user_id, type, message_fr, message_ru, link) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($recipients as $recipientId) {
        $ins->execute([$recipientId, $type, $messageFr, $messageRu, $link]);

        // Send real push notification with a type-specific, recognizable title
        try {
            if (file_exists(__DIR__ . '/push_helper.php')) {
                require_once __DIR__ . '/push_helper.php';
                if (function_exists('sendPushToUser')) {
                    $rlang = db()->prepare("SELECT lang FROM users WHERE id=?");
                    $rlang->execute([$recipientId]);
                    $rLang = $rlang->fetchColumn() ?: 'fr';
                    $pushMsg   = $rLang === 'ru' ? $messageRu : $messageFr;
                    $pushTitle = notifPushTitle($type, $rLang);
                    sendPushToUser($recipientId, $pushTitle, $pushMsg, $link ?? '');
                }
            }
        } catch (Exception $e) {
            error_log('Push notification error: ' . $e->getMessage());
        }
    }
}

/**
 * Titre de notification push, clair et reconnaissable, par type (FR/RU).
 */
function notifPushTitle(string $type, string $lang): string
{
    $titles = [
        'message'       => ['✉️ Nouveau message',           '✉️ Новое сообщение'],
        'pensee'        => ['💭 Une pensée pour toi',        '💭 Мысль о тебе'],
        'album'         => ['📸 Photo du jour',              '📸 Фото дня'],
        'gratitude'     => ['🙏 Gratitude du jour',          '🙏 Благодарность дня'],
        'moment'        => ['📝 Nouveau moment',             '📝 Новый момент'],
        'reaction'      => ['❤️ Une réaction',               '❤️ Реакция'],
        'defi'          => ['🎯 Défi du jour',               '🎯 Вызов дня'],
        'jeu'           => ['🎲 Un jeu vous attend',         '🎲 Вас ждёт игра'],
        'histoire'      => ['📖 Notre histoire',             '📖 Наша история'],
        'chapitre'      => ['📖 Nouveau chapitre',           '📖 Новая глава'],
        'livre_secret'  => ['🌹 Le Jardin Secret',           '🌹 Тайный Сад'],
        'reve'          => ['⭐ Rêves & Projets',             '⭐ Мечты и Планы'],
        'calendrier'    => ['📅 Calendrier',                 '📅 Календарь'],
        'lieu'          => ['📍 Un nouveau lieu',            '📍 Новое место'],
        'musique'       => ['🎵 Une chanson partagée',       '🎵 Песня'],
        'film'          => ['🎬 Un film partagé',            '🎬 Фильм'],
        'questionnaire' => ['💌 Un questionnaire',           '💌 Анкета'],
        'reponse'       => ['💌 Réponse à un questionnaire', '💌 Ответ на анкету'],
        'badge'         => ['🏆 Nouveau badge',              '🏆 Новый значок'],
    ];
    $t = $titles[$type] ?? ['💌 Natacha', '💌 Natacha'];
    return $lang === 'ru' ? $t[1] : $t[0];
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
