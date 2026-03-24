<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/notifications.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Liste des utilisateurs (pour destinataire)
$all_users = db()->query("SELECT * FROM users ORDER BY id")->fetchAll();
$users_by_id = [];
foreach ($all_users as $u) $users_by_id[$u['id']] = $u;

// Créer un questionnaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_quiz') {
        $titre = trim($_POST['titre'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $dest  = $_POST['destinataire'] ?? 'tous';
        if (!in_array($dest, ['tous', 'raphael', 'marina'])) $dest = 'tous';
        if ($titre) {
            db()->prepare("INSERT INTO questionnaires (created_by, titre, description, destinataire) VALUES (?,?,?,?)")
               ->execute([$user['id'], $titre, $desc, $dest]);
            $qid = db()->lastInsertId();
            $questions_fr = $_POST['questions_fr'] ?? [];
            $questions_ru = $_POST['questions_ru'] ?? [];
            $opts_fr = $_POST['opts_fr'] ?? [];
            $opts_ru = $_POST['opts_ru'] ?? [];
            $stmt = db()->prepare("INSERT INTO questionnaire_questions (questionnaire_id, position, question_fr, question_ru, opt_a_fr, opt_b_fr, opt_c_fr, opt_d_fr, opt_a_ru, opt_b_ru, opt_c_ru, opt_d_ru) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($questions_fr as $i => $qfr) {
                if (!trim($qfr)) continue;
                $of = $opts_fr[$i] ?? [];
                $or = $opts_ru[$i] ?? [];
                $stmt->execute([$qid, $i+1, $qfr, $questions_ru[$i]??'', $of[0]??'', $of[1]??'', $of[2]??null, $of[3]??null, $or[0]??null, $or[1]??null, $or[2]??null, $or[3]??null]);
            }
            // Notify
            try {
                notifyOtherUser($user['id'], 'questionnaire',
                    $user['display_name'].' a créé un questionnaire : '.$titre,
                    $user['display_name'].' создал(а) анкету: '.$titre,
                    BASE_URL.'/questionnaires.php?view='.$qid);
            } catch (Exception $e) {}
            header('Location: '.BASE_URL.'/questionnaires.php?view='.$qid); exit;
        }
    }
    if ($action === 'submit_answers') {
        $qid  = (int)($_POST['quiz_id'] ?? 0);
        // Vérifier que l'utilisateur est bien destinataire
        $checkQuiz = db()->prepare("SELECT destinataire FROM questionnaires WHERE id=?");
        $checkQuiz->execute([$qid]);
        $checkDest = $checkQuiz->fetchColumn();
        $canAnswer = ($checkDest === 'tous' || $checkDest === $user['username']);
        if ($canAnswer) {
            $reps = $_POST['answers'] ?? [];
            foreach ($reps as $question_id => $answer_index) {
                db()->prepare("DELETE FROM questionnaire_reponses WHERE questionnaire_id=? AND user_id=? AND question_id=?")
                   ->execute([$qid, $user['id'], $question_id]);
                db()->prepare("INSERT INTO questionnaire_reponses (questionnaire_id, user_id, question_id, answer_index) VALUES (?,?,?,?)")
                   ->execute([$qid, $user['id'], $question_id, (int)$answer_index]);
            }
            // Notify
            try {
                notifyOtherUser($user['id'], 'reponse',
                    $user['display_name'].' a répondu à un questionnaire',
                    $user['display_name'].' ответил(а) на анкету',
                    BASE_URL.'/questionnaires.php?view='.$qid.'&results=1');
            } catch (Exception $e) {}
        }
        header('Location: '.BASE_URL.'/questionnaires.php?view='.$qid.'&done=1'); exit;
    }
}

$view = isset($_GET['view']) ? (int)$_GET['view'] : null;
$mode = $_GET['mode'] ?? 'list';
$done = isset($_GET['done']);

if ($view) {
    $quiz = db()->prepare("SELECT q.*, u.display_name FROM questionnaires q JOIN users u ON u.id=q.created_by WHERE q.id=?");
    $quiz->execute([$view]);
    $quiz = $quiz->fetch();
    if ($quiz) {
        $questions = db()->prepare("SELECT * FROM questionnaire_questions WHERE questionnaire_id=? ORDER BY position");
        $questions->execute([$view]);
        $questions = $questions->fetchAll();
        // Réponses de chaque user
        $reponses = db()->prepare("SELECT r.*, u.display_name, u.avatar FROM questionnaire_reponses r JOIN users u ON u.id=r.user_id WHERE r.questionnaire_id=?");
        $reponses->execute([$view]);
        $reponses_raw = $reponses->fetchAll();
        $reponses_map = [];
        foreach ($reponses_raw as $r) {
            $reponses_map[$r['question_id']][$r['user_id']] = $r;
        }
        // Destinataire
        $destinataire = $quiz['destinataire'] ?? 'tous';
        $isTarget = ($destinataire === 'tous' || $destinataire === $user['username']);
        // A-t-il déjà répondu ?
        $already = !empty($reponses_map) && isset(array_values($reponses_map)[0][$user['id']]);
        // Y a-t-il des réponses de quelqu'un ?
        $hasAnyAnswers = !empty($reponses_raw);
        // Doit-on afficher le formulaire de réponse ?
        $showForm = $isTarget && !$already && !isset($_GET['results']);
        // Doit-on afficher les résultats ?
        $showResults = ($already || !$isTarget || isset($_GET['results'])) && $hasAnyAnswers;
    }
}

$quizzes = db()->query("SELECT q.*, u.display_name, (SELECT COUNT(*) FROM questionnaire_questions WHERE questionnaire_id=q.id) as nb_q FROM questionnaires q JOIN users u ON u.id=q.created_by ORDER BY q.created_at DESC")->fetchAll();
$letters = ['A','B','C','D'];

// Labels destinataire
function destLabel(string $dest, string $langCode): string {
    $labels = [
        'tous'    => $langCode === 'ru' ? 'Для обоих' : 'Pour les deux',
        'raphael' => $langCode === 'ru' ? 'Для Raphaël' : 'Pour Raphaël',
        'marina'  => $langCode === 'ru' ? 'Для Marina' : 'Pour Marina',
    ];
    return $labels[$dest] ?? $labels['tous'];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Questionnaires','Анкеты') ?></title>
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
.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}
.btn.secondary{border-color:var(--border);color:var(--muted)}
.btn.secondary:hover{border-color:var(--accent);color:var(--accent);background:transparent}
.wrap{max-width:760px;margin:0 auto;padding:2.5rem 2rem}

/* Liste quiz */
.quiz-item{padding:1.2rem 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:1rem}
.quiz-title{font-family:'Cormorant Garamond',serif;font-size:1.15rem;color:var(--text)}
.quiz-meta{font-size:.58rem;color:var(--muted);letter-spacing:.08em;margin-top:.2rem}
.dest-badge{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;border:1px solid rgba(201,169,110,.3);color:var(--accent);padding:.15rem .5rem;display:inline-block;margin-top:.3rem}
.dest-badge.pour-moi{border-color:rgba(110,201,138,.3);color:#6ec98a}
.empty{text-align:center;padding:4rem;font-size:.72rem;color:var(--muted)}

/* Formulaire création */
.form-section{margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid var(--border)}
.form-section h3{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);margin-bottom:1rem}
label{display:block;font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;margin-top:.8rem}
input[type=text],textarea,select{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.8rem;padding:.7rem .9rem;outline:none;transition:border .2s}
select{appearance:none;cursor:pointer}
select option{background:var(--bg);color:var(--text)}
textarea{min-height:80px;resize:vertical}
input:focus,textarea:focus,select:focus{border-color:var(--accent)}
.q-block{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.8rem}
.q-block-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem}
.q-num{font-size:.6rem;letter-spacing:.12em;color:var(--accent)}
.remove-q{font-size:.55rem;color:var(--muted);background:none;border:none;cursor:pointer;letter-spacing:.1em}
.remove-q:hover{color:#c96e6e}
.opts-grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-top:.4rem}
.add-q-btn{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px dashed var(--border);color:var(--muted);padding:.6rem 1rem;cursor:pointer;width:100%;transition:all .2s;margin-top:.5rem}
.add-q-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Destinataire selector */
.dest-selector{display:flex;gap:.5rem;margin-top:.4rem}
.dest-opt{flex:1;text-align:center;padding:.7rem .5rem;border:1px solid var(--border);cursor:pointer;font-size:.65rem;letter-spacing:.08em;transition:all .2s;position:relative}
.dest-opt:hover{border-color:var(--accent);color:var(--accent)}
.dest-opt.active{border-color:var(--accent);background:var(--as);color:var(--accent)}
.dest-opt input{position:absolute;opacity:0;width:0;height:0}
.dest-opt .dest-icon{font-size:1.2rem;display:block;margin-bottom:.3rem}

/* Vue questionnaire */
.qview-header{margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border)}
.qview-title{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,4vw,2.2rem);font-weight:300;line-height:1.3;margin-bottom:.4rem}
.qview-meta{font-size:.6rem;color:var(--muted);letter-spacing:.1em}
.qview-block{margin-bottom:2.5rem;padding-bottom:2rem;border-bottom:1px solid var(--border)}
.qview-num{font-size:.6rem;letter-spacing:.2em;color:var(--accent);margin-bottom:.5rem;display:block}
.qview-q{font-family:'Cormorant Garamond',serif;font-size:1.2rem;line-height:1.5;margin-bottom:1rem}
.qview-q-ru{font-size:.9rem;font-style:italic;color:var(--muted);margin-bottom:1rem;font-family:'Cormorant Garamond',serif}
.opt-row{display:flex;align-items:center;gap:.8rem;padding:.6rem .8rem;border:1px solid var(--border);margin-bottom:.3rem;cursor:pointer;transition:all .2s;background:transparent;width:100%;text-align:left;color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem}
.opt-row:hover{border-color:var(--accent);background:var(--as)}
.opt-row.selected{border-color:var(--accent);background:var(--as)}
.opt-letter{font-size:.6rem;color:var(--accent);width:1rem;flex-shrink:0}
/* Réponses comparées */
.answers-compare{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-top:.5rem}
.answers-compare.single{grid-template-columns:1fr}
.ans-box{padding:.5rem .7rem;border:1px solid var(--border);font-size:.65rem}
.ans-box .who{font-size:.55rem;letter-spacing:.1em;color:var(--muted);margin-bottom:.2rem;text-transform:uppercase}
.ans-box .what{color:var(--text)}
.ans-box.same{border-color:var(--accent);background:var(--as)}
.match-badge{font-size:.58rem;color:var(--accent);letter-spacing:.1em;margin-top:.3rem;display:inline-block}
.success-bar{background:var(--as);border:1px solid rgba(201,169,110,.3);padding:1rem 1.5rem;text-align:center;margin-bottom:2rem;font-size:.7rem;color:var(--accent);letter-spacing:.1em}
.waiting-bar{background:rgba(122,114,104,.08);border:1px solid var(--border);padding:1rem 1.5rem;text-align:center;margin-bottom:2rem;font-size:.7rem;color:var(--muted);letter-spacing:.1em}
.actions-bar{display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1rem}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= $view || $mode==='new' ? BASE_URL.'/questionnaires.php' : BASE_URL.'/dashboard.php' ?>">← <?= $view||$mode==='new' ? t('Retour','Назад') : t('Accueil','Главная') ?></a>
  <div class="topbar-title">💌 <?= t('Questionnaires','Анкеты') ?></div>
  <?php if (!$view && $mode!=='new'): ?>
  <a class="btn" href="?mode=new">+ <?= t('Créer','Создать') ?></a>
  <?php else: ?><span style="width:80px"></span><?php endif; ?>
</div>

<div class="wrap">

<?php if ($mode === 'new'): ?>
<!-- ═══════ Création ═══════ -->
<h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:2rem"><?= t('Nouveau questionnaire','Новая анкета') ?></h2>
<form method="POST" id="quiz-form">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="create_quiz">
  <div class="form-section">
    <label><?= t('Titre du questionnaire','Название анкеты') ?></label>
    <input type="text" name="titre" required placeholder="<?= t('Ex: Questionnaire sur nos habitudes…','Напр: Анкета о наших привычках…') ?>">
    <label><?= t('Description (optionnel)','Описание (необязательно)') ?></label>
    <input type="text" name="description" placeholder="…">
    <label><?= t('Qui doit répondre ?','Кто должен ответить?') ?></label>
    <div class="dest-selector">
      <label class="dest-opt active" onclick="selectDest(this)">
        <input type="radio" name="destinataire" value="tous" checked>
        <span class="dest-icon">👫</span>
        <?= t('Les deux','Оба') ?>
      </label>
      <label class="dest-opt" onclick="selectDest(this)">
        <input type="radio" name="destinataire" value="raphael">
        <span class="dest-icon">R</span>
        Raphaël
      </label>
      <label class="dest-opt" onclick="selectDest(this)">
        <input type="radio" name="destinataire" value="marina">
        <span class="dest-icon">M</span>
        Marina
      </label>
    </div>
  </div>
  <div id="questions-wrap"></div>
  <button type="button" class="add-q-btn" onclick="addQuestion()">+ <?= t('Ajouter une question','Добавить вопрос') ?></button>
  <div style="display:flex;gap:1rem;margin-top:2rem">
    <button type="submit" class="btn primary"><?= t('Publier','Опубликовать') ?></button>
    <a class="btn secondary" href="<?= BASE_URL ?>/questionnaires.php"><?= t('Annuler','Отмена') ?></a>
  </div>
</form>

<script>
function selectDest(el) {
  document.querySelectorAll('.dest-opt').forEach(d => d.classList.remove('active'));
  el.classList.add('active');
  el.querySelector('input').checked = true;
}
let qCount = 0;
function addQuestion() {
  const i = qCount++;
  const wrap = document.getElementById('questions-wrap');
  const block = document.createElement('div');
  block.className = 'q-block';
  block.id = 'q'+i;
  block.innerHTML = `
    <div class="q-block-header">
      <span class="q-num"><?= t('Question','Вопрос') ?> ${i+1}</span>
      <button type="button" class="remove-q" onclick="document.getElementById('q${i}').remove()">✕</button>
    </div>
    <label><?= t('Question (FR)','Вопрос (FR)') ?></label>
    <input type="text" name="questions_fr[${i}]" required placeholder="<?= t('En français…','По-французски…') ?>">
    <label><?= t('Question (RU)','Вопрос (RU)') ?></label>
    <input type="text" name="questions_ru[${i}]" placeholder="По-русски… (необязательно)">
    <label><?= t('Options FR (A, B, C, D)','Варианты FR (A, B, C, D)') ?></label>
    <div class="opts-grid">
      <input type="text" name="opts_fr[${i}][0]" placeholder="A…" required>
      <input type="text" name="opts_fr[${i}][1]" placeholder="B…" required>
      <input type="text" name="opts_fr[${i}][2]" placeholder="C… (optionnel)">
      <input type="text" name="opts_fr[${i}][3]" placeholder="D… (optionnel)">
    </div>
    <label><?= t('Options RU (optionnel)','Варианты RU (необязательно)') ?></label>
    <div class="opts-grid">
      <input type="text" name="opts_ru[${i}][0]" placeholder="А…">
      <input type="text" name="opts_ru[${i}][1]" placeholder="Б…">
      <input type="text" name="opts_ru[${i}][2]" placeholder="В…">
      <input type="text" name="opts_ru[${i}][3]" placeholder="Г…">
    </div>`;
  wrap.appendChild(block);
}
addQuestion();
</script>

<?php elseif ($view && $quiz): ?>
<!-- ═══════ Vue questionnaire ═══════ -->
<?php
  $destLabel = destLabel($destinataire, $lang);
?>
<div class="qview-header">
  <div class="qview-title"><?= h($quiz['titre']) ?></div>
  <div class="qview-meta">
    <?= h($quiz['display_name']) ?> · <?= count($questions) ?> <?= t('questions','вопросов') ?>
    · <span class="dest-badge"><?= h($destLabel) ?></span>
  </div>
  <?php if ($quiz['description']): ?><div style="font-size:.72rem;color:var(--muted);margin-top:.5rem"><?= h($quiz['description']) ?></div><?php endif; ?>
</div>

<?php if ($done): ?><div class="success-bar">✓ <?= t('Réponses enregistrées !','Ответы сохранены!') ?></div><?php endif; ?>

<?php if ($showResults): ?>
  <!-- ═══ Résultats visibles par tous ═══ -->
  <?php
    // Quels utilisateurs afficher dans les résultats ?
    if ($destinataire === 'tous') {
        $display_users = $all_users; // Les deux
    } else {
        // Seulement le destinataire
        $display_users = array_filter($all_users, fn($u) => $u['username'] === $destinataire);
    }
    $isSingleDest = ($destinataire !== 'tous');
  ?>
  <?php foreach ($questions as $qi => $q): ?>
  <?php
    $opts_fr = [$q['opt_a_fr'],$q['opt_b_fr'],$q['opt_c_fr'],$q['opt_d_fr']];
    $opts_ru = [$q['opt_a_ru'],$q['opt_b_ru'],$q['opt_c_ru'],$q['opt_d_ru']];
    $reps_q  = $reponses_map[$q['id']] ?? [];
  ?>
  <div class="qview-block">
    <span class="qview-num">— <?= str_pad($qi+1,2,'0',STR_PAD_LEFT) ?></span>
    <div class="qview-q"><?= h($lang==='ru'&&$q['question_ru'] ? $q['question_ru'] : $q['question_fr']) ?></div>
    <?php if ($lang!=='ru' && $q['question_ru']): ?><div class="qview-q-ru"><?= h($q['question_ru']) ?></div><?php endif; ?>
    <div class="answers-compare <?= $isSingleDest ? 'single' : '' ?>">
      <?php
      // Vérifier si même réponse (pour "tous")
      $all_same = !$isSingleDest && count($reps_q) >= 2;
      $prev_ai = null;
      foreach ($reps_q as $uid => $rep) {
        if ($prev_ai !== null && $rep['answer_index'] !== $prev_ai) $all_same = false;
        $prev_ai = $rep['answer_index'];
      }
      foreach ($display_users as $u):
        $rep = $reps_q[$u['id']] ?? null;
        $ai  = $rep ? $rep['answer_index'] : null;
        $opt = $ai !== null ? ($lang==='ru' && $opts_ru[$ai] ? $opts_ru[$ai] : $opts_fr[$ai]) : '—';
      ?>
      <div class="ans-box <?= $all_same&&$rep?'same':'' ?>">
        <div class="who"><?= h($u['display_name']) ?></div>
        <div class="what"><?= $ai!==null ? $letters[$ai].'. '.h($opt) : '<span style="color:var(--muted)">'.t('En attente…','Ожидание…').'</span>' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($all_same && count($reps_q) >= 2): ?>
    <div class="match-badge">✓ <?= t('Même réponse !','Одинаковый ответ!') ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="actions-bar">
    <?php if ($isTarget && $already): ?>
    <a class="btn secondary" href="?view=<?= $view ?>"><?= t('Modifier mes réponses','Изменить ответы') ?></a>
    <?php endif; ?>
    <?php if ($isTarget && !$already): ?>
    <a class="btn primary" href="?view=<?= $view ?>"><?= t('Répondre','Ответить') ?></a>
    <?php endif; ?>
  </div>

<?php elseif ($showForm): ?>
  <!-- ═══ Formulaire de réponses ═══ -->
  <?php if ($hasAnyAnswers): ?>
  <a class="btn secondary" href="?view=<?= $view ?>&results=1" style="margin-bottom:1.5rem"><?= t('Voir les résultats','Посмотреть результаты') ?></a>
  <?php endif; ?>
  <form method="POST" id="answer-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="submit_answers">
    <input type="hidden" name="quiz_id" value="<?= $view ?>">
    <?php foreach ($questions as $qi => $q): ?>
    <?php
      $opts_fr = [$q['opt_a_fr'],$q['opt_b_fr'],$q['opt_c_fr'],$q['opt_d_fr']];
      $opts_ru = [$q['opt_a_ru'],$q['opt_b_ru'],$q['opt_c_ru'],$q['opt_d_ru']];
      $my_rep  = ($reponses_map[$q['id']][$user['id']]) ?? null;
    ?>
    <div class="qview-block">
      <span class="qview-num">— <?= str_pad($qi+1,2,'0',STR_PAD_LEFT) ?></span>
      <div class="qview-q"><?= h($lang==='ru'&&$q['question_ru'] ? $q['question_ru'] : $q['question_fr']) ?></div>
      <?php if ($lang!=='ru' && $q['question_ru']): ?><div class="qview-q-ru"><?= h($q['question_ru']) ?></div><?php endif; ?>
      <?php foreach ([0,1,2,3] as $oi):
        $opt = $lang==='ru'&&$opts_ru[$oi] ? $opts_ru[$oi] : $opts_fr[$oi];
        if (!$opt) continue;
        $checked = $my_rep && $my_rep['answer_index'] == $oi;
      ?>
      <label class="opt-row <?= $checked?'selected':'' ?>" onclick="selectOpt(this,<?= $q['id'] ?>,<?= $oi ?>)">
        <span class="opt-letter"><?= $letters[$oi] ?></span>
        <?= h($opt) ?>
        <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $oi ?>" style="display:none" <?= $checked?'checked':'' ?>>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn primary" id="submit-btn" style="display:none"><?= t('Enregistrer mes réponses','Сохранить ответы') ?></button>
  </form>
  <script>
  const answered = new Set();
  const total = <?= count($questions) ?>;
  document.querySelectorAll('input[type=radio]:checked').forEach(r => {
    const qid = r.name.match(/\d+/)[0];
    answered.add(qid);
  });
  checkSubmit();
  function selectOpt(label, qid, oi) {
    const name = 'answers['+qid+']';
    document.querySelectorAll('[name="'+name+'"]').forEach(r => {
      r.closest('.opt-row').classList.remove('selected');
    });
    label.classList.add('selected');
    label.querySelector('input').checked = true;
    answered.add(String(qid));
    checkSubmit();
  }
  function checkSubmit() {
    if (answered.size >= total) document.getElementById('submit-btn').style.display = 'inline-block';
  }
  </script>

<?php else: ?>
  <!-- Pas destinataire et pas de réponses encore -->
  <div class="waiting-bar">
    ⏳ <?= t('En attente des réponses de ', 'Ожидание ответов от ') ?>
    <?php
      if ($destinataire === 'raphael') echo 'Raphaël';
      elseif ($destinataire === 'marina') echo 'Marina';
      else echo t('tout le monde', 'всех');
    ?>
  </div>
<?php endif; ?>

<?php else: ?>
<!-- ═══════ Liste des questionnaires ═══════ -->
<?php if (empty($quizzes)): ?>
<div class="empty"><?= t('Aucun questionnaire encore.<br>Créez le premier !','Анкет пока нет.<br>Создайте первую!') ?></div>
<?php else: ?>
<?php foreach ($quizzes as $qz):
  $qzDest = $qz['destinataire'] ?? 'tous';
  $qzDestLabel = destLabel($qzDest, $lang);
  $isPourMoi = ($qzDest === $user['username']);
?>
<div class="quiz-item">
  <div>
    <div class="quiz-title"><?= h($qz['titre']) ?></div>
    <div class="quiz-meta"><?= h($qz['display_name']) ?> · <?= $qz['nb_q'] ?> <?= t('questions','вопросов') ?> · <?= date('d/m/Y', strtotime($qz['created_at'])) ?></div>
    <span class="dest-badge <?= $isPourMoi ? 'pour-moi' : '' ?>"><?= h($qzDestLabel) ?><?= $isPourMoi ? ' ← '.t('toi','тебе') : '' ?></span>
  </div>
  <a class="btn" href="?view=<?= $qz['id'] ?>"><?= t('Ouvrir','Открыть') ?></a>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
