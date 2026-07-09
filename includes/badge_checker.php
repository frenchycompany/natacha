<?php
/**
 * NATACHA — Badge/Achievement Checker
 * Checks and awards badges based on user activity.
 */

require_once __DIR__ . '/../config.php';

/**
 * Ensure badge tables exist and seed default badges.
 */
function ensureBadgeTables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try { db()->query("SELECT 1 FROM badges LIMIT 1"); } catch (Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS badges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            badge_key VARCHAR(50) NOT NULL UNIQUE,
            name_fr VARCHAR(100) NOT NULL,
            name_ru VARCHAR(100) NOT NULL,
            description_fr VARCHAR(255) NOT NULL,
            description_ru VARCHAR(255) NOT NULL,
            emoji VARCHAR(10) NOT NULL,
            category ENUM('gratitude','jeux','couple','social','special') DEFAULT 'couple',
            threshold INT UNSIGNED DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try { db()->query("SELECT 1 FROM user_badges LIMIT 1"); } catch (Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS user_badges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            badge_key VARCHAR(50) NOT NULL,
            unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_badge (user_id, badge_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Seed badges
    db()->exec("INSERT IGNORE INTO badges (badge_key, name_fr, name_ru, description_fr, description_ru, emoji, category, threshold) VALUES
        ('gratitude_3',    '3 jours de gratitude',   '3 дня благодарности',     'Écrire 3 gratitudes',              'Написать 3 благодарности',           '🙏', 'gratitude', 3),
        ('gratitude_7',    '7 jours d''affilée',     '7 дней подряд',           '7 jours de gratitude consécutifs', '7 дней благодарности подряд',        '🔥', 'gratitude', 7),
        ('gratitude_30',   '30 jours de gratitude',  '30 дней благодарности',   'Écrire 30 gratitudes',             'Написать 30 благодарностей',         '⭐', 'gratitude', 30),
        ('gratitude_100',  '100 mercis',             '100 благодарностей',      'Écrire 100 gratitudes',            'Написать 100 благодарностей',        '💎', 'gratitude', 100),
        ('games_first',    'Premier jeu',            'Первая игра',             'Jouer à 1 jeu',                    'Сыграть в 1 игру',                   '🎮', 'jeux',      1),
        ('games_10',       '10 parties',             '10 игр',                  'Jouer à 10 jeux',                  'Сыграть в 10 игр',                   '🏆', 'jeux',      10),
        ('quiz_perfect',   'Sans faute',             'Без ошибок',              '5 bonnes réponses de suite',       '5 правильных ответов подряд',        '💯', 'jeux',      5),
        ('couple_week',    '1 semaine',              '1 неделя',                'Couple depuis 7 jours',            'Пара уже 7 дней',                    '💕', 'couple',    7),
        ('couple_month',   '1 mois',                 '1 месяц',                 'Couple depuis 30 jours',           'Пара уже 30 дней',                   '💝', 'couple',    30),
        ('couple_year',    '1 an',                   '1 год',                   'Couple depuis 365 jours',          'Пара уже 365 дней',                  '💖', 'couple',    365),
        ('level_2',        'Niveau 2',               'Уровень 2',               'Atteindre le niveau 2',            'Достичь уровня 2',                   '🌱', 'couple',    2),
        ('level_3',        'Niveau 3',               'Уровень 3',               'Atteindre le niveau 3',            'Достичь уровня 3',                   '🌿', 'couple',    3),
        ('first_heart',    'Premier coeur',          'Первое сердечко',         'Donner sa première réaction',      'Поставить первое сердечко',          '❤️', 'social',    1),
        ('hearts_50',      '50 coeurs',              '50 сердечек',             'Donner 50 réactions',              'Поставить 50 сердечек',              '💕', 'social',    50),
        ('first_moment',   'Premier moment',         'Первый момент',           'Écrire son premier moment du jour','Написать первый момент дня',         '📝', 'social',    1),
        ('moments_30',     '30 moments',             '30 моментов',             'Écrire 30 moments',               'Написать 30 моментов',               '📸', 'social',    30),
        ('first_chapter',  'Premier chapitre',       'Первая глава',            'Écrire un chapitre d''histoire',   'Написать главу истории',              '📖', 'social',    1),
        ('defi_10',        '10 défis',               '10 вызовов',              'Compléter 10 défis',              'Выполнить 10 вызовов',               '🎯', 'social',    10)
    ");
}

/**
 * Check all badge conditions and award newly earned badges.
 * Returns array of newly awarded badge_keys.
 */
function checkAndAwardBadges(int $userId, int $coupleId): array {
    ensureBadgeTables();

    $newBadges = [];

    // Get already earned badges for this user
    $earned = [];
    $stmt = db()->prepare("SELECT badge_key FROM user_badges WHERE user_id = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $earned[$row['badge_key']] = true;
    }

    // Helper: award badge if not already earned
    $award = function(string $key) use ($userId, &$newBadges, &$earned) {
        if (isset($earned[$key])) return;
        $stmt = db()->prepare("INSERT IGNORE INTO user_badges (user_id, badge_key) VALUES (?, ?)");
        $stmt->execute([$userId, $key]);
        if ($stmt->rowCount() > 0) {
            $newBadges[] = $key;
            $earned[$key] = true;
        }
    };

    // ── Gratitude badges ──
    try {
        // Compte les JOURS distincts (les badges disent "X jours"), pas les entrées
        $cnt = db()->prepare("SELECT COUNT(DISTINCT entry_date) FROM gratitude_entries WHERE user_id = ?");
        $cnt->execute([$userId]);
        $gratitudeCount = (int)$cnt->fetchColumn();

        if ($gratitudeCount >= 3)   $award('gratitude_3');
        if ($gratitudeCount >= 30)  $award('gratitude_30');
        if ($gratitudeCount >= 100) $award('gratitude_100');
    } catch (Exception $e) {}

    // Gratitude streak (count consecutive days backwards from today)
    try {
        if (!isset($earned['gratitude_7'])) {
            $streak = 0;
            $checkDate = new DateTime();
            while (true) {
                $d = $checkDate->format('Y-m-d');
                $has = db()->prepare("SELECT 1 FROM gratitude_entries WHERE user_id = ? AND entry_date = ?");
                $has->execute([$userId, $d]);
                if ($has->fetch()) {
                    $streak++;
                    $checkDate->modify('-1 day');
                } else {
                    break;
                }
                if ($streak > 365) break;
            }
            if ($streak >= 7) $award('gratitude_7');
        }
    } catch (Exception $e) {}

    // ── Games badges ──
    try {
        $cnt = db()->prepare("SELECT COUNT(*) FROM couple_activities WHERE user_id = ? AND activity_type = 'jeu'");
        $cnt->execute([$userId]);
        $gamesCount = (int)$cnt->fetchColumn();

        if ($gamesCount >= 1)  $award('games_first');
        if ($gamesCount >= 10) $award('games_10');
    } catch (Exception $e) {}

    // quiz_perfect — skip for now (requires specific game tracking)

    // ── Couple badges ──
    try {
        $stmt = db()->prepare("SELECT DATEDIFF(NOW(), birth_date) AS age_days, level FROM couples WHERE id = ?");
        $stmt->execute([$coupleId]);
        $coupleData = $stmt->fetch();
        if ($coupleData) {
            $ageDays = (int)$coupleData['age_days'];
            $level = (int)$coupleData['level'];

            if ($ageDays >= 7)   $award('couple_week');
            if ($ageDays >= 30)  $award('couple_month');
            if ($ageDays >= 365) $award('couple_year');
            if ($level >= 2) $award('level_2');
            if ($level >= 3) $award('level_3');
        }
    } catch (Exception $e) {}

    // ── Social badges ──
    // Reactions
    try {
        $cnt = db()->prepare("SELECT COUNT(*) FROM reactions WHERE user_id = ?");
        $cnt->execute([$userId]);
        $reactCount = (int)$cnt->fetchColumn();

        if ($reactCount >= 1)  $award('first_heart');
        if ($reactCount >= 50) $award('hearts_50');
    } catch (Exception $e) {}

    // Moments (couple_quick_actions)
    try {
        $cnt = db()->prepare("SELECT COUNT(*) FROM couple_quick_actions WHERE user_id = ?");
        $cnt->execute([$userId]);
        $momentCount = (int)$cnt->fetchColumn();

        if ($momentCount >= 1)  $award('first_moment');
        if ($momentCount >= 30) $award('moments_30');
    } catch (Exception $e) {}

    // Histoire chapters
    try {
        $cnt = db()->prepare("SELECT COUNT(*) FROM histoire_chapters WHERE user_id = ?");
        $cnt->execute([$userId]);
        $chapterCount = (int)$cnt->fetchColumn();

        if ($chapterCount >= 1) $award('first_chapter');
    } catch (Exception $e) {}

    // Defis completed
    try {
        // Determine user position in couple
        $pos = db()->prepare("SELECT
            CASE WHEN user1_id = ? THEN 1 WHEN user2_id = ? THEN 2 ELSE 0 END AS pos
            FROM couples WHERE id = ?");
        $pos->execute([$userId, $userId, $coupleId]);
        $userPos = (int)$pos->fetchColumn();

        if ($userPos > 0) {
            $completeCol = $userPos === 1 ? 'complete_user1' : 'complete_user2';
            $cnt = db()->prepare("SELECT COUNT(*) FROM defis_log WHERE couple_id = ? AND $completeCol = 1");
            $cnt->execute([$coupleId]);
            $defiCount = (int)$cnt->fetchColumn();

            if ($defiCount >= 10) $award('defi_10');
        }
    } catch (Exception $e) {}

    // Send notification for each new badge
    if (!empty($newBadges)) {
        try {
            require_once __DIR__ . '/notifications.php';
            foreach ($newBadges as $key) {
                $badge = db()->prepare("SELECT * FROM badges WHERE badge_key = ?");
                $badge->execute([$key]);
                $b = $badge->fetch();
                if ($b) {
                    // Pass the earner's real user id → notifies the partner in
                    // the same couple only (not user 0 = everyone).
                    $earnerName = currentUser()['display_name'] ?? '';
                    notifyOtherUser($userId, 'badge',
                        $b['emoji'] . ' ' . $earnerName . ' a obtenu le badge "' . $b['name_fr'] . '"',
                        $b['emoji'] . ' ' . $earnerName . ' получил(а) значок "' . $b['name_ru'] . '"',
                        BASE_URL . '/profil.php');
                }
            }
        } catch (Exception $e) {}
    }

    return $newBadges;
}

/**
 * Get all badges with user's earned status.
 */
function getUserBadges(int $userId): array {
    ensureBadgeTables();

    $stmt = db()->prepare("SELECT b.*, ub.unlocked_at
        FROM badges b
        LEFT JOIN user_badges ub ON ub.badge_key = b.badge_key AND ub.user_id = ?
        ORDER BY b.category, b.threshold");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
