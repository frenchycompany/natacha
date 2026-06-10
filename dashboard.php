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
    session_write_close();
    header('Location: '.BASE_URL.'/dashboard.php'); exit;
}

// AJAX: update love start date
if (isset($_POST['action']) && $_POST['action'] === 'update_start_date' && csrfVerify()) {
    header('Content-Type: application/json');
    $date = $_POST['start_date'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
        setSetting('love_start_date', $date);
        $days = (int)((time() - strtotime($date)) / 86400);
        echo json_encode(['ok' => true, 'days' => $days]);
    } else {
        echo json_encode(['ok' => false, 'error' => t('Date invalide','Неверная дата')]);
    }
    exit;
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

// Prochain événement calendrier
$next_cal_event = null;
try {
    $cy = (int)date('Y');
    $td = date('Y-m-d');
    $next_cal_event = db()->prepare("
        SELECT *,
            CASE WHEN recurrent = 1 THEN
                CASE
                    WHEN CONCAT(?, '-', LPAD(MONTH(date_event),2,'0'), '-', LPAD(DAY(date_event),2,'0')) >= ?
                    THEN CONCAT(?, '-', LPAD(MONTH(date_event),2,'0'), '-', LPAD(DAY(date_event),2,'0'))
                    ELSE CONCAT(?+1, '-', LPAD(MONTH(date_event),2,'0'), '-', LPAD(DAY(date_event),2,'0'))
                END
            ELSE date_event END AS next_date
        FROM calendrier_events
        HAVING next_date >= ?
        ORDER BY next_date ASC
        LIMIT 1
    ");
    $next_cal_event->execute([$cy, $td, $cy, $cy, $td]);
    $next_cal_event = $next_cal_event->fetch();
} catch(Exception $e) { $next_cal_event = null; }

// ═══ Mot du jour ═══
try { db()->query("SELECT 1 FROM mots_du_jour LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_mots_du_jour.sql'));
}

// AJAX: save mot du jour
if (isset($_POST['action']) && $_POST['action'] === 'save_mot' && csrfVerify()) {
    header('Content-Type: application/json');
    $msg = trim($_POST['message'] ?? '');
    if (!$msg || mb_strlen($msg) > 280) {
        echo json_encode(['ok' => false, 'error' => t('Message vide ou trop long','Сообщение пустое или слишком длинное')]);
        exit;
    }
    // Check if already wrote today
    $existing = db()->prepare("SELECT id FROM mots_du_jour WHERE user_id=? AND date_mot=CURDATE()");
    $existing->execute([$user['id']]);
    if ($existing->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'already_sent']);
        exit;
    }
    $stmt = db()->prepare("INSERT INTO mots_du_jour (user_id, message, date_mot) VALUES (?, ?, CURDATE())");
    $stmt->execute([$user['id'], $msg]);
    echo json_encode(['ok' => true]);
    exit;
}

// Mot reçu de l'autre (pour aujourd'hui)
$mot_recu = null;
try {
    $stmt = db()->prepare("SELECT m.id, m.message, m.message_translated, m.created_at, u.display_name, u.avatar, u.lang AS author_lang
        FROM mots_du_jour m JOIN users u ON u.id = m.user_id
        WHERE m.user_id != ? AND m.date_mot = CURDATE() LIMIT 1");
    $stmt->execute([$user['id']]);
    $mot_recu = $stmt->fetch();
    if ($mot_recu) {
        // Use cached translation if available, otherwise translate + cache
        if (!empty($mot_recu['message_translated'])) {
            $mot_recu['traduction'] = $mot_recu['message_translated'];
        } else {
            $fromLang = $mot_recu['author_lang'] === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $trad = translateText($mot_recu['message'], $fromLang, $toLang);
            $mot_recu['traduction'] = $trad;
            if ($trad) {
                try {
                    db()->prepare("UPDATE mots_du_jour SET message_translated=?, message_lang=? WHERE id=?")
                        ->execute([$trad, $fromLang, $mot_recu['id']]);
                } catch (Exception $e) {}
            }
        }

        // Load reaction for mot du jour
        try {
            $motReaction = db()->prepare("SELECT 1 FROM reactions WHERE user_id=? AND item_type='mot_du_jour' AND item_id=?");
            $motReaction->execute([$user['id'], $mot_recu['id'] ?? 0]);
            $mot_recu['liked'] = (bool)$motReaction->fetch();

            $motReactCount = db()->prepare("SELECT COUNT(*) FROM reactions WHERE item_type='mot_du_jour' AND item_id=?");
            $motReactCount->execute([$mot_recu['id'] ?? 0]);
            $mot_recu['like_count'] = (int)$motReactCount->fetchColumn();
        } catch (Exception $e) {
            $mot_recu['liked'] = false;
            $mot_recu['like_count'] = 0;
        }
    }
} catch (Exception $e) {}

// Mon mot du jour (déjà envoyé ?)
$mon_mot = null;
try {
    $stmt = db()->prepare("SELECT message FROM mots_du_jour WHERE user_id=? AND date_mot=CURDATE()");
    $stmt->execute([$user['id']]);
    $mon_mot = $stmt->fetch();
} catch (Exception $e) {}

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

/* Section labels */
.section-label{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--accent);margin:2.5rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.section-label:first-of-type{margin-top:0}

.card-defi{grid-column:1/-1;border-color:var(--accent);box-shadow:0 0 30px rgba(201,169,110,.08),0 0 60px rgba(201,169,110,.03)}
.card-defi::before{opacity:.3}
.card-defi .card-title{font-size:1.6rem}
.defi-preview{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--text);line-height:1.5;margin:.6rem 0 .3rem}
.defi-alt{font-size:.6rem;color:var(--muted);font-style:italic}
.defi-meta{display:flex;gap:.8rem;align-items:center;margin-top:.8rem;flex-wrap:wrap}
.defi-cat{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;padding:.15rem .45rem;border:1px solid}
.defi-diff{font-size:.6rem;letter-spacing:.15em}

/* Mot du jour */
.mot-du-jour{margin-bottom:2.5rem;position:relative}
.mot-recu{background:var(--s);border:1px solid rgba(201,169,110,.25);padding:1.5rem 2rem;text-align:center;position:relative;animation:motIn .6s ease}
@keyframes motIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
.mot-recu::before,.mot-recu::after{content:'';position:absolute;top:0;height:2px;background:linear-gradient(90deg,transparent,var(--accent),transparent);width:60%}
.mot-recu::before{left:20%}
.mot-recu::after{bottom:0;top:auto;left:20%}
.mot-recu-label{font-size:.5rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:.8rem}
.mot-recu-text{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent);line-height:1.6}
.mot-recu-trad{font-family:'Cormorant Garamond',serif;font-size:.85rem;font-weight:300;font-style:italic;color:var(--muted);margin-top:.4rem;opacity:.7}
.mot-recu-from{font-size:.55rem;letter-spacing:.12em;color:var(--muted);margin-top:.8rem}

.mot-ecrire{margin-top:1rem;text-align:center}
.mot-input-wrap{display:flex;gap:.5rem;align-items:center;justify-content:center;max-width:500px;margin:0 auto}
.mot-input{flex:1;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1rem;font-style:italic;padding:.6rem 1rem;outline:none;transition:border-color .2s;text-align:center}
.mot-input:focus{border-color:var(--accent)}
.mot-input::placeholder{color:var(--muted);font-style:italic}
.mot-send{background:var(--as);border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.1em;padding:.55rem .8rem;cursor:pointer;transition:all .2s;white-space:nowrap}
.mot-send:hover{background:var(--accent);color:var(--bg)}
.mot-send:disabled{opacity:.4;cursor:not-allowed}
.mot-sent{font-size:.6rem;color:var(--muted);font-style:italic;text-align:center;margin-top:.6rem}
.mot-sent em{color:var(--accent)}
.mot-counter{font-size:.5rem;color:var(--muted);margin-top:.3rem}

.heart-btn{background:none;border:none;cursor:pointer;font-size:1rem;display:flex;align-items:center;gap:.3rem;padding:.3rem;transition:transform .2s;margin:.5rem auto 0}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.55rem;color:var(--muted);font-family:'DM Mono',monospace}

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
    <?php $love_start = getSetting('love_start_date', '2024-07-14'); $days_together = (int)((time() - strtotime($love_start)) / 86400); ?>
    <p id="love-counter" style="font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--accent);font-size:1.1rem;margin-top:.8rem;opacity:.85">
      ❤ <span id="love-days"><?= $days_together ?></span> <?= t('jours d\'amour','дней любви') ?>
      <span id="love-edit-btn" title="<?= t('Modifier la date','Изменить дату') ?>" style="cursor:pointer;font-size:.75rem;opacity:.4;margin-left:.3rem;transition:opacity .2s;font-style:normal">&#9998;</span>
    </p>
    <div id="love-date-editor" style="display:none;margin-top:.5rem;align-items:center;gap:.5rem">
      <input type="date" id="love-date-input" value="<?= h($love_start) ?>" style="background:var(--s);border:1px solid var(--border);color:var(--accent);font-family:'DM Mono',monospace;font-size:.7rem;padding:.3rem .5rem;outline:none">
      <button id="love-date-save" style="background:var(--as);border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.1em;padding:.3rem .7rem;cursor:pointer;transition:all .2s"><?= t('OK','OK') ?></button>
      <button id="love-date-cancel" style="background:transparent;border:1px solid var(--border);color:var(--muted);font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.1em;padding:.3rem .7rem;cursor:pointer;transition:all .2s"><?= t('Annuler','Отмена') ?></button>
    </div>
  </div>

  <!-- ═══ Mot du jour ═══ -->
  <div class="mot-du-jour">
    <?php if ($mot_recu): ?>
    <div class="mot-recu">
      <div class="mot-recu-label">💌 <?= t('Petit mot du jour','Записка дня') ?></div>
      <div class="mot-recu-text">&laquo; <?= h($mot_recu['message']) ?> &raquo;</div>
      <?php if (!empty($mot_recu['traduction'])): ?>
      <div class="mot-recu-trad">&laquo; <?= h($mot_recu['traduction']) ?> &raquo;</div>
      <?php endif; ?>
      <div class="mot-recu-from">— <?= h($mot_recu['display_name']) ?></div>
      <button class="heart-btn <?= $mot_recu['liked'] ? 'liked' : '' ?>" id="motHeart"
          onclick="toggleMotHeart(this, <?= (int)($mot_recu['id'] ?? 0) ?>)">
          <?= $mot_recu['liked'] ? '❤️' : '🤍' ?>
          <span class="heart-count"><?= $mot_recu['like_count'] ?: '' ?></span>
      </button>
    </div>
    <?php endif; ?>

    <?php if ($mon_mot): ?>
    <div class="mot-sent">
      ✓ <?= t('Ton mot du jour','Твоя записка дня') ?> : <em>&laquo; <?= h($mon_mot['message']) ?> &raquo;</em>
      <div class="mot-counter"><?= t('Prochain mot demain','Следующая записка завтра') ?> ✨</div>
    </div>
    <?php else: ?>
    <div class="mot-ecrire" id="motEcrire">
      <div style="font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem">
        ✍ <?= t('Écris un petit mot pour l\'autre','Напиши записку для другого') ?>
      </div>
      <div class="mot-input-wrap">
        <input type="text" class="mot-input" id="motInput" maxlength="280" placeholder="<?= t('Une pensée, un mot doux…','Мысль, ласковое слово…') ?>">
        <button class="mot-send" id="motSend" disabled onclick="sendMot()"><?= t('Envoyer','Отправить') ?></button>
      </div>
      <div class="mot-counter" id="motCounter">0 / 280</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ═══ Défi du jour (mis en avant) ═══ -->
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
  <a class="card card-defi" href="<?= BASE_URL ?>/defis.php" style="margin-bottom:2.5rem">
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

  <!-- ═══ 💛 NOTRE COUPLE ═══ -->
  <div class="section-label">💛 <?= t('Notre Couple','Наша Пара') ?></div>
  <div class="grid">
    <a class="card" href="<?= BASE_URL ?>/couple.php">
      <span class="card-icon">🌱</span>
      <div class="card-title"><?= t('Notre Couple','Наша Пара') ?></div>
      <div class="card-desc"><?= t('Avatar vivant, jauges, humeur, activité.','Живой аватар, шкалы, настроение, активность.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/histoire.php">
      <span class="card-stat"><?= $nb_chapitres ?> <?= t('chapitres','глав') ?></span>
      <span class="card-icon">📖</span>
      <div class="card-title"><?= t('Notre Histoire','Наша История') ?></div>
      <div class="card-desc"><?= t('Journal partagé, chapitres, moments.','Общий дневник, главы, моменты.') ?></div>
      <?php if ($dernier_chap): ?>
      <div class="card-hint"><?= t('Dernier :','Последнее:') ?> <?= h($dernier_chap['titre']) ?></div>
      <?php endif; ?>
    </a>
    <a class="card" href="<?= BASE_URL ?>/calendrier.php">
      <span class="card-icon">📅</span>
      <div class="card-title"><?= t('Calendrier','Календарь') ?></div>
      <div class="card-desc"><?= t('Dates importantes et anniversaires.','Важные даты и годовщины.') ?></div>
      <?php if ($next_cal_event): ?>
      <?php
        $cal_title = $lang === 'ru' && $next_cal_event['titre_ru'] ? $next_cal_event['titre_ru'] : $next_cal_event['titre_fr'];
        $cal_diff = (int)((strtotime($next_cal_event['next_date']) - strtotime(date('Y-m-d'))) / 86400);
        $cal_countdown = $cal_diff === 0 ? t("Aujourd'hui","Сегодня") : ($cal_diff === 1 ? t('Demain','Завтра') : ($lang === 'ru' ? "через $cal_diff дн." : "dans $cal_diff j."));
      ?>
      <div class="card-hint" style="color:<?= h($next_cal_event['couleur']) ?>"><?= h($cal_title) ?> — <?= $cal_countdown ?></div>
      <?php endif; ?>
    </a>
  </div>

  <!-- ═══ 🎮 SE DIVERTIR ═══ -->
  <div class="section-label">🎮 <?= t('Se Divertir','Развлечения') ?></div>
  <div class="grid">
    <a class="card" href="<?= BASE_URL ?>/jeux.php">
      <span class="card-icon">🎲</span>
      <div class="card-title"><?= t('Nos Jeux','Наши Игры') ?></div>
      <div class="card-desc"><?= t('Action ou Vérité, quiz, et plus.','Правда или Действие, викторины и другое.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/defis.php">
      <span class="card-icon">🎯</span>
      <div class="card-title"><?= t('Défis','Вызовы') ?></div>
      <div class="card-desc"><?= t('Un défi par jour à relever ensemble.','Один вызов в день, который нужно принять вместе.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/questionnaires.php">
      <span class="card-stat"><?= $nb_quizz ?> <?= t('QCM','тестов') ?></span>
      <span class="card-icon">💌</span>
      <div class="card-title"><?= t('Nos QCM','Наши Тесты') ?></div>
      <div class="card-desc"><?= t('Créer et remplir nos questionnaires.','Создавать и заполнять анкеты.') ?></div>
    </a>
  </div>

  <!-- ═══ 📝 NOS JOURNAUX ═══ -->
  <div class="section-label">📝 <?= t('Nos Journaux','Наши Дневники') ?></div>
  <div class="grid">
    <a class="card" href="<?= BASE_URL ?>/gratitude.php">
      <span class="card-icon">🙏</span>
      <div class="card-title"><?= t('Merci pour...','Спасибо за...') ?></div>
      <div class="card-desc"><?= t('Gratitude quotidienne, dire merci.','Ежедневная благодарность.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/best_moment.php">
      <span class="card-icon">✨</span>
      <div class="card-title"><?= t('Plus Beau Moment','Лучший Момент') ?></div>
      <div class="card-desc"><?= t('Le meilleur moment de la journée.','Лучший момент дня.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/reves_projets.php">
      <span class="card-icon">🌠</span>
      <div class="card-title"><?= t('Rêves & Projets','Мечты и Планы') ?></div>
      <div class="card-desc"><?= t('Voyages, expériences, objectifs.','Путешествия, впечатления, цели.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/livre_secret.php">
      <span class="card-icon">🌹</span>
      <div class="card-title"><?= t('Le Jardin Secret','Тайный Сад') ?></div>
      <div class="card-desc"><?= t('Désirs et pensées intimes.','Желания и интимные мысли.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/mots_historique.php">
      <span class="card-icon">💌</span>
      <div class="card-title"><?= t('Nos Petits Mots','Наши Записки') ?></div>
      <div class="card-desc"><?= t('Historique de nos mots du jour.','История наших записок дня.') ?></div>
    </a>
  </div>

  <!-- ═══ 💭 QUOTIDIEN ═══ -->
  <div class="section-label">💭 <?= t('Quotidien','Ежедневное') ?></div>
  <div class="grid">
    <a class="card" href="<?= BASE_URL ?>/question_du_jour.php">
      <span class="card-icon">💭</span>
      <div class="card-title"><?= t('Question du Jour','Вопрос Дня') ?></div>
      <div class="card-desc"><?= t('Une question par jour, on compare nos réponses.','Один вопрос в день, сравниваем ответы.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/mood_tracker.php">
      <span class="card-icon">🌈</span>
      <div class="card-title"><?= t('Notre Humeur','Наше Настроение') ?></div>
      <div class="card-desc"><?= t('Suivi d\'humeur quotidien du couple.','Ежедневный трекер настроения пары.') ?></div>
    </a>
  </div>

  <!-- ═══ 🔧 OUTILS ═══ -->
  <div class="section-label">🔧 <?= t('Outils','Инструменты') ?></div>
  <div class="grid">
    <a class="card" href="<?= BASE_URL ?>/carte.php">
      <span class="card-stat"><?= $nb_lieux ?> <?= t('lieux','мест') ?></span>
      <span class="card-icon">🗺</span>
      <div class="card-title"><?= t('Notre Carte','Наша Карта') ?></div>
      <div class="card-desc"><?= t('Nos lieux visités ensemble.','Места, которые мы посетили.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/medias.php">
      <span class="card-stat"><?= $nb_musiques + $nb_films ?></span>
      <span class="card-icon">🎵</span>
      <div class="card-title"><?= t('Médias','Медиа') ?></div>
      <div class="card-desc"><?= t('Musique et films partagés.','Музыка и фильмы.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/coffre_fort.php">
      <span class="card-icon">🔐</span>
      <div class="card-title"><?= t('Coffre-Fort','Сейф') ?></div>
      <div class="card-desc"><?= t('Fichiers chiffrés et privés.','Зашифрованные файлы.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/galerie.php">
      <span class="card-icon">📸</span>
      <div class="card-title"><?= t('Galerie','Галерея') ?></div>
      <div class="card-desc"><?= t('Photos du coffre en mosaïque.','Фото из сейфа в мозаике.') ?></div>
    </a>
    <a class="card" href="<?= BASE_URL ?>/profil.php">
      <span class="card-icon">⚙</span>
      <div class="card-title"><?= t('Mon Profil','Мой Профиль') ?></div>
      <div class="card-desc"><?= t('Nom, avatar, mot de passe.','Имя, аватар, пароль.') ?></div>
    </a>
  </div>
</div>
<script>
// ═══ Mot du jour ═══
(function(){
  const input = document.getElementById('motInput');
  const sendBtn = document.getElementById('motSend');
  const counter = document.getElementById('motCounter');
  if (!input) return;

  input.addEventListener('input', function(){
    const len = input.value.length;
    counter.textContent = len + ' / 280';
    sendBtn.disabled = len === 0;
  });

  input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !sendBtn.disabled) sendMot();
  });
})();

function sendMot(){
  const input = document.getElementById('motInput');
  const msg = input.value.trim();
  if (!msg) return;
  const btn = document.getElementById('motSend');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'save_mot');
  fd.append('csrf_token', '<?= csrfToken() ?>');
  fd.append('message', msg);
  fetch('<?= BASE_URL ?>/dashboard.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        const wrap = document.getElementById('motEcrire');
        wrap.innerHTML = '<div class="mot-sent">✓ <?= t("Ton mot du jour","Твоя записка дня") ?> : <em>&laquo; ' + msg.replace(/</g,'&lt;') + ' &raquo;</em><div class="mot-counter"><?= t("Prochain mot demain","Следующая записка завтра") ?> ✨</div></div>';
      } else if (data.error === 'already_sent') {
        btn.disabled = true;
        input.disabled = true;
        input.value = '<?= t("Déjà envoyé aujourd\\'hui","Уже отправлено сегодня") ?>';
      }
    })
    .catch(() => { btn.disabled = false; });
}

// ═══ Heart reaction for mot du jour ═══
function toggleMotHeart(btn, id) {
    const fd = new FormData();
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fd.append('item_type', 'mot_du_jour');
    fd.append('item_id', id);
    fetch('<?= BASE_URL ?>/api/react.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                btn.querySelector('.heart-count').textContent = data.count || '';
                btn.childNodes[0].textContent = data.liked ? '❤️' : '🤍';
            }
        });
}

// ═══ Love counter editor ═══
(function(){
  const btn = document.getElementById('love-edit-btn');
  const editor = document.getElementById('love-date-editor');
  const input = document.getElementById('love-date-input');
  const saveBtn = document.getElementById('love-date-save');
  const cancelBtn = document.getElementById('love-date-cancel');
  const daysSpan = document.getElementById('love-days');
  const csrf = '<?= csrfToken() ?>';

  btn.addEventListener('mouseenter', function(){ this.style.opacity='1'; });
  btn.addEventListener('mouseleave', function(){ this.style.opacity='.4'; });

  btn.addEventListener('click', function(){
    editor.style.display = editor.style.display === 'none' ? 'flex' : 'none';
  });

  cancelBtn.addEventListener('click', function(){
    editor.style.display = 'none';
  });

  saveBtn.addEventListener('click', function(){
    const date = input.value;
    if (!date) return;
    const fd = new FormData();
    fd.append('action', 'update_start_date');
    fd.append('start_date', date);
    fd.append('csrf_token', csrf);
    fetch('<?= BASE_URL ?>/dashboard.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          daysSpan.textContent = data.days;
          editor.style.display = 'none';
        } else {
          alert(data.error || <?= json_encode(t('Erreur','Ошибка')) ?>);
        }
      })
      .catch(() => alert(<?= json_encode(t('Erreur réseau','Ошибка сети')) ?>));
  });
})();
</script>
<?php include __DIR__."/includes/bottom_nav.php"; ?>
</body>
</html>
