<?php
/**
 * NATACHA — Le Jardin Secret
 * Livre intime des désirs du couple
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
try { db()->query("SELECT 1 FROM livre_desirs LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS livre_desirs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        couple_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        mood ENUM('doux','intense','fou','secret') DEFAULT 'doux',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_couple_date (couple_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { db()->query("SELECT content_translated FROM livre_desirs LIMIT 1"); } catch (Exception $e) {
    db()->exec("ALTER TABLE livre_desirs ADD COLUMN content_translated TEXT DEFAULT NULL, ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'");
}

// Moods config
$moods = [
    'doux'    => ['icon' => '🌸', 'label' => t('Doux','Нежное'),     'color' => '#c9a96e'],
    'intense' => ['icon' => '🔥', 'label' => t('Intense','Страстное'), 'color' => '#c96e6e'],
    'fou'     => ['icon' => '🤪', 'label' => t('Fou','Безумное'),      'color' => '#c96ec9'],
    'secret'  => ['icon' => '🤫', 'label' => t('Secret','Секретное'),   'color' => '#6e9dc9'],
];

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? 'add';

    if ($action === 'add') {
        $content = trim($_POST['content'] ?? '');
        $mood = $_POST['mood'] ?? 'doux';
        if (!isset($moods[$mood])) $mood = 'doux';
        if ($content && mb_strlen($content) <= 1000) {
            $stmt = db()->prepare("INSERT INTO livre_desirs (couple_id, user_id, content, mood) VALUES (?,?,?,?)");
            $stmt->execute([$coupleId, $user['id'], $content, $mood]);

            // Record couple activity (once per day)
            $today = date('Y-m-d');
            $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='mot' AND DATE(created_at)=? AND description_fr LIKE '%jardin%'");
            $already->execute([$coupleId, $user['id'], $today]);
            if (!$already->fetch()) {
                require_once __DIR__.'/includes/couple_helper.php';
                $ce = new CoupleEntity(db());
                $ce->recordActivity($coupleId, $user['id'], 'mot',
                    $user['display_name'].' a écrit dans le jardin secret',
                    $user['display_name'].' написал(а) в тайный сад');
            }

            $newId = db()->lastInsertId();
            // Auto-translate
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($content, $fromLang, $toLang);
            if ($translated) {
                db()->prepare("UPDATE livre_desirs SET content_translated=?, content_lang=? WHERE id=?")
                    ->execute([$translated, $fromLang, $newId]);
            }
            echo json_encode(['ok' => true, 'id' => $newId]);
        } else {
            echo json_encode(['ok' => false, 'error' => t('Contenu invalide','Недействительное содержание')]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = db()->prepare("DELETE FROM livre_desirs WHERE id=? AND couple_id=? AND user_id=?");
            $stmt->execute([$id, $coupleId, $user['id']]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// Filter
$moodFilter = $_GET['mood'] ?? 'all';
if ($moodFilter !== 'all' && !isset($moods[$moodFilter])) $moodFilter = 'all';

$where = 'WHERE l.couple_id=?';
$params = [$coupleId];
if ($moodFilter !== 'all') {
    $where .= ' AND l.mood=?';
    $params[] = $moodFilter;
}

$entries = db()->prepare("SELECT l.*, u.display_name, u.avatar FROM livre_desirs l
    JOIN users u ON u.id=l.user_id $where ORDER BY l.created_at DESC LIMIT 50");
$entries->execute($params);
$entries = $entries->fetchAll();

$reactionCounts = [];
$myReactions = [];
if (!empty($entries)) {
    $eids = array_column($entries, 'id');
    $ph = implode(',', array_fill(0, count($eids), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='livre_secret' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($eids);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];
        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='livre_secret' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $eids));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

$totalEntries = db()->prepare("SELECT COUNT(*) FROM livre_desirs WHERE couple_id=?");
$totalEntries->execute([$coupleId]);
$totalEntries = $totalEntries->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Le Jardin Secret','Тайный Сад') ?></title>
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

/* Header */
.page-header{text-align:center;margin-bottom:2.5rem}
.page-header .icon{font-size:3rem;margin-bottom:.8rem}
.page-header h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.page-header p{font-size:.6rem;color:var(--muted);letter-spacing:.08em;line-height:1.8;max-width:400px;margin:0 auto}
.page-header .disclaimer{margin-top:.8rem;font-size:.5rem;color:var(--muted);opacity:.6;border:1px dashed var(--border);padding:.4rem .8rem;display:inline-block}

/* Write */
.write-card{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.write-label{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);margin-bottom:1rem;text-align:center}
.write-area{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:.95rem;padding:1rem;min-height:120px;resize:vertical;outline:none;transition:border .3s;line-height:1.7}
.write-area:focus{border-color:var(--accent)}
.write-area::placeholder{color:var(--muted);font-style:italic}

.mood-picker{display:flex;gap:.5rem;margin:1rem 0;justify-content:center;flex-wrap:wrap}
.mood-btn{font-size:.6rem;letter-spacing:.08em;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.35rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.mood-btn:hover,.mood-btn.active{background:var(--as)}
.write-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.8rem}
.char-count{font-size:.5rem;color:var(--muted)}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:var(--accent);border:1px solid var(--accent);color:var(--bg);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{opacity:.85}
.btn:disabled{opacity:.4;cursor:not-allowed}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.5rem;justify-content:center}
.fbtn{font-size:.55rem;letter-spacing:.08em;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .6rem;cursor:pointer;text-decoration:none;transition:all .2s}
.fbtn:hover,.fbtn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Entries */
.entry{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.8rem;position:relative;transition:all .3s;animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.entry:hover{border-color:var(--accent)}
.entry-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem}
.entry-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent)}
.entry-mood{font-size:.55rem;padding:.15rem .5rem;border:1px solid;display:inline-flex;align-items:center;gap:.3rem}
.entry-content{font-family:'Cormorant Garamond',serif;font-size:.95rem;color:var(--text);line-height:1.8;font-style:italic;white-space:pre-line}
.entry-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.6rem}
.entry-date{font-size:.45rem;color:var(--muted)}
.entry-delete{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.6rem;opacity:0;transition:opacity .2s}
.entry:hover .entry-delete{opacity:1}
.entry-delete:hover{color:#c96e6e}

.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}
.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}
.counter{text-align:center;font-size:.5rem;color:var(--muted);letter-spacing:.1em;margin-bottom:1.5rem}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Le Jardin Secret','Тайный Сад') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <div class="page-header">
        <div class="icon">🌹</div>
        <h1><?= t('Le Jardin Secret','Тайный Сад') ?></h1>
        <p><?= t(
            'Un espace intime rien qu\'à vous deux. Écrivez vos envies, vos fantasmes, vos pensées inavouables... Sans filtre, sans jugement.',
            'Интимное пространство только для вас двоих. Пишите свои желания, фантазии, тайные мысли... Без фильтров, без осуждения.'
        ) ?></p>
        <div class="disclaimer">🔒 <?= t('Visible uniquement par votre couple','Видно только вашей паре') ?></div>
    </div>

    <!-- Write -->
    <div class="write-card">
        <div class="write-label"><?= t('Écrire un désir...','Написать желание...') ?></div>
        <textarea class="write-area" id="contentArea" maxlength="1000"
            placeholder="<?= t('J\'aimerais qu\'on essaie de...','Я бы хотел(а), чтобы мы попробовали...') ?>"></textarea>

        <div class="mood-picker">
            <?php foreach ($moods as $key => $m): ?>
            <button class="mood-btn <?= $key==='doux'?'active':'' ?>" data-mood="<?= $key ?>"
                onclick="selectMood('<?= $key ?>')" style="<?= $key==='doux'?'border-color:'.$m['color'].';color:'.$m['color']:'' ?>">
                <?= $m['icon'] ?> <?= $m['label'] ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="write-footer">
            <span class="char-count"><span id="charCount">0</span>/1000</span>
            <button class="btn" id="saveBtn" onclick="saveEntry()" disabled><?= t('Publier','Опубликовать') ?></button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <a class="fbtn <?= $moodFilter==='all'?'active':'' ?>" href="?mood=all"><?= t('Tous','Все') ?></a>
        <?php foreach ($moods as $key => $m): ?>
        <a class="fbtn <?= $moodFilter===$key?'active':'' ?>" href="?mood=<?= $key ?>"><?= $m['icon'] ?> <?= $m['label'] ?></a>
        <?php endforeach; ?>
    </div>

    <div class="counter"><?= $totalEntries ?> <?= t('désirs partagés','желаний') ?></div>

    <!-- Entries -->
    <?php if (!empty($entries)): ?>
    <?php foreach ($entries as $entry):
        $m = $moods[$entry['mood']] ?? $moods['doux'];
    ?>
    <div class="entry" id="entry-<?= $entry['id'] ?>">
        <div class="entry-header">
            <span class="entry-author"><?= h($entry['display_name']) ?></span>
            <span class="entry-mood" style="color:<?= $m['color'] ?>;border-color:<?= $m['color'] ?>"><?= $m['icon'] ?> <?= $m['label'] ?></span>
        </div>
        <?php
        $eLang = $entry['content_lang'] ?? 'fr';
        $ePrimary = ($lang !== $eLang && !empty($entry['content_translated'])) ? $entry['content_translated'] : $entry['content'];
        $eSecondary = ($lang !== $eLang && !empty($entry['content_translated'])) ? $entry['content'] : ($entry['content_translated'] ?? '');
        ?>
        <div class="entry-content"><?= h($ePrimary) ?></div>
        <?php if ($eSecondary && $eSecondary !== $ePrimary): ?>
        <div style="font-family:'Cormorant Garamond',serif;font-size:.8rem;color:var(--muted);font-style:italic;margin-top:.2rem;opacity:.7"><?= h($eSecondary) ?></div>
        <?php endif; ?>
        <div class="entry-footer">
            <span class="entry-date"><?= date('d/m/Y H:i', strtotime($entry['created_at'])) ?></span>
            <div style="display:flex;align-items:center;gap:.5rem">
                <button class="heart-btn <?= isset($myReactions[$entry['id']]) ? 'liked' : '' ?>"
                    onclick="toggleHeart(this,'livre_secret',<?= $entry['id'] ?>)">
                    <?= isset($myReactions[$entry['id']]) ? '❤️' : '🤍' ?>
                    <span class="heart-count"><?= $reactionCounts[$entry['id']] ?? '' ?></span>
                </button>
                <?php if ($entry['user_id'] == $user['id']): ?>
                <button class="entry-delete" onclick="deleteEntry(<?= $entry['id'] ?>)">✕ <?= t('supprimer','удалить') ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty"><?= t('Aucun désir encore... Osez écrire le premier !','Желаний пока нет... Осмельтесь написать первое!') ?></div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
let selectedMood = 'doux';
const MOOD_COLORS = <?= json_encode(array_map(fn($m) => $m['color'], $moods)) ?>;

const textarea = document.getElementById('contentArea');
const charCount = document.getElementById('charCount');
const saveBtn = document.getElementById('saveBtn');

textarea.addEventListener('input', () => {
    charCount.textContent = textarea.value.length;
    saveBtn.disabled = textarea.value.trim().length < 3;
});

function selectMood(mood) {
    selectedMood = mood;
    document.querySelectorAll('.mood-btn').forEach(btn => {
        const m = btn.dataset.mood;
        const isActive = m === mood;
        btn.classList.toggle('active', isActive);
        btn.style.borderColor = isActive ? MOOD_COLORS[m] : '';
        btn.style.color = isActive ? MOOD_COLORS[m] : '';
    });
}

function saveEntry() {
    const content = textarea.value.trim();
    if (!content) return;
    saveBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'add');
    fd.append('content', content);
    fd.append('mood', selectedMood);
    fetch(BASE + '/livre_secret.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); else saveBtn.disabled = false; })
        .catch(() => { saveBtn.disabled = false; });
}

function deleteEntry(id) {
    if (!confirm(<?= json_encode(t('Supprimer ce désir ?','Удалить это желание?')) ?>)) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch(BASE + '/livre_secret.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const el = document.getElementById('entry-' + id);
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 300);
            }
        });
}

textarea.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !saveBtn.disabled) saveEntry();
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
            }
        });
}
</script>
</body>
</html>
