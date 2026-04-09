<?php
/**
 * Daily reminders — run via cron at 20:00
 * crontab -e → 0 20 * * * php /var/www/natacha/cron/daily_reminders.php
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/push_helper.php';

// Get all users with push subscriptions
$users = db()->query("SELECT DISTINCT u.id, u.display_name, u.lang, u.couple_id
    FROM users u
    JOIN push_subscriptions ps ON ps.user_id = u.id")->fetchAll();

$today = date('Y-m-d');

foreach ($users as $user) {
    $reminders = [];

    // Check gratitude
    try {
        $grat = db()->prepare("SELECT 1 FROM gratitude_entries WHERE user_id=? AND entry_date=?");
        $grat->execute([$user['id'], $today]);
        if (!$grat->fetch()) {
            $reminders[] = $user['lang'] === 'ru'
                ? 'Ты ещё не написал(а) благодарность дня 🙏'
                : 'Tu n\'as pas encore écrit ta gratitude du jour 🙏';
        }
    } catch (Exception $e) {}

    // Check mot du jour
    try {
        $mot = db()->prepare("SELECT 1 FROM mots_du_jour WHERE user_id=? AND date_mot=?");
        $mot->execute([$user['id'], $today]);
        if (!$mot->fetch()) {
            $reminders[] = $user['lang'] === 'ru'
                ? 'Напиши записку дня для партнёра 💌'
                : 'Écris un petit mot du jour pour ton/ta partenaire 💌';
        }
    } catch (Exception $e) {}

    // Check defi du jour
    try {
        // Get today's defi
        $defi = db()->prepare("SELECT dl.* FROM defis_log dl WHERE dl.date_defi=?");
        $defi->execute([$today]);
        $defiToday = $defi->fetch();
        if ($defiToday) {
            // Check which column to look at
            $allUsers = db()->query("SELECT id FROM users ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $col = ($user['id'] == ($allUsers[0] ?? 0)) ? 'complete_user1' : 'complete_user2';
            if (!$defiToday[$col]) {
                $reminders[] = $user['lang'] === 'ru'
                    ? 'Вызов дня ждёт тебя! 🎯'
                    : 'Le défi du jour t\'attend ! 🎯';
            }
        }
    } catch (Exception $e) {}

    // Check pending validations (jeux_connaissance)
    try {
        $pending = db()->prepare("SELECT COUNT(*) FROM jeux_connaissance_reponses WHERE user_id!=? AND is_self_answer=0 AND is_correct IS NULL");
        $pending->execute([$user['id']]);
        $pendingCount = (int)$pending->fetchColumn();
        if ($pendingCount > 0) {
            $reminders[] = $user['lang'] === 'ru'
                ? $pendingCount . ' ответ(ов) ждут проверки 🎮'
                : $pendingCount . ' réponse(s) à valider 🎮';
        }
    } catch (Exception $e) {}

    // Send consolidated reminder
    if (!empty($reminders)) {
        $title = $user['lang'] === 'ru' ? 'Natacha — Напоминание' : 'Natacha — Rappel du soir';
        $body = implode("\n", $reminders);
        try {
            sendPushToUser($user['id'], $title, $body, '/natacha/dashboard.php');
        } catch (Exception $e) {}
    }
}

echo "Reminders sent to " . count($users) . " users\n";
