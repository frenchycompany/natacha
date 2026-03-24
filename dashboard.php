<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Notifications
$unreadNotifs = 0;
try {
    require_once __DIR__.'/includes/notifications.php';
    $unreadNotifs = getUnreadCount($user['id']);
} catch (Exception $e) {}

if (isset($_GET['logout'])) { session_destroy(); header('Location: '.BASE_URL.'/login.php'); exit; }
if (isset($_POST['set_lang']) && csrfVerify()) {
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
    $nb_lieux = db()->query("SELECT COUNT(*) FROM lieux")->fetchColumn();
} catch(Exception $e) { $nb_lieux = 0; }
try {
    $nb_musiques = db()->query("SELECT COUNT(*) FROM musiques")->fetchColumn();
} catch(Exception $e) { $nb_musiques = 0; }
try {
    $nb_films = db()->query("SELECT COUNT(*) FROM films")->fetchColumn();
} catch(Exception $e) { $nb_films = 0; }
try {
    $dernier_chap = db()->query("SELECT h.titre, u.display_name, h.created_at FROM histoire_chapitres h JOIN users u ON u.id=h.user_id ORDER BY h.created_at DESC LIMIT 1")->fetch();
} catch(Exception $e) { $dernier_chap = null; }

// Défi du jour
$defi_today = null;
try {
    $defi_today = db()->query("SELECT dl.*, d.contenu_fr, d.contenu_ru, d.categorie, d.difficulte
        FROM defis_log dl JOIN defis d ON d.id = dl.defi_id WHERE dl.date_defi = CURDATE()")->fetch();
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
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
.topbar-link{font-size:.85rem;color:var(--muted);text-decoration:none;position:relative;transition:color .2s}
.topbar-link:hover{color:var(--accent)}
.notif-badge{position:absolute;top:-.4rem;right:-.5rem;background:#c96e6e;color:#fff;font-size:.45rem;padding:.1rem .3rem;border-radius:50%;min-width:.7rem;text-align:center;line-height:1}

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

.card-defi{grid-column:1/-1;border-color:var(--accent);box-shadow:0 0 30px rgba(201,169,110,.08),0 0 60px rgba(201,169,110,.03)}
.card-defi::before{opacity:.3}
.card-defi .card-title{font-size:1.6rem}
.defi-preview{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--text);line-height:1.5;margin:.6rem 0 .3rem}
.defi-alt{font-size:.6rem;color:var(--muted);font-style:italic}
.defi-meta{display:flex;gap:.8rem;align-items:center;margin-top:.8rem;flex-wrap:wrap}
.defi-cat{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;padding:.15rem .45rem;border:1px solid}
.defi-diff{font-size:.6rem;letter-spacing:.15em}

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
      <?= csrfField() ?>
      <button type="submit" name="set_lang" value="fr" class="lb <?= $lang==='fr'?'active':'' ?>">FR</button>
      <button type="submit" name="set_lang" value="ru" class="lb <?= $lang==='ru'?'active':'' ?>">RU</button>
    </form>
    <a class="topbar-link" href="<?= BASE_URL ?>/notifications.php" title="<?= t('Notifications','Уведомления') ?>">
      🔔<?php if ($unreadNotifs > 0): ?><span class="notif-badge"><?= $unreadNotifs ?></span><?php endif; ?>
    </a>
    <a class="topbar-link" href="<?= BASE_URL ?>/profil.php" title="<?= t('Profil','Профиль') ?>">⚙</a>
    <a class="logout" href="?logout=1"><?= t('Quitter','Выйти') ?></a>
  </div>
</div>

<div class="wrap">
  <div class="greeting">
    <h2><?= t('Bonjour,','Привет,') ?> <em><?= h($user['display_name']) ?></em> 💌</h2>
    <p><?= t('Bienvenue dans notre espace privé.','Добро пожаловать в наше личное пространство.') ?></p>
    <?php $days_together = (int)((time() - strtotime('2024-07-14')) / 86400); ?>
    <p style="font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--accent);font-size:1.1rem;margin-top:.8rem;opacity:.85">❤ <?= $days_together ?> <?= t('jours d\'amour','дней любви') ?></p>
  </div>

  <div class="grid">

    <!-- Défi du Jour -->
    <?php if ($defi_today): ?>
    <?php
      $defi_cat_colors = ['romantique'=>'#c96e8b','aventure'=>'#6ec9a8','cuisine'=>'#c9a96e','creativite'=>'#8b6ec9','communication'=>'#6ea8c9'];
      $defi_cat_labels = ['romantique'=>t('Romantique','Романтика'),'aventure'=>t('Aventure','Приключение'),'cuisine'=>t('Cuisine','Кухня'),'creativite'=>t('Créativité','Творчество'),'communication'=>t('Communication','Общение')];
      $dcat = $defi_today['categorie'];
      $dcol = $defi_cat_colors[$dcat] ?? 'var(--accent)';
      $dlab = $defi_cat_labels[$dcat] ?? ucfirst($dcat);
      $ddiff = (int)$defi_today['difficulte'];
      $ddots = str_repeat("\u{25CF}", $ddiff) . str_repeat("\u{25CB}", 3 - $ddiff);
      $dtext = $lang === 'ru' ? $defi_today['contenu_ru'] : $defi_today['contenu_fr'];
      $dalt = $lang === 'ru' ? $defi_today['contenu_fr'] : $defi_today['contenu_ru'];
    ?>
    <a class="card card-defi" href="<?= BASE_URL ?>/defis.php">
      <span class="card-icon">🎯</span>
      <div class="card-title"><?= t('Défi du Jour','Вызов дня') ?></div>
      <div class="defi-preview"><?= h(mb_strimwidth($dtext, 0, 80, '...')) ?></div>
      <div class="defi-alt"><?= h(mb_strimwidth($dalt, 0, 60, '...')) ?></div>
      <div class="defi-meta">
        <span class="defi-cat" style="color:<?= $dcol ?>;border-color:<?= $dcol ?>"><?= h($dlab) ?></span>
        <span class="defi-diff" style="color:<?= $dcol ?>"><?= $ddots ?></span>
      </div>
    </a>
    <?php endif; ?>

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

    <!-- Notre Carte -->
    <a class="card" href="<?= BASE_URL ?>/carte.php">
      <span class="card-stat"><?= $nb_lieux ?> <?= t('lieux','мест') ?></span>
      <span class="card-icon">🗺</span>
      <div class="card-title"><?= t('Notre Carte','Наша Карта') ?></div>
      <div class="card-desc"><?= t('Carte interactive de nos lieux visités ensemble.','Интерактивная карта мест, которые мы посетили вместе.') ?></div>
    </a>

    <!-- Coffre-Fort -->
    <a class="card" href="<?= BASE_URL ?>/coffre_fort.php">
      <span class="card-icon">🔐</span>
      <div class="card-title"><?= t('Coffre-Fort','Сейф') ?></div>
      <div class="card-desc"><?= t('Stockage chiffré AES-256. Photos, documents, fichiers privés.','Зашифрованное хранилище AES-256. Фото, документы, личные файлы.') ?></div>
    </a>

    <!-- Galerie -->
    <a class="card" href="<?= BASE_URL ?>/galerie.php">
      <span class="card-icon">📸</span>
      <div class="card-title"><?= t('Galerie','Галерея') ?></div>
      <div class="card-desc"><?= t('Galerie photos protégée — vos photos du coffre-fort en mosaïque.','Защищённая фотогалерея — ваши фото из сейфа в мозаике.') ?></div>
    </a>

    <!-- Qui me connaît le mieux -->
    <a class="card" href="<?= BASE_URL ?>/jeux_quiz.php">
      <span class="card-icon">💡</span>
      <div class="card-title"><?= t('Qui me connaît le mieux ?','Кто знает меня лучше?') ?></div>
      <div class="card-desc"><?= t('Questions sur l\'autre — testez votre connaissance mutuelle.','Вопросы друг о друге — проверьте, как хорошо вы знаете друг друга.') ?></div>
    </a>

    <!-- Nos Médias -->
    <a class="card" href="<?= BASE_URL ?>/medias.php">
      <span class="card-stat"><?= $nb_musiques + $nb_films ?> <?= t('médias','медиа') ?></span>
      <span class="card-icon">🎵</span>
      <div class="card-title"><?= t('Nos Médias','Наши Медиа') ?></div>
      <div class="card-desc"><?= t('Musique et films — nos coups de cœur partagés.','Музыка и фильмы — наши общие избранные.') ?></div>
      <div class="card-hint"><?= $nb_musiques ?> <?= t('chansons','песен') ?> · <?= $nb_films ?> <?= t('films','фильмов') ?></div>
    </a>

    <!-- Profil -->
    <a class="card" href="<?= BASE_URL ?>/profil.php">
      <span class="card-icon">⚙</span>
      <div class="card-title"><?= t('Mon Profil','Мой Профиль') ?></div>
      <div class="card-desc"><?= t('Changer le nom, l\'avatar, le mot de passe, le PIN du coffre.','Изменить имя, аватар, пароль, PIN сейфа.') ?></div>
    </a>

  </div>
</div>
</body>
</html>
