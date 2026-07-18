<?php
/**
 * NATACHA — Compte à rebours (retour du/de la partenaire)
 * Date + nom configurables. Traduit FR/RU.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'] ?? 'fr';

// Partenaire : l'autre utilisateur du couple
$partnerName = '';
try {
    $stmt = db()->prepare("SELECT display_name FROM users WHERE id != ? ORDER BY id LIMIT 1");
    $stmt->execute([$user['id']]);
    $partnerName = $stmt->fetchColumn() ?: '';
} catch (Exception $e) {}
if (!$partnerName) $partnerName = ($lang==='ru'?'любимый(ая)':'ton amour');

// Dates configurables
$returnDate = getSetting('countdown_return_date', COUNTDOWN_DEFAULT_RETURN);
$startDate  = getSetting('countdown_start_date', COUNTDOWN_DEFAULT_START);

// POST: mettre à jour les dates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrfVerify()) {
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $r = $_POST['return_date'] ?? '';
    $s = $_POST['start_date'] ?? '';
    $validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $saved = false;
    if ($validDate($r)) { setSetting('countdown_return_date', $r); $saved = true; }
    if ($validDate($s)) { setSetting('countdown_start_date', $s); $saved = true; }
    echo json_encode(['ok' => $saved]);
    exit;
}

// Messages qui tournent (FR/RU)
$messages = $lang === 'ru' ? [
    "Ты скучаешь по мне немного больше с каждой секундой.",
    "Время тянется, но оно на нашей стороне.",
    "Каждый прошедший день — это день ближе к тебе.",
    "Я уже придумал(а) всё, что мы сделаем по твоему возвращению…",
    "Расстояние ничего не меняет, оно лишь напоминает, как ты важен(на).",
    "Скоро я буду там. И ты тоже. Наконец-то.",
    "Терпение… самые красивые встречи заставляют себя ждать.",
    "Я считаю дни. Буквально. Посмотри наверх.",
] : [
    "Tu me manques un peu plus à chaque seconde qui passe.",
    "Le temps est long, mais il joue en notre faveur.",
    "Chaque jour qui passe est un jour de moins sans toi.",
    "J'ai déjà prévu tout ce qu'on fera à ton retour…",
    "La distance ne change rien, elle rappelle juste à quel point tu comptes.",
    "Bientôt je serai là. Et toi aussi. Enfin.",
    "Patience… les plus belles retrouvailles se font attendre.",
    "Je compte les jours. Littéralement. Regarde au-dessus.",
];

// Formatage date lisible
$fmtStart  = date('j', strtotime($startDate)).' '.($lang==='ru'
    ? ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'][(int)date('n',strtotime($startDate))]
    : ['','janv','févr','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'][(int)date('n',strtotime($startDate))]);
$fmtReturn = date('j', strtotime($returnDate)).' '.($lang==='ru'
    ? ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'][(int)date('n',strtotime($returnDate))]
    : ['','janv','févr','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'][(int)date('n',strtotime($returnDate))]);
$fmtReturnFull = date('j', strtotime($returnDate)).' '.($lang==='ru'
    ? ['','января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'][(int)date('n',strtotime($returnDate))]
    : ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][(int)date('n',strtotime($returnDate))]).' '.date('Y', strtotime($returnDate));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('En attendant','В ожидании') ?> <?= h($partnerName) ?> 🕰️</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Cormorant Garamond',Georgia,serif;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50;font-family:'DM Mono',monospace}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.edit-btn{font-size:.9rem;color:var(--muted);text-decoration:none;cursor:pointer;background:none;border:none}
.edit-btn:hover{color:var(--accent)}

.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1.5rem;text-align:center;min-height:calc(100vh - 60px)}

.ornament{font-size:3rem;margin-bottom:2rem;display:block;animation:float 3s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
h1{font-size:clamp(2rem,6vw,3.5rem);font-weight:300;letter-spacing:.02em;line-height:1.2;margin-bottom:1rem}
h1 em{font-style:italic;color:var(--accent)}
.subtitle{font-family:'DM Mono',monospace;font-size:.75rem;color:var(--muted);letter-spacing:.15em;margin-bottom:3.5rem;line-height:1.8;text-transform:uppercase}

.countdown{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:3rem}
.unit{border:1px solid var(--border);background:var(--as);padding:1.5rem 1rem;min-width:110px;transition:border-color .3s}
.unit:hover{border-color:var(--accent)}
.unit .num{font-family:'DM Mono',monospace;font-size:clamp(2.2rem,7vw,3.2rem);font-weight:300;color:var(--accent);line-height:1;font-variant-numeric:tabular-nums}
.unit .lbl{font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.25em;text-transform:uppercase;color:var(--muted);margin-top:.8rem;display:block}

.prog-wrap{width:100%;max-width:520px;margin-bottom:3rem}
.prog-info{display:flex;justify-content:space-between;margin-bottom:.6rem}
.prog-info span{font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.15em;color:var(--muted);text-transform:uppercase}
.prog-track{height:1px;background:var(--border);position:relative}
.prog-fill{height:100%;background:var(--accent);transition:width 1s ease;width:0%}
.prog-heart{position:absolute;top:50%;transform:translate(-50%,-50%);font-size:.9rem;transition:left 1s ease;left:0%}

.deco{display:flex;align-items:center;gap:1rem;margin:0 auto 2.5rem;color:var(--border);width:100%;max-width:400px}
.deco::before,.deco::after{content:'';flex:1;height:1px;background:var(--border)}
.deco span{font-size:.8rem;color:var(--accent)}

.message{font-size:1.25rem;font-style:italic;color:var(--text);line-height:1.6;max-width:480px;min-height:3.2em;opacity:0;transition:opacity .8s}
.message.vis{opacity:1}
.date-line{font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin-top:2.5rem}

.arrived h1{font-size:clamp(2.2rem,7vw,4rem)}
.hidden{display:none}

/* Editor */
.editor{display:none;margin-top:2rem;padding:1.2rem;border:1px solid var(--border);background:var(--s);font-family:'DM Mono',monospace}
.editor.open{display:block}
.editor label{display:block;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:.5rem 0 .3rem}
.editor input{background:transparent;border:1px solid var(--border);color:var(--accent);font-family:'DM Mono',monospace;font-size:.75rem;padding:.4rem .6rem;outline:none}
.editor button{margin-top:1rem;background:var(--as);border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.1em;padding:.4rem 1rem;cursor:pointer;text-transform:uppercase}

@media(max-width:480px){.unit{min-width:calc(50% - .75rem);padding:1.2rem .8rem}}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">🕰️ <?= t('Le Retour','Возвращение') ?></div>
  <button class="edit-btn" onclick="document.getElementById('editor').classList.toggle('open')">⚙</button>
</div>

<div class="wrap">

<div id="waiting">
  <span class="ornament">🕰️</span>
  <h1><?= t('En attendant','В ожидании') ?> <em><?= h($partnerName) ?></em></h1>
  <p class="subtitle"><?= t('Chaque seconde nous rapproche','Каждая секунда сближает нас') ?></p>

  <div class="countdown">
    <div class="unit"><div class="num" id="d">–</div><span class="lbl"><?= t('Jours','Дни') ?></span></div>
    <div class="unit"><div class="num" id="h">–</div><span class="lbl"><?= t('Heures','Часы') ?></span></div>
    <div class="unit"><div class="num" id="m">–</div><span class="lbl"><?= t('Minutes','Минуты') ?></span></div>
    <div class="unit"><div class="num" id="s">–</div><span class="lbl"><?= t('Secondes','Секунды') ?></span></div>
  </div>

  <div class="prog-wrap">
    <div class="prog-info">
      <span><?= h($fmtStart) ?></span>
      <span id="prog-pct">0%</span>
      <span><?= h($fmtReturn) ?></span>
    </div>
    <div class="prog-track">
      <div class="prog-fill" id="prog-fill"></div>
      <span class="prog-heart" id="prog-heart">❤️</span>
    </div>
  </div>

  <div class="deco"><span>◆</span></div>
  <p class="message" id="message"></p>
  <p class="date-line"><?= t('Retour prévu','Возвращение') ?> · <?= h($fmtReturnFull) ?></p>

  <div class="editor" id="editor">
    <label><?= t('Date de retour','Дата возвращения') ?></label>
    <input type="date" id="returnDate" value="<?= h($returnDate) ?>">
    <label><?= t('Date de départ','Дата отъезда') ?></label>
    <input type="date" id="startDate" value="<?= h($startDate) ?>">
    <br>
    <button onclick="saveDates()"><?= t('Enregistrer','Сохранить') ?></button>
  </div>
</div>

<div id="arrived" class="arrived hidden">
  <span class="ornament">🎉</span>
  <h1><?= h($partnerName) ?> <em><?= t('est de retour','вернулся(ась)') ?></em> !</h1>
  <p class="subtitle"><?= t('L\'attente est terminée','Ожидание закончилось') ?></p>
  <div class="deco"><span>◆</span></div>
  <p class="message vis"><?= t('Et enfin te revoilà.','И вот наконец ты снова здесь.') ?> ❤️</p>
</div>

</div>

<script>
const DEPART = new Date(<?= json_encode($startDate.'T00:00:00') ?>);
const RETOUR = new Date(<?= json_encode($returnDate.'T00:00:00') ?>);
const MESSAGES = <?= json_encode($messages, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const pad = n => String(n).padStart(2, '0');

function tick() {
  const now = new Date();
  const diff = RETOUR - now;
  if (diff <= 0) {
    document.getElementById('waiting').classList.add('hidden');
    document.getElementById('arrived').classList.remove('hidden');
    return;
  }
  document.getElementById('d').textContent = Math.floor(diff / 864e5);
  document.getElementById('h').textContent = pad(Math.floor(diff / 36e5) % 24);
  document.getElementById('m').textContent = pad(Math.floor(diff / 6e4) % 60);
  document.getElementById('s').textContent = pad(Math.floor(diff / 1e3) % 60);
  const pct = Math.min(100, Math.max(0, (now - DEPART) / (RETOUR - DEPART) * 100));
  document.getElementById('prog-fill').style.width = pct + '%';
  document.getElementById('prog-heart').style.left = pct + '%';
  document.getElementById('prog-pct').textContent = pct.toFixed(1) + '%';
}

function rotateMessage() {
  const el = document.getElementById('message');
  const msg = MESSAGES[Math.floor(new Date() / 6e4) % MESSAGES.length];
  if (el.textContent !== msg) {
    el.classList.remove('vis');
    setTimeout(() => { el.textContent = msg; el.classList.add('vis'); }, 800);
  }
}

function saveDates() {
  const r = document.getElementById('returnDate').value;
  const s = document.getElementById('startDate').value;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('return_date', r);
  fd.append('start_date', s);
  fetch(BASE + '/compteur.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(d => {
      if (d.ok) location.reload();
      else alert(<?= json_encode(t('Enregistrement échoué. Réessayez.','Не удалось сохранить. Попробуйте снова.')) ?>);
    })
    .catch(() => alert(<?= json_encode(t('Erreur réseau.','Ошибка сети.')) ?>));
}

tick();
rotateMessage();
setInterval(tick, 1000);
setInterval(rotateMessage, 5000);
</script>
</body>
</html>
