<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/notifications.php';
securityHeaders();
requireLogin();
$user = currentUser();
$lang = $user['lang'];

// ── Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    if (isset($_POST['mark_all_read'])) {
        markAllAsRead($user['id']);
        header('Location: '.BASE_URL.'/notifications.php');
        exit;
    }
    if (isset($_POST['mark_read']) && is_numeric($_POST['mark_read'])) {
        markAsRead((int)$_POST['mark_read'], $user['id']);
        header('Location: '.BASE_URL.'/notifications.php');
        exit;
    }
}

$notifications = getNotifications($user['id'], 50);
$unreadCount   = getUnreadCount($user['id']);

// Icon mapping by notification type
function notifIcon(string $type): string {
    $icons = [
        'histoire'      => '📖',
        'questionnaire' => '💌',
        'jeu'           => '🎲',
        'coffre'        => '🔐',
        'message'       => '✉️',
        'photo'         => '📷',
        'gratitude'     => '🙏',
        'livre_secret'  => '🌹',
        'reve'          => '⭐',
        'moment'        => '📝',
        'reaction'      => '❤️',
        'calendrier'    => '📅',
        'lieu'          => '📍',
        'musique'       => '🎵',
        'film'          => '🎬',
    ];
    return $icons[$type] ?? '🔔';
}

function timeAgo(string $datetime, string $lang): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)   return $lang === 'ru' ? 'только что'            : "à l'instant";
    if ($diff < 3600) return ($lang === 'ru' ? '' : 'il y a ') . floor($diff/60)  . ($lang === 'ru' ? ' мин. назад' : ' min');
    if ($diff < 86400) return ($lang === 'ru' ? '' : 'il y a ') . floor($diff/3600) . ($lang === 'ru' ? ' ч. назад'  : ' h');
    if ($diff < 604800) return ($lang === 'ru' ? '' : 'il y a ') . floor($diff/86400) . ($lang === 'ru' ? ' дн. назад' : ' j');
    return date('d/m/Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Notifications','Уведомления') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}

.wrap{max-width:700px;margin:0 auto;padding:2.5rem 2rem}

.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;flex-wrap:wrap;gap:1rem}
.page-head h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,3.5vw,2.2rem);font-weight:300;font-style:italic;color:var(--text)}
.page-head h2 em{color:var(--accent)}
.badge{background:var(--accent);color:var(--bg);font-size:.6rem;padding:.15rem .45rem;border-radius:2px;vertical-align:middle;margin-left:.4rem;font-style:normal}

.mark-all{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.35rem .8rem;cursor:pointer;font-family:inherit;transition:all .2s}
.mark-all:hover{background:var(--as)}

.notif-list{display:flex;flex-direction:column;gap:0}

.notif{display:flex;align-items:flex-start;gap:1rem;padding:1.2rem 1rem;border-bottom:1px solid var(--border);transition:background .2s;position:relative}
.notif.unread{background:var(--as);border-left:2px solid var(--accent)}
.notif.read{opacity:.6}
.notif:hover{background:rgba(201,169,110,.06)}

.notif-icon{font-size:1.4rem;flex-shrink:0;margin-top:.1rem}
.notif-body{flex:1;min-width:0}
.notif-msg{font-size:.72rem;line-height:1.6;color:var(--text);margin-bottom:.3rem}
.notif-msg a{color:var(--accent);text-decoration:none;border-bottom:1px solid rgba(201,169,110,.3)}
.notif-msg a:hover{border-color:var(--accent)}
.notif-meta{font-size:.55rem;color:var(--muted);letter-spacing:.08em;display:flex;align-items:center;gap:.8rem}

.notif-actions{flex-shrink:0;display:flex;align-items:center}
.mark-btn{background:transparent;border:1px solid var(--border);color:var(--muted);font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;cursor:pointer;font-family:inherit;transition:all .2s}
.mark-btn:hover{border-color:var(--accent);color:var(--accent)}

.empty{text-align:center;padding:4rem 1rem;color:var(--muted);font-size:.7rem;letter-spacing:.08em}
.empty-icon{font-size:2.5rem;margin-bottom:1rem;display:block}
@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
}
</style>
</head>
<body>

<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">🔔 <?= t('Notifications','Уведомления') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="page-head">
    <h2><?= t('Vos <em>notifications</em>','Ваши <em>уведомления</em>') ?>
      <?php if ($unreadCount > 0): ?><span class="badge"><?= $unreadCount ?></span><?php endif; ?>
    </h2>
    <?php if ($unreadCount > 0): ?>
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <button type="submit" name="mark_all_read" value="1" class="mark-all"><?= t('Tout marquer lu','Отметить все') ?></button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="empty">
      <span class="empty-icon">🔕</span>
      <?= t('Aucune notification pour le moment.','Пока нет уведомлений.') ?>
    </div>
  <?php else: ?>
    <div class="notif-list">
    <?php foreach ($notifications as $n): ?>
      <div class="notif <?= $n['is_read'] ? 'read' : 'unread' ?>">
        <div class="notif-icon"><?= notifIcon($n['type']) ?></div>
        <div class="notif-body">
          <div class="notif-msg">
            <?php
              $msg = $lang === 'ru' ? $n['message_ru'] : $n['message_fr'];
              if ($n['link']) {
                  echo '<a href="'.h($n['link']).'">'.h($msg).'</a>';
              } else {
                  echo h($msg);
              }
            ?>
          </div>
          <div class="notif-meta">
            <span><?= timeAgo($n['created_at'], $lang) ?></span>
            <span><?= h($n['type']) ?></span>
          </div>
        </div>
        <?php if (!$n['is_read']): ?>
        <div class="notif-actions">
          <form method="POST">
            <?= csrfField() ?>
            <button type="submit" name="mark_read" value="<?= $n['id'] ?>" class="mark-btn"><?= t('Lu','Прочитано') ?></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
<?php include __DIR__."/includes/bottom_nav.php"; ?>
</body>
</html>
