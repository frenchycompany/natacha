<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/notifications.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Get couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}

// ═══ Get both users for display ═══
$allUsers = db()->query("SELECT id, display_name FROM users ORDER BY id")->fetchAll();
$userMap = [];
foreach ($allUsers as $u) $userMap[$u['id']] = $u['display_name'];
$user1Id = $allUsers[0]['id'] ?? 1;
$user2Id = $allUsers[1]['id'] ?? 2;

// ═══ POST: mark challenge complete ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'complete') {
        $logId = (int)($_POST['log_id'] ?? 0);
        $comment = trim($_POST['commentaire'] ?? '');

        if ($logId) {
            $col = ($user['id'] == $user1Id) ? 'complete_user1' : 'complete_user2';
            $commentCol = ($lang === 'ru') ? 'commentaire_ru' : 'commentaire_fr';

            $sql = "UPDATE defis_log SET {$col} = TRUE";
            $params = [];
            if ($comment) {
                $sql .= ", {$commentCol} = ?";
                $params[] = $comment;
            }
            // Check if other user already completed — if so, mark completed_at
            $otherCol = ($col === 'complete_user1') ? 'complete_user2' : 'complete_user1';
            $sql .= ", completed_at = IF({$otherCol} = TRUE, NOW(), completed_at)";
            $sql .= " WHERE id = ?";
            $params[] = $logId;

            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            // Notify the other user
            try {
                notifyOtherUser($user['id'], 'defi',
                    $user['display_name'].' a complété le défi du jour !',
                    $user['display_name'].' выполнил(а) вызов дня!',
                    BASE_URL.'/defis.php');
            } catch (Exception $e) {}

            // Record couple activity
            if ($coupleId) {
                require_once __DIR__.'/includes/couple_helper.php';
                $ce = new CoupleEntity(db());
                $ce->recordActivity($coupleId, $user['id'], 'defi',
                    $user['display_name'].' a complété le défi du jour',
                    $user['display_name'].' выполнил(а) вызов дня');
            }
        }
        header('Location: '.BASE_URL.'/defis.php'); exit;
    }
}

// ═══ Today's challenge logic ═══
$today = date('Y-m-d');
$todayLog = db()->prepare("SELECT dl.*, d.contenu_fr, d.contenu_ru, d.categorie, d.difficulte
    FROM defis_log dl JOIN defis d ON d.id = dl.defi_id WHERE dl.date_defi = ?");
$todayLog->execute([$today]);
$todayChallenge = $todayLog->fetch();

if (!$todayChallenge) {
    // Pick a challenge not used in last 30 days
    $recentIds = db()->query("SELECT defi_id FROM defis_log WHERE date_defi > DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($recentIds)) {
        $defi = db()->query("SELECT * FROM defis ORDER BY RAND(".date('z').") LIMIT 1")->fetch();
    } else {
        $placeholders = implode(',', array_fill(0, count($recentIds), '?'));
        $stmt = db()->prepare("SELECT * FROM defis WHERE id NOT IN ({$placeholders}) ORDER BY RAND(".date('z').") LIMIT 1");
        $stmt->execute($recentIds);
        $defi = $stmt->fetch();
        if (!$defi) {
            // All used recently, pick any
            $defi = db()->query("SELECT * FROM defis ORDER BY RAND(".date('z').") LIMIT 1")->fetch();
        }
    }
    if ($defi) {
        db()->prepare("INSERT INTO defis_log (defi_id, date_defi) VALUES (?, ?)")->execute([$defi['id'], $today]);
        $todayLog = db()->prepare("SELECT dl.*, d.contenu_fr, d.contenu_ru, d.categorie, d.difficulte
            FROM defis_log dl JOIN defis d ON d.id = dl.defi_id WHERE dl.date_defi = ?");
        $todayLog->execute([$today]);
        $todayChallenge = $todayLog->fetch();
    }
}

// ═══ Check if current user already completed ═══
$myCol = ($user['id'] == $user1Id) ? 'complete_user1' : 'complete_user2';
$myCompleted = $todayChallenge[$myCol] ?? false;
$bothCompleted = ($todayChallenge['complete_user1'] ?? false) && ($todayChallenge['complete_user2'] ?? false);

// ═══ History: last 7 days ═══
$history = db()->query("SELECT dl.*, d.contenu_fr, d.contenu_ru, d.categorie, d.difficulte
    FROM defis_log dl JOIN defis d ON d.id = dl.defi_id
    WHERE dl.date_defi < CURDATE()
    ORDER BY dl.date_defi DESC LIMIT 7")->fetchAll();

// ═══ Stats ═══
// Streak: consecutive days both completed (from yesterday backwards)
$streakRows = db()->query("SELECT date_defi, complete_user1, complete_user2 FROM defis_log WHERE date_defi <= CURDATE() ORDER BY date_defi DESC")->fetchAll();
$streak = 0;
$checkDate = $today;
foreach ($streakRows as $row) {
    if ($row['date_defi'] !== $checkDate) break;
    if ($row['complete_user1'] && $row['complete_user2']) {
        $streak++;
        $checkDate = date('Y-m-d', strtotime($checkDate.' -1 day'));
    } else {
        // If today is not yet both completed, skip today and check from yesterday
        if ($row['date_defi'] === $today && !($row['complete_user1'] && $row['complete_user2'])) {
            $checkDate = date('Y-m-d', strtotime($checkDate.' -1 day'));
            continue;
        }
        break;
    }
}
$totalCompleted = db()->query("SELECT COUNT(*) FROM defis_log WHERE complete_user1 = TRUE AND complete_user2 = TRUE")->fetchColumn();

// Category labels & colors
$catLabels = [
    'romantique' => [t('Romantique','Романтика'), '#c96e8b'],
    'aventure' => [t('Aventure','Приключение'), '#6ec9a8'],
    'cuisine' => [t('Cuisine','Кухня'), '#c9a96e'],
    'creativite' => [t('Créativité','Творчество'), '#8b6ec9'],
    'communication' => [t('Communication','Общение'), '#6ea8c9'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Défi du Jour','Вызов дня') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}

.wrap{max-width:720px;margin:0 auto;padding:2rem 1.5rem 4rem}

/* Today's challenge card */
.today-header{text-align:center;margin-bottom:1rem}
.today-header h2{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:300;letter-spacing:.2em;text-transform:uppercase;color:var(--muted)}
.today-header .date{font-size:.6rem;color:var(--muted);letter-spacing:.15em;margin-top:.3rem}

.challenge-card{background:var(--s);border:1px solid var(--accent);padding:2.5rem 2rem;text-align:center;position:relative;overflow:hidden;box-shadow:0 0 40px rgba(201,169,110,.08), 0 0 80px rgba(201,169,110,.04)}
.challenge-card::before{content:'';position:absolute;inset:-1px;background:linear-gradient(135deg,rgba(201,169,110,.15),transparent 50%,rgba(201,169,110,.08));pointer-events:none}
.challenge-card::after{content:'';position:absolute;inset:0;background:var(--as);opacity:0;transition:opacity .5s}

.cat-badge{display:inline-block;font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;padding:.2rem .6rem;border:1px solid;margin-bottom:1.2rem;position:relative;z-index:1}
.difficulty{font-size:.7rem;letter-spacing:.2em;margin-bottom:1rem;position:relative;z-index:1}
.challenge-text{font-family:'Cormorant Garamond',serif;font-size:clamp(1.4rem,3.5vw,2rem);font-weight:300;font-style:italic;line-height:1.5;color:var(--text);margin-bottom:.8rem;position:relative;z-index:1}
.challenge-alt{font-size:.65rem;color:var(--muted);font-style:italic;line-height:1.6;position:relative;z-index:1}

/* Completion section */
.completion{margin-top:2rem;padding:1.5rem;background:var(--s);border:1px solid var(--border)}
.completion h3{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem;text-align:center}
.user-status{display:flex;justify-content:center;gap:2rem;margin-bottom:1.2rem;flex-wrap:wrap}
.status-item{display:flex;align-items:center;gap:.5rem;font-size:.65rem;letter-spacing:.05em}
.status-dot{width:10px;height:10px;border-radius:50%;border:1px solid var(--border)}
.status-dot.done{background:#6ec96e;border-color:#6ec96e}
.status-dot.pending{background:transparent;border-color:var(--muted)}

.complete-form{text-align:center}
.comment-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:.6rem;font-family:'DM Mono',monospace;font-size:.65rem;margin-bottom:.8rem;resize:vertical;min-height:2.5rem}
.comment-input:focus{outline:none;border-color:var(--accent)}
.comment-input::placeholder{color:var(--muted)}
.btn-complete{background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.5rem;font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;cursor:pointer;transition:all .3s}
.btn-complete:hover{background:var(--accent);color:var(--bg)}
.btn-done{background:rgba(110,201,110,.1);border:1px solid #6ec96e;color:#6ec96e;padding:.5rem 1.5rem;font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;cursor:default}

/* Celebration animation */
.celebration{position:relative}
.celebration .challenge-card{border-color:#6ec96e;box-shadow:0 0 40px rgba(110,201,110,.15), 0 0 80px rgba(201,169,110,.08)}
@keyframes sparkle{0%{transform:translateY(0) scale(1);opacity:1}100%{transform:translateY(-80px) scale(0);opacity:0}}
.sparkles{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:2}
.sparkle{position:absolute;width:4px;height:4px;background:var(--accent);border-radius:50%;animation:sparkle 1.5s ease-out infinite}
.sparkle:nth-child(1){left:10%;bottom:0;animation-delay:0s}
.sparkle:nth-child(2){left:25%;bottom:0;animation-delay:.2s}
.sparkle:nth-child(3){left:40%;bottom:0;animation-delay:.5s}
.sparkle:nth-child(4){left:55%;bottom:0;animation-delay:.3s}
.sparkle:nth-child(5){left:70%;bottom:0;animation-delay:.7s}
.sparkle:nth-child(6){left:85%;bottom:0;animation-delay:.1s}
.sparkle:nth-child(7){left:15%;bottom:0;animation-delay:.9s}
.sparkle:nth-child(8){left:50%;bottom:0;animation-delay:.4s}
.sparkle:nth-child(9){left:35%;bottom:0;animation-delay:.6s}
.sparkle:nth-child(10){left:75%;bottom:0;animation-delay:.8s}
.sparkle:nth-child(odd){background:#6ec96e}
.celebration-text{text-align:center;font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-style:italic;color:#6ec96e;margin-top:1rem;animation:fadeIn .5s ease-in}
@keyframes fadeIn{0%{opacity:0;transform:translateY(10px)}100%{opacity:1;transform:translateY(0)}}

/* Stats */
.stats{display:flex;gap:1.5rem;margin:2rem 0;justify-content:center;flex-wrap:wrap}
.stat-box{background:var(--s);border:1px solid var(--border);padding:1rem 1.5rem;text-align:center;min-width:120px}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;color:var(--accent)}
.stat-label{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}

/* History */
.history{margin-top:2.5rem}
.history h3{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem}
.history-item{display:flex;align-items:center;gap:1rem;padding:.8rem 1rem;background:var(--s);border:1px solid var(--border);margin-bottom:.5rem}
.history-date{font-size:.55rem;letter-spacing:.1em;color:var(--muted);min-width:70px}
.history-text{font-size:.65rem;color:var(--text);flex:1;line-height:1.5}
.history-status{display:flex;gap:.3rem;flex-shrink:0}
.hist-dot{width:8px;height:8px;border-radius:50%}
.hist-dot.green{background:#6ec96e}
.hist-dot.yellow{background:#c9a96e}
.hist-dot.gray{background:var(--border)}
.history-badge{font-size:.45rem;letter-spacing:.1em;text-transform:uppercase;padding:.15rem .4rem;border:1px solid;flex-shrink:0}
@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">🎯 <?= t('Défi du Jour','Вызов дня') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

<?php if ($todayChallenge): ?>
  <!-- Stats -->
  <div class="stats">
    <div class="stat-box">
      <div class="stat-num"><?= $streak ?></div>
      <div class="stat-label"><?= t('Jours de suite','Дней подряд') ?></div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= $totalCompleted ?></div>
      <div class="stat-label"><?= t('Défis réussis','Выполнено') ?></div>
    </div>
  </div>

  <!-- Today's Challenge -->
  <div class="today-header">
    <h2>✦ <?= t('Challenge du jour','Вызов дня') ?> ✦</h2>
    <div class="date"><?= strftime('%d %B %Y', strtotime($today)) ?></div>
  </div>

  <div class="<?= $bothCompleted ? 'celebration' : '' ?>">
    <div class="challenge-card">
      <?php if ($bothCompleted): ?>
      <div class="sparkles">
        <div class="sparkle"></div><div class="sparkle"></div><div class="sparkle"></div>
        <div class="sparkle"></div><div class="sparkle"></div><div class="sparkle"></div>
        <div class="sparkle"></div><div class="sparkle"></div><div class="sparkle"></div>
        <div class="sparkle"></div>
      </div>
      <?php endif; ?>

      <?php
        $cat = $todayChallenge['categorie'];
        $catInfo = $catLabels[$cat] ?? [ucfirst($cat), 'var(--accent)'];
        $diff = (int)$todayChallenge['difficulte'];
        $dots = str_repeat('●', $diff) . str_repeat('○', 3 - $diff);
      ?>
      <div class="cat-badge" style="color:<?= $catInfo[1] ?>;border-color:<?= $catInfo[1] ?>"><?= h($catInfo[0]) ?></div>
      <div class="difficulty" style="color:<?= $catInfo[1] ?>"><?= $dots ?></div>

      <?php if ($lang === 'ru'): ?>
        <div class="challenge-text"><?= h($todayChallenge['contenu_ru']) ?></div>
        <div class="challenge-alt"><?= h($todayChallenge['contenu_fr']) ?></div>
      <?php else: ?>
        <div class="challenge-text"><?= h($todayChallenge['contenu_fr']) ?></div>
        <div class="challenge-alt"><?= h($todayChallenge['contenu_ru']) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($bothCompleted): ?>
    <div class="celebration-text">🎉 <?= t('Défi accompli ensemble !','Вызов выполнен вместе!') ?></div>
    <?php endif; ?>
  </div>

  <!-- Completion tracking -->
  <div class="completion">
    <h3><?= t('Progression','Прогресс') ?></h3>
    <div class="user-status">
      <div class="status-item">
        <span class="status-dot <?= $todayChallenge['complete_user1'] ? 'done' : 'pending' ?>"></span>
        <?= $todayChallenge['complete_user1'] ? '✓' : '○' ?>
        <?= h($userMap[$user1Id] ?? 'User 1') ?>
      </div>
      <div class="status-item">
        <span class="status-dot <?= $todayChallenge['complete_user2'] ? 'done' : 'pending' ?>"></span>
        <?= $todayChallenge['complete_user2'] ? '✓' : '○' ?>
        <?= h($userMap[$user2Id] ?? 'User 2') ?>
      </div>
    </div>

    <?php if (!$myCompleted): ?>
    <form method="POST" class="complete-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="complete">
      <input type="hidden" name="log_id" value="<?= $todayChallenge['id'] ?>">
      <textarea name="commentaire" class="comment-input" placeholder="<?= t('Un commentaire ? (optionnel)','Комментарий? (необязательно)') ?>" rows="2"></textarea>
      <button type="submit" class="btn-complete"><?= t('J\'ai complété le défi !','Я выполнил(а) вызов!') ?></button>
    </form>
    <?php else: ?>
    <div style="text-align:center">
      <span class="btn-done">✓ <?= t('Complété','Выполнено') ?></span>
    </div>
    <?php endif; ?>

    <?php
    $commentFr = $todayChallenge['commentaire_fr'] ?? '';
    $commentRu = $todayChallenge['commentaire_ru'] ?? '';
    if ($commentFr || $commentRu): ?>
    <div style="margin-top:1rem;padding-top:.8rem;border-top:1px solid var(--border)">
      <?php if ($commentFr): ?>
      <div style="font-size:.6rem;color:var(--muted);margin-bottom:.4rem"><span style="color:var(--accent)">FR:</span> <?= h($commentFr) ?></div>
      <?php endif; ?>
      <?php if ($commentRu): ?>
      <div style="font-size:.6rem;color:var(--muted)"><span style="color:var(--accent)">RU:</span> <?= h($commentRu) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div style="text-align:center;padding:3rem 1rem">
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-style:italic;color:var(--muted)">
      <?= t('Aucun défi disponible. Ajoutez des défis dans la base de données.','Нет доступных вызовов. Добавьте вызовы в базу данных.') ?>
    </div>
  </div>
<?php endif; ?>

  <!-- History -->
  <?php if (!empty($history)): ?>
  <div class="history">
    <h3><?= t('Historique récent','Недавняя история') ?></h3>
    <?php foreach ($history as $h_item):
      $hCat = $h_item['categorie'];
      $hCatInfo = $catLabels[$hCat] ?? [ucfirst($hCat), 'var(--accent)'];
      $hBoth = $h_item['complete_user1'] && $h_item['complete_user2'];
      $hOne = $h_item['complete_user1'] || $h_item['complete_user2'];
    ?>
    <div class="history-item">
      <div class="history-date"><?= date('d/m', strtotime($h_item['date_defi'])) ?></div>
      <div class="history-text"><?= h($lang === 'ru' ? $h_item['contenu_ru'] : $h_item['contenu_fr']) ?></div>
      <span class="history-badge" style="color:<?= $hCatInfo[1] ?>;border-color:<?= $hCatInfo[1] ?>"><?= h($hCatInfo[0]) ?></span>
      <div class="history-status">
        <span class="hist-dot <?= $h_item['complete_user1'] ? ($hBoth ? 'green' : 'yellow') : 'gray' ?>"></span>
        <span class="hist-dot <?= $h_item['complete_user2'] ? ($hBoth ? 'green' : 'yellow') : 'gray' ?>"></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
