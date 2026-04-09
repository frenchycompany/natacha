<?php
/**
 * NATACHA — Qui est notre couple ?
 * Each partner answers the same questions about their couple.
 * When both have answered, answers appear side by side.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];
$userId = (int)$user['id'];

// Get couple_id (standard fallback)
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $coupleId = $stmt->fetchColumn() ?: null;
}

// Record game activity (once per day)
if ($coupleId) {
    $today = date('Y-m-d');
    $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='jeu' AND DATE(created_at)=?");
    $already->execute([$coupleId, $userId, $today]);
    if (!$already->fetch()) {
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $ce->recordActivity($coupleId, $userId, 'jeu',
            $user['display_name'].' a joue au quiz couple',
            $user['display_name'].' played the couple quiz');
    }
}

// Get partner info
$partner = null;
if ($coupleId) {
    $stmt = db()->prepare("SELECT id, display_name, lang FROM users WHERE couple_id=? AND id!=? LIMIT 1");
    $stmt->execute([$coupleId, $userId]);
    $partner = $stmt->fetch();
}

// ═══ Ensure tables exist ═══
try { db()->query("SELECT 1 FROM couple_quiz_questions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS couple_quiz_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_fr TEXT NOT NULL,
        question_ru TEXT,
        categorie ENUM('valeurs','quotidien','futur','fun') DEFAULT 'valeurs',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("INSERT IGNORE INTO couple_quiz_questions (question_fr, question_ru, categorie) VALUES
        ('Quel est le plus grand point fort de notre couple ?', 'Какая самая сильная сторона нашей пары?', 'valeurs'),
        ('Qu''est-ce qu''on devrait ameliorer dans notre couple ?', 'Что нам стоит улучшить в наших отношениях?', 'valeurs'),
        ('Quel est notre rituel prefere a deux ?', 'Какой наш любимый ритуал вдвоём?', 'quotidien'),
        ('Ou se voit-on dans 5 ans ?', 'Где мы видим себя через 5 лет?', 'futur'),
        ('Quel voyage reve-t-on de faire ensemble ?', 'Какое путешествие мы мечтаем совершить вместе?', 'futur'),
        ('Quelle chanson represente le mieux notre couple ?', 'Какая песня лучше всего описывает нашу пару?', 'fun'),
        ('Quel film/serie on adore regarder ensemble ?', 'Какой фильм/сериал мы обожаем смотреть вместе?', 'fun'),
        ('C''est quoi notre plus beau souvenir ensemble ?', 'Какое наше самое красивое воспоминание вместе?', 'valeurs'),
        ('Qu''est-ce qui nous fait le plus rire ?', 'Что нас больше всего смешит?', 'quotidien'),
        ('Si on avait un super-pouvoir de couple, ce serait quoi ?', 'Если бы у нас была суперспособность пары, какая бы это была?', 'fun')");
}

try { db()->query("SELECT 1 FROM couple_quiz_answers LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS couple_quiz_answers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        answer TEXT NOT NULL,
        answer_translated TEXT DEFAULT NULL,
        answer_lang CHAR(2) DEFAULT 'fr',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_q_user (question_id, user_id),
        INDEX idx_question (question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ═══ POST ACTIONS ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    // Save answer
    if ($action === 'answer') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $answer = trim($_POST['answer'] ?? '');
        if ($qid && $answer) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($answer, $fromLang, $toLang);

            $stmt = db()->prepare("INSERT INTO couple_quiz_answers (question_id, user_id, answer, answer_translated, answer_lang)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE answer=VALUES(answer), answer_translated=VALUES(answer_translated), answer_lang=VALUES(answer_lang), created_at=CURRENT_TIMESTAMP");
            $stmt->execute([$qid, $userId, $answer, $translated ?: null, $fromLang]);

            // Notify partner
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($userId, 'jeu',
                    $user['display_name'].' a repondu a une question du quiz couple !',
                    $user['display_name'].' ответил(а) на вопрос квиза пары!',
                    BASE_URL.'/jeux_couple_quiz.php');
            } catch (Exception $e) {}
        }
        header('Location: '.BASE_URL.'/jeux_couple_quiz.php');
        exit;
    }

    // Add custom question
    if ($action === 'add_question') {
        $qfr = trim($_POST['question_fr'] ?? '');
        $qru = trim($_POST['question_ru'] ?? '');
        $cat = $_POST['categorie'] ?? 'valeurs';
        $valid = ['valeurs','quotidien','futur','fun'];
        if (!in_array($cat, $valid)) $cat = 'valeurs';
        if ($qfr || $qru) {
            if ($qfr && !$qru) $qru = translateText($qfr, 'fr', 'ru') ?: '';
            if ($qru && !$qfr) $qfr = translateText($qru, 'ru', 'fr') ?: $qru;
            db()->prepare("INSERT INTO couple_quiz_questions (question_fr, question_ru, categorie) VALUES (?,?,?)")
                ->execute([$qfr, $qru ?: null, $cat]);
        }
        header('Location: '.BASE_URL.'/jeux_couple_quiz.php');
        exit;
    }
}

// ═══ GET DATA ═══
$catLabels = [
    'valeurs'   => t('Valeurs','Ценности'),
    'quotidien' => t('Quotidien','Повседневность'),
    'futur'     => t('Futur','Будущее'),
    'fun'       => t('Fun','Веселье'),
];
$catColors = [
    'valeurs'   => '#c96ea0',
    'quotidien' => '#c9a96e',
    'futur'     => '#6e9dc9',
    'fun'       => '#6ec98a',
];

// Fetch all questions
$questions = db()->query("SELECT * FROM couple_quiz_questions ORDER BY categorie, id")->fetchAll();

// Fetch all answers for current user and partner
$myAnswers = [];
$partnerAnswers = [];

$stmt = db()->prepare("SELECT question_id, answer, answer_translated, answer_lang FROM couple_quiz_answers WHERE user_id=?");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $a) {
    $myAnswers[$a['question_id']] = $a;
}

if ($partner) {
    $stmt = db()->prepare("SELECT question_id, answer, answer_translated, answer_lang FROM couple_quiz_answers WHERE user_id=?");
    $stmt->execute([$partner['id']]);
    foreach ($stmt->fetchAll() as $a) {
        $partnerAnswers[$a['question_id']] = $a;
    }
}

$partnerName = $partner ? h($partner['display_name']) : t('Partenaire','Партнёр');

// Stats
$totalQ = count($questions);
$answeredBoth = 0;
$answeredMe = 0;
$answeredPartner = 0;
foreach ($questions as $q) {
    $me = isset($myAnswers[$q['id']]);
    $them = isset($partnerAnswers[$q['id']]);
    if ($me && $them) $answeredBoth++;
    elseif ($me) $answeredMe++;
    elseif ($them) $answeredPartner++;
}

// Category filter
$filterCat = $_GET['cat'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Qui est notre couple ?','Кто наша пара?') ?></title>
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
.wrap{max-width:780px;margin:0 auto;padding:2.5rem 2rem}

/* Header */
.intro{text-align:center;margin-bottom:2.5rem}
.intro h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:var(--accent);margin-bottom:.6rem}
.intro p{font-size:.65rem;color:var(--muted);line-height:1.8;letter-spacing:.05em;max-width:500px;margin:0 auto}

/* Stats bar */
.stats-bar{display:flex;justify-content:center;gap:1.5rem;margin-bottom:2rem;flex-wrap:wrap}
.stat-item{text-align:center;padding:.8rem 1.2rem;background:var(--s);border:1px solid var(--border)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--accent);display:block}
.stat-lbl{font-size:.55rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-top:.2rem}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:2.5rem;justify-content:center}
.filter-btn{font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.35rem .7rem;cursor:pointer;text-decoration:none;transition:all .2s}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Question list */
.q-list{display:flex;flex-direction:column;gap:1.5rem;margin-bottom:3rem}

.q-card{background:var(--s);border:1px solid var(--border);overflow:hidden;animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

.q-header{padding:1.2rem 1.5rem;display:flex;align-items:flex-start;gap:1rem}
.q-cat{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;white-space:nowrap;padding:.2rem .5rem;border:1px solid;flex-shrink:0;margin-top:.15rem}
.q-texts{flex:1}
.q-fr{font-family:'Cormorant Garamond',serif;font-size:1.15rem;line-height:1.5;color:var(--text)}
.q-ru{font-family:'Cormorant Garamond',serif;font-size:.9rem;color:var(--muted);font-style:italic;margin-top:.3rem;line-height:1.5}
.q-status{flex-shrink:0;font-size:.7rem;margin-top:.15rem}

/* Answer form */
.answer-form{padding:0 1.5rem 1.5rem}
.answer-form textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:.8rem;resize:vertical;min-height:80px;transition:border-color .2s}
.answer-form textarea:focus{outline:none;border-color:var(--accent)}
.answer-form .form-row{display:flex;justify-content:flex-end;margin-top:.6rem}

.btn{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.45rem 1rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.primary{border-color:var(--accent);color:var(--accent)}
.btn.primary:hover{background:var(--accent);color:var(--bg)}

/* Waiting badge */
.badge-waiting{display:inline-block;font-size:.58rem;color:var(--muted);letter-spacing:.08em;padding:.6rem 1.5rem;border-top:1px solid var(--border);width:100%;text-align:center;font-style:italic}

/* Side by side answers */
.answers-compare{border-top:1px solid var(--border);padding:1.5rem}
.answers-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
.answer-col{padding:1rem;background:var(--bg);border:1px solid var(--border);position:relative}
.answer-col::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.answer-col.me::before{background:var(--accent)}
.answer-col.them::before{background:#c96ea0}
.answer-name{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:.6rem;display:block}
.answer-col.me .answer-name{color:var(--accent)}
.answer-col.them .answer-name{color:#c96ea0}
.answer-text{font-size:.72rem;line-height:1.8;color:var(--text)}
.answer-original{font-size:.6rem;color:var(--muted);font-style:italic;margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border);line-height:1.6}

/* Toggle form button */
.toggle-answer{font-size:.6rem;color:var(--accent);cursor:pointer;background:none;border:none;padding:.6rem 1.5rem;width:100%;text-align:center;border-top:1px solid var(--border);letter-spacing:.1em;text-transform:uppercase;font-family:'DM Mono',monospace;transition:background .2s}
.toggle-answer:hover{background:var(--as)}

/* Add question section */
.add-section{margin-top:2.5rem;padding:1.5rem;background:var(--s);border:1px solid var(--border)}
.add-section h3{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;color:var(--accent);margin-bottom:1rem}
.add-section label{font-size:.6rem;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;display:block;margin-bottom:.3rem;margin-top:.8rem}
.add-section input[type="text"],.add-section select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:.5rem .7rem;transition:border-color .2s}
.add-section input[type="text"]:focus,.add-section select:focus{outline:none;border-color:var(--accent)}
.add-section select{appearance:none;cursor:pointer}
.add-section .form-row{margin-top:1rem}

/* How to play */
.how-to{margin-top:2rem;padding:1.5rem;border:1px solid var(--border);background:var(--s)}
.how-to h3{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin-bottom:1rem}
.how-to ol{padding-left:1.2rem;font-size:.65rem;color:var(--muted);line-height:2}

/* Empty */
.empty{text-align:center;padding:3rem;font-size:.7rem;color:var(--muted)}

/* Responsive */
@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .answers-grid{grid-template-columns:1fr}
    .q-header{flex-direction:column;gap:.5rem}
    .stats-bar{gap:.8rem}
    .stat-item{padding:.6rem .8rem}
    .intro h1{font-size:1.4rem}
}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/jeux.php">&larr; <?= t('Jeux','Игры') ?></a>
  <div class="topbar-title"><?= t('Qui est notre couple ?','Кто наша пара?') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="intro">
    <h1><?= t('Qui est notre couple ?','Кто наша пара?') ?></h1>
    <p><?= t(
      'Repondez chacun aux memes questions sur votre couple, puis decouvrez vos reponses cote a cote. Etes-vous sur la meme longueur d\'onde ?',
      'Каждый отвечает на одни и те же вопросы о вашей паре, а потом вы видите ответы рядом. Вы на одной волне?'
    ) ?></p>
  </div>

  <!-- Stats -->
  <div class="stats-bar">
    <div class="stat-item">
      <span class="stat-num"><?= $answeredBoth ?></span>
      <span class="stat-lbl"><?= t('Compares','Сравнено') ?></span>
    </div>
    <div class="stat-item">
      <span class="stat-num"><?= $answeredMe + $answeredBoth ?></span>
      <span class="stat-lbl"><?= t('Mes reponses','Мои ответы') ?></span>
    </div>
    <div class="stat-item">
      <span class="stat-num"><?= $totalQ ?></span>
      <span class="stat-lbl"><?= t('Questions','Вопросы') ?></span>
    </div>
  </div>

  <!-- Category filters -->
  <div class="filters">
    <a class="filter-btn <?= $filterCat==='all'?'active':'' ?>" href="?cat=all"><?= t('Toutes','Все') ?></a>
    <?php foreach ($catLabels as $key => $label): ?>
    <a class="filter-btn <?= $filterCat===$key?'active':'' ?>" href="?cat=<?= $key ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Question list -->
  <div class="q-list">
  <?php
  $shown = 0;
  foreach ($questions as $q):
      if ($filterCat !== 'all' && $q['categorie'] !== $filterCat) continue;
      $shown++;
      $qid = (int)$q['id'];
      $cat = $q['categorie'];
      $color = $catColors[$cat] ?? 'var(--accent)';
      $iAnswered = isset($myAnswers[$qid]);
      $theyAnswered = isset($partnerAnswers[$qid]);
      $bothDone = $iAnswered && $theyAnswered;

      // Display question in reader's lang first
      $qPrimary = ($lang === 'ru' && $q['question_ru']) ? $q['question_ru'] : $q['question_fr'];
      $qSecondary = ($lang === 'ru') ? $q['question_fr'] : ($q['question_ru'] ?? '');
  ?>
    <div class="q-card" id="q-<?= $qid ?>">
      <div class="q-header">
        <span class="q-cat" style="color:<?= $color ?>;border-color:<?= $color ?>"><?= h($catLabels[$cat] ?? $cat) ?></span>
        <div class="q-texts">
          <div class="q-fr"><?= h($qPrimary) ?></div>
          <?php if ($qSecondary): ?>
          <div class="q-ru"><?= h($qSecondary) ?></div>
          <?php endif; ?>
        </div>
        <span class="q-status">
          <?php if ($bothDone): ?>
            &#10003;
          <?php elseif ($iAnswered): ?>
            &#9997;&#65039;
          <?php elseif ($theyAnswered): ?>
            &#9203;
          <?php else: ?>
            &#128274;
          <?php endif; ?>
        </span>
      </div>

      <?php if ($bothDone): ?>
        <!-- Both answered: show side by side -->
        <div class="answers-compare">
          <div class="answers-grid">
            <?php
              $myA = $myAnswers[$qid];
              $pA = $partnerAnswers[$qid];

              // My answer: show in reader's lang
              $myPrimary = $myA['answer'];
              $myOriginal = '';
              if ($myA['answer_lang'] !== $lang && $myA['answer_translated']) {
                  $myPrimary = $myA['answer_translated'];
                  $myOriginal = $myA['answer'];
              }

              // Partner answer: show in reader's lang
              $pPrimary = $pA['answer'];
              $pOriginal = '';
              if ($pA['answer_lang'] !== $lang && $pA['answer_translated']) {
                  $pPrimary = $pA['answer_translated'];
                  $pOriginal = $pA['answer'];
              }
            ?>
            <div class="answer-col me">
              <span class="answer-name"><?= h($user['display_name']) ?></span>
              <div class="answer-text"><?= h($myPrimary) ?></div>
              <?php if ($myOriginal): ?>
              <div class="answer-original"><?= h($myOriginal) ?></div>
              <?php endif; ?>
            </div>
            <div class="answer-col them">
              <span class="answer-name"><?= $partnerName ?></span>
              <div class="answer-text"><?= h($pPrimary) ?></div>
              <?php if ($pOriginal): ?>
              <div class="answer-original"><?= h($pOriginal) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      <?php elseif ($iAnswered && !$theyAnswered): ?>
        <!-- Only I answered: waiting for partner -->
        <div class="badge-waiting"><?= t('En attente de ','В ожидании ') ?><?= $partnerName ?>...</div>

      <?php else: ?>
        <!-- Not yet answered (or only partner answered): show answer form -->
        <?php if ($theyAnswered && !$iAnswered): ?>
        <button class="toggle-answer" onclick="toggleForm(<?= $qid ?>)"><?= t('Repondre','Ответить') ?> &rarr;</button>
        <?php else: ?>
        <button class="toggle-answer" onclick="toggleForm(<?= $qid ?>)"><?= t('Repondre','Ответить') ?></button>
        <?php endif; ?>
        <div class="answer-form" id="form-<?= $qid ?>" style="display:none">
          <form method="POST" action="">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="answer">
            <input type="hidden" name="question_id" value="<?= $qid ?>">
            <textarea name="answer" placeholder="<?= t('Votre reponse...','Ваш ответ...') ?>" required></textarea>
            <div class="form-row">
              <button type="submit" class="btn primary"><?= t('Envoyer','Отправить') ?></button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($shown === 0): ?>
  <div class="empty"><?= t('Aucune question dans cette categorie.','Нет вопросов в этой категории.') ?></div>
  <?php endif; ?>
  </div>

  <!-- Add custom question -->
  <div class="add-section">
    <h3><?= t('Ajouter une question','Добавить вопрос') ?></h3>
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_question">

      <label><?= t('Question (FR)','Вопрос (FR)') ?></label>
      <input type="text" name="question_fr" placeholder="<?= t('En francais...','На французском...') ?>">

      <label><?= t('Question (RU)','Вопрос (RU)') ?></label>
      <input type="text" name="question_ru" placeholder="<?= t('En russe (optionnel, auto-traduit)','На русском (необязательно, авто-перевод)') ?>">

      <label><?= t('Categorie','Категория') ?></label>
      <select name="categorie">
        <?php foreach ($catLabels as $key => $label): ?>
        <option value="<?= $key ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="form-row">
        <button type="submit" class="btn primary"><?= t('Ajouter','Добавить') ?></button>
      </div>
    </form>
  </div>

  <!-- How to play -->
  <div class="how-to">
    <h3><?= t('Comment ca marche','Как это работает') ?></h3>
    <ol>
      <li><?= t('Chaque partenaire repond aux memes questions sur le couple','Каждый партнёр отвечает на одни и те же вопросы о паре') ?></li>
      <li><?= t('Les reponses sont cachees tant que les deux n\'ont pas repondu','Ответы скрыты, пока оба не ответят') ?></li>
      <li><?= t('Quand les deux ont repondu, les reponses s\'affichent cote a cote','Когда оба ответили, ответы появляются рядом') ?></li>
      <li><?= t('Decouvrez si vous etes sur la meme longueur d\'onde !','Узнайте, на одной ли вы волне!') ?></li>
    </ol>
  </div>

</div>

<script>
function toggleForm(qid) {
    var form = document.getElementById('form-' + qid);
    if (!form) return;
    var visible = form.style.display !== 'none';
    form.style.display = visible ? 'none' : 'block';
    if (!visible) {
        var ta = form.querySelector('textarea');
        if (ta) ta.focus();
    }
}
</script>
</body>
</html>
