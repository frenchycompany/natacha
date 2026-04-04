<?php
/**
 * NATACHA — Merci pour...
 * Journal de gratitude quotidien du couple
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];
// Refresh couple_id from DB (session may be stale)
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

// Ensure table
try { db()->query("SELECT 1 FROM gratitude_entries LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS gratitude_entries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        couple_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        entry_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_date (user_id, entry_date),
        INDEX idx_couple_date (couple_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { db()->query("SELECT content_translated FROM gratitude_entries LIMIT 1"); } catch (Exception $e) {
    db()->exec("ALTER TABLE gratitude_entries ADD COLUMN content_translated TEXT DEFAULT NULL, ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'");
}

// POST: add entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $content = trim($_POST['content'] ?? '');
    if ($content && mb_strlen($content) <= 500) {
        $today = date('Y-m-d');
        $stmt = db()->prepare("INSERT INTO gratitude_entries (couple_id, user_id, content, entry_date)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE content=?, created_at=CURRENT_TIMESTAMP");
        $stmt->execute([$coupleId, $user['id'], $content, $today, $content]);

        // Auto-translate
        $fromLang = $lang === 'ru' ? 'ru' : 'fr';
        $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
        $translated = translateText($content, $fromLang, $toLang);
        if ($translated) {
            db()->prepare("UPDATE gratitude_entries SET content_translated=?, content_lang=? WHERE user_id=? AND entry_date=?")
                ->execute([$translated, $fromLang, $user['id'], $today]);
        }

        // Record couple activity
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        // Only award XP once per day
        $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='gratitude' AND DATE(created_at)=?");
        $already->execute([$coupleId, $user['id'], $today]);
        if (!$already->fetch()) {
            $ce->recordActivity($coupleId, $user['id'], 'mot',
                $user['display_name'].' a écrit sa gratitude du jour',
                $user['display_name'].' написал(а) благодарность дня');
        }
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => t('Contenu invalide','Недействительное содержание')]);
    }
    exit;
}

// GET: load entries
$today = date('Y-m-d');

// Today's entry for current user
$myEntry = db()->prepare("SELECT * FROM gratitude_entries WHERE user_id=? AND entry_date=?");
$myEntry->execute([$user['id'], $today]);
$myEntry = $myEntry->fetch();

// All entries (both users)
$entries = db()->prepare("SELECT g.*, u.display_name, u.avatar FROM gratitude_entries g
    JOIN users u ON u.id=g.user_id WHERE g.couple_id=? ORDER BY g.entry_date DESC, g.created_at DESC LIMIT 60");
$entries->execute([$coupleId]);
$entries = $entries->fetchAll();

// Load reactions
$reactionCounts = [];
$myReactions = [];
if (!empty($entries)) {
    $ids = array_column($entries, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));

    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='gratitude' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($ids);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];

        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='gratitude' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $ids));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

// Group by date
$grouped = [];
foreach ($entries as $e) {
    $grouped[$e['entry_date']][] = $e;
}

// Stats
$totalEntries = db()->prepare("SELECT COUNT(*) FROM gratitude_entries WHERE couple_id=?");
$totalEntries->execute([$coupleId]);
$totalEntries = $totalEntries->fetchColumn();

$streak = 0;
$checkDate = new DateTime($today);
while (true) {
    $d = $checkDate->format('Y-m-d');
    $has = db()->prepare("SELECT 1 FROM gratitude_entries WHERE couple_id=? AND entry_date=?");
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
<title>Natacha — <?= t('Merci pour...','Спасибо за...') ?></title>
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
.wrap{max-width:600px;margin:0 auto;padding:2rem 1.5rem}

/* Stats */
.stats-bar{display:flex;justify-content:center;gap:2rem;margin-bottom:2rem;padding:1rem;border:1px solid var(--border);background:var(--s)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--accent);text-align:center;line-height:1}
.stat-label{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:.3rem}
.stat-divider{width:1px;height:40px;background:var(--border);align-self:center}

/* Write section */
.write-section{margin-bottom:2.5rem}
.write-prompt{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;font-style:italic;color:var(--accent);text-align:center;margin-bottom:1.2rem}
.write-sub{text-align:center;font-size:.6rem;color:var(--muted);margin-bottom:1rem;letter-spacing:.08em}
.write-area{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1rem;padding:1.2rem;min-height:100px;resize:vertical;outline:none;transition:border .3s;line-height:1.7}
.write-area:focus{border-color:var(--accent)}
.write-area::placeholder{color:var(--muted);font-style:italic}
.write-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.6rem}
.char-count{font-size:.5rem;color:var(--muted)}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn.filled{background:var(--accent);color:var(--bg)}
.btn.filled:hover{opacity:.85}
.success-msg{text-align:center;color:var(--accent);font-size:.7rem;margin-top:.8rem;display:none}
.already-written{text-align:center;padding:1.5rem;border:1px solid var(--border);background:var(--s);margin-bottom:.8rem}
.already-text{font-family:'Cormorant Garamond',serif;font-size:1rem;color:var(--text);font-style:italic;line-height:1.7}
.already-label{font-size:.5rem;color:var(--accent);letter-spacing:.12em;text-transform:uppercase;margin-bottom:.5rem}

/* Timeline */
.timeline-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);margin-bottom:1.5rem;text-align:center}
.day-group{margin-bottom:2rem}
.day-date{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;padding-bottom:.3rem;border-bottom:1px solid var(--border)}
.entry-card{background:var(--s);border:1px solid var(--border);padding:1rem 1.2rem;margin-bottom:.5rem;position:relative;transition:border-color .3s}
.entry-card:hover{border-color:var(--accent)}
.entry-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.4}
.entry-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.4rem}
.entry-content{font-family:'Cormorant Garamond',serif;font-size:.95rem;color:var(--text);line-height:1.7;font-style:italic}
.entry-time{font-size:.45rem;color:var(--muted);margin-top:.4rem}

.entry-footer-row{display:flex;justify-content:space-between;align-items:center;margin-top:.4rem}
.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}

.entry-trad{font-family:'Cormorant Garamond',serif;font-size:.8rem;color:var(--muted);line-height:1.6;font-style:italic;margin-top:.2rem;opacity:.7}
.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .stats-bar{gap:1rem}
    .stat-num{font-size:1.4rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Merci pour...','Спасибо за...') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <!-- Stats -->
    <div class="stats-bar">
        <div>
            <div class="stat-num"><?= $totalEntries ?></div>
            <div class="stat-label"><?= t('mercis','благодарностей') ?></div>
        </div>
        <div class="stat-divider"></div>
        <div>
            <div class="stat-num"><?= $streak ?></div>
            <div class="stat-label"><?= t('jours de suite','дней подряд') ?></div>
        </div>
    </div>

    <!-- Write today -->
    <div class="write-section">
        <div class="write-prompt"><?= t('Aujourd\'hui, merci pour...','Сегодня, спасибо за...') ?></div>
        <div class="write-sub"><?= t('Chaque jour ou quand tu veux, écris ce pour quoi tu es reconnaissant(e)...','Каждый день или когда хочешь, напиши, за что ты благодарен(на)...') ?></div>

        <?php if ($myEntry): ?>
        <div class="already-written">
            <div class="already-label"><?= t('Ta gratitude du jour','Твоя благодарность дня') ?></div>
            <div class="already-text">&laquo; <?= h($myEntry['content']) ?> &raquo;</div>
        </div>
        <div style="text-align:center;margin-bottom:1rem">
            <button class="btn" onclick="document.getElementById('editForm').style.display='block';this.style.display='none'"><?= t('Modifier','Изменить') ?></button>
        </div>
        <div id="editForm" style="display:none">
        <?php endif; ?>

        <textarea class="write-area" id="gratitudeText" maxlength="500"
            placeholder="<?= t('Ce matin, ton sourire quand...','Сегодня утром, твоя улыбка когда...') ?>"><?= $myEntry ? h($myEntry['content']) : '' ?></textarea>
        <div class="write-footer">
            <span class="char-count"><span id="charCount">0</span>/500</span>
            <button class="btn filled" id="saveBtn" onclick="saveGratitude()" disabled><?= t('Enregistrer','Сохранить') ?></button>
        </div>
        <div class="success-msg" id="successMsg"><?= t('Merci enregistré avec amour','Благодарность сохранена с любовью') ?></div>

        <?php if ($myEntry): ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Timeline -->
    <?php if (!empty($grouped)): ?>
    <div class="timeline-title"><?= t('Nos mercis','Наши благодарности') ?></div>
    <?php foreach ($grouped as $date => $dayEntries): ?>
    <div class="day-group">
        <div class="day-date">
            <?php
            $d = new DateTime($date);
            $today_dt = new DateTime($today);
            $yesterday = (new DateTime($today))->modify('-1 day');
            if ($date === $today) echo t('Aujourd\'hui','Сегодня');
            elseif ($date === $yesterday->format('Y-m-d')) echo t('Hier','Вчера');
            else echo $d->format('d/m/Y');
            ?>
        </div>
        <?php foreach ($dayEntries as $entry): ?>
        <div class="entry-card">
            <div class="entry-author"><?= h($entry['display_name']) ?></div>
            <?php
            $entryLang = $entry['content_lang'] ?? 'fr';
            $showOriginal = $entry['content'];
            $showTranslated = $entry['content_translated'] ?? '';
            // If reader's lang matches the original, show original first
            // If not, show translation first (if available)
            if ($lang !== $entryLang && $showTranslated) {
                $primary = $showTranslated;
                $secondary = $showOriginal;
            } else {
                $primary = $showOriginal;
                $secondary = $showTranslated;
            }
            ?>
            <div class="entry-content">&laquo; <?= h($primary) ?> &raquo;</div>
            <?php if ($secondary && $secondary !== $primary): ?>
            <div class="entry-trad">&laquo; <?= h($secondary) ?> &raquo;</div>
            <?php endif; ?>
            <div class="entry-footer-row">
                <div class="entry-time"><?= date('H:i', strtotime($entry['created_at'])) ?></div>
                <button class="heart-btn <?= isset($myReactions[$entry['id']]) ? 'liked' : '' ?>"
                    onclick="toggleHeart(this,'gratitude',<?= $entry['id'] ?>)">
                    <?= isset($myReactions[$entry['id']]) ? '❤️' : '🤍' ?>
                    <span class="heart-count"><?= $reactionCounts[$entry['id']] ?? '' ?></span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty"><?= t('Aucune gratitude encore... Soyez les premiers !','Благодарностей пока нет... Будьте первыми!') ?></div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const textarea = document.getElementById('gratitudeText');
const charCount = document.getElementById('charCount');
const saveBtn = document.getElementById('saveBtn');

textarea.addEventListener('input', () => {
    charCount.textContent = textarea.value.length;
    saveBtn.disabled = textarea.value.trim().length < 2;
});
charCount.textContent = textarea.value.length;
if (textarea.value.trim().length >= 2) saveBtn.disabled = false;

function saveGratitude() {
    const content = textarea.value.trim();
    if (!content) return;
    saveBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('content', content);
    fetch(BASE + '/gratitude.php', { method: 'POST', body: fd })
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

textarea.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !saveBtn.disabled) saveGratitude();
});

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
</body>
</html>
