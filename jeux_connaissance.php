<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Ensure tables exist
try { db()->query("SELECT 1 FROM jeux_connaissance LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_jeux_connaissance.sql'));
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Answer a question
    if ($action === 'answer') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $reponse = trim($_POST['reponse'] ?? '');
        if ($qid && $reponse) {
            $stmt = db()->prepare("INSERT INTO jeux_connaissance_reponses (question_id, user_id, reponse) VALUES (?,?,?)");
            $stmt->execute([$qid, $user['id'], $reponse]);
            echo json_encode(['ok' => true, 'id' => db()->lastInsertId()]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Missing data']);
        }
        exit;
    }

    // Validate partner's answer
    if ($action === 'validate') {
        $rid = (int)($_POST['reponse_id'] ?? 0);
        $correct = (int)($_POST['correct'] ?? 0);
        if ($rid) {
            $stmt = db()->prepare("UPDATE jeux_connaissance_reponses SET is_correct=?, validated_by=? WHERE id=? AND user_id != ?");
            $stmt->execute([$correct ? 1 : 0, $user['id'], $rid, $user['id']]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    // Add custom question
    if ($action === 'add_question') {
        $qfr = trim($_POST['question_fr'] ?? '');
        $qru = trim($_POST['question_ru'] ?? '');
        $cat = $_POST['categorie'] ?? 'preferences';
        $valid = ['preferences','souvenirs','personnalite','reves'];
        if (!in_array($cat, $valid)) $cat = 'preferences';
        if ($qfr) {
            $stmt = db()->prepare("INSERT INTO jeux_connaissance (question_fr, question_ru, categorie) VALUES (?,?,?)");
            $stmt->execute([$qfr, $qru ?: null, $cat]);
            echo json_encode(['ok' => true, 'id' => db()->lastInsertId()]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Missing question']);
        }
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// GET: Random question
$cat_filter = $_GET['cat'] ?? 'all';
$valid_cats = ['all','preferences','souvenirs','personnalite','reves'];
if (!in_array($cat_filter, $valid_cats)) $cat_filter = 'all';

$where = '';
$params = [];
if ($cat_filter !== 'all') {
    $where = "WHERE categorie=?";
    $params[] = $cat_filter;
}

$stmt = db()->prepare("SELECT * FROM jeux_connaissance $where ORDER BY RAND() LIMIT 1");
$stmt->execute($params);
$question = $stmt->fetch();

$total = db()->prepare("SELECT COUNT(*) FROM jeux_connaissance $where");
$total->execute($params);
$total = $total->fetchColumn();

// Scores
$score_stmt = db()->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) as correct
    FROM jeux_connaissance_reponses WHERE validated_by IS NOT NULL");
$score_stmt->execute();
$scores = $score_stmt->fetch();

// Pending validations (answers by the other user that I need to validate)
$pending = db()->prepare("SELECT r.*, q.question_fr, q.question_ru, u.display_name, u.avatar
    FROM jeux_connaissance_reponses r
    JOIN jeux_connaissance q ON q.id = r.question_id
    JOIN users u ON u.id = r.user_id
    WHERE r.user_id != ? AND r.is_correct IS NULL
    ORDER BY r.created_at DESC LIMIT 10");
$pending->execute([$user['id']]);
$pending = $pending->fetchAll();

// Recent answers (validated)
$recent = db()->prepare("SELECT r.*, q.question_fr, q.question_ru, u.display_name, u.avatar
    FROM jeux_connaissance_reponses r
    JOIN jeux_connaissance q ON q.id = r.question_id
    JOIN users u ON u.id = r.user_id
    WHERE r.is_correct IS NOT NULL
    ORDER BY r.created_at DESC LIMIT 10");
$recent->execute();
$recent = $recent->fetchAll();

$catLabels = [
    'preferences' => t('Préférences','Предпочтения'),
    'souvenirs'   => t('Souvenirs','Воспоминания'),
    'personnalite'=> t('Personnalité','Личность'),
    'reves'       => t('Rêves','Мечты'),
];
$catEmojis = ['preferences'=>'💜','souvenirs'=>'📸','personnalite'=>'🌟','reves'=>'✨'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Qui me connaît le mieux ?','Кто знает меня лучше?') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<script src="<?= BASE_URL ?>/includes/autotranslate.js"></script>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:700px;margin:0 auto;padding:2.5rem 2rem}

/* Score banner */
.score-banner{display:flex;justify-content:center;align-items:center;gap:2rem;padding:1rem;border:1px solid var(--border);background:var(--s);margin-bottom:2rem;text-align:center}
.score-num{font-family:'Cormorant Garamond',serif;font-size:2rem;color:var(--accent);line-height:1}
.score-label{font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.score-divider{width:1px;height:40px;background:var(--border)}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:2rem;justify-content:center}
.fbtn{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .6rem;cursor:pointer;text-decoration:none;transition:all .2s}
.fbtn:hover,.fbtn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Question card */
.q-card{background:var(--s);border:1px solid var(--border);padding:2.5rem 2rem;text-align:center;margin-bottom:2rem;animation:cardIn .4s ease;position:relative}
@keyframes cardIn{from{opacity:0;transform:translateY(15px)}to{opacity:1;transform:none}}
.q-cat{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:1.5rem}
.q-text{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;line-height:1.5;color:var(--text);margin-bottom:.8rem}
.q-text-alt{font-size:1rem;color:var(--muted);font-style:italic;font-family:'Cormorant Garamond',serif;margin-bottom:1.5rem}
.answer-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.8rem;padding:.8rem 1rem;outline:none;transition:border .2s;text-align:center;margin-bottom:1rem}
.answer-input:focus{border-color:var(--accent)}
.answer-input::placeholder{color:var(--muted)}
.q-btns{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.45rem 1rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn.secondary{border-color:var(--border);color:var(--muted)}
.btn.secondary:hover{border-color:var(--accent);color:var(--accent);background:transparent}
.btn:disabled{opacity:.4;cursor:not-allowed}
.success-msg{color:var(--accent);font-size:.7rem;text-align:center;margin-top:.8rem;display:none}

/* Pending validations */
.section-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);margin-bottom:1rem;margin-top:2.5rem}
.pending-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.6rem}
.pending-q{font-size:.7rem;color:var(--muted);margin-bottom:.4rem}
.pending-answer{font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:var(--text);margin-bottom:.5rem}
.pending-who{font-size:.55rem;color:var(--muted);letter-spacing:.08em;margin-bottom:.6rem}
.pending-btns{display:flex;gap:.5rem}
.btn-correct{font-size:.55rem;letter-spacing:.1em;background:transparent;border:1px solid #6ec98a;color:#6ec98a;padding:.3rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn-correct:hover{background:#6ec98a;color:var(--bg)}
.btn-wrong{font-size:.55rem;letter-spacing:.1em;background:transparent;border:1px solid #c96e6e;color:#c96e6e;padding:.3rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn-wrong:hover{background:#c96e6e;color:#fff}

/* Recent answers */
.recent-card{background:var(--s);border:1px solid var(--border);padding:.8rem 1rem;margin-bottom:.4rem;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.recent-left{flex:1;min-width:0}
.recent-q{font-size:.6rem;color:var(--muted);margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.recent-a{font-size:.75rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.recent-who{font-size:.5rem;color:var(--muted);margin-top:.15rem}
.result-badge{font-size:.65rem;padding:.2rem .5rem;border:1px solid;flex-shrink:0}
.result-badge.correct{border-color:#6ec98a;color:#6ec98a}
.result-badge.wrong{border-color:#c96e6e;color:#c96e6e}

/* Add question */
.add-section{margin-top:2.5rem;border-top:1px solid var(--border);padding-top:2rem}
.add-toggle{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);cursor:pointer;border:1px dashed var(--border);padding:.5rem 1rem;text-align:center;transition:all .2s;background:transparent;width:100%;font-family:'DM Mono',monospace}
.add-toggle:hover{border-color:var(--accent);color:var(--accent)}
.add-form{display:none;margin-top:1rem}
.add-form.open{display:block}
.field{margin-bottom:.8rem}
.field label{display:block;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.field input,.field select,.field textarea{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);padding:.5rem .7rem;font-family:'DM Mono',monospace;font-size:.7rem;outline:none;transition:border .2s}
.field input:focus,.field select:focus{border-color:var(--accent)}
.field select option{background:var(--bg);color:var(--text)}
.empty{text-align:center;padding:2rem;font-size:.7rem;color:var(--muted)}
.stats{text-align:center;font-size:.55rem;color:var(--muted);letter-spacing:.1em;margin-top:.5rem}
.badge-pending{background:var(--accent);color:var(--bg);font-size:.5rem;padding:.1rem .4rem;font-weight:bold;margin-left:.3rem}

@media(max-width:600px){
  .topbar{padding:.8rem 1rem}
  .wrap{padding:1.5rem 1rem}
  .q-text{font-size:1.2rem}
  .score-banner{gap:1rem}
  .score-num{font-size:1.5rem}
}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/jeux.php">&larr; <?= t('Jeux','Игры') ?></a>
  <div class="topbar-title">💕 <?= t('Qui me connaît ?','Кто меня знает?') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

  <!-- Score -->
  <div class="score-banner">
    <div>
      <div class="score-num"><?= (int)($scores['correct'] ?? 0) ?></div>
      <div class="score-label"><?= t('Bonnes réponses','Правильных') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= (int)($scores['total'] ?? 0) ?></div>
      <div class="score-label"><?= t('Total','Всего') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= $scores['total'] ? round(($scores['correct'] / $scores['total']) * 100) : 0 ?>%</div>
      <div class="score-label"><?= t('Réussite','Успех') ?></div>
    </div>
  </div>

  <!-- Category filters -->
  <div class="filters">
    <a class="fbtn <?= $cat_filter==='all'?'active':'' ?>" href="?cat=all"><?= t('Toutes','Все') ?></a>
    <?php foreach ($catLabels as $k => $label): ?>
    <a class="fbtn <?= $cat_filter===$k?'active':'' ?>" href="?cat=<?= $k ?>"><?= $catEmojis[$k] ?> <?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Question card -->
  <?php if ($question): ?>
  <div class="q-card" id="qCard">
    <div class="q-cat"><?= $catEmojis[$question['categorie']] ?? '' ?> <?= $catLabels[$question['categorie']] ?? '' ?></div>
    <div class="q-text"><?= h($lang === 'ru' && $question['question_ru'] ? $question['question_ru'] : $question['question_fr']) ?></div>
    <?php if ($lang !== 'ru' && $question['question_ru']): ?>
      <div class="q-text-alt"><?= h($question['question_ru']) ?></div>
    <?php elseif ($lang === 'ru' && $question['question_fr']): ?>
      <div class="q-text-alt"><?= h($question['question_fr']) ?></div>
    <?php endif; ?>
    <input type="text" class="answer-input" id="answerInput" placeholder="<?= t('Ta réponse…','Твой ответ…') ?>" autocomplete="off">
    <div class="q-btns">
      <button class="btn" id="submitAnswer" onclick="submitAnswer(<?= $question['id'] ?>)" disabled><?= t('Valider','Подтвердить') ?></button>
      <a class="btn secondary" href="?cat=<?= $cat_filter ?>"><?= t('Passer','Пропустить') ?></a>
    </div>
    <div class="success-msg" id="successMsg">✓ <?= t('Réponse envoyée ! Ton/ta partenaire doit valider.','Ответ отправлен! Твой партнёр должен подтвердить.') ?></div>
  </div>
  <div class="stats"><?= $total ?> <?= t('questions disponibles','вопросов доступно') ?></div>
  <?php else: ?>
  <div class="empty"><?= t('Aucune question disponible.','Нет доступных вопросов.') ?></div>
  <?php endif; ?>

  <!-- Pending validations -->
  <?php if (!empty($pending)): ?>
  <div class="section-title"><?= t('À valider','К проверке') ?> <span class="badge-pending"><?= count($pending) ?></span></div>
  <?php foreach ($pending as $p): ?>
  <div class="pending-card" id="pending-<?= $p['id'] ?>">
    <div class="pending-q"><?= h($lang === 'ru' && $p['question_ru'] ? $p['question_ru'] : $p['question_fr']) ?></div>
    <div class="pending-answer">&laquo; <?= h($p['reponse']) ?> &raquo;</div>
    <div class="pending-who"><?= h($p['display_name']) ?> <?= t('pense que…','думает, что…') ?></div>
    <div class="pending-btns">
      <button class="btn-correct" onclick="validate(<?= $p['id'] ?>, 1)">✓ <?= t('Correct','Правильно') ?></button>
      <button class="btn-wrong" onclick="validate(<?= $p['id'] ?>, 0)">✗ <?= t('Faux','Неправильно') ?></button>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Recent answers -->
  <?php if (!empty($recent)): ?>
  <div class="section-title"><?= t('Dernières réponses','Последние ответы') ?></div>
  <?php foreach ($recent as $r): ?>
  <div class="recent-card">
    <div class="recent-left">
      <div class="recent-q"><?= h($lang === 'ru' && $r['question_ru'] ? $r['question_ru'] : $r['question_fr']) ?></div>
      <div class="recent-a">&laquo; <?= h($r['reponse']) ?> &raquo;</div>
      <div class="recent-who"><?= h($r['display_name']) ?></div>
    </div>
    <div class="result-badge <?= $r['is_correct'] ? 'correct' : 'wrong' ?>"><?= $r['is_correct'] ? '✓' : '✗' ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Add custom question -->
  <div class="add-section">
    <button class="add-toggle" onclick="document.getElementById('addForm').classList.toggle('open')">+ <?= t('Ajouter une question','Добавить вопрос') ?></button>
    <div class="add-form" id="addForm">
      <div class="field">
        <label><?= t('Question (français)','Вопрос (французский)') ?> *</label>
        <input type="text" id="newQFr" placeholder="<?= t('Ex: Quel est mon film préféré ?','Напр: Какой мой любимый фильм?') ?>">
      </div>
      <div class="field">
        <label><?= t('Question (russe)','Вопрос (русский)') ?></label>
        <input type="text" id="newQRu">
      </div>
      <div class="field">
        <label><?= t('Catégorie','Категория') ?></label>
        <select id="newQCat">
          <?php foreach ($catLabels as $k => $label): ?>
          <option value="<?= $k ?>"><?= $catEmojis[$k] ?> <?= $label ?></option>
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
const LANG = <?= json_encode($lang) ?>;

// Enable submit when answer has text
const answerInput = document.getElementById('answerInput');
const submitBtn = document.getElementById('submitAnswer');
if (answerInput) {
    answerInput.addEventListener('input', () => {
        submitBtn.disabled = answerInput.value.trim().length < 1;
    });
    answerInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !submitBtn.disabled) submitBtn.click();
    });
}

function submitAnswer(qid) {
    const reponse = answerInput.value.trim();
    if (!reponse) return;
    submitBtn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'answer');
    fd.append('csrf_token', CSRF);
    fd.append('question_id', qid);
    fd.append('reponse', reponse);
    fetch(BASE + '/jeux_connaissance.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                answerInput.disabled = true;
                document.getElementById('successMsg').style.display = 'block';
            }
        });
}

function validate(rid, correct) {
    const fd = new FormData();
    fd.append('action', 'validate');
    fd.append('csrf_token', CSRF);
    fd.append('reponse_id', rid);
    fd.append('correct', correct);
    fetch(BASE + '/jeux_connaissance.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const el = document.getElementById('pending-' + rid);
                el.style.opacity = '0.3';
                el.style.pointerEvents = 'none';
                el.querySelector('.pending-btns').innerHTML = correct
                    ? '<span style="color:#6ec98a">✓ ' + (LANG==='ru'?'Правильно':'Correct') + '</span>'
                    : '<span style="color:#c96e6e">✗ ' + (LANG==='ru'?'Неправильно':'Faux') + '</span>';
                el.style.opacity = '1';
            }
        });
}

function addQuestion() {
    const fr = document.getElementById('newQFr').value.trim();
    const ru = document.getElementById('newQRu').value.trim();
    const cat = document.getElementById('newQCat').value;
    if (!fr) return;
    const fd = new FormData();
    fd.append('action', 'add_question');
    fd.append('csrf_token', CSRF);
    fd.append('question_fr', fr);
    fd.append('question_ru', ru);
    fd.append('categorie', cat);
    fetch(BASE + '/jeux_connaissance.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('newQFr').value = '';
                document.getElementById('newQRu').value = '';
                document.getElementById('addForm').classList.remove('open');
                location.reload();
            }
        });
}

// Auto-translate for new question form
autoTranslate([
    { fr: '#newQFr', ru: '#newQRu' }
], LANG, BASE);
</script>
</body>
</html>
