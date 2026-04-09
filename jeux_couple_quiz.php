<?php
/**
 * NATACHA — Qui est notre couple ?
 * Chacun répond aux mêmes questions. Quand les deux ont répondu, on compare.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

// Record activity once per day
$today = date('Y-m-d');
$already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='jeu' AND DATE(created_at)=? AND description_fr LIKE '%couple quiz%'");
$already->execute([$coupleId, $user['id'], $today]);
if (!$already->fetch()) {
    require_once __DIR__.'/includes/couple_helper.php';
    $ce = new CoupleEntity(db());
    $ce->recordActivity($coupleId, $user['id'], 'jeu',
        $user['display_name'].' a joué au couple quiz',
        $user['display_name'].' играл(а) в викторину пары');
}

// Ensure tables
try { db()->query("SELECT 1 FROM couple_quiz_questions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS couple_quiz_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_fr TEXT NOT NULL,
        question_ru TEXT,
        categorie ENUM('valeurs','quotidien','futur','fun') DEFAULT 'valeurs',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
    // Seed questions
    db()->exec("INSERT IGNORE INTO couple_quiz_questions (question_fr, question_ru, categorie) VALUES
        ('Quel est le plus grand point fort de notre couple ?','Какая самая сильная сторона нашей пары?','valeurs'),
        ('Qu''est-ce qu''on devrait améliorer ensemble ?','Что нам стоит улучшить вместе?','valeurs'),
        ('Quel est notre rituel préféré à deux ?','Какой наш любимый ритуал вдвоём?','quotidien'),
        ('Où se voit-on dans 5 ans ?','Где мы видим себя через 5 лет?','futur'),
        ('Quel voyage rêve-t-on de faire ensemble ?','Какое путешествие мы мечтаем совершить вместе?','futur'),
        ('Quelle chanson représente le mieux notre couple ?','Какая песня лучше всего описывает нашу пару?','fun'),
        ('Quel film/série on adore regarder ensemble ?','Какой фильм/сериал мы обожаем смотреть вместе?','fun'),
        ('C''est quoi notre plus beau souvenir ensemble ?','Какое наше самое красивое воспоминание вместе?','valeurs'),
        ('Qu''est-ce qui nous fait le plus rire ?','Что нас больше всего смешит?','quotidien'),
        ('Si on avait un super-pouvoir de couple, ce serait quoi ?','Если бы у нас была суперспособность пары, какая?','fun')");
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'answer') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $answer = trim($_POST['answer'] ?? '');
        if ($qid && $answer && mb_strlen($answer) <= 500) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($answer, $fromLang, $toLang);
            db()->prepare("INSERT INTO couple_quiz_answers (question_id, user_id, answer, answer_translated, answer_lang) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer), answer_translated=VALUES(answer_translated), answer_lang=VALUES(answer_lang)")
                ->execute([$qid, $user['id'], $answer, $translated ?: null, $fromLang]);
            // Notify partner
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($user['id'], 'jeu',
                    $user['display_name'].' a répondu au quiz couple !',
                    $user['display_name'].' ответил(а) на викторину пары!',
                    BASE_URL.'/jeux_couple_quiz.php');
            } catch (Exception $e) {}
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    if ($action === 'add_question') {
        $qfr = trim($_POST['question_fr'] ?? '');
        $qru = trim($_POST['question_ru'] ?? '');
        $cat = $_POST['categorie'] ?? 'valeurs';
        if (!in_array($cat, ['valeurs','quotidien','futur','fun'])) $cat = 'valeurs';
        if ($qfr || $qru) {
            if ($qfr && !$qru) $qru = translateText($qfr, 'fr', 'ru') ?: '';
            if ($qru && !$qfr) $qfr = translateText($qru, 'ru', 'fr') ?: $qru;
            db()->prepare("INSERT INTO couple_quiz_questions (question_fr, question_ru, categorie) VALUES (?,?,?)")
                ->execute([$qfr, $qru ?: null, $cat]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    echo json_encode(['ok' => false]); exit;
}

// Load all questions with answers
$questions = db()->query("SELECT * FROM couple_quiz_questions ORDER BY id")->fetchAll();

// Get couple members
$members = db()->prepare("SELECT id, display_name FROM users WHERE couple_id=? ORDER BY id");
$members->execute([$coupleId]);
$members = $members->fetchAll();

// Get all answers for this couple's users
$memberIds = array_column($members, 'id');
$answers = [];
if (!empty($memberIds)) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = db()->prepare("SELECT * FROM couple_quiz_answers WHERE user_id IN ($ph)");
    $stmt->execute($memberIds);
    foreach ($stmt->fetchAll() as $a) {
        $answers[$a['question_id']][$a['user_id']] = $a;
    }
}

$catLabels = [
    'valeurs'   => t('Valeurs','Ценности'),
    'quotidien' => t('Quotidien','Ежедневное'),
    'futur'     => t('Futur','Будущее'),
    'fun'       => t('Fun','Веселье'),
];
$catEmojis = ['valeurs'=>'💛','quotidien'=>'☕','futur'=>'🚀','fun'=>'🎉'];
$totalQ = count($questions);
$myAnswered = 0;
$bothAnswered = 0;
foreach ($questions as $q) {
    if (isset($answers[$q['id']][$user['id']])) $myAnswered++;
    if (count($answers[$q['id']] ?? []) >= 2) $bothAnswered++;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Quiz Couple','Викторина пары') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--green:#6ec98a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:700px;margin:0 auto;padding:2rem}

.header{text-align:center;margin-bottom:2rem}
.header h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:var(--accent);margin-bottom:.4rem}
.header p{font-size:.6rem;color:var(--muted);letter-spacing:.06em;line-height:1.8}

.stats-bar{display:flex;justify-content:center;gap:2rem;padding:1rem;border:1px solid var(--border);background:var(--s);margin-bottom:2rem;text-align:center}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--accent);line-height:1}
.stat-label{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.stat-divider{width:1px;height:35px;background:var(--border);align-self:center}

.q-item{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.8rem;transition:border-color .3s}
.q-item:hover{border-color:var(--accent)}
.q-cat{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
.q-text{font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:var(--text);line-height:1.5;margin-bottom:.3rem}
.q-text-alt{font-size:.75rem;color:var(--muted);font-style:italic;margin-bottom:.8rem}

.q-status{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .5rem;display:inline-block;margin-bottom:.8rem}
.q-status.waiting{color:var(--muted);border:1px solid var(--border)}
.q-status.ready{color:var(--accent);border:1px solid var(--accent)}
.q-status.done{color:var(--green);border:1px solid var(--green)}

.answer-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:.95rem;padding:.7rem;outline:none;transition:border .2s;margin-bottom:.5rem;resize:vertical;min-height:50px}
.answer-input:focus{border-color:var(--accent)}
.answer-input::placeholder{color:var(--muted);font-style:italic}
.btn{font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .8rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}

.compare{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-top:.8rem}
.compare-box{padding:.8rem;border:1px solid var(--border);background:rgba(201,169,110,.03)}
.compare-name{font-size:.45rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.3rem}
.compare-answer{font-family:'Cormorant Garamond',serif;font-size:.9rem;color:var(--text);line-height:1.5}
.compare-trad{font-size:.7rem;color:var(--muted);font-style:italic;margin-top:.2rem}

.add-section{margin-top:2rem;border-top:1px solid var(--border);padding-top:1.5rem}
.add-toggle{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);cursor:pointer;border:1px dashed var(--border);padding:.5rem;text-align:center;width:100%;background:transparent;font-family:'DM Mono',monospace;transition:all .2s}
.add-toggle:hover{border-color:var(--accent);color:var(--accent)}
.add-form{display:none;margin-top:1rem}
.add-form.open{display:block}
.field{margin-bottom:.8rem}
.field label{display:block;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.field input,.field select{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);padding:.5rem .7rem;font-family:'DM Mono',monospace;font-size:.7rem;outline:none}
.field select option{background:var(--bg);color:var(--text)}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .compare{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/jeux.php">&larr; <?= t('Jeux','Игры') ?></a>
  <div class="topbar-title">💞 <?= t('Quiz Couple','Викторина пары') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <div class="header">
    <h1><?= t('Qui est notre couple ?','Кто наша пара?') ?></h1>
    <p><?= t('Répondez chacun aux mêmes questions, puis découvrez vos réponses côte à côte !','Каждый отвечает на одни и те же вопросы, потом смотрите ответы рядом!') ?></p>
  </div>

  <div class="stats-bar">
    <div>
      <div class="stat-num"><?= $myAnswered ?>/<?= $totalQ ?></div>
      <div class="stat-label"><?= t('mes réponses','моих ответов') ?></div>
    </div>
    <div class="stat-divider"></div>
    <div>
      <div class="stat-num"><?= $bothAnswered ?></div>
      <div class="stat-label"><?= t('comparées','сравнено') ?></div>
    </div>
  </div>

  <?php foreach ($questions as $q):
    $qText = ($lang === 'ru' && $q['question_ru']) ? $q['question_ru'] : $q['question_fr'];
    $qAlt = ($lang === 'ru') ? $q['question_fr'] : ($q['question_ru'] ?? '');
    $myAns = $answers[$q['id']][$user['id']] ?? null;
    $partnerAns = null;
    foreach ($answers[$q['id']] ?? [] as $uid => $a) {
        if ($uid != $user['id']) { $partnerAns = $a; break; }
    }
    $bothDone = $myAns && $partnerAns;
    $cat = $catLabels[$q['categorie']] ?? '';
    $catE = $catEmojis[$q['categorie']] ?? '';
  ?>
  <div class="q-item" id="q-<?= $q['id'] ?>">
    <div class="q-cat"><?= $catE ?> <?= $cat ?></div>
    <div class="q-text"><?= h($qText) ?></div>
    <?php if ($qAlt): ?><div class="q-text-alt"><?= h($qAlt) ?></div><?php endif; ?>

    <?php if ($bothDone): ?>
      <div class="q-status done">✓ <?= t('Les deux ont répondu','Оба ответили') ?></div>
      <div class="compare">
        <?php foreach ($members as $m):
          $a = $answers[$q['id']][$m['id']] ?? null;
          if (!$a) continue;
          $aLang = $a['answer_lang'] ?? 'fr';
          $aPrimary = ($lang !== $aLang && $a['answer_translated']) ? $a['answer_translated'] : $a['answer'];
          $aSecondary = ($lang !== $aLang && $a['answer_translated']) ? $a['answer'] : ($a['answer_translated'] ?? '');
        ?>
        <div class="compare-box">
          <div class="compare-name"><?= h($m['display_name']) ?></div>
          <div class="compare-answer"><?= h($aPrimary) ?></div>
          <?php if ($aSecondary && $aSecondary !== $aPrimary): ?>
          <div class="compare-trad"><?= h($aSecondary) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($myAns): ?>
      <div class="q-status waiting">⏳ <?= t('En attente du/de la partenaire','Ожидание партнёра') ?></div>

    <?php else: ?>
      <?php if ($partnerAns): ?>
      <div class="q-status ready">✨ <?= t('Ton/ta partenaire a répondu — à toi !','Партнёр ответил(а) — твоя очередь!') ?></div>
      <?php endif; ?>
      <textarea class="answer-input" id="ans-<?= $q['id'] ?>" placeholder="<?= t('Ta réponse...','Твой ответ...') ?>"></textarea>
      <button class="btn" id="btn-<?= $q['id'] ?>" onclick="submitAnswer(<?= $q['id'] ?>)" disabled><?= t('Répondre','Ответить') ?></button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- Add question -->
  <div class="add-section">
    <button class="add-toggle" onclick="document.getElementById('addForm').classList.toggle('open')">+ <?= t('Ajouter une question','Добавить вопрос') ?></button>
    <div class="add-form" id="addForm">
      <div class="field">
        <label><?= t('Question (français)','Вопрос (французский)') ?></label>
        <input type="text" id="newQFr" placeholder="<?= t('Ex: Quel est notre plat préféré ?','Напр: Какое наше любимое блюдо?') ?>">
      </div>
      <div class="field">
        <label><?= t('Question (russe)','Вопрос (русский)') ?></label>
        <input type="text" id="newQRu" placeholder="<?= t('Auto-traduit si vide','Авто-перевод если пусто') ?>">
      </div>
      <div class="field">
        <label><?= t('Catégorie','Категория') ?></label>
        <select id="newQCat">
          <?php foreach ($catLabels as $k => $v): ?>
          <option value="<?= $k ?>"><?= $catEmojis[$k] ?> <?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" onclick="addQuestion()"><?= t('Ajouter','Добавить') ?></button>
    </div>
  </div>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;

// Enable buttons when input has text
document.querySelectorAll('.answer-input').forEach(ta => {
    const id = ta.id.replace('ans-','');
    const btn = document.getElementById('btn-' + id);
    if (!btn) return;
    ta.addEventListener('input', () => { btn.disabled = ta.value.trim().length < 1; });
    ta.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey && !btn.disabled) { e.preventDefault(); btn.click(); } });
});

function submitAnswer(qid) {
    const ta = document.getElementById('ans-' + qid);
    const btn = document.getElementById('btn-' + qid);
    const answer = ta.value.trim();
    if (!answer) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'answer');
    fd.append('question_id', qid);
    fd.append('answer', answer);
    fetch(BASE + '/jeux_couple_quiz.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); else btn.disabled = false; })
        .catch(() => { btn.disabled = false; });
}

function addQuestion() {
    const fr = document.getElementById('newQFr').value.trim();
    const ru = document.getElementById('newQRu').value.trim();
    const cat = document.getElementById('newQCat').value;
    if (!fr && !ru) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'add_question');
    fd.append('question_fr', fr);
    fd.append('question_ru', ru);
    fd.append('categorie', cat);
    fetch(BASE + '/jeux_couple_quiz.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}
</script>
</body>
</html>
