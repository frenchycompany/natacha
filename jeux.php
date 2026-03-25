<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Jeux','Игры') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
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
.wrap{max-width:780px;margin:0 auto;padding:3rem 2rem}

/* Header */
.hub-header{text-align:center;margin-bottom:3.5rem}
.hub-header h1{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;color:var(--accent);margin-bottom:.6rem}
.hub-header p{font-size:.65rem;color:var(--muted);letter-spacing:.08em;line-height:1.8}

/* Game cards grid */
.games-grid{display:grid;grid-template-columns:1fr;gap:1.5rem}
@media(min-width:600px){.games-grid{grid-template-columns:1fr 1fr 1fr}}

.game-card{display:block;text-decoration:none;background:var(--s);border:1px solid var(--border);padding:2.5rem 2rem;text-align:center;position:relative;overflow:hidden;transition:all .35s cubic-bezier(.4,0,.2,1);cursor:pointer}
.game-card::before{content:'';position:absolute;inset:0;border:1px solid transparent;transition:border-color .35s,box-shadow .35s;pointer-events:none}
.game-card:hover{transform:translateY(-4px) scale(1.02)}
.game-card:hover::before{border-color:var(--accent);box-shadow:0 0 25px rgba(201,169,110,.15),inset 0 0 25px rgba(201,169,110,.03)}
.game-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);opacity:0;transition:opacity .35s}
.game-card:hover::after{opacity:1}

.game-card .emoji{font-size:2.8rem;margin-bottom:1.2rem;display:block;filter:grayscale(.2);transition:filter .35s}
.game-card:hover .emoji{filter:grayscale(0)}
.game-card .title{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:400;color:var(--text);margin-bottom:.3rem;transition:color .35s}
.game-card:hover .title{color:var(--accent)}
.game-card .subtitle{font-size:.58rem;color:var(--muted);letter-spacing:.08em;font-style:italic;margin-bottom:1.2rem;opacity:.7}
.game-card .desc{font-size:.6rem;color:var(--muted);line-height:1.8;letter-spacing:.04em}

/* Card accent colors on hover */
.game-card.action:hover::before{border-color:#c96e6e;box-shadow:0 0 25px rgba(201,110,110,.15),inset 0 0 25px rgba(201,110,110,.03)}
.game-card.action::after{background:#c96e6e}
.game-card.action:hover .title{color:#c96e6e}

.game-card.connaissance:hover::before{border-color:#c96e9d;box-shadow:0 0 25px rgba(201,110,157,.15),inset 0 0 25px rgba(201,110,157,.03)}
.game-card.connaissance::after{background:#c96e9d}
.game-card.connaissance:hover .title{color:#c96e9d}

.game-card.quiz:hover::before{border-color:#6e9dc9;box-shadow:0 0 25px rgba(110,157,201,.15),inset 0 0 25px rgba(110,157,201,.03)}
.game-card.quiz::after{background:#6e9dc9}
.game-card.quiz:hover .title{color:#6e9dc9}

.game-card.couple-quiz:hover::before{border-color:#c96ea0;box-shadow:0 0 25px rgba(201,110,160,.15),inset 0 0 25px rgba(201,110,160,.03)}
.game-card.couple-quiz::after{background:#c96ea0}
.game-card.couple-quiz:hover .title{color:#c96ea0}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title"><?= t('Nos Jeux','Наши Игры') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="hub-header">
    <h1><?= t('Espace Jeux','Игровая Зона') ?></h1>
    <p><?= t('Choisissez un jeu et amusez-vous ensemble','Выберите игру и развлекайтесь вместе') ?></p>
  </div>

  <div class="games-grid">

    <a class="game-card action" href="<?= BASE_URL ?>/jeux_action_verite.php">
      <span class="emoji">&#9889;</span>
      <div class="title"><?= t('Action ou Vérité','Правда или Действие') ?></div>
      <div class="subtitle"><?= t('Правда или Действие','Action ou Vérité') ?></div>
      <div class="desc"><?= t(
        'Tirez une carte et choisissez : action audacieuse ou vérité révélatrice ?',
        'Вытяните карту и выберите: смелое действие или откровенная правда?'
      ) ?></div>
    </a>

    <a class="game-card connaissance" href="<?= BASE_URL ?>/jeux_connaissance.php">
      <span class="emoji">&#128149;</span>
      <div class="title"><?= t('Qui me connaît le mieux ?','Кто знает меня лучше?') ?></div>
      <div class="subtitle"><?= t('Кто знает меня лучше?','Qui me connaît le mieux ?') ?></div>
      <div class="desc"><?= t(
        'Devinez les réponses de votre partenaire et découvrez qui connaît l\'autre le mieux !',
        'Угадайте ответы партнёра и узнайте, кто лучше знает другого!'
      ) ?></div>
    </a>

    <a class="game-card quiz" href="<?= BASE_URL ?>/jeux_quiz.php">
      <span class="emoji">&#128161;</span>
      <div class="title"><?= t('Quiz Couple','Викторина') ?></div>
      <div class="subtitle"><?= t('Викторина для пары','Quiz Couple') ?></div>
      <div class="desc"><?= t(
        'Questions fun sur vos préférences, souvenirs et personnalités. Qui marquera le plus de points ?',
        'Весёлые вопросы о предпочтениях, воспоминаниях и характерах. Кто наберёт больше очков?'
      ) ?></div>
    </a>

    <a class="game-card couple-quiz" href="<?= BASE_URL ?>/jeux_couple_quiz.php">
      <span class="emoji">💞</span>
      <div class="title"><?= t('Qui est notre couple ?','Кто наша пара?') ?></div>
      <div class="subtitle"><?= t('Кто наша пара?','Qui est notre couple ?') ?></div>
      <div class="desc"><?= t(
        'Répondez chacun aux mêmes questions et découvrez ensemble le portrait de votre couple !',
        'Каждый отвечает на одни и те же вопросы, и вместе вы узнаёте портрет вашей пары!'
      ) ?></div>
    </a>

  </div>

</div>
</body>
</html>
