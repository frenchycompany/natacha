<?php
require_once __DIR__.'/config.php';
requireLogin();
$user = currentUser();
$lang = $user['lang'];

if (isset($_GET['logout'])) { session_destroy(); header('Location: '.BASE_URL.'/login.php'); exit; }
if (isset($_POST['set_lang'])) {
    $nl = in_array($_POST['set_lang'],['fr','ru'])?$_POST['set_lang']:'fr';
    db()->prepare("UPDATE users SET lang=? WHERE id=?")->execute([$nl,$user['id']]);
    $_SESSION['user']['lang'] = $nl;
    header('Location: '.BASE_URL.'/dashboard.php'); exit;
}

// Stats rapides
try {
    $nb_chapitres = db()->query("SELECT COUNT(*) FROM histoire_chapitres")->fetchColumn();
} catch(Exception $e) { $nb_chapitres = 0; }
try {
    $nb_quizz = db()->query("SELECT COUNT(*) FROM questionnaires")->fetchColumn();
} catch(Exception $e) { $nb_quizz = 0; }
try {
    $dernier_chap = db()->query("SELECT h.titre, u.display_name, h.created_at FROM histoire_chapitres h JOIN users u ON u.id=h.user_id ORDER BY h.created_at DESC LIMIT 1")->fetch();
} catch(Exception $e) { $dernier_chap = null; }
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1.2rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-left{display:flex;align-items:center;gap:1.2rem}
.logo{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-style:italic;color:var(--accent)}
.who{font-size:.6rem;letter-spacing:.12em;color:var(--muted)}
.avatar{width:28px;height:28px;border-radius:50%;background:var(--as);border:1px solid var(--accent);display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--accent);flex-shrink:0}
.topbar-right{display:flex;align-items:center;gap:.8rem}
.lang-form{display:flex;gap:.3rem}
.lb{font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.25rem .55rem;cursor:pointer;transition:all .2s}
.lb.active{border-color:var(--accent);color:var(--accent);background:var(--as)}
.logout{font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.25rem .55rem;transition:all .2s}
.logout:hover{border-color:#c96e6e;color:#c96e6e}

.wrap{max-width:900px;margin:0 auto;padding:3rem 2rem}
.greeting{margin-bottom:3rem}
.greeting h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.8rem,4vw,2.6rem);font-weight:300;font-style:italic;line-height:1.3;color:var(--text)}
.greeting h2 em{color:var(--accent)}
.greeting p{font-size:.65rem;color:var(--muted);letter-spacing:.1em;margin-top:.5rem}

.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.2rem}
@media(max-width:600px){.grid{grid-template-columns:1fr}}

.card{background:var(--s);border:1px solid var(--border);padding:2rem;text-decoration:none;color:var(--text);display:block;transition:all .3s;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;inset:0;background:var(--as);opacity:0;transition:opacity .3s}
.card:hover::before{opacity:1}
.card:hover{border-color:var(--accent)}
.card-icon{font-size:1.8rem;margin-bottom:1rem;display:block}
.card-title{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.4rem}
.card-desc{font-size:.65rem;color:var(--muted);line-height:1.7;letter-spacing:.05em}
.card-stat{position:absolute;top:1rem;right:1rem;font-size:.58rem;letter-spacing:.1em;color:var(--accent);background:var(--as);border:1px solid rgba(201,169,110,.2);padding:.2rem .5rem}
.card-hint{font-size:.6rem;color:var(--muted);margin-top:.8rem;font-style:italic}

</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-left">
    <div class="avatar"><?= h($user['avatar']) ?></div>
    <div>
      <div class="logo">💌 Natacha</div>
      <div class="who"><?= h($user['display_name']) ?></div>
    </div>
  </div>
  <div class="topbar-right">
    <form method="POST" class="lang-form">
      <button type="submit" name="set_lang" value="fr" class="lb <?= $lang==='fr'?'active':'' ?>">FR</button>
      <button type="submit" name="set_lang" value="ru" class="lb <?= $lang==='ru'?'active':'' ?>">RU</button>
    </form>
    <a class="logout" href="?logout=1"><?= t('Quitter','Выйти') ?></a>
  </div>
</div>

<div class="wrap">
  <div class="greeting">
    <h2><?= t('Bonjour,','Привет,') ?> <em><?= h($user['display_name']) ?></em> 💌</h2>
    <p><?= t('Bienvenue dans notre espace privé.','Добро пожаловать в наше личное пространство.') ?></p>
  </div>

  <div class="grid">

    <!-- Notre Histoire -->
    <a class="card" href="<?= BASE_URL ?>/histoire.php">
      <span class="card-stat"><?= $nb_chapitres ?> <?= t('chapitres','глав') ?></span>
      <span class="card-icon">📖</span>
      <div class="card-title"><?= t('Notre Histoire','Наша История') ?></div>
      <div class="card-desc"><?= t('Journal partagé, chapitres, moments du quotidien.','Общий дневник, главы, моменты из жизни.') ?></div>
      <?php if ($dernier_chap): ?>
      <div class="card-hint"><?= t('Dernier :','Последнее:') ?> <?= h($dernier_chap['titre']) ?> — <?= h($dernier_chap['display_name']) ?></div>
      <?php endif; ?>
    </a>

    <!-- Nos QCM -->
    <a class="card" href="<?= BASE_URL ?>/questionnaires.php">
      <span class="card-stat"><?= $nb_quizz ?> <?= t('QCM','тестов') ?></span>
      <span class="card-icon">💌</span>
      <div class="card-title"><?= t('Nos QCM','Наши Тесты') ?></div>
      <div class="card-desc"><?= t('Créer, remplir et consulter nos questionnaires.','Создавать, заполнять и просматривать анкеты.') ?></div>
    </a>

    <!-- Nos Jeux -->
    <a class="card" href="<?= BASE_URL ?>/jeux.php">
      <span class="card-icon">🎲</span>
      <div class="card-title"><?= t('Nos Jeux','Наши Игры') ?></div>
      <div class="card-desc"><?= t('Action ou Vérité et autres jeux pour nous deux.','Правда или Действие и другие игры для нас двоих.') ?></div>
    </a>

    <!-- Coffre-Fort -->
    <a class="card" href="<?= BASE_URL ?>/coffre_fort.php">
      <span class="card-icon">🔐</span>
      <div class="card-title"><?= t('Coffre-Fort','Сейф') ?></div>
      <div class="card-desc"><?= t('Stockage chiffré AES-256. Photos, documents, fichiers privés.','Зашифрованное хранилище AES-256. Фото, документы, личные файлы.') ?></div>
    </a>

  </div>
</div>
</body>
</html>
