<?php
/**
 * NATACHA — Nos Rêves & Projets
 * Voyages, expériences, objectifs (semaine/mois/année)
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
try { db()->query("SELECT 1 FROM couple_dreams LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_journals.sql'));
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cat = $_POST['category'] ?? '';
        $content = trim($_POST['content'] ?? '');
        $validCats = ['voyage','experience','goal_week','goal_month','goal_year'];
        if (in_array($cat, $validCats) && $content && mb_strlen($content) <= 300) {
            $stmt = db()->prepare("INSERT INTO couple_dreams (couple_id, user_id, category, content) VALUES (?,?,?,?)");
            $stmt->execute([$coupleId, $user['id'], $cat, $content]);

            require_once __DIR__.'/includes/couple_helper.php';
            $ce = new CoupleEntity(db());
            $ce->recordActivity($coupleId, $user['id'], 'calendrier',
                $user['display_name'].' a ajouté un rêve/projet',
                $user['display_name'].' added a dream/plan');

            echo json_encode(['ok' => true, 'id' => db()->lastInsertId()]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid data']);
        }
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = db()->prepare("UPDATE couple_dreams SET is_done = NOT is_done, done_at = IF(is_done=0, NOW(), NULL) WHERE id=? AND couple_id=?");
            $stmt->execute([$id, $coupleId]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = db()->prepare("DELETE FROM couple_dreams WHERE id=? AND couple_id=? AND user_id=?");
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

// Categories config
$categories = [
    'voyage'     => ['icon' => '✈️', 'label' => t('Voyages','Путешествия'), 'color' => '#6e9dc9'],
    'experience' => ['icon' => '🌟', 'label' => t('Expériences','Впечатления'), 'color' => '#c96ec9'],
    'goal_week'  => ['icon' => '📌', 'label' => t('Cette semaine','На этой неделе'), 'color' => '#6ec98a'],
    'goal_month' => ['icon' => '🎯', 'label' => t('Ce mois','В этом месяце'), 'color' => '#c9a96e'],
    'goal_year'  => ['icon' => '⭐', 'label' => t('Cette année','В этом году'), 'color' => '#c96e6e'],
];

$currentCat = $_GET['cat'] ?? 'all';
if ($currentCat !== 'all' && !isset($categories[$currentCat])) $currentCat = 'all';

// Fetch dreams
$where = 'WHERE d.couple_id=?';
$params = [$coupleId];
if ($currentCat !== 'all') {
    $where .= ' AND d.category=?';
    $params[] = $currentCat;
}

$dreams = db()->prepare("SELECT d.*, u.display_name FROM couple_dreams d
    JOIN users u ON u.id=d.user_id $where ORDER BY d.is_done ASC, d.created_at DESC");
$dreams->execute($params);
$dreams = $dreams->fetchAll();

// Stats
$totalDreams = db()->prepare("SELECT COUNT(*) FROM couple_dreams WHERE couple_id=?");
$totalDreams->execute([$coupleId]);
$totalDreams = $totalDreams->fetchColumn();

$doneDreams = db()->prepare("SELECT COUNT(*) FROM couple_dreams WHERE couple_id=? AND is_done=1");
$doneDreams->execute([$coupleId]);
$doneDreams = $doneDreams->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Nos Rêves','Наши Мечты') ?></title>
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
.wrap{max-width:650px;margin:0 auto;padding:2rem 1.5rem}

/* Header */
.page-header{text-align:center;margin-bottom:2rem}
.page-header h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:var(--accent);margin-bottom:.5rem}
.page-header p{font-size:.6rem;color:var(--muted);letter-spacing:.08em}

/* Stats */
.stats-bar{display:flex;justify-content:center;gap:2rem;margin-bottom:2rem;padding:1rem;border:1px solid var(--border);background:var(--s)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--accent);text-align:center;line-height:1}
.stat-label{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:.3rem}
.stat-divider{width:1px;height:35px;background:var(--border);align-self:center}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.5rem;justify-content:center}
.fbtn{font-size:.55rem;letter-spacing:.08em;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.35rem .6rem;cursor:pointer;text-decoration:none;transition:all .2s}
.fbtn:hover,.fbtn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Add form */
.add-section{margin-bottom:2rem}
.add-toggle{width:100%;font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);cursor:pointer;border:1px dashed var(--border);padding:.7rem;text-align:center;transition:all .2s;background:transparent;font-family:'DM Mono',monospace}
.add-toggle:hover{border-color:var(--accent);color:var(--accent)}
.add-form{display:none;margin-top:1rem;border:1px solid var(--border);padding:1.2rem;background:var(--s)}
.add-form.open{display:block}
.field{margin-bottom:.8rem}
.field label{display:block;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.field input,.field select,.field textarea{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);padding:.5rem .7rem;font-family:'DM Mono',monospace;font-size:.7rem;outline:none;transition:border .2s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent)}
.field select option{background:var(--bg);color:var(--text)}
.field textarea{min-height:70px;resize:vertical;font-family:'Cormorant Garamond',serif;font-size:.9rem;line-height:1.6}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.45rem 1rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}

/* Dream cards */
.dream-card{display:flex;align-items:flex-start;gap:.8rem;background:var(--s);border:1px solid var(--border);padding:1rem;margin-bottom:.5rem;transition:all .3s;position:relative}
.dream-card:hover{border-color:var(--accent)}
.dream-card.done{opacity:.5}
.dream-card.done .dream-text{text-decoration:line-through}
.dream-check{width:20px;height:20px;border:1px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem;transition:all .2s;font-size:.7rem;background:transparent;color:transparent}
.dream-check:hover{border-color:var(--accent)}
.dream-card.done .dream-check{border-color:var(--accent);color:var(--accent);background:var(--as)}
.dream-body{flex:1;min-width:0}
.dream-cat{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;margin-bottom:.3rem;display:inline-block;padding:.1rem .4rem;border:1px solid}
.dream-text{font-family:'Cormorant Garamond',serif;font-size:.95rem;color:var(--text);line-height:1.6}
.dream-meta{font-size:.45rem;color:var(--muted);margin-top:.3rem}
.dream-delete{position:absolute;top:.5rem;right:.5rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem;opacity:0;transition:opacity .2s}
.dream-card:hover .dream-delete{opacity:1}
.dream-delete:hover{color:#c96e6e}

.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/couple.php">&larr; <?= t('Retour','Назад') ?></a>
    <div class="topbar-title"><?= t('Nos Rêves','Наши Мечты') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <div class="page-header">
        <h1><?= t('Rêves & Projets','Мечты и Планы') ?></h1>
        <p><?= t('Voyages, expériences, objectifs... Tout ce qu\'on veut vivre ensemble','Путешествия, впечатления, цели... Всё, что мы хотим пережить вместе') ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div>
            <div class="stat-num"><?= $totalDreams ?></div>
            <div class="stat-label"><?= t('rêves','мечт') ?></div>
        </div>
        <div class="stat-divider"></div>
        <div>
            <div class="stat-num"><?= $doneDreams ?></div>
            <div class="stat-label"><?= t('réalisés','исполнено') ?></div>
        </div>
        <div class="stat-divider"></div>
        <div>
            <div class="stat-num"><?= $totalDreams ? round(($doneDreams/$totalDreams)*100) : 0 ?>%</div>
            <div class="stat-label"><?= t('accomplis','выполнено') ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <a class="fbtn <?= $currentCat==='all'?'active':'' ?>" href="?cat=all"><?= t('Tous','Все') ?></a>
        <?php foreach ($categories as $key => $cat): ?>
        <a class="fbtn <?= $currentCat===$key?'active':'' ?>" href="?cat=<?= $key ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Add -->
    <div class="add-section">
        <button class="add-toggle" onclick="document.getElementById('addForm').classList.toggle('open')">+ <?= t('Ajouter un rêve / projet','Добавить мечту / проект') ?></button>
        <div class="add-form" id="addForm">
            <div class="field">
                <label><?= t('Catégorie','Категория') ?></label>
                <select id="newCat">
                    <?php foreach ($categories as $key => $cat): ?>
                    <option value="<?= $key ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label><?= t('Votre rêve / projet','Ваша мечта / проект') ?></label>
                <textarea id="newContent" placeholder="<?= t('Ex: Voir les aurores boréales en Islande...','Напр: Увидеть северное сияние в Исландии...') ?>"></textarea>
            </div>
            <button class="btn" onclick="addDream()"><?= t('Ajouter','Добавить') ?></button>
        </div>
    </div>

    <!-- Dreams list -->
    <?php if (!empty($dreams)): ?>
    <?php foreach ($dreams as $dream):
        $cat = $categories[$dream['category']] ?? $categories['experience'];
    ?>
    <div class="dream-card <?= $dream['is_done'] ? 'done' : '' ?>" id="dream-<?= $dream['id'] ?>">
        <button class="dream-check" onclick="toggleDream(<?= $dream['id'] ?>)"><?= $dream['is_done'] ? '✓' : '' ?></button>
        <div class="dream-body">
            <span class="dream-cat" style="color:<?= $cat['color'] ?>;border-color:<?= $cat['color'] ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></span>
            <div class="dream-text"><?= h($dream['content']) ?></div>
            <div class="dream-meta"><?= h($dream['display_name']) ?> · <?= date('d/m/Y', strtotime($dream['created_at'])) ?><?= $dream['is_done'] && $dream['done_at'] ? ' · ✓ '.date('d/m/Y', strtotime($dream['done_at'])) : '' ?></div>
        </div>
        <?php if ($dream['user_id'] == $user['id']): ?>
        <button class="dream-delete" onclick="deleteDream(<?= $dream['id'] ?>)">✕</button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty"><?= t('Aucun rêve encore... Commencez à rêver ensemble !','Мечт пока нет... Начните мечтать вместе!') ?></div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;

function addDream() {
    const cat = document.getElementById('newCat').value;
    const content = document.getElementById('newContent').value.trim();
    if (!content) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'add');
    fd.append('category', cat);
    fd.append('content', content);
    fetch(BASE + '/reves_projets.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}

function toggleDream(id) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'toggle');
    fd.append('id', id);
    fetch(BASE + '/reves_projets.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}

function deleteDream(id) {
    if (!confirm(<?= json_encode(t('Supprimer ce rêve ?','Удалить эту мечту?')) ?>)) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch(BASE + '/reves_projets.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}
</script>
</body>
</html>
