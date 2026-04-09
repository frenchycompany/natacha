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
            $user['display_name'].' a joué au Quiz Couple',
            $user['display_name'].' играл(а) в Викторину');
    }
}

// Filtre par catégorie
$cat = $_GET['cat'] ?? 'all';
$allowedCats = ['all', 'preferences', 'personnel', 'couple', 'fun'];
if (!in_array($cat, $allowedCats)) $cat = 'all';

try {
    $where = "WHERE 1=1";
    $params = [];
    if ($cat !== 'all') {
        $where .= " AND categorie=?";
        $params[] = $cat;
    }

    $stmt = db()->prepare("SELECT * FROM jeux_quiz_questions $where ORDER BY RAND() LIMIT 1");
    $stmt->execute($params);
    $question = $stmt->fetch();

    $total = db()->prepare("SELECT COUNT(*) FROM jeux_quiz_questions $where");
    $total->execute($params);
    $total = $total->fetchColumn();
} catch(Exception $e) { $question = null; $total = 0; }

$catLabels = [
    'all'         => t('Toutes', 'Все'),
    'preferences' => t('Préférences', 'Предпочтения'),
    'personnel'   => t('Personnel', 'Личное'),
    'couple'      => t('Couple', 'Пара'),
    'fun'         => t('Fun', 'Веселье'),
];

$catColors = [
    'preferences' => '#6e9dc9',
    'personnel'   => '#c9a96e',
    'couple'      => '#c96e9d',
    'fun'         => '#6ec99d',
];
$currentColor = $catColors[$cat] ?? 'var(--accent)';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Quiz Couple','Викторина') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--cat-color:<?= $currentColor ?>}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:680px;margin:0 auto;padding:3rem 2rem}

/* Intro */
.intro{text-align:center;margin-bottom:2.5rem}
.intro h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;color:var(--accent);margin-bottom:.6rem}
.intro p{font-size:.65rem;color:var(--muted);line-height:1.8;letter-spacing:.05em;max-width:440px;margin:0 auto}

/* Filtres */
.filters{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:3rem;justify-content:center}
.filter-btn{font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.35rem .7rem;cursor:pointer;text-decoration:none;transition:all .2s}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{border-color:var(--cat-color);color:var(--cat-color);background:rgba(201,169,110,.08)}

/* Carte question */
.card-wrap{display:flex;justify-content:center;margin-bottom:3rem}
.game-card{width:100%;max-width:480px;min-height:300px;background:var(--s);border:1px solid var(--border);padding:2.5rem;display:flex;flex-direction:column;justify-content:space-between;animation:cardIn .4s ease;position:relative;overflow:hidden}
@keyframes cardIn{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:none}}
.game-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--cat-color)}
.card-cat{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;margin-bottom:1.5rem;color:var(--cat-color)}
.card-text{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;line-height:1.6;color:var(--text);flex:1;display:flex;align-items:center}
.card-ru{font-size:1rem;color:var(--muted);margin-top:1rem;font-style:italic;font-family:'Cormorant Garamond',serif}
.card-id{position:absolute;bottom:1rem;right:1.5rem;font-size:.5rem;color:var(--muted);letter-spacing:.1em}

/* Règles */
.how-to{margin-top:1rem;margin-bottom:2rem;padding:1.5rem;border:1px solid var(--border);background:var(--s)}
.how-to h3{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin-bottom:1rem}
.how-to ol{padding-left:1.2rem;font-size:.65rem;color:var(--muted);line-height:2}

/* Actions */
.card-btns{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center}
.cbtn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.5rem 1.2rem;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-block}
.cbtn:hover{border-color:var(--accent);color:var(--accent)}
.cbtn.main{border-color:var(--cat-color);color:var(--cat-color)}
.cbtn.main:hover{background:var(--cat-color);color:#0f0d0b}

.stats{text-align:center;font-size:.6rem;color:var(--muted);margin-top:2rem;letter-spacing:.1em}
.no-card{text-align:center;padding:4rem;font-size:.75rem;color:var(--muted)}
@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/jeux.php">&larr; <?= t('Jeux','Игры') ?></a>
  <div class="topbar-title"><?= t('Quiz Couple','Викторина') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="intro">
    <h2><?= t('Quiz Couple','Викторина') ?></h2>
    <p><?= t(
      'Un jeu simple : lisez la question à voix haute, l\'autre répond ce qu\'il/elle pense. Comparez et découvrez à quel point vous vous connaissez !',
      'Простая игра: прочитайте вопрос вслух, другой отвечает, что думает. Сравните и узнайте, насколько хорошо вы знаете друг друга!'
    ) ?></p>
  </div>

  <div class="filters">
    <?php foreach ($catLabels as $key => $label): ?>
    <a class="filter-btn <?= $cat===$key?'active':'' ?>" href="?cat=<?= $key ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($question): ?>
  <div class="card-wrap">
    <div class="game-card">
      <div>
        <div class="card-cat"><?= h($catLabels[$question['categorie']] ?? $question['categorie']) ?></div>
        <div class="card-text">
          <div>
            <?= h($lang === 'ru' && $question['question_ru'] ? $question['question_ru'] : $question['question_fr']) ?>
            <?php if ($lang !== 'ru' && $question['question_ru']): ?>
            <div class="card-ru"><?= h($question['question_ru']) ?></div>
            <?php elseif ($lang === 'ru'): ?>
            <div class="card-ru"><?= h($question['question_fr']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card-id">#<?= $question['id'] ?></div>
    </div>
  </div>

  <div class="card-btns">
    <a class="cbtn main" href="?cat=<?= h($cat) ?>"><?= t('Question suivante','Следующий вопрос') ?> &rarr;</a>
    <a class="cbtn" href="?cat=all"><?= t('Toutes catégories','Все категории') ?></a>
  </div>

  <div class="stats"><?= $total ?> <?= t('questions disponibles','вопросов доступно') ?></div>

  <?php else: ?>
  <div class="no-card"><?= t('Aucune question disponible pour cette catégorie.','Нет вопросов для этой категории.') ?></div>
  <?php endif; ?>

  <div class="how-to">
    <h3><?= t('Comment jouer','Как играть') ?></h3>
    <ol>
      <li><?= t('Un partenaire lit la question à voix haute','Один партнёр читает вопрос вслух') ?></li>
      <li><?= t('L\'autre donne sa réponse (ce qu\'il/elle pense)','Другой даёт свой ответ (что он/она думает)') ?></li>
      <li><?= t('Celui qui a posé la question révèle la vraie réponse','Тот, кто задал вопрос, раскрывает настоящий ответ') ?></li>
      <li><?= t('Comparez et riez ensemble !','Сравните и посмейтесь вместе!') ?></li>
    </ol>
  </div>

</div>
</body>
</html>
