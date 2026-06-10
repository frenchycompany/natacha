<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Ensure table exists
try { db()->query("SELECT 1 FROM calendrier_events LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_calendrier.sql'));
}

// Category colors
$catColors = [
    'anniversaire' => '#c9a96e',
    'voyage'       => '#6ea9c9',
    'souvenir'     => '#c96e9a',
    'rdv'          => '#6ec98a',
    'autre'        => '#7a7268',
];

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $titre_fr = trim($_POST['titre_fr'] ?? '');
        $titre_ru = trim($_POST['titre_ru'] ?? '');
        $desc_fr  = trim($_POST['description_fr'] ?? '');
        $desc_ru  = trim($_POST['description_ru'] ?? '');
        $date     = $_POST['date_event'] ?? '';
        $recurrent = isset($_POST['recurrent']) ? 1 : 0;
        $categorie = $_POST['categorie'] ?? 'autre';
        $couleur   = $_POST['couleur'] ?? '#c9a96e';

        if ($titre_fr && $date) {
            if (!in_array($categorie, ['anniversaire','voyage','souvenir','rdv','autre'])) $categorie = 'autre';
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) $couleur = $catColors[$categorie] ?? '#c9a96e';

            db()->prepare("INSERT INTO calendrier_events (user_id, titre_fr, titre_ru, description_fr, description_ru, date_event, recurrent, categorie, couleur) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$user['id'], $titre_fr, $titre_ru, $desc_fr, $desc_ru, $date, $recurrent, $categorie, $couleur]);

            // Notify partner
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($user['id'], 'calendrier',
                    $user['display_name'].' a ajouté un événement : '.$titre_fr,
                    $user['display_name'].' добавил(а) событие : '.($titre_ru ?: $titre_fr),
                    BASE_URL.'/calendrier.php');
            } catch (Exception $e) {}
        }
        header('Location: '.BASE_URL.'/calendrier.php?month='.(isset($_POST['nav_month']) ? $_POST['nav_month'] : date('Y-m'))); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM calendrier_events WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
        }
        header('Location: '.BASE_URL.'/calendrier.php?month='.($_POST['nav_month'] ?? date('Y-m'))); exit;
    }

    if ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $titre_fr  = trim($_POST['titre_fr'] ?? '');
        $titre_ru  = trim($_POST['titre_ru'] ?? '');
        $desc_fr   = trim($_POST['description_fr'] ?? '');
        $desc_ru   = trim($_POST['description_ru'] ?? '');
        $date      = $_POST['date_event'] ?? '';
        $recurrent = isset($_POST['recurrent']) ? 1 : 0;
        $categorie = $_POST['categorie'] ?? 'autre';
        $couleur   = $_POST['couleur'] ?? '#c9a96e';

        if ($id && $titre_fr && $date) {
            if (!in_array($categorie, ['anniversaire','voyage','souvenir','rdv','autre'])) $categorie = 'autre';
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) $couleur = $catColors[$categorie] ?? '#c9a96e';

            db()->prepare("UPDATE calendrier_events SET titre_fr=?, titre_ru=?, description_fr=?, description_ru=?, date_event=?, recurrent=?, categorie=?, couleur=? WHERE id=? AND user_id=?")
               ->execute([$titre_fr, $titre_ru, $desc_fr, $desc_ru, $date, $recurrent, $categorie, $couleur, $id, $user['id']]);
        }
        header('Location: '.BASE_URL.'/calendrier.php?month='.($_POST['nav_month'] ?? date('Y-m'))); exit;
    }
}

// Current month navigation
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) $monthParam = date('Y-m');
$year  = (int)substr($monthParam, 0, 4);
$month = (int)substr($monthParam, 5, 2);
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2099) { $year = (int)date('Y'); $month = (int)date('n'); }

$firstDay  = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDay);
$startDow  = ((int)date('N', $firstDay)); // 1=Mon, 7=Sun

$prevMonth = $month === 1 ? ($year-1).'-12' : $year.'-'.str_pad($month-1, 2, '0', STR_PAD_LEFT);
$nextMonth = $month === 12 ? ($year+1).'-01' : $year.'-'.str_pad($month+1, 2, '0', STR_PAD_LEFT);

$monthNamesFr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$monthNamesRu = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
$monthName = $lang === 'ru' ? $monthNamesRu[$month] : $monthNamesFr[$month];

// Fetch events for this month (including recurring from other years)
$stmt = db()->prepare("SELECT * FROM calendrier_events WHERE (MONTH(date_event) = ? AND YEAR(date_event) = ?) OR (recurrent = 1 AND MONTH(date_event) = ?) ORDER BY date_event ASC");
$stmt->execute([$month, $year, $month]);
$allEvents = $stmt->fetchAll();

// Build events by day
$eventsByDay = [];
foreach ($allEvents as $ev) {
    $evYear  = (int)substr($ev['date_event'], 0, 4);
    $evMonth = (int)substr($ev['date_event'], 5, 2);
    $evDay   = (int)substr($ev['date_event'], 8, 2);
    if ($evMonth == $month && ($evYear == $year || $ev['recurrent'])) {
        $eventsByDay[$evDay][] = $ev;
    }
}

// Selected day
$selectedDay = isset($_GET['day']) ? (int)$_GET['day'] : null;
if ($selectedDay && ($selectedDay < 1 || $selectedDay > $daysInMonth)) $selectedDay = null;
$selectedEvents = $selectedDay ? ($eventsByDay[$selectedDay] ?? []) : [];

// Upcoming events (next 5)
$today = date('Y-m-d');
$stmtUpcoming = db()->prepare("
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
    LIMIT 5
");
$cy = (int)date('Y');
$stmtUpcoming->execute([$cy, $today, $cy, $cy, $today]);
$upcoming = $stmtUpcoming->fetchAll();

$todayDay   = (int)date('j');
$todayMonth = (int)date('n');
$todayYear  = (int)date('Y');

// Fetch ALL events for the list view
$stmtAll = db()->prepare("SELECT * FROM calendrier_events ORDER BY date_event ASC");
$stmtAll->execute();
$allEventsList = $stmtAll->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Calendrier','Календарь') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<script src="<?= BASE_URL ?>/includes/autotranslate.js"></script>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}

.wrap{max-width:900px;margin:0 auto;padding:2rem}

.page-title{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,4vw,2.2rem);font-weight:300;font-style:italic;color:var(--text);margin-bottom:2rem;text-align:center}
.page-title em{color:var(--accent)}

/* Calendar navigation */
.cal-nav{display:flex;justify-content:center;align-items:center;gap:1.5rem;margin-bottom:1.5rem}
.cal-nav a{color:var(--muted);text-decoration:none;font-size:1.2rem;padding:.3rem .6rem;border:1px solid var(--border);transition:all .2s}
.cal-nav a:hover{color:var(--accent);border-color:var(--accent)}
.cal-nav .month-label{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-style:italic;color:var(--accent);min-width:200px;text-align:center}

/* Calendar grid */
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border:1px solid var(--border);margin-bottom:2rem}
.cal-header{background:var(--s);padding:.6rem .3rem;text-align:center;font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.cal-day{background:var(--bg);padding:.5rem;min-height:70px;position:relative;cursor:pointer;transition:all .2s}
.cal-day:hover{background:var(--s)}
.cal-day.empty{cursor:default;background:rgba(15,13,11,.5)}
.cal-day.empty:hover{background:rgba(15,13,11,.5)}
.cal-day.today{background:var(--as);border:1px solid var(--accent)}
.cal-day.selected{background:rgba(201,169,110,.15);border:1px solid var(--accent)}
.cal-day .day-num{font-size:.75rem;color:var(--muted);margin-bottom:.3rem}
.cal-day.today .day-num{color:var(--accent);font-weight:bold}
.cal-day .event-dots{display:flex;gap:3px;flex-wrap:wrap}
.event-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}

@media(max-width:600px){
    .cal-day{min-height:50px;padding:.3rem}
    .cal-day .day-num{font-size:.6rem}
    .event-dot{width:5px;height:5px}
}

/* Day events panel */
.day-panel{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.day-panel h3{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem}
.day-event{display:flex;align-items:flex-start;gap:.8rem;padding:.8rem 0;border-bottom:1px solid var(--border)}
.day-event:last-child{border-bottom:none}
.day-event .ev-color{width:4px;min-height:30px;border-radius:2px;flex-shrink:0;margin-top:.2rem}
.day-event .ev-info{flex:1}
.day-event .ev-title{font-size:.8rem;color:var(--text);margin-bottom:.2rem}
.day-event .ev-desc{font-size:.6rem;color:var(--muted);line-height:1.6}
.day-event .ev-cat{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);background:var(--bg);padding:.15rem .4rem;display:inline-block;margin-top:.3rem}
.day-event .ev-recur{font-size:.5rem;color:var(--accent);margin-left:.5rem}
.btn-del{background:transparent;border:1px solid #c96e6e;color:#c96e6e;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s;margin-top:.3rem}
.btn-del:hover{background:#c96e6e;color:var(--bg)}
.no-events{font-size:.65rem;color:var(--muted);font-style:italic}

/* Add event form */
.add-section{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.add-section h3{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem}
.toggle-form{background:transparent;border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;padding:.4rem 1rem;cursor:pointer;transition:all .2s;margin-bottom:1rem;display:inline-block}
.toggle-form:hover{background:var(--as)}
.form-hidden{display:none}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem}
.form-row.full{grid-template-columns:1fr}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.form-group label{display:block;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.form-group input,.form-group select,.form-group textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;padding:.5rem;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--accent)}
.form-group textarea{resize:vertical;min-height:60px}
.form-group select{appearance:none;cursor:pointer}
.form-check{display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem}
.form-check input[type="checkbox"]{accent-color:var(--accent);width:14px;height:14px}
.form-check label{font-size:.65rem;color:var(--muted);cursor:pointer}
.color-options{display:flex;gap:.5rem;margin-bottom:.8rem}
.color-opt{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:all .2s}
.color-opt:hover,.color-opt.active{border-color:var(--text);transform:scale(1.15)}
.color-opt input{display:none}
.btn-submit{background:var(--accent);color:var(--bg);border:none;font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.12em;text-transform:uppercase;padding:.5rem 1.5rem;cursor:pointer;transition:all .2s}
.btn-submit:hover{opacity:.85}

/* Upcoming */
.upcoming{background:var(--s);border:1px solid var(--border);padding:1.5rem}
.upcoming h3{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem}
.up-item{display:flex;align-items:center;gap:.8rem;padding:.6rem 0;border-bottom:1px solid var(--border)}
.up-item:last-child{border-bottom:none}
.up-color{width:4px;height:30px;border-radius:2px;flex-shrink:0}
.up-info{flex:1}
.up-title{font-size:.75rem;color:var(--text)}
.up-date{font-size:.55rem;color:var(--muted);margin-top:.15rem}
.up-countdown{font-size:.6rem;color:var(--accent);text-align:right;white-space:nowrap}

/* Event list panel */
.events-list-section{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.events-list-section h3{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem}
.filter-bar{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.2rem}
.filter-btn{background:transparent;border:1px solid var(--border);color:var(--muted);font-family:'DM Mono',monospace;font-size:.55rem;letter-spacing:.08em;text-transform:uppercase;padding:.3rem .7rem;cursor:pointer;transition:all .2s}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{background:var(--accent);color:var(--bg);border-color:var(--accent)}
.ev-card{display:flex;align-items:flex-start;gap:.8rem;padding:.8rem;border:1px solid var(--border);margin-bottom:.5rem;transition:all .2s;background:var(--bg)}
.ev-card:hover{border-color:var(--accent);background:rgba(201,169,110,.03)}
.ev-card .ev-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:.35rem}
.ev-card .ev-body{flex:1;min-width:0}
.ev-card .ev-card-title{font-size:.8rem;color:var(--text);margin-bottom:.15rem}
.ev-card .ev-card-title-ru{font-size:.65rem;color:var(--muted);font-style:italic;margin-bottom:.25rem}
.ev-card .ev-card-date{font-size:.55rem;color:var(--muted);margin-bottom:.3rem}
.ev-card .ev-card-desc{font-size:.6rem;color:var(--muted);line-height:1.5;margin-bottom:.3rem}
.ev-card .ev-badge{display:inline-block;font-size:.5rem;letter-spacing:.08em;text-transform:uppercase;padding:.15rem .45rem;border-radius:2px;margin-right:.4rem}
.ev-card .ev-recur-icon{font-size:.55rem;color:var(--accent);margin-left:.3rem}
.ev-card .ev-actions{display:flex;gap:.4rem;margin-top:.4rem;flex-wrap:wrap}
.btn-edit{background:transparent;border:1px solid var(--accent);color:var(--accent);font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s}
.btn-edit:hover{background:var(--accent);color:var(--bg)}

/* Inline edit form */
.edit-form-inline{background:var(--bg);border:1px solid var(--accent);padding:1rem;margin-top:.5rem;display:none}
.edit-form-inline.open{display:block}
.edit-form-inline .form-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.6rem}
@media(max-width:600px){.edit-form-inline .form-row{grid-template-columns:1fr}}
.edit-form-inline .form-group label{display:block;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.2rem}
.edit-form-inline .form-group input,
.edit-form-inline .form-group select,
.edit-form-inline .form-group textarea{width:100%;background:var(--s);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:.4rem;transition:border-color .2s}
.edit-form-inline .form-group input:focus,
.edit-form-inline .form-group select:focus,
.edit-form-inline .form-group textarea:focus{outline:none;border-color:var(--accent)}
.edit-form-inline .form-group textarea{resize:vertical;min-height:50px}
.edit-form-inline .form-check{display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem}
.edit-form-inline .form-check input[type="checkbox"]{accent-color:var(--accent);width:13px;height:13px}
.edit-form-inline .form-check label{font-size:.6rem;color:var(--muted);cursor:pointer}
.edit-form-inline .form-actions{display:flex;gap:.5rem;margin-top:.5rem}
.btn-cancel{background:transparent;border:1px solid var(--border);color:var(--muted);font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s}
.btn-cancel:hover{border-color:var(--accent);color:var(--accent)}
.ev-card[style*="display: none"]{display:none!important}
</style>
</head>
<body>

<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">📅 <?= t('Calendrier','Календарь') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

<h1 class="page-title"><?= t('Nos Dates <em>Importantes</em>','Наши <em>Важные</em> Даты') ?></h1>

<!-- Month navigation -->
<div class="cal-nav">
  <a href="?month=<?= $prevMonth ?><?= $selectedDay ? '&day='.$selectedDay : '' ?>">&larr;</a>
  <div class="month-label"><?= h($monthName) ?> <?= $year ?></div>
  <a href="?month=<?= $nextMonth ?><?= $selectedDay ? '&day='.$selectedDay : '' ?>">&rarr;</a>
</div>

<!-- Calendar grid -->
<div class="cal-grid">
  <?php
  $dayHeadersFr = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
  $dayHeadersRu = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
  $headers = $lang === 'ru' ? $dayHeadersRu : $dayHeadersFr;
  foreach ($headers as $dh): ?>
    <div class="cal-header"><?= $dh ?></div>
  <?php endforeach; ?>

  <?php
  // Empty cells before first day
  for ($i = 1; $i < $startDow; $i++): ?>
    <div class="cal-day empty"></div>
  <?php endfor; ?>

  <?php for ($d = 1; $d <= $daysInMonth; $d++):
    $isToday = ($d === $todayDay && $month === $todayMonth && $year === $todayYear);
    $isSelected = ($d === $selectedDay);
    $dayEvents = $eventsByDay[$d] ?? [];
    $classes = 'cal-day';
    if ($isToday) $classes .= ' today';
    if ($isSelected) $classes .= ' selected';
  ?>
    <a href="?month=<?= $monthParam ?>&day=<?= $d ?>" class="<?= $classes ?>" style="text-decoration:none;color:inherit">
      <div class="day-num"><?= $d ?></div>
      <?php if (!empty($dayEvents)): ?>
      <div class="event-dots">
        <?php foreach ($dayEvents as $ev): ?>
          <div class="event-dot" style="background:<?= h($ev['couleur']) ?>"></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </a>
  <?php endfor; ?>

  <?php
  // Empty cells after last day
  $totalCells = ($startDow - 1) + $daysInMonth;
  $remaining = (7 - ($totalCells % 7)) % 7;
  for ($i = 0; $i < $remaining; $i++): ?>
    <div class="cal-day empty"></div>
  <?php endfor; ?>
</div>

<!-- Selected day events -->
<?php if ($selectedDay !== null): ?>
<div class="day-panel">
  <h3><?= $selectedDay ?> <?= h($monthName) ?> <?= $year ?></h3>
  <?php if (empty($selectedEvents)): ?>
    <div class="no-events"><?= t('Aucun événement ce jour.','Нет событий в этот день.') ?></div>
  <?php else: ?>
    <?php foreach ($selectedEvents as $ev): ?>
    <div class="day-event">
      <div class="ev-color" style="background:<?= h($ev['couleur']) ?>"></div>
      <div class="ev-info">
        <div class="ev-title"><?= h($lang === 'ru' && $ev['titre_ru'] ? $ev['titre_ru'] : $ev['titre_fr']) ?></div>
        <?php
          $desc = $lang === 'ru' && $ev['description_ru'] ? $ev['description_ru'] : $ev['description_fr'];
          if ($desc): ?>
        <div class="ev-desc"><?= h($desc) ?></div>
        <?php endif; ?>
        <span class="ev-cat"><?= h($ev['categorie']) ?></span>
        <?php if ($ev['recurrent']): ?>
          <span class="ev-recur"><?= t('Chaque année','Каждый год') ?></span>
        <?php endif; ?>
        <?php if ((int)$ev['user_id'] === (int)$user['id']): ?>
        <div class="ev-actions">
          <button type="button" class="btn-edit" onclick="toggleEditForm('day-edit-<?= $ev['id'] ?>')"><?= t('Modifier','Изменить') ?></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $ev['id'] ?>">
            <input type="hidden" name="nav_month" value="<?= $monthParam ?>">
            <button type="submit" class="btn-del"><?= t('Supprimer','Удалить') ?></button>
          </form>
        </div>
        <div class="edit-form-inline" id="day-edit-<?= $ev['id'] ?>">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $ev['id'] ?>">
            <input type="hidden" name="nav_month" value="<?= $monthParam ?>">
            <input type="hidden" name="couleur" value="<?= h($ev['couleur']) ?>" class="edit-couleur">
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Titre (FR)','Название (FR)') ?></label>
                <input type="text" name="titre_fr" value="<?= h($ev['titre_fr']) ?>" required data-edit-titre-fr="<?= $ev['id'] ?>">
              </div>
              <div class="form-group">
                <label><?= t('Titre (RU)','Название (RU)') ?></label>
                <input type="text" name="titre_ru" value="<?= h($ev['titre_ru'] ?? '') ?>" data-edit-titre-ru="<?= $ev['id'] ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Description (FR)','Описание (FR)') ?></label>
                <textarea name="description_fr" rows="2" data-edit-desc-fr="<?= $ev['id'] ?>"><?= h($ev['description_fr'] ?? '') ?></textarea>
              </div>
              <div class="form-group">
                <label><?= t('Description (RU)','Описание (RU)') ?></label>
                <textarea name="description_ru" rows="2" data-edit-desc-ru="<?= $ev['id'] ?>"><?= h($ev['description_ru'] ?? '') ?></textarea>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Date','Дата') ?></label>
                <input type="date" name="date_event" value="<?= h($ev['date_event']) ?>" required>
              </div>
              <div class="form-group">
                <label><?= t('Catégorie','Категория') ?></label>
                <select name="categorie">
                  <?php foreach (['anniversaire'=>t('Anniversaire','Годовщина'),'voyage'=>t('Voyage','Путешествие'),'souvenir'=>t('Souvenir','Воспоминание'),'rdv'=>t('Rendez-vous','Встреча'),'autre'=>t('Autre','Другое')] as $ck=>$cv): ?>
                  <option value="<?= $ck ?>" <?= $ev['categorie']===$ck?'selected':'' ?>><?= $cv ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-check">
              <input type="checkbox" name="recurrent" value="1" <?= $ev['recurrent']?'checked':'' ?> id="day-rec-<?= $ev['id'] ?>">
              <label for="day-rec-<?= $ev['id'] ?>"><?= t('Récurrent','Повторяется') ?></label>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-submit"><?= t('Enregistrer','Сохранить') ?></button>
              <button type="button" class="btn-cancel" onclick="toggleEditForm('day-edit-<?= $ev['id'] ?>')"><?= t('Annuler','Отмена') ?></button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add event -->
<div class="add-section">
  <button class="toggle-form" onclick="document.getElementById('addForm').classList.toggle('form-hidden')">
    + <?= t('Ajouter un événement','Добавить событие') ?>
  </button>
  <form method="POST" id="addForm" class="form-hidden">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="nav_month" value="<?= $monthParam ?>">
    <input type="hidden" name="couleur" id="selectedColor" value="#c9a96e">

    <div class="form-row">
      <div class="form-group">
        <label><?= t('Titre (FR)','Название (FR)') ?> *</label>
        <input type="text" name="titre_fr" required placeholder="<?= t('Ex: Notre anniversaire','Напр: Наша годовщина') ?>">
      </div>
      <div class="form-group">
        <label><?= t('Titre (RU)','Название (RU)') ?></label>
        <input type="text" name="titre_ru" placeholder="<?= t('Traduction russe','Перевод на русский') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label><?= t('Description (FR)','Описание (FR)') ?></label>
        <textarea name="description_fr" rows="2"></textarea>
      </div>
      <div class="form-group">
        <label><?= t('Description (RU)','Описание (RU)') ?></label>
        <textarea name="description_ru" rows="2"></textarea>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label><?= t('Date','Дата') ?> *</label>
        <input type="date" name="date_event" required value="<?= $selectedDay ? sprintf('%04d-%02d-%02d', $year, $month, $selectedDay) : date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label><?= t('Catégorie','Категория') ?></label>
        <select name="categorie" onchange="updateColorFromCat(this.value)">
          <option value="anniversaire"><?= t('Anniversaire','Годовщина') ?></option>
          <option value="voyage"><?= t('Voyage','Путешествие') ?></option>
          <option value="souvenir"><?= t('Souvenir','Воспоминание') ?></option>
          <option value="rdv"><?= t('Rendez-vous','Встреча') ?></option>
          <option value="autre"><?= t('Autre','Другое') ?></option>
        </select>
      </div>
    </div>

    <div class="form-check">
      <input type="checkbox" name="recurrent" id="recurrent" value="1">
      <label for="recurrent"><?= t('Récurrent (chaque année)','Повторяется (каждый год)') ?></label>
    </div>

    <div class="form-group" style="margin-bottom:.8rem">
      <label style="display:block;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem"><?= t('Couleur','Цвет') ?></label>
      <div class="color-options">
        <div class="color-opt active" style="background:#c9a96e" onclick="pickColor(this,'#c9a96e')" title="<?= t('Or','Золотой') ?>"></div>
        <div class="color-opt" style="background:#c96e6e" onclick="pickColor(this,'#c96e6e')" title="<?= t('Rouge','Красный') ?>"></div>
        <div class="color-opt" style="background:#6ea9c9" onclick="pickColor(this,'#6ea9c9')" title="<?= t('Bleu','Синий') ?>"></div>
        <div class="color-opt" style="background:#6ec98a" onclick="pickColor(this,'#6ec98a')" title="<?= t('Vert','Зелёный') ?>"></div>
        <div class="color-opt" style="background:#9a6ec9" onclick="pickColor(this,'#9a6ec9')" title="<?= t('Violet','Фиолетовый') ?>"></div>
      </div>
    </div>

    <button type="submit" class="btn-submit"><?= t('Enregistrer','Сохранить') ?></button>
  </form>
</div>

<!-- Upcoming events -->
<?php if (!empty($upcoming)): ?>
<div class="upcoming">
  <h3><?= t('Prochains événements','Ближайшие события') ?></h3>
  <?php foreach ($upcoming as $up):
    $nextDate = $up['next_date'];
    $diff = (int)((strtotime($nextDate) - strtotime($today)) / 86400);
    if ($diff < 0) continue;
    $countdown = $diff === 0
        ? t("Aujourd'hui","Сегодня")
        : ($diff === 1 ? t('Demain','Завтра') : ($lang === 'ru' ? "через $diff дн." : "dans $diff j."));
  ?>
  <div class="up-item">
    <div class="up-color" style="background:<?= h($up['couleur']) ?>"></div>
    <div class="up-info">
      <div class="up-title"><?= h($lang === 'ru' && $up['titre_ru'] ? $up['titre_ru'] : $up['titre_fr']) ?></div>
      <div class="up-date"><?= h($nextDate) ?> &middot; <?= h($up['categorie']) ?></div>
    </div>
    <div class="up-countdown"><?= $countdown ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- All events list -->
<div class="events-list-section">
  <h3><?= t('Tous les événements','Все события') ?></h3>
  <div class="filter-bar">
    <button class="filter-btn active" data-cat="all" onclick="filterEvents('all',this)"><?= t('Tous','Все') ?></button>
    <button class="filter-btn" data-cat="anniversaire" onclick="filterEvents('anniversaire',this)"><?= t('Anniversaire','Годовщина') ?></button>
    <button class="filter-btn" data-cat="voyage" onclick="filterEvents('voyage',this)"><?= t('Voyage','Путешествие') ?></button>
    <button class="filter-btn" data-cat="souvenir" onclick="filterEvents('souvenir',this)"><?= t('Souvenir','Воспоминание') ?></button>
    <button class="filter-btn" data-cat="rdv" onclick="filterEvents('rdv',this)"><?= t('RDV','Встреча') ?></button>
    <button class="filter-btn" data-cat="autre" onclick="filterEvents('autre',this)"><?= t('Autre','Другое') ?></button>
  </div>
  <div id="eventsListContainer">
  <?php if (empty($allEventsList)): ?>
    <div class="no-events"><?= t('Aucun événement.','Нет событий.') ?></div>
  <?php else: ?>
    <?php
    $catLabels = ['anniversaire'=>t('Anniversaire','Годовщина'),'voyage'=>t('Voyage','Путешествие'),'souvenir'=>t('Souvenir','Воспоминание'),'rdv'=>t('Rendez-vous','Встреча'),'autre'=>t('Autre','Другое')];
    foreach ($allEventsList as $ev):
      $evDateObj = strtotime($ev['date_event']);
      $formattedDate = $lang === 'ru'
        ? date('d', $evDateObj).' '.$monthNamesRu[(int)date('n', $evDateObj)].' '.date('Y', $evDateObj)
        : date('d', $evDateObj).' '.$monthNamesFr[(int)date('n', $evDateObj)].' '.date('Y', $evDateObj);
      $badgeBg = $catColors[$ev['categorie']] ?? '#7a7268';
    ?>
    <div class="ev-card" data-category="<?= h($ev['categorie']) ?>">
      <div class="ev-dot" style="background:<?= h($ev['couleur']) ?>"></div>
      <div class="ev-body">
        <div class="ev-card-title"><?= h($ev['titre_fr']) ?></div>
        <?php if ($ev['titre_ru']): ?>
        <div class="ev-card-title-ru"><?= h($ev['titre_ru']) ?></div>
        <?php endif; ?>
        <div class="ev-card-date"><?= h($formattedDate) ?></div>
        <?php
          $descList = $lang === 'ru' && $ev['description_ru'] ? $ev['description_ru'] : ($ev['description_fr'] ?? '');
          if ($descList): ?>
        <div class="ev-card-desc"><?= h($descList) ?></div>
        <?php endif; ?>
        <span class="ev-badge" style="background:<?= $badgeBg ?>20;color:<?= $badgeBg ?>"><?= h($catLabels[$ev['categorie']] ?? $ev['categorie']) ?></span>
        <?php if ($ev['recurrent']): ?>
          <span class="ev-recur-icon" title="<?= t('Récurrent','Повторяется') ?>">&#x21bb;</span>
        <?php endif; ?>
        <?php if ((int)$ev['user_id'] === (int)$user['id']): ?>
        <div class="ev-actions">
          <button type="button" class="btn-edit" onclick="toggleEditForm('list-edit-<?= $ev['id'] ?>')"><?= t('Modifier','Изменить') ?></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $ev['id'] ?>">
            <input type="hidden" name="nav_month" value="<?= $monthParam ?>">
            <button type="submit" class="btn-del"><?= t('Supprimer','Удалить') ?></button>
          </form>
        </div>
        <div class="edit-form-inline" id="list-edit-<?= $ev['id'] ?>">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $ev['id'] ?>">
            <input type="hidden" name="nav_month" value="<?= $monthParam ?>">
            <input type="hidden" name="couleur" value="<?= h($ev['couleur']) ?>" class="edit-couleur">
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Titre (FR)','Название (FR)') ?></label>
                <input type="text" name="titre_fr" value="<?= h($ev['titre_fr']) ?>" required data-edit-titre-fr="list-<?= $ev['id'] ?>">
              </div>
              <div class="form-group">
                <label><?= t('Titre (RU)','Название (RU)') ?></label>
                <input type="text" name="titre_ru" value="<?= h($ev['titre_ru'] ?? '') ?>" data-edit-titre-ru="list-<?= $ev['id'] ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Description (FR)','Описание (FR)') ?></label>
                <textarea name="description_fr" rows="2" data-edit-desc-fr="list-<?= $ev['id'] ?>"><?= h($ev['description_fr'] ?? '') ?></textarea>
              </div>
              <div class="form-group">
                <label><?= t('Description (RU)','Описание (RU)') ?></label>
                <textarea name="description_ru" rows="2" data-edit-desc-ru="list-<?= $ev['id'] ?>"><?= h($ev['description_ru'] ?? '') ?></textarea>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Date','Дата') ?></label>
                <input type="date" name="date_event" value="<?= h($ev['date_event']) ?>" required>
              </div>
              <div class="form-group">
                <label><?= t('Catégorie','Категория') ?></label>
                <select name="categorie">
                  <?php foreach (['anniversaire'=>t('Anniversaire','Годовщина'),'voyage'=>t('Voyage','Путешествие'),'souvenir'=>t('Souvenir','Воспоминание'),'rdv'=>t('Rendez-vous','Встреча'),'autre'=>t('Autre','Другое')] as $ck=>$cv): ?>
                  <option value="<?= $ck ?>" <?= $ev['categorie']===$ck?'selected':'' ?>><?= $cv ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-check">
              <input type="checkbox" name="recurrent" value="1" <?= $ev['recurrent']?'checked':'' ?> id="list-rec-<?= $ev['id'] ?>">
              <label for="list-rec-<?= $ev['id'] ?>"><?= t('Récurrent','Повторяется') ?></label>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-submit"><?= t('Enregistrer','Сохранить') ?></button>
              <button type="button" class="btn-cancel" onclick="toggleEditForm('list-edit-<?= $ev['id'] ?>')"><?= t('Annuler','Отмена') ?></button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>

</div>

<script>
function pickColor(el, color) {
    document.querySelectorAll('.color-opt').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('selectedColor').value = color;
}
const catColorMap = {anniversaire:'#c9a96e',voyage:'#6ea9c9',souvenir:'#c96e9a',rdv:'#6ec98a',autre:'#7a7268'};

// Auto-translate for add form
autoTranslate([
    { fr: '#addForm input[name="titre_fr"]',       ru: '#addForm input[name="titre_ru"]' },
    { fr: '#addForm textarea[name="description_fr"]', ru: '#addForm textarea[name="description_ru"]' }
], <?= json_encode($lang) ?>, <?= json_encode(BASE_URL) ?>);

function updateColorFromCat(cat) {
    const c = catColorMap[cat] || '#c9a96e';
    document.getElementById('selectedColor').value = c;
    document.querySelectorAll('.color-opt').forEach(el => {
        el.classList.toggle('active', el.style.background === c || el.style.backgroundColor === c);
    });
}

// Toggle edit form
function toggleEditForm(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const wasOpen = el.classList.contains('open');
    // Close all open edit forms first
    document.querySelectorAll('.edit-form-inline.open').forEach(f => f.classList.remove('open'));
    if (!wasOpen) {
        el.classList.add('open');
        // Wire autotranslate for this edit form
        wireEditAutoTranslate(el);
    }
}

// Wire autotranslate for edit form fields (reuses shared autoTranslate)
function wireEditAutoTranslate(formEl) {
    const titreFr = formEl.querySelector('input[name="titre_fr"]');
    const titreRu = formEl.querySelector('input[name="titre_ru"]');
    const descFr = formEl.querySelector('textarea[name="description_fr"]');
    const descRu = formEl.querySelector('textarea[name="description_ru"]');
    if (titreFr && titreRu && !titreFr._atWired) {
        titreFr._atWired = true;
        const pairs = [];
        if (titreFr && titreRu) pairs.push({ fr: titreFr, ru: titreRu });
        if (descFr && descRu) pairs.push({ fr: descFr, ru: descRu });
        autoTranslate(pairs, <?= json_encode($lang) ?>, <?= json_encode(BASE_URL) ?>);
    }
}

// Filter events by category
function filterEvents(cat, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#eventsListContainer .ev-card').forEach(card => {
        if (cat === 'all' || card.dataset.category === cat) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>
<?php include __DIR__."/includes/bottom_nav.php"; ?>
</body>
</html>
