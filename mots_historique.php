<?php
/**
 * NATACHA — Nos Petits Mots
 * Historique complet des mots du jour échangés
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

// Ensure mots_du_jour table
try { db()->query("SELECT 1 FROM mots_du_jour LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_mots_du_jour.sql'));
}

// Get partner users
$coupleUsers = db()->prepare("SELECT id, display_name, avatar, lang FROM users WHERE couple_id=? ORDER BY id");
$coupleUsers->execute([$coupleId]);
$coupleUsers = $coupleUsers->fetchAll();
$usersById = [];
foreach ($coupleUsers as $u) $usersById[$u['id']] = $u;

// Search
$search = trim($_GET['q'] ?? '');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Count total dates (for pagination)
if ($search) {
    $countStmt = db()->prepare("SELECT COUNT(DISTINCT date_mot) FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=? AND m.message LIKE ?");
    $countStmt->execute([$coupleId, '%'.$search.'%']);
} else {
    $countStmt = db()->prepare("SELECT COUNT(DISTINCT date_mot) FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=?");
    $countStmt->execute([$coupleId]);
}
$totalDates = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalDates / $perPage));
if ($page > $totalPages) $page = $totalPages;

// Get distinct dates for this page
if ($search) {
    $datesStmt = db()->prepare("SELECT DISTINCT date_mot FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=? AND m.message LIKE ? ORDER BY date_mot DESC LIMIT ? OFFSET ?");
    $datesStmt->execute([$coupleId, '%'.$search.'%', $perPage, $offset]);
} else {
    $datesStmt = db()->prepare("SELECT DISTINCT date_mot FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=? ORDER BY date_mot DESC LIMIT ? OFFSET ?");
    $datesStmt->execute([$coupleId, $perPage, $offset]);
}
$dates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all mots for these dates
$mots = [];
$grouped = [];
if (!empty($dates)) {
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $motsStmt = db()->prepare("SELECT m.* FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=? AND m.date_mot IN ($ph) ORDER BY m.date_mot DESC, m.user_id ASC");
    $motsStmt->execute(array_merge([$coupleId], $dates));
    $mots = $motsStmt->fetchAll();
    foreach ($mots as $m) {
        $grouped[$m['date_mot']][] = $m;
    }
}

// Load reactions
$reactionCounts = [];
$myReactions = [];
if (!empty($mots)) {
    $ids = array_column($mots, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='mot_du_jour' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($ids);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];
        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='mot_du_jour' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $ids));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

// Cache translations in memory (translate on the fly)
$translations = [];
foreach ($mots as $m) {
    $authorLang = $usersById[$m['user_id']]['lang'] ?? 'fr';
    $fromLang = $authorLang === 'ru' ? 'ru' : 'fr';
    $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
    $translated = translateText($m['message'], $fromLang, $toLang);
    $translations[$m['id']] = [
        'original' => $m['message'],
        'translated' => $translated,
        'original_lang' => $fromLang,
    ];
}

// Stats
$totalMots = db()->prepare("SELECT COUNT(*) FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=?");
$totalMots->execute([$coupleId]);
$totalMots = (int)$totalMots->fetchColumn();

// Longest streak: days where BOTH partners wrote a mot
$streakStmt = db()->prepare("SELECT date_mot, COUNT(DISTINCT user_id) as cnt FROM mots_du_jour m JOIN users u ON u.id=m.user_id WHERE u.couple_id=? GROUP BY date_mot HAVING cnt >= 2 ORDER BY date_mot DESC");
$streakStmt->execute([$coupleId]);
$allBothDays = $streakStmt->fetchAll(PDO::FETCH_COLUMN);

$longestStreak = 0;
$currentStreak = 0;
$prevDate = null;
foreach ($allBothDays as $d) {
    $dt = new DateTime($d);
    if ($prevDate === null) {
        $currentStreak = 1;
    } else {
        $diff = $prevDate->diff($dt)->days;
        if ($diff === 1) {
            $currentStreak++;
        } else {
            $currentStreak = 1;
        }
    }
    if ($currentStreak > $longestStreak) $longestStreak = $currentStreak;
    $prevDate = $dt;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Nos Petits Mots','Наши Записки') ?></title>
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
.wrap{max-width:700px;margin:0 auto;padding:2rem 1.5rem}

/* Header */
.page-header{text-align:center;margin-bottom:2rem}
.page-emoji{font-size:2.5rem;margin-bottom:.5rem}
.page-title{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.3rem}
.page-sub{font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}

/* Stats */
.stats-bar{display:flex;justify-content:center;gap:2rem;margin-bottom:2rem;padding:1rem;border:1px solid var(--border);background:var(--s)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--accent);text-align:center;line-height:1}
.stat-label{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:.3rem}
.stat-divider{width:1px;height:40px;background:var(--border);align-self:center}

/* Search */
.search-wrap{margin-bottom:2rem;position:relative}
.search-input{width:100%;background:var(--s);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;padding:.7rem 1rem .7rem 2.2rem;outline:none;transition:border .3s}
.search-input:focus{border-color:var(--accent)}
.search-input::placeholder{color:var(--muted);font-style:italic}
.search-icon{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.8rem}

/* Timeline */
.timeline-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);margin-bottom:1.5rem;text-align:center}
.day-group{margin-bottom:2.5rem}
.day-date{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.8rem;padding-bottom:.3rem;border-bottom:1px solid var(--border);text-align:center}
.day-cards{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.mot-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;position:relative;transition:border-color .3s}
.mot-card:hover{border-color:var(--accent)}
.mot-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.4}
.mot-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem}
.mot-avatar{width:20px;height:20px;border-radius:50%;object-fit:cover;border:1px solid var(--border)}
.mot-text{font-family:'Cormorant Garamond',serif;font-size:.95rem;color:var(--text);line-height:1.7;font-style:italic}
.mot-translation{font-family:'Cormorant Garamond',serif;font-size:.8rem;color:var(--muted);line-height:1.6;font-style:italic;margin-top:.4rem;opacity:.7}
.mot-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.6rem}
.mot-time{font-size:.45rem;color:var(--muted)}
.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}

/* Empty day slot */
.mot-card.empty-slot{border-style:dashed;opacity:.5;display:flex;align-items:center;justify-content:center;min-height:80px}
.empty-slot-text{font-size:.6rem;color:var(--muted);font-style:italic}

/* Pagination */
.pagination{display:flex;justify-content:center;align-items:center;gap:.5rem;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)}
.page-link{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s;font-family:'DM Mono',monospace}
.page-link:hover{border-color:var(--accent);color:var(--accent)}
.page-link.active{background:var(--accent);color:var(--bg);border-color:var(--accent)}
.page-info{font-size:.5rem;color:var(--muted);letter-spacing:.08em}

.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .day-cards{grid-template-columns:1fr}
    .stats-bar{gap:1rem}
    .stat-num{font-size:1.4rem}
    .page-title{font-size:1.4rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Nos Petits Mots','Наши Записки') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <!-- Header -->
    <div class="page-header">
        <div class="page-emoji">💌</div>
        <div class="page-title"><?= t('Nos Petits Mots','Наши Записки') ?></div>
        <div class="page-sub"><?= t('Tous les mots doux échangés','Все нежные слова, которыми мы обменялись') ?></div>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div>
            <div class="stat-num"><?= $totalMots ?></div>
            <div class="stat-label"><?= t('mots échangés','записок') ?></div>
        </div>
        <div class="stat-divider"></div>
        <div>
            <div class="stat-num"><?= $longestStreak ?></div>
            <div class="stat-label"><?= t('jours de suite','дней подряд') ?></div>
        </div>
    </div>

    <!-- Search -->
    <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <form method="get" action="">
            <input type="text" class="search-input" name="q" value="<?= h($search) ?>"
                placeholder="<?= t('Rechercher dans les mots...','Искать в записках...') ?>">
        </form>
    </div>

    <?php if ($search && empty($grouped)): ?>
    <div class="empty"><?= t('Aucun mot trouvé pour « '.h($search).' »','Записки по запросу «'.h($search).'» не найдены') ?></div>
    <?php elseif (empty($grouped)): ?>
    <div class="empty"><?= t('Aucun mot échangé encore...','Записок пока нет...') ?></div>
    <?php else: ?>

    <div class="timeline-title"><?= t('Fil des mots','Лента записок') ?></div>

    <?php
    $today = date('Y-m-d');
    $yesterday = (new DateTime($today))->modify('-1 day')->format('Y-m-d');
    foreach ($grouped as $date => $dayMots):
        // Organize by user
        $motsByUser = [];
        foreach ($dayMots as $m) {
            $motsByUser[$m['user_id']] = $m;
        }
    ?>
    <div class="day-group">
        <div class="day-date">
            <?php
            if ($date === $today) echo t('Aujourd\'hui','Сегодня');
            elseif ($date === $yesterday) echo t('Hier','Вчера');
            else {
                $d = new DateTime($date);
                echo $d->format('d/m/Y');
            }
            ?>
        </div>
        <div class="day-cards">
            <?php foreach ($coupleUsers as $cu):
                if (isset($motsByUser[$cu['id']])):
                    $m = $motsByUser[$cu['id']];
                    $tr = $translations[$m['id']] ?? null;
                    // Show appropriate text based on reader's language
                    if ($tr) {
                        if ($lang !== $tr['original_lang'] && $tr['translated']) {
                            $primary = $tr['translated'];
                            $secondary = $tr['original'];
                        } else {
                            $primary = $tr['original'];
                            $secondary = $tr['translated'];
                        }
                    } else {
                        $primary = $m['message'];
                        $secondary = '';
                    }
            ?>
            <div class="mot-card">
                <div class="mot-author">
                    <?php if ($cu['avatar']): ?>
                    <img class="mot-avatar" src="<?= BASE_URL ?>/uploads/<?= h($cu['avatar']) ?>" alt="">
                    <?php endif; ?>
                    <?= h($cu['display_name']) ?>
                </div>
                <div class="mot-text">&laquo; <?= h($primary) ?> &raquo;</div>
                <?php if ($secondary && $secondary !== $primary): ?>
                <div class="mot-translation">&laquo; <?= h($secondary) ?> &raquo;</div>
                <?php endif; ?>
                <div class="mot-footer">
                    <div class="mot-time"><?= date('H:i', strtotime($m['created_at'])) ?></div>
                    <button class="heart-btn <?= isset($myReactions[$m['id']]) ? 'liked' : '' ?>"
                        onclick="toggleHeart(this,'mot_du_jour',<?= $m['id'] ?>)">
                        <?= isset($myReactions[$m['id']]) ? '❤️' : '🤍' ?>
                        <span class="heart-count"><?= $reactionCounts[$m['id']] ?? '' ?></span>
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="mot-card empty-slot">
                <div class="empty-slot-text"><?= h($cu['display_name']) ?> — <?= t('pas de mot','нет записки') ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&q='.urlencode($search) : '' ?>">&larr;</a>
        <?php endif; ?>

        <?php
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $page + 2);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
        <a class="page-link <?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p ?><?= $search ? '&q='.urlencode($search) : '' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&q='.urlencode($search) : '' ?>">&rarr;</a>
        <?php endif; ?>

        <span class="page-info"><?= $page ?>/<?= $totalPages ?></span>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;

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
