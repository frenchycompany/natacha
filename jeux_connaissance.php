<?php
/**
 * NATACHA — Qui me connaît le mieux ?
 * Phase 1: Répondre (answer about yourself)
 * Phase 2: Jouer (guess partner's answers)
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Get couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}

// Record game activity (once per day)
if ($coupleId) {
    $today = date('Y-m-d');
    $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='jeu' AND DATE(created_at)=?");
    $already->execute([$coupleId, $user['id'], $today]);
    if (!$already->fetch()) {
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $ce->recordActivity($coupleId, $user['id'], 'jeu',
            $user['display_name'].' a joué à Qui me connaît',
            $user['display_name'].' играл(а) в Кто знает меня лучше');
    }
}

// Ensure tables exist
try { db()->query("SELECT 1 FROM jeux_connaissance LIMIT 1"); } catch (Exception $e) {
    db()->exec(file_get_contents(__DIR__.'/migrate_jeux_connaissance.sql'));
}
// Add new columns
try { db()->query("SELECT is_self_answer FROM jeux_connaissance_reponses LIMIT 1"); } catch (Exception $e) {
    db()->exec("ALTER TABLE jeux_connaissance_reponses ADD COLUMN is_self_answer TINYINT(1) DEFAULT 0, ADD COLUMN reponse_translated TEXT DEFAULT NULL, ADD COLUMN reponse_lang CHAR(2) DEFAULT 'fr'");
}

$mode = $_GET['mode'] ?? '';

// ═══ POST ACTIONS ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Self-answer (répondre about yourself)
    if ($action === 'self_answer') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $reponse = trim($_POST['reponse'] ?? '');
        if ($qid && $reponse) {
            // Check not already answered
            $chk = db()->prepare("SELECT 1 FROM jeux_connaissance_reponses WHERE question_id=? AND user_id=? AND is_self_answer=1");
            $chk->execute([$qid, $user['id']]);
            if ($chk->fetch()) {
                echo json_encode(['ok' => false, 'error' => 'already_answered']); exit;
            }
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($reponse, $fromLang, $toLang);
            $stmt = db()->prepare("INSERT INTO jeux_connaissance_reponses (question_id, user_id, reponse, is_self_answer, reponse_translated, reponse_lang) VALUES (?,?,?,1,?,?)");
            $stmt->execute([$qid, $user['id'], $reponse, $translated ?: null, $fromLang]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Missing data']);
        }
        exit;
    }

    // Guess partner's answer
    if ($action === 'guess') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $reponse = trim($_POST['reponse'] ?? '');
        if ($qid && $reponse) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($reponse, $fromLang, $toLang);
            $stmt = db()->prepare("INSERT INTO jeux_connaissance_reponses (question_id, user_id, reponse, is_self_answer, reponse_translated, reponse_lang) VALUES (?,?,?,0,?,?)");
            $stmt->execute([$qid, $user['id'], $reponse, $translated ?: null, $fromLang]);
            // Get partner's real answer
            $real = db()->prepare("SELECT r.reponse, r.reponse_translated, r.reponse_lang, u.display_name FROM jeux_connaissance_reponses r JOIN users u ON u.id=r.user_id WHERE r.question_id=? AND r.user_id!=? AND r.is_self_answer=1 LIMIT 1");
            $real->execute([$qid, $user['id']]);
            $realAnswer = $real->fetch();
            $guessId = db()->lastInsertId();
            if ($realAnswer) {
                $raLang = $realAnswer['reponse_lang'] ?? 'fr';
                $raPrimary = ($lang !== $raLang && $realAnswer['reponse_translated']) ? $realAnswer['reponse_translated'] : $realAnswer['reponse'];
                $raSecondary = ($lang !== $raLang && $realAnswer['reponse_translated']) ? $realAnswer['reponse'] : ($realAnswer['reponse_translated'] ?? '');
                echo json_encode(['ok' => true, 'guess_id' => $guessId, 'real_answer' => $raPrimary, 'real_alt' => $raSecondary, 'partner' => $realAnswer['display_name']]);
            } else {
                echo json_encode(['ok' => true, 'guess_id' => $guessId, 'real_answer' => null]);
            }
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    // Validate a guess
    if ($action === 'validate') {
        $rid = (int)($_POST['reponse_id'] ?? 0);
        $correct = (int)($_POST['correct'] ?? 0);
        if ($rid) {
            db()->prepare("UPDATE jeux_connaissance_reponses SET is_correct=?, validated_by=? WHERE id=? AND user_id!=? AND is_self_answer=0")
                ->execute([$correct ? 1 : 0, $user['id'], $rid, $user['id']]);
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
        if ($qfr || $qru) {
            if ($qfr && !$qru) $qru = translateText($qfr, 'fr', 'ru') ?: '';
            if ($qru && !$qfr) $qfr = translateText($qru, 'ru', 'fr') ?: $qru;
            db()->prepare("INSERT INTO jeux_connaissance (question_fr, question_ru, categorie) VALUES (?,?,?)")
                ->execute([$qfr, $qru ?: null, $cat]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    echo json_encode(['ok' => false]); exit;
}

// ═══ GET DATA ═══
$catLabels = [
    'preferences' => t('Préférences','Предпочтения'),
    'souvenirs'   => t('Souvenirs','Воспоминания'),
    'personnalite'=> t('Personnalité','Личность'),
    'reves'       => t('Rêves','Мечты'),
];
$catEmojis = ['preferences'=>'💜','souvenirs'=>'📸','personnalite'=>'🌟','reves'=>'✨'];

// Count my self-answers
$myAnswered = db()->prepare("SELECT COUNT(*) FROM jeux_connaissance_reponses WHERE user_id=? AND is_self_answer=1");
$myAnswered->execute([$user['id']]);
$myAnsweredCount = (int)$myAnswered->fetchColumn();

$totalQ = (int)db()->query("SELECT COUNT(*) FROM jeux_connaissance")->fetchColumn();
$toAnswer = $totalQ - $myAnsweredCount;

// Count playable (partner answered, I haven't guessed)
$playable = db()->prepare("SELECT COUNT(DISTINCT r.question_id) FROM jeux_connaissance_reponses r WHERE r.is_self_answer=1 AND r.user_id!=? AND r.question_id NOT IN (SELECT question_id FROM jeux_connaissance_reponses WHERE user_id=? AND is_self_answer=0)");
$playable->execute([$user['id'], $user['id']]);
$toPlay = (int)$playable->fetchColumn();

// Pending validations (guesses from partner that I need to validate)
$pending = db()->prepare("SELECT r.*, q.question_fr, q.question_ru, u.display_name FROM jeux_connaissance_reponses r JOIN jeux_connaissance q ON q.id=r.question_id JOIN users u ON u.id=r.user_id WHERE r.is_self_answer=0 AND r.user_id!=? AND r.is_correct IS NULL ORDER BY r.created_at DESC LIMIT 10");
$pending->execute([$user['id']]);
$pending = $pending->fetchAll();

// Scores
$scores = db()->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) as correct FROM jeux_connaissance_reponses WHERE is_self_answer=0 AND validated_by IS NOT NULL");
$scores->execute();
$scores = $scores->fetch();

// Recent validated
$recent = db()->prepare("SELECT r.*, r.reponse_translated, r.reponse_lang, q.question_fr, q.question_ru, u.display_name FROM jeux_connaissance_reponses r JOIN jeux_connaissance q ON q.id=r.question_id JOIN users u ON u.id=r.user_id WHERE r.is_self_answer=0 AND r.is_correct IS NOT NULL ORDER BY r.created_at DESC LIMIT 8");
$recent->execute();
$recent = $recent->fetchAll();

// Mode-specific data
$question = null;
if ($mode === 'repondre') {
    $answeredIds = db()->prepare("SELECT question_id FROM jeux_connaissance_reponses WHERE user_id=? AND is_self_answer=1");
    $answeredIds->execute([$user['id']]);
    $answeredIds = $answeredIds->fetchAll(PDO::FETCH_COLUMN);
    if (empty($answeredIds)) {
        $question = db()->query("SELECT * FROM jeux_connaissance ORDER BY RAND() LIMIT 1")->fetch();
    } else {
        $ph = implode(',', array_fill(0, count($answeredIds), '?'));
        $stmt = db()->prepare("SELECT * FROM jeux_connaissance WHERE id NOT IN ($ph) ORDER BY RAND() LIMIT 1");
        $stmt->execute($answeredIds);
        $question = $stmt->fetch();
    }
} elseif ($mode === 'jouer') {
    $stmt = db()->prepare("SELECT q.*, sa.reponse AS partner_answer, sa.reponse_translated AS partner_translated, sa.reponse_lang AS partner_lang, u.display_name AS partner_name FROM jeux_connaissance q JOIN jeux_connaissance_reponses sa ON sa.question_id=q.id AND sa.is_self_answer=1 AND sa.user_id!=? JOIN users u ON u.id=sa.user_id WHERE q.id NOT IN (SELECT question_id FROM jeux_connaissance_reponses WHERE user_id=? AND is_self_answer=0) ORDER BY RAND() LIMIT 1");
    $stmt->execute([$user['id'], $user['id']]);
    $question = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Qui me connaît ?','Кто меня знает?') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--green:#6ec98a;--red:#c96e6e}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:700px;margin:0 auto;padding:2rem 2rem}

/* Mode cards (landing) */
.mode-cards{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:2rem}
.mode-card{display:block;text-decoration:none;background:var(--s);border:1px solid var(--border);padding:2rem 1.5rem;text-align:center;transition:all .3s;position:relative;overflow:hidden}
.mode-card:hover{border-color:var(--accent);transform:translateY(-3px)}
.mode-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);opacity:0;transition:opacity .3s}
.mode-card:hover::after{opacity:1}
.mode-emoji{font-size:2.5rem;margin-bottom:.8rem;display:block}
.mode-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:300;color:var(--accent);margin-bottom:.3rem}
.mode-desc{font-size:.55rem;color:var(--muted);letter-spacing:.06em;line-height:1.7;margin-bottom:.8rem}
.mode-count{font-size:1.5rem;font-family:'Cormorant Garamond',serif;color:var(--accent)}
.mode-count-label{font-size:.45rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.mode-card.play{border-color:rgba(201,169,110,.3)}

/* Score banner */
.score-banner{display:flex;justify-content:center;gap:2rem;padding:1rem;border:1px solid var(--border);background:var(--s);margin-bottom:2rem;text-align:center}
.score-num{font-family:'Cormorant Garamond',serif;font-size:2rem;color:var(--accent);line-height:1}
.score-label{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:.2rem}
.score-divider{width:1px;height:40px;background:var(--border)}

/* Question card */
.q-card{background:var(--s);border:1px solid var(--border);padding:2.5rem 2rem;text-align:center;margin-bottom:2rem;animation:cardIn .4s ease}
@keyframes cardIn{from{opacity:0;transform:translateY(15px)}to{opacity:1;transform:none}}
.q-cat{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:1.5rem}
.q-text{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;line-height:1.5;color:var(--text);margin-bottom:.5rem}
.q-text-alt{font-size:.9rem;color:var(--muted);font-style:italic;font-family:'Cormorant Garamond',serif;margin-bottom:1.5rem}
.q-mode-label{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin-bottom:1rem;background:var(--as);display:inline-block;padding:.2rem .6rem;border:1px solid rgba(201,169,110,.3)}
.answer-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.8rem;padding:.8rem 1rem;outline:none;transition:border .2s;text-align:center;margin-bottom:1rem}
.answer-input:focus{border-color:var(--accent)}
.answer-input::placeholder{color:var(--muted)}
.q-btns{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.45rem 1rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn.secondary{border-color:var(--border);color:var(--muted)}
.btn.secondary:hover{border-color:var(--accent);color:var(--accent);background:transparent}
.btn:disabled{opacity:.4;cursor:not-allowed}

/* Reveal */
.reveal{display:none;margin-top:1.5rem;padding:1.5rem;border:1px solid var(--accent);background:rgba(201,169,110,.05);animation:revealIn .5s ease}
@keyframes revealIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.reveal-label{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin-bottom:.5rem}
.reveal-answer{font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:var(--accent);line-height:1.5}
.reveal-alt{font-size:.8rem;color:var(--muted);font-style:italic;margin-top:.3rem}
.reveal-partner{font-size:.5rem;color:var(--muted);letter-spacing:.1em;margin-top:.5rem}

/* Pending / Recent */
.section-title{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);margin:2rem 0 1rem}
.pending-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.5rem}
.pending-q{font-size:.65rem;color:var(--muted);margin-bottom:.3rem}
.pending-answer{font-family:'Cormorant Garamond',serif;font-size:1rem;color:var(--text);margin-bottom:.4rem}
.pending-who{font-size:.5rem;color:var(--muted);letter-spacing:.08em;margin-bottom:.5rem}
.pending-btns{display:flex;gap:.5rem}
.btn-ok{font-size:.55rem;letter-spacing:.1em;background:transparent;border:1px solid var(--green);color:var(--green);padding:.3rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn-ok:hover{background:var(--green);color:var(--bg)}
.btn-no{font-size:.55rem;letter-spacing:.1em;background:transparent;border:1px solid var(--red);color:var(--red);padding:.3rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn-no:hover{background:var(--red);color:#fff}
.recent-card{background:var(--s);border:1px solid var(--border);padding:.7rem 1rem;margin-bottom:.4rem;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.recent-left{flex:1;min-width:0}
.recent-q{font-size:.55rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recent-a{font-size:.7rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recent-who{font-size:.45rem;color:var(--muted);margin-top:.1rem}
.badge{font-size:.6rem;padding:.15rem .4rem;border:1px solid;flex-shrink:0}
.badge.ok{border-color:var(--green);color:var(--green)}
.badge.no{border-color:var(--red);color:var(--red)}
.badge-count{background:var(--accent);color:var(--bg);font-size:.5rem;padding:.1rem .4rem;font-weight:bold;margin-left:.3rem}

/* Add question */
.add-section{margin-top:2rem;border-top:1px solid var(--border);padding-top:1.5rem}
.add-toggle{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);cursor:pointer;border:1px dashed var(--border);padding:.5rem;text-align:center;width:100%;background:transparent;font-family:'DM Mono',monospace;transition:all .2s}
.add-toggle:hover{border-color:var(--accent);color:var(--accent)}
.add-form{display:none;margin-top:1rem}
.add-form.open{display:block}
.field{margin-bottom:.8rem}
.field label{display:block;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.field input,.field select{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);padding:.5rem .7rem;font-family:'DM Mono',monospace;font-size:.7rem;outline:none;transition:border .2s}
.field input:focus,.field select:focus{border-color:var(--accent)}
.field select option{background:var(--bg);color:var(--text)}
.empty{text-align:center;padding:2rem;font-size:.7rem;color:var(--muted);font-style:italic}
.progress{font-size:.55rem;color:var(--muted);text-align:center;margin-top:.5rem;letter-spacing:.08em}

@media(max-width:600px){
  .topbar{padding:.8rem 1rem}
  .wrap{padding:1.5rem 1rem}
  .mode-cards{grid-template-columns:1fr}
  .q-text{font-size:1.2rem}
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

<?php if ($mode === 'repondre'): ?>
  <!-- ═══ MODE RÉPONDRE ═══ -->
  <div style="text-align:center;margin-bottom:1.5rem">
    <div class="q-mode-label">✍ <?= t('Répondre sur toi','Ответь о себе') ?></div>
    <div class="progress"><?= $myAnsweredCount ?> / <?= $totalQ ?> <?= t('questions répondues','вопросов отвечено') ?></div>
  </div>

  <?php if ($question): ?>
  <div class="q-card" id="qCard">
    <div class="q-cat"><?= $catEmojis[$question['categorie']] ?? '' ?> <?= $catLabels[$question['categorie']] ?? '' ?></div>
    <div class="q-text"><?= h($lang === 'ru' && $question['question_ru'] ? $question['question_ru'] : $question['question_fr']) ?></div>
    <?php if ($lang !== 'ru' && $question['question_ru']): ?>
      <div class="q-text-alt"><?= h($question['question_ru']) ?></div>
    <?php elseif ($lang === 'ru'): ?>
      <div class="q-text-alt"><?= h($question['question_fr']) ?></div>
    <?php endif; ?>
    <input type="text" class="answer-input" id="answerInput" placeholder="<?= t('Ta réponse...','Твой ответ...') ?>" autocomplete="off">
    <div class="q-btns">
      <button class="btn" id="submitBtn" onclick="selfAnswer(<?= $question['id'] ?>)" disabled><?= t('Valider','Подтвердить') ?></button>
      <a class="btn secondary" href="?mode=repondre"><?= t('Passer','Пропустить') ?></a>
    </div>
    <div id="successMsg" style="display:none;color:var(--accent);font-size:.7rem;margin-top:1rem">✓ <?= t('Réponse enregistrée !','Ответ сохранён!') ?></div>
  </div>
  <?php else: ?>
  <div class="empty">🎉 <?= t('Tu as répondu à toutes les questions !','Ты ответил(а) на все вопросы!') ?></div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:1rem">
    <a class="btn secondary" href="<?= BASE_URL ?>/jeux_connaissance.php">&larr; <?= t('Retour','Назад') ?></a>
  </div>

<?php elseif ($mode === 'jouer'): ?>
  <!-- ═══ MODE JOUER ═══ -->
  <div style="text-align:center;margin-bottom:1.5rem">
    <div class="q-mode-label">🎮 <?= t('Deviner les réponses','Угадай ответы') ?></div>
  </div>

  <!-- Score -->
  <div class="score-banner">
    <div>
      <div class="score-num"><?= (int)($scores['correct'] ?? 0) ?></div>
      <div class="score-label"><?= t('Correct','Правильно') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= (int)($scores['total'] ?? 0) ?></div>
      <div class="score-label"><?= t('Total','Всего') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= $scores['total'] ? round(($scores['correct']/$scores['total'])*100) : 0 ?>%</div>
      <div class="score-label"><?= t('Réussite','Успех') ?></div>
    </div>
  </div>

  <?php if ($question): ?>
  <div class="q-card" id="qCard">
    <div class="q-cat"><?= $catEmojis[$question['categorie']] ?? '' ?> <?= $catLabels[$question['categorie']] ?? '' ?></div>
    <div class="q-text"><?= h($lang === 'ru' && $question['question_ru'] ? $question['question_ru'] : $question['question_fr']) ?></div>
    <?php if ($lang !== 'ru' && $question['question_ru']): ?>
      <div class="q-text-alt"><?= h($question['question_ru']) ?></div>
    <?php elseif ($lang === 'ru'): ?>
      <div class="q-text-alt"><?= h($question['question_fr']) ?></div>
    <?php endif; ?>
    <div style="font-size:.55rem;color:var(--muted);margin-bottom:1rem">
      <?= t('Que penses-tu que','Как ты думаешь, что') ?> <strong style="color:var(--accent)"><?= h($question['partner_name']) ?></strong> <?= t('a répondu ?','ответил(а)?') ?>
    </div>
    <input type="text" class="answer-input" id="guessInput" placeholder="<?= t('Ta supposition...','Твоя догадка...') ?>" autocomplete="off">
    <div class="q-btns" id="guessButtons">
      <button class="btn" id="guessBtn" onclick="submitGuess(<?= $question['id'] ?>)" disabled><?= t('Deviner','Угадать') ?></button>
      <a class="btn secondary" href="?mode=jouer"><?= t('Passer','Пропустить') ?></a>
    </div>
    <!-- Reveal -->
    <div class="reveal" id="revealBox">
      <div class="reveal-label">💡 <?= t('La vraie réponse de','Настоящий ответ') ?> <?= h($question['partner_name'] ?? '') ?></div>
      <div class="reveal-answer" id="revealAnswer"></div>
      <div class="reveal-alt" id="revealAlt"></div>
      <div style="margin-top:1rem">
        <a class="btn" href="?mode=jouer"><?= t('Question suivante','Следующий вопрос') ?> →</a>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="empty"><?= $toPlay > 0 ? t('Aucune question disponible pour le moment.','Пока нет доступных вопросов.') : t('Ton/ta partenaire doit d\'abord répondre à des questions !','Твой партнёр должен сначала ответить на вопросы!') ?></div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:1rem">
    <a class="btn secondary" href="<?= BASE_URL ?>/jeux_connaissance.php">&larr; <?= t('Retour','Назад') ?></a>
  </div>

<?php else: ?>
  <!-- ═══ LANDING ═══ -->
  <div style="text-align:center;margin-bottom:2rem">
    <div style="font-size:3rem;margin-bottom:.5rem">💕</div>
    <h1 style="font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:var(--accent);margin-bottom:.4rem"><?= t('Qui me connaît le mieux ?','Кто знает меня лучше?') ?></h1>
    <p style="font-size:.6rem;color:var(--muted);letter-spacing:.06em;line-height:1.8"><?= t('Répondez chacun aux questions, puis devinez les réponses de l\'autre !','Каждый отвечает на вопросы, потом угадывает ответы другого!') ?></p>
  </div>

  <div class="mode-cards">
    <a class="mode-card" href="?mode=repondre">
      <span class="mode-emoji">✍️</span>
      <div class="mode-title"><?= t('Répondre','Ответить') ?></div>
      <div class="mode-desc"><?= t('Réponds aux questions sur toi-même pour que ton/ta partenaire puisse deviner','Ответь на вопросы о себе, чтобы партнёр мог угадать') ?></div>
      <div class="mode-count"><?= $toAnswer ?></div>
      <div class="mode-count-label"><?= t('questions à répondre','вопросов для ответа') ?></div>
    </a>
    <a class="mode-card play" href="?mode=jouer">
      <span class="mode-emoji">🎮</span>
      <div class="mode-title"><?= t('Jouer','Играть') ?></div>
      <div class="mode-desc"><?= t('Devine ce que ton/ta partenaire a répondu — et découvre la vraie réponse !','Угадай, что ответил(а) партнёр — и узнай настоящий ответ!') ?></div>
      <div class="mode-count"><?= $toPlay ?></div>
      <div class="mode-count-label"><?= t('questions à deviner','вопросов для угадывания') ?></div>
    </a>
  </div>

  <!-- Score global -->
  <?php if (($scores['total'] ?? 0) > 0): ?>
  <div class="score-banner">
    <div>
      <div class="score-num"><?= (int)$scores['correct'] ?></div>
      <div class="score-label"><?= t('Correct','Правильно') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= (int)$scores['total'] ?></div>
      <div class="score-label"><?= t('Total','Всего') ?></div>
    </div>
    <div class="score-divider"></div>
    <div>
      <div class="score-num"><?= round(($scores['correct']/$scores['total'])*100) ?>%</div>
      <div class="score-label"><?= t('Réussite','Успех') ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pending validations -->
  <?php if (!empty($pending)): ?>
  <div class="section-title"><?= t('À valider','Проверить') ?> <span class="badge-count"><?= count($pending) ?></span></div>
  <?php foreach ($pending as $p):
    $pqText = ($lang === 'ru' && $p['question_ru']) ? $p['question_ru'] : $p['question_fr'];
    $pLang = $p['reponse_lang'] ?? 'fr';
    $pPrimary = ($lang !== $pLang && $p['reponse_translated']) ? $p['reponse_translated'] : $p['reponse'];
    $pSecondary = ($lang !== $pLang && $p['reponse_translated']) ? $p['reponse'] : ($p['reponse_translated'] ?? '');
  ?>
  <div class="pending-card" id="pending-<?= $p['id'] ?>">
    <div class="pending-q"><?= h($pqText) ?></div>
    <div class="pending-answer">&laquo; <?= h($pPrimary) ?> &raquo;</div>
    <?php if ($pSecondary && $pSecondary !== $pPrimary): ?>
    <div style="font-size:.7rem;color:var(--muted);font-style:italic;margin-bottom:.3rem">&laquo; <?= h($pSecondary) ?> &raquo;</div>
    <?php endif; ?>
    <div class="pending-who"><?= h($p['display_name']) ?> <?= t('pense que...','думает, что...') ?></div>
    <div class="pending-btns">
      <button class="btn-ok" onclick="validate(<?= $p['id'] ?>,1)">✓ <?= t('Correct','Правильно') ?></button>
      <button class="btn-no" onclick="validate(<?= $p['id'] ?>,0)">✗ <?= t('Faux','Неверно') ?></button>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Recent -->
  <?php if (!empty($recent)): ?>
  <div class="section-title"><?= t('Derniers résultats','Последние результаты') ?></div>
  <?php foreach ($recent as $r):
    $rqText = ($lang === 'ru' && $r['question_ru']) ? $r['question_ru'] : $r['question_fr'];
    $rLang = $r['reponse_lang'] ?? 'fr';
    $rText = ($lang !== $rLang && $r['reponse_translated']) ? $r['reponse_translated'] : $r['reponse'];
  ?>
  <div class="recent-card">
    <div class="recent-left">
      <div class="recent-q"><?= h($rqText) ?></div>
      <div class="recent-a">&laquo; <?= h($rText) ?> &raquo;</div>
      <div class="recent-who"><?= h($r['display_name']) ?></div>
    </div>
    <div class="badge <?= $r['is_correct'] ? 'ok' : 'no' ?>"><?= $r['is_correct'] ? '✓' : '✗' ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Add question -->
  <div class="add-section">
    <button class="add-toggle" onclick="document.getElementById('addForm').classList.toggle('open')">+ <?= t('Ajouter une question','Добавить вопрос') ?></button>
    <div class="add-form" id="addForm">
      <div class="field">
        <label><?= t('Question (français)','Вопрос (французский)') ?></label>
        <input type="text" id="newQFr" placeholder="<?= t('Ex: Quel est mon film préféré ?','Напр: Какой мой любимый фильм?') ?>">
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

<?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const LANG = <?= json_encode($lang) ?>;

// ═══ Répondre mode ═══
const answerInput = document.getElementById('answerInput');
const submitBtn = document.getElementById('submitBtn');
if (answerInput && submitBtn) {
    answerInput.addEventListener('input', () => { submitBtn.disabled = answerInput.value.trim().length < 1; });
    answerInput.addEventListener('keydown', e => { if (e.key==='Enter' && !submitBtn.disabled) submitBtn.click(); });
}

function selfAnswer(qid) {
    const v = answerInput.value.trim();
    if (!v) return;
    submitBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'self_answer');
    fd.append('question_id', qid);
    fd.append('reponse', v);
    fetch(BASE + '/jeux_connaissance.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                answerInput.disabled = true;
                document.getElementById('successMsg').style.display = 'block';
                setTimeout(() => { location.href = '?mode=repondre'; }, 1000);
            } else { submitBtn.disabled = false; }
        });
}

// ═══ Jouer mode ═══
const guessInput = document.getElementById('guessInput');
const guessBtn = document.getElementById('guessBtn');
if (guessInput && guessBtn) {
    guessInput.addEventListener('input', () => { guessBtn.disabled = guessInput.value.trim().length < 1; });
    guessInput.addEventListener('keydown', e => { if (e.key==='Enter' && !guessBtn.disabled) guessBtn.click(); });
}

function submitGuess(qid) {
    const v = guessInput.value.trim();
    if (!v) return;
    guessBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'guess');
    fd.append('question_id', qid);
    fd.append('reponse', v);
    fetch(BASE + '/jeux_connaissance.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.real_answer) {
                guessInput.disabled = true;
                document.getElementById('guessButtons').style.display = 'none';
                const box = document.getElementById('revealBox');
                document.getElementById('revealAnswer').textContent = '« ' + data.real_answer + ' »';
                if (data.real_alt) document.getElementById('revealAlt').textContent = '« ' + data.real_alt + ' »';
                box.style.display = 'block';
            } else { guessBtn.disabled = false; }
        });
}

// ═══ Validate ═══
function validate(rid, correct) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'validate');
    fd.append('reponse_id', rid);
    fd.append('correct', correct);
    fetch(BASE + '/jeux_connaissance.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const el = document.getElementById('pending-' + rid);
                el.querySelector('.pending-btns').innerHTML = correct
                    ? '<span style="color:var(--green)">✓ ' + (LANG==='ru'?'Правильно':'Correct') + '</span>'
                    : '<span style="color:var(--red)">✗ ' + (LANG==='ru'?'Неверно':'Faux') + '</span>';
            }
        });
}

// ═══ Add question ═══
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
    fetch(BASE + '/jeux_connaissance.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}
</script>
</body>
</html>
