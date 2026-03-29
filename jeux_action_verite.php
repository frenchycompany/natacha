<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Record game activity (once per day)
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if ($coupleId) {
    $today = date('Y-m-d');
    $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='jeu' AND DATE(created_at)=?");
    $already->execute([$coupleId, $user['id'], $today]);
    if (!$already->fetch()) {
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $ce->recordActivity($coupleId, $user['id'], 'jeu',
            $user['display_name'].' a joué à Action ou Vérité',
            $user['display_name'].' играл(а) в Правда или Действие');
    }
}

// Tirer une carte aléatoire
$type   = $_GET['type'] ?? 'all';
$niveau = (int)($_GET['niveau'] ?? 0);

try {
    $where = "WHERE 1=1";
    $params = [];
    if ($type === 'action')  { $where .= " AND type='action'";  }
    if ($type === 'verite')  { $where .= " AND type='verite'";  }
    if ($niveau > 0)         { $where .= " AND niveau=?"; $params[] = $niveau; }

    $carte_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if ($carte_id) {
        $stmt = db()->prepare("SELECT * FROM jeux_cartes WHERE id=?");
        $stmt->execute([$carte_id]);
        $carte = $stmt->fetch();
    } else {
        $stmt = db()->prepare("SELECT * FROM jeux_cartes $where ORDER BY RAND() LIMIT 1");
        $stmt->execute($params);
        $carte = $stmt->fetch();
    }
    $total = db()->prepare("SELECT COUNT(*) FROM jeux_cartes $where");
    $total->execute($params);
    $total = $total->fetchColumn();
} catch(Exception $e) { $carte = null; $total = 0; }
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Action ou Vérité','Правда или Действие') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--action:#c96e6e;--verite:#6e9dc9}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:680px;margin:0 auto;padding:3rem 2rem}

/* Filtres */
.filters{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:3rem;justify-content:center}
.filter-btn{font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.35rem .7rem;cursor:pointer;text-decoration:none;transition:all .2s}
.filter-btn:hover,.filter-btn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}
.filter-btn.action.active{border-color:var(--action);color:var(--action);background:rgba(201,110,110,.08)}
.filter-btn.verite.active{border-color:var(--verite);color:var(--verite);background:rgba(110,157,201,.08)}

/* Carte */
.card-wrap{display:flex;justify-content:center;margin-bottom:3rem}
.game-card{width:100%;max-width:480px;min-height:280px;background:var(--s);border:1px solid var(--border);padding:2.5rem;display:flex;flex-direction:column;justify-content:space-between;animation:cardIn .4s ease;position:relative;overflow:hidden}
@keyframes cardIn{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:none}}
.game-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.game-card.action::after{background:var(--action)}
.game-card.verite::after{background:var(--verite)}
.card-type{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;margin-bottom:1.5rem}
.card-type.action{color:var(--action)}
.card-type.verite{color:var(--verite)}
.card-niveau{position:absolute;top:1rem;right:1rem;font-size:.55rem;letter-spacing:.1em;color:var(--muted)}
.card-text{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;line-height:1.6;color:var(--text);flex:1;display:flex;align-items:center}
.card-ru{font-size:1rem;color:var(--muted);margin-top:1rem;font-style:italic;font-family:'Cormorant Garamond',serif}

/* Actions */
.card-btns{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center}
.cbtn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.5rem 1.2rem;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-block}
.cbtn:hover{border-color:var(--accent);color:var(--accent)}
.cbtn.main{border-color:var(--accent);color:var(--accent)}
.cbtn.main:hover{background:var(--accent);color:#0f0d0b}
.cbtn.act{border-color:var(--action);color:var(--action)}
.cbtn.act:hover{background:var(--action);color:#fff}
.cbtn.ver{border-color:var(--verite);color:var(--verite)}
.cbtn.ver:hover{background:var(--verite);color:#fff}

.stats{text-align:center;font-size:.6rem;color:var(--muted);margin-top:2rem;letter-spacing:.1em}
.no-card{text-align:center;padding:4rem;font-size:.75rem;color:var(--muted)}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/jeux.php">&larr; <?= t('Jeux','Игры') ?></a>
  <div class="topbar-title"><?= t('Action ou Vérité','Правда или Действие') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="filters">
    <a class="filter-btn <?= $type==='all'?'active':'' ?>" href="?type=all"><?= t('Tout','Все') ?></a>
    <a class="filter-btn action <?= $type==='action'?'active':'' ?>" href="?type=action"><?= t('Action','Действие') ?></a>
    <a class="filter-btn verite <?= $type==='verite'?'active':'' ?>" href="?type=verite"><?= t('Vérité','Правда') ?></a>
    <a class="filter-btn <?= $niveau===1?'active':'' ?>" href="?type=<?= $type ?>&niveau=1"><?= t('Doux','Мягко') ?> ①</a>
    <a class="filter-btn <?= $niveau===2?'active':'' ?>" href="?type=<?= $type ?>&niveau=2"><?= t('Intense','Интенсивно') ?> ②</a>
  </div>

  <?php if ($carte): ?>
  <div class="card-wrap">
    <div class="game-card <?= $carte['type'] ?>">
      <div>
        <div class="card-type <?= $carte['type'] ?>">
          <?php if ($carte['type']==='action'): ?>
            <?= t('⚡ Action','⚡ Действие') ?>
          <?php else: ?>
            <?= t('💬 Vérité','💬 Правда') ?>
          <?php endif; ?>
        </div>
        <div class="card-niveau"><?= str_repeat('●', $carte['niveau']) ?><?= str_repeat('○', 2-$carte['niveau']) ?></div>
        <div class="card-text">
          <div>
            <?= h($lang === 'ru' && $carte['contenu_ru'] ? $carte['contenu_ru'] : $carte['contenu_fr']) ?>
            <?php if ($lang !== 'ru' && $carte['contenu_ru']): ?>
            <div class="card-ru"><?= h($carte['contenu_ru']) ?></div>
            <?php elseif ($lang === 'ru'): ?>
            <div class="card-ru"><?= h($carte['contenu_fr']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card-btns">
    <a class="cbtn main" href="?type=<?= $type ?>&niveau=<?= $niveau ?>">🎲 <?= t('Nouvelle carte','Новая карта') ?></a>
    <a class="cbtn act" href="?type=action&niveau=<?= $niveau ?>"><?= t('Action','Действие') ?></a>
    <a class="cbtn ver" href="?type=verite&niveau=<?= $niveau ?>"><?= t('Vérité','Правда') ?></a>
  </div>

  <div class="stats"><?= $total ?> <?= t('cartes disponibles','карт доступно') ?></div>

  <?php else: ?>
  <div class="no-card"><?= t('Aucune carte disponible pour ces filtres.','Нет карточек для выбранных фильтров.') ?></div>
  <?php endif; ?>

</div>
</body>
</html>
