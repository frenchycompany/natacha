<?php
/**
 * NATACHA — Notre Humeur
 * Suivi quotidien de l'humeur du couple
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Refresh couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

// Ensure table
try { db()->query("SELECT 1 FROM mood_entries LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS mood_entries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        couple_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        mood ENUM('amazing','happy','good','neutral','tired','sad','angry') NOT NULL,
        note TEXT DEFAULT NULL,
        note_translated TEXT DEFAULT NULL,
        note_lang CHAR(2) DEFAULT 'fr',
        entry_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_date (user_id, entry_date),
        INDEX idx_couple_date (couple_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Mood config
$moods = [
    'amazing' => ['emoji' => '🤩', 'fr' => 'Incroyable',  'ru' => 'Невероятно',      'score' => 7],
    'happy'   => ['emoji' => '😊', 'fr' => 'Heureux',     'ru' => 'Счастлив(а)',     'score' => 6],
    'good'    => ['emoji' => '🙂', 'fr' => 'Bien',        'ru' => 'Хорошо',          'score' => 5],
    'neutral' => ['emoji' => '😐', 'fr' => 'Neutre',      'ru' => 'Нейтрально',      'score' => 4],
    'tired'   => ['emoji' => '😴', 'fr' => 'Fatigué',     'ru' => 'Устал(а)',        'score' => 3],
    'sad'     => ['emoji' => '😢', 'fr' => 'Triste',      'ru' => 'Грустно',         'score' => 2],
    'angry'   => ['emoji' => '😤', 'fr' => 'Énervé',      'ru' => 'Раздражён(а)',    'score' => 1],
];

// POST: save mood
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $mood = $_POST['mood'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (isset($moods[$mood])) {
        $today = date('Y-m-d');
        $noteVal = $note && mb_strlen($note) <= 300 ? $note : null;
        $stmt = db()->prepare("INSERT INTO mood_entries (couple_id, user_id, mood, note, entry_date)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE mood=?, note=?, created_at=CURRENT_TIMESTAMP");
        $stmt->execute([$coupleId, $user['id'], $mood, $noteVal, $today, $mood, $noteVal]);

        // Auto-translate note
        if ($noteVal) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($noteVal, $fromLang, $toLang);
            if ($translated) {
                db()->prepare("UPDATE mood_entries SET note_translated=?, note_lang=? WHERE user_id=? AND entry_date=?")
                    ->execute([$translated, $fromLang, $user['id'], $today]);
            }
        }

        // Record couple activity (once per day)
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='gratitude' AND description_fr LIKE '%humeur%' AND DATE(created_at)=?");
        $already->execute([$coupleId, $user['id'], $today]);
        if (!$already->fetch()) {
            $ce->recordActivity($coupleId, $user['id'], 'gratitude',
                $user['display_name'].' a partagé son humeur du jour',
                $user['display_name'].' shared their mood');
        }

        // Notify partner
        $moodEmoji = $moods[$mood]['emoji'];
        try {
            require_once __DIR__.'/includes/notifications.php';
            notifyOtherUser($user['id'], 'mood',
                $user['display_name'].' a partagé son humeur : '.$moodEmoji,
                $user['display_name'].' поделился настроением: '.$moodEmoji,
                BASE_URL.'/mood_tracker.php');
        } catch (Exception $e) {}

        // Check badges
        try {
            require_once __DIR__.'/includes/badge_checker.php';
            checkAndAwardBadges($user['id'], $coupleId);
        } catch (Exception $e) {}

        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => t('Humeur invalide','Недействительное настроение')]);
    }
    exit;
}

// GET: load data
$today = date('Y-m-d');

// Today's entries
$myMood = db()->prepare("SELECT * FROM mood_entries WHERE user_id=? AND entry_date=?");
$myMood->execute([$user['id'], $today]);
$myMood = $myMood->fetch();

$partnerMood = db()->prepare("SELECT me.*, u.display_name, u.avatar FROM mood_entries me JOIN users u ON u.id=me.user_id WHERE me.couple_id=? AND me.user_id!=? AND me.entry_date=?");
$partnerMood->execute([$coupleId, $user['id'], $today]);
$partnerMood = $partnerMood->fetch();

// Get partner info
$partner = db()->prepare("SELECT id, display_name, avatar FROM users WHERE couple_id=? AND id!=?");
$partner->execute([$coupleId, $user['id']]);
$partner = $partner->fetch();

// History: last 14 days for chart
$chartDays = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDays[] = $d;
}

$chartData = [];
$histStmt = db()->prepare("SELECT user_id, mood, entry_date FROM mood_entries WHERE couple_id=? AND entry_date >= ? ORDER BY entry_date ASC");
$histStmt->execute([$coupleId, $chartDays[0]]);
foreach ($histStmt->fetchAll() as $h) {
    $chartData[$h['entry_date']][$h['user_id']] = $h['mood'];
}

// Recent entries with notes
$recentEntries = db()->prepare("SELECT me.*, u.display_name, u.avatar FROM mood_entries me
    JOIN users u ON u.id=me.user_id WHERE me.couple_id=? ORDER BY me.entry_date DESC, me.created_at DESC LIMIT 40");
$recentEntries->execute([$coupleId]);
$recentEntries = $recentEntries->fetchAll();

// Load reactions
$reactionCounts = [];
$myReactions = [];
if (!empty($recentEntries)) {
    $ids = array_column($recentEntries, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='mood' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($ids);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];

        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='mood' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $ids));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

// Group recent by date
$grouped = [];
foreach ($recentEntries as $e) {
    $grouped[$e['entry_date']][] = $e;
}

// Stats: average mood this week
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekAvg = db()->prepare("SELECT AVG(CASE mood
    WHEN 'amazing' THEN 7 WHEN 'happy' THEN 6 WHEN 'good' THEN 5 WHEN 'neutral' THEN 4
    WHEN 'tired' THEN 3 WHEN 'sad' THEN 2 WHEN 'angry' THEN 1 END) as avg_mood
    FROM mood_entries WHERE couple_id=? AND entry_date >= ?");
$weekAvg->execute([$coupleId, $weekStart]);
$avgMoodScore = round((float)($weekAvg->fetchColumn() ?: 0), 1);

// Map score to label
$avgLabel = '';
if ($avgMoodScore >= 6.5) $avgLabel = t('Incroyable','Невероятно');
elseif ($avgMoodScore >= 5.5) $avgLabel = t('Heureux','Счастливы');
elseif ($avgMoodScore >= 4.5) $avgLabel = t('Bien','Хорошо');
elseif ($avgMoodScore >= 3.5) $avgLabel = t('Neutre','Нейтрально');
elseif ($avgMoodScore >= 2.5) $avgLabel = t('Fatigué','Устали');
elseif ($avgMoodScore >= 1.5) $avgLabel = t('Triste','Грустно');
elseif ($avgMoodScore > 0) $avgLabel = t('Difficile','Трудно');
else $avgLabel = '—';

// Streak: consecutive days with at least one entry
$streak = 0;
$checkDate = new DateTime($today);
while (true) {
    $d = $checkDate->format('Y-m-d');
    $has = db()->prepare("SELECT 1 FROM mood_entries WHERE couple_id=? AND entry_date=?");
    $has->execute([$coupleId, $d]);
    if ($has->fetch()) { $streak++; $checkDate->modify('-1 day'); } else { break; }
    if ($streak > 365) break;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Notre Humeur','Наше Настроение') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:640px;margin:0 auto;padding:2rem 1.5rem}

/* Stats */
.stats-bar{display:flex;justify-content:center;gap:2rem;margin-bottom:2rem;padding:1rem;border:1px solid var(--border);background:var(--s)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--accent);text-align:center;line-height:1}
.stat-label{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:.3rem}
.stat-divider{width:1px;height:40px;background:var(--border);align-self:center}

/* Mood selector */
.mood-section{margin-bottom:2.5rem}
.mood-prompt{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;font-style:italic;color:var(--accent);text-align:center;margin-bottom:1.2rem}
.mood-sub{text-align:center;font-size:.6rem;color:var(--muted);margin-bottom:1.2rem;letter-spacing:.08em}
.mood-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.5rem;margin-bottom:1rem}
.mood-option{display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:.6rem .3rem;border:1px solid var(--border);background:transparent;cursor:pointer;transition:all .3s;border-radius:4px}
.mood-option:hover{border-color:var(--accent);background:var(--as)}
.mood-option.selected{border-color:var(--accent);background:var(--as);box-shadow:0 0 12px rgba(201,169,110,.15)}
.mood-option .mood-emoji{font-size:1.6rem;line-height:1}
.mood-option .mood-label{font-size:.4rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.mood-option.selected .mood-label{color:var(--accent)}

.note-area{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1rem;padding:1rem;min-height:70px;resize:vertical;outline:none;transition:border .3s;line-height:1.7;margin-bottom:.6rem}
.note-area:focus{border-color:var(--accent)}
.note-area::placeholder{color:var(--muted);font-style:italic}
.note-label{font-size:.5rem;color:var(--muted);letter-spacing:.08em;margin-bottom:.4rem}
.save-row{display:flex;justify-content:space-between;align-items:center}
.char-count{font-size:.5rem;color:var(--muted)}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn.filled{background:var(--accent);color:var(--bg)}
.btn.filled:hover{opacity:.85}
.success-msg{text-align:center;color:var(--accent);font-size:.7rem;margin-top:.8rem;display:none}

/* Today side by side */
.today-pair{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:2.5rem}
.today-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;text-align:center;transition:border-color .3s}
.today-card:hover{border-color:var(--accent)}
.today-card .card-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.5rem}
.today-card .card-emoji{font-size:2.2rem;margin-bottom:.3rem}
.today-card .card-mood{font-family:'Cormorant Garamond',serif;font-size:1rem;color:var(--text);font-style:italic}
.today-card .card-note{font-family:'Cormorant Garamond',serif;font-size:.85rem;color:var(--muted);font-style:italic;margin-top:.5rem;line-height:1.6}
.today-card .card-note-trad{font-family:'Cormorant Garamond',serif;font-size:.75rem;color:var(--muted);font-style:italic;margin-top:.2rem;opacity:.6;line-height:1.5}
.today-label{text-align:center;font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);margin-bottom:1rem}
.today-emoji{text-align:center;font-size:1.8rem;margin-bottom:.5rem}

/* Already logged */
.already-box{text-align:center;padding:1.2rem;border:1px solid var(--border);background:var(--s);margin-bottom:.8rem}
.already-emoji{font-size:2rem;margin-bottom:.3rem}
.already-mood{font-family:'Cormorant Garamond',serif;font-size:1rem;color:var(--accent);font-style:italic}
.already-note-text{font-family:'Cormorant Garamond',serif;font-size:.9rem;color:var(--text);font-style:italic;margin-top:.4rem;line-height:1.6}

/* Mood chart */
.chart-section{margin-bottom:2.5rem}
.chart-title{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);text-align:center;margin-bottom:1rem}
.chart-wrap{border:1px solid var(--border);background:var(--s);padding:1rem;overflow-x:auto}
.chart-table{width:100%;border-collapse:collapse}
.chart-table td{text-align:center;padding:.3rem .15rem;vertical-align:middle}
.chart-name{font-size:.45rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);white-space:nowrap;text-align:right;padding-right:.5rem !important;width:60px}
.chart-day{font-size:.4rem;color:var(--muted);padding-top:.3rem !important}
.chart-dot{font-size:1.1rem;line-height:1;opacity:.9}
.chart-dot.empty{font-size:.6rem;opacity:.3}
.chart-bar{display:inline-block;width:14px;border-radius:2px;background:var(--accent);min-height:2px;transition:height .3s}

/* Timeline */
.timeline-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);margin-bottom:1.5rem;text-align:center}
.day-group{margin-bottom:2rem}
.day-date{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;padding-bottom:.3rem;border-bottom:1px solid var(--border)}
.entry-card{background:var(--s);border:1px solid var(--border);padding:1rem 1.2rem;margin-bottom:.5rem;position:relative;transition:border-color .3s}
.entry-card:hover{border-color:var(--accent)}
.entry-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.4}
.entry-head{display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem}
.entry-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent)}
.entry-mood-emoji{font-size:1.2rem}
.entry-mood-label{font-family:'Cormorant Garamond',serif;font-size:.9rem;color:var(--text);font-style:italic}
.entry-note{font-family:'Cormorant Garamond',serif;font-size:.9rem;color:var(--text);line-height:1.7;font-style:italic;margin-top:.3rem}
.entry-trad{font-family:'Cormorant Garamond',serif;font-size:.8rem;color:var(--muted);line-height:1.6;font-style:italic;margin-top:.2rem;opacity:.7}
.entry-footer-row{display:flex;justify-content:space-between;align-items:center;margin-top:.4rem}
.entry-time{font-size:.45rem;color:var(--muted)}
.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}

.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .stats-bar{gap:1rem}
    .stat-num{font-size:1.4rem}
    .mood-grid{grid-template-columns:repeat(4,1fr);gap:.4rem}
    .mood-option .mood-emoji{font-size:1.3rem}
    .today-pair{grid-template-columns:1fr}
    .chart-wrap{padding:.6rem}
    .chart-dot{font-size:.9rem}
    .chart-name{font-size:.4rem;width:45px}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Notre Humeur','Наше Настроение') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <!-- Stats -->
    <div class="stats-bar">
        <div>
            <div class="stat-num"><?= $avgLabel ?></div>
            <div class="stat-label"><?= t('cette semaine','на этой неделе') ?></div>
        </div>
        <div class="stat-divider"></div>
        <div>
            <div class="stat-num"><?= $streak ?></div>
            <div class="stat-label"><?= t('jours de suite','дней подряд') ?></div>
        </div>
    </div>

    <!-- Mood selector -->
    <div class="mood-section">
        <div class="mood-prompt"><?= t('Comment te sens-tu aujourd\'hui ?','Как ты себя чувствуешь сегодня?') ?></div>
        <div class="mood-sub"><?= t('Choisis ton humeur et ajoute un mot si tu veux...','Выбери настроение и добавь слово, если хочешь...') ?></div>

        <?php if ($myMood): ?>
        <div class="already-box">
            <div class="already-emoji"><?= $moods[$myMood['mood']]['emoji'] ?></div>
            <div class="already-mood"><?= $lang === 'ru' ? $moods[$myMood['mood']]['ru'] : $moods[$myMood['mood']]['fr'] ?></div>
            <?php if ($myMood['note']): ?>
            <div class="already-note-text">&laquo; <?= h($myMood['note']) ?> &raquo;</div>
            <?php endif; ?>
        </div>
        <div style="text-align:center;margin-bottom:1rem">
            <button class="btn" onclick="document.getElementById('editForm').style.display='block';this.style.display='none'"><?= t('Modifier','Изменить') ?></button>
        </div>
        <div id="editForm" style="display:none">
        <?php endif; ?>

        <div class="mood-grid">
            <?php foreach ($moods as $key => $m): ?>
            <button class="mood-option <?= ($myMood && $myMood['mood'] === $key) ? 'selected' : '' ?>" data-mood="<?= $key ?>" onclick="selectMood(this,'<?= $key ?>')">
                <span class="mood-emoji"><?= $m['emoji'] ?></span>
                <span class="mood-label"><?= $lang === 'ru' ? $m['ru'] : $m['fr'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="note-label"><?= t('Note (optionnel)','Заметка (необязательно)') ?></div>
        <textarea class="note-area" id="noteText" maxlength="300"
            placeholder="<?= t('Un mot sur ta journée...','Слово о твоём дне...') ?>"><?= $myMood && $myMood['note'] ? h($myMood['note']) : '' ?></textarea>
        <div class="save-row">
            <span class="char-count"><span id="charCount">0</span>/300</span>
            <button class="btn filled" id="saveBtn" onclick="saveMood()" disabled><?= t('Enregistrer','Сохранить') ?></button>
        </div>
        <div class="success-msg" id="successMsg"><?= t('Humeur enregistrée','Настроение сохранено') ?></div>

        <?php if ($myMood): ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Both moods side by side -->
    <?php if ($myMood && $partnerMood): ?>
    <div class="today-emoji"><?= t('🌈','🌈') ?></div>
    <div class="today-label"><?= t('Nos humeurs aujourd\'hui','Наши настроения сегодня') ?></div>
    <div class="today-pair">
        <?php
        $todayCards = [
            ['name' => $user['display_name'], 'data' => $myMood],
            ['name' => $partnerMood['display_name'], 'data' => $partnerMood],
        ];
        foreach ($todayCards as $tc):
            $m = $moods[$tc['data']['mood']];
            $noteLang = $tc['data']['note_lang'] ?? 'fr';
            $notePrimary = ($lang !== $noteLang && $tc['data']['note_translated']) ? $tc['data']['note_translated'] : ($tc['data']['note'] ?? '');
            $noteSecondary = ($lang !== $noteLang && $tc['data']['note_translated']) ? ($tc['data']['note'] ?? '') : ($tc['data']['note_translated'] ?? '');
        ?>
        <div class="today-card">
            <div class="card-author"><?= h($tc['name']) ?></div>
            <div class="card-emoji"><?= $m['emoji'] ?></div>
            <div class="card-mood"><?= $lang === 'ru' ? $m['ru'] : $m['fr'] ?></div>
            <?php if ($notePrimary): ?>
            <div class="card-note">&laquo; <?= h($notePrimary) ?> &raquo;</div>
            <?php endif; ?>
            <?php if ($noteSecondary && $noteSecondary !== $notePrimary): ?>
            <div class="card-note-trad">&laquo; <?= h($noteSecondary) ?> &raquo;</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Mood chart: 14 days -->
    <div class="chart-section">
        <div class="chart-title"><?= t('Nos 14 derniers jours','Наши последние 14 дней') ?></div>
        <div class="chart-wrap">
            <table class="chart-table">
                <!-- Partner 1 (me) row -->
                <tr>
                    <td class="chart-name"><?= h(mb_substr($user['display_name'], 0, 8)) ?></td>
                    <?php foreach ($chartDays as $cd):
                        $m = $chartData[$cd][$user['id']] ?? null;
                    ?>
                    <td><span class="chart-dot <?= $m ? '' : 'empty' ?>"><?= $m ? $moods[$m]['emoji'] : '·' ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <!-- Partner 2 row -->
                <?php if ($partner): ?>
                <tr>
                    <td class="chart-name"><?= h(mb_substr($partner['display_name'], 0, 8)) ?></td>
                    <?php foreach ($chartDays as $cd):
                        $m = $chartData[$cd][$partner['id']] ?? null;
                    ?>
                    <td><span class="chart-dot <?= $m ? '' : 'empty' ?>"><?= $m ? $moods[$m]['emoji'] : '·' ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <?php endif; ?>
                <!-- Date labels -->
                <tr>
                    <td></td>
                    <?php foreach ($chartDays as $cd): ?>
                    <td class="chart-day"><?= date('d', strtotime($cd)) ?></td>
                    <?php endforeach; ?>
                </tr>
            </table>
        </div>
    </div>

    <!-- Timeline -->
    <?php if (!empty($grouped)): ?>
    <div class="timeline-title"><?= t('Historique','История') ?></div>
    <?php foreach ($grouped as $date => $dayEntries): ?>
    <div class="day-group">
        <div class="day-date">
            <?php
            $d = new DateTime($date);
            $yesterday = (new DateTime($today))->modify('-1 day');
            if ($date === $today) echo t('Aujourd\'hui','Сегодня');
            elseif ($date === $yesterday->format('Y-m-d')) echo t('Hier','Вчера');
            else echo $d->format('d/m/Y');
            ?>
        </div>
        <?php foreach ($dayEntries as $entry):
            $em = $moods[$entry['mood']] ?? null;
            if (!$em) continue;
            $noteLang = $entry['note_lang'] ?? 'fr';
            $showNote = $entry['note'] ?? '';
            $showNoteTrad = $entry['note_translated'] ?? '';
            if ($lang !== $noteLang && $showNoteTrad) {
                $primaryNote = $showNoteTrad;
                $secondaryNote = $showNote;
            } else {
                $primaryNote = $showNote;
                $secondaryNote = $showNoteTrad;
            }
        ?>
        <div class="entry-card">
            <div class="entry-head">
                <span class="entry-mood-emoji"><?= $em['emoji'] ?></span>
                <span class="entry-author"><?= h($entry['display_name']) ?></span>
                <span class="entry-mood-label"><?= $lang === 'ru' ? $em['ru'] : $em['fr'] ?></span>
            </div>
            <?php if ($primaryNote): ?>
            <div class="entry-note">&laquo; <?= h($primaryNote) ?> &raquo;</div>
            <?php endif; ?>
            <?php if ($secondaryNote && $secondaryNote !== $primaryNote): ?>
            <div class="entry-trad">&laquo; <?= h($secondaryNote) ?> &raquo;</div>
            <?php endif; ?>
            <div class="entry-footer-row">
                <div class="entry-time"><?= date('H:i', strtotime($entry['created_at'])) ?></div>
                <button class="heart-btn <?= isset($myReactions[$entry['id']]) ? 'liked' : '' ?>"
                    onclick="toggleHeart(this,'mood',<?= $entry['id'] ?>)">
                    <?= isset($myReactions[$entry['id']]) ? '❤️' : '🤍' ?>
                    <span class="heart-count"><?= $reactionCounts[$entry['id']] ?? '' ?></span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty"><?= t('Aucune humeur encore... Soyez les premiers !','Настроений пока нет... Будьте первыми!') ?></div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
let selectedMood = <?= $myMood ? json_encode($myMood['mood']) : 'null' ?>;
const noteArea = document.getElementById('noteText');
const charCount = document.getElementById('charCount');
const saveBtn = document.getElementById('saveBtn');

charCount.textContent = noteArea.value.length;
noteArea.addEventListener('input', () => {
    charCount.textContent = noteArea.value.length;
});

function selectMood(el, mood) {
    document.querySelectorAll('.mood-option').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    selectedMood = mood;
    saveBtn.disabled = false;
}

if (selectedMood) saveBtn.disabled = false;

function saveMood() {
    if (!selectedMood) return;
    saveBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('mood', selectedMood);
    fd.append('note', noteArea.value.trim());
    fetch(BASE + '/mood_tracker.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('successMsg').style.display = 'block';
                setTimeout(() => location.reload(), 1200);
            } else {
                saveBtn.disabled = false;
            }
        })
        .catch(() => { saveBtn.disabled = false; });
}

function toggleHeart(btn, type, id) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('item_type', type);
    fd.append('item_id', id);
    fetch(BASE + '/api/react.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                btn.querySelector('.heart-count').textContent = data.count || '';
                btn.childNodes[0].textContent = data.liked ? '❤️' : '🤍';
                if (data.liked) btn.classList.add('liked');
            }
        });
}
</script>
<?php include __DIR__."/includes/bottom_nav.php"; ?>
</body>
</html>
