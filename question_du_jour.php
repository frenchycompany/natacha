<?php
/**
 * NATACHA — Question du Jour
 * Une question quotidienne à laquelle les deux partenaires répondent
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Refresh couple_id from DB (session may be stale)
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

// ═══ Auto-create tables ═══
try { db()->query("SELECT 1 FROM daily_questions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS daily_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_fr TEXT NOT NULL,
        question_ru TEXT NOT NULL,
        categorie ENUM('souvenir','futur','fun','profond','romantique') DEFAULT 'fun',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed 30 questions
    db()->exec("INSERT IGNORE INTO daily_questions (question_fr, question_ru, categorie) VALUES
        ('Si on pouvait revivre un moment ensemble, lequel ?', 'Если бы мы могли пережить один момент заново, какой?', 'souvenir'),
        ('Quel est ton souvenir préféré de nous cette semaine ?', 'Какое твоё любимое воспоминание о нас на этой неделе?', 'souvenir'),
        ('Où aimerais-tu qu''on voyage ensemble ?', 'Куда бы ты хотел(а) путешествовать вместе?', 'futur'),
        ('Comment tu m''imagines dans 10 ans ?', 'Каким(ой) ты меня представляешь через 10 лет?', 'futur'),
        ('Quel super-pouvoir tu me donnerais ?', 'Какую суперспособность ты бы мне дал(а)?', 'fun'),
        ('Si on était un duo de film, on serait qui ?', 'Если бы мы были парой из фильма, кто бы мы были?', 'fun'),
        ('Qu''est-ce que tu admires le plus chez moi ?', 'Чем ты больше всего восхищаешься во мне?', 'profond'),
        ('De quoi tu as le plus peur pour nous ?', 'Чего ты больше всего боишься за нас?', 'profond'),
        ('Quel petit geste de ma part te rend le plus heureux/se ?', 'Какой мой маленький жест делает тебя самым(ой) счастливым(ой)?', 'romantique'),
        ('Écris-moi une déclaration en une phrase.', 'Напиши мне признание в одном предложении.', 'romantique'),
        ('Quelle chanson te fait penser à nous ?', 'Какая песня напоминает тебе о нас?', 'souvenir'),
        ('Quel repas on devrait cuisiner ensemble ce week-end ?', 'Какое блюдо нам стоит приготовить вместе в выходные?', 'fun'),
        ('Qu''est-ce qui a changé en toi depuis qu''on est ensemble ?', 'Что изменилось в тебе с тех пор как мы вместе?', 'profond'),
        ('Quel est notre point fort en tant que couple ?', 'Какая наша сильная сторона как пары?', 'profond'),
        ('Si on gagnait au loto demain, on ferait quoi en premier ?', 'Если бы мы завтра выиграли в лотерею, что бы мы сделали первым делом?', 'fun'),
        ('Quel moment de la journée tu préfères avec moi ?', 'Какое время дня тебе нравится проводить со мной больше всего?', 'romantique'),
        ('Qu''est-ce que tu voudrais qu''on fasse plus souvent ?', 'Что бы ты хотел(а), чтобы мы делали чаще?', 'romantique'),
        ('Raconte un rêve que tu as fait récemment.', 'Расскажи сон, который тебе недавно снился.', 'fun'),
        ('Quel est le truc le plus drôle qu''on a vécu ensemble ?', 'Что самое смешное мы пережили вместе?', 'souvenir'),
        ('Si tu pouvais changer une chose dans notre quotidien ?', 'Если бы ты мог(ла) изменить одну вещь в нашей повседневности?', 'profond'),
        ('Quel compliment tu ne me fais pas assez ?', 'Какой комплимент ты мне делаешь недостаточно?', 'romantique'),
        ('Qu''est-ce qui te manquerait le plus si j''étais absent(e) une semaine ?', 'Чего тебе больше всего не хватало бы, если бы меня не было неделю?', 'romantique'),
        ('Quel est notre meilleur fou rire ?', 'Какой наш лучший приступ смеха?', 'souvenir'),
        ('Si on ouvrait un business ensemble, ce serait quoi ?', 'Если бы мы открыли бизнес вместе, что бы это было?', 'futur'),
        ('Quel est ton endroit préféré pour être avec moi ?', 'Какое твоё любимое место, чтобы быть со мной?', 'romantique'),
        ('Qu''est-ce que tu as appris de moi ?', 'Чему ты научился(ась) у меня?', 'profond'),
        ('Si notre couple était un animal, ce serait lequel ?', 'Если бы наша пара была животным, каким?', 'fun'),
        ('Quel projet tu aimerais qu''on réalise cette année ?', 'Какой проект ты хотел(а) бы реализовать в этом году?', 'futur'),
        ('Quel est ton moment préféré de notre première rencontre ?', 'Какой твой любимый момент нашей первой встречи?', 'souvenir'),
        ('Dis-moi quelque chose que tu ne m''as jamais dit.', 'Скажи мне что-то, чего ты мне никогда не говорил(а).', 'profond')
    ");
}

try { db()->query("SELECT 1 FROM daily_question_answers LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS daily_question_answers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        answer TEXT NOT NULL,
        answer_translated TEXT DEFAULT NULL,
        answer_lang CHAR(2) DEFAULT 'fr',
        answer_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_question_user_date (question_id, user_id, answer_date),
        INDEX idx_date (answer_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try { db()->query("SELECT 1 FROM daily_question_log LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS daily_question_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id INT UNSIGNED NOT NULL,
        question_date DATE NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ═══ Daily question logic ═══
$today = date('Y-m-d');

// Check if today has a question assigned
$todayLog = db()->prepare("SELECT ql.*, q.question_fr, q.question_ru, q.categorie FROM daily_question_log ql JOIN daily_questions q ON q.id=ql.question_id WHERE ql.question_date=?");
$todayLog->execute([$today]);
$todayQuestion = $todayLog->fetch();

if (!$todayQuestion) {
    // Pick a random question not used in the last 30 days
    $recentIds = db()->prepare("SELECT question_id FROM daily_question_log WHERE question_date > DATE_SUB(?, INTERVAL 30 DAY)");
    $recentIds->execute([$today]);
    $recentIds = $recentIds->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($recentIds)) {
        $ph = implode(',', array_fill(0, count($recentIds), '?'));
        $pick = db()->prepare("SELECT id FROM daily_questions WHERE id NOT IN ($ph) ORDER BY RAND() LIMIT 1");
        $pick->execute($recentIds);
    } else {
        $pick = db()->query("SELECT id FROM daily_questions ORDER BY RAND() LIMIT 1");
    }
    $pickedId = $pick->fetchColumn();

    // Fallback: if all questions used in last 30 days, pick any random
    if (!$pickedId) {
        $pickedId = db()->query("SELECT id FROM daily_questions ORDER BY RAND() LIMIT 1")->fetchColumn();
    }

    if ($pickedId) {
        db()->prepare("INSERT IGNORE INTO daily_question_log (question_id, question_date) VALUES (?,?)")->execute([$pickedId, $today]);
        $todayLog = db()->prepare("SELECT ql.*, q.question_fr, q.question_ru, q.categorie FROM daily_question_log ql JOIN daily_questions q ON q.id=ql.question_id WHERE ql.question_date=?");
        $todayLog->execute([$today]);
        $todayQuestion = $todayLog->fetch();
    }
}

// ═══ POST: Submit answer ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $answer = trim($_POST['answer'] ?? '');
    $qId = (int)($_POST['question_id'] ?? 0);

    if (!$answer || mb_strlen($answer) > 1000 || !$qId) {
        echo json_encode(['ok' => false, 'error' => t('Réponse invalide','Недействительный ответ')]);
        exit;
    }

    // Check if already answered
    $existing = db()->prepare("SELECT id FROM daily_question_answers WHERE question_id=? AND user_id=? AND answer_date=?");
    $existing->execute([$qId, $user['id'], $today]);
    if ($existing->fetch()) {
        echo json_encode(['ok' => false, 'error' => t('Tu as déjà répondu aujourd\'hui','Ты уже ответил(а) сегодня')]);
        exit;
    }

    $fromLang = $lang === 'ru' ? 'ru' : 'fr';
    $toLang = $fromLang === 'fr' ? 'ru' : 'fr';

    $stmt = db()->prepare("INSERT INTO daily_question_answers (question_id, user_id, answer, answer_lang, answer_date) VALUES (?,?,?,?,?)");
    $stmt->execute([$qId, $user['id'], $answer, $fromLang, $today]);
    $newId = db()->lastInsertId();

    // Auto-translate
    $translated = translateText($answer, $fromLang, $toLang);
    if ($translated) {
        db()->prepare("UPDATE daily_question_answers SET answer_translated=? WHERE id=?")->execute([$translated, $newId]);
    }

    // Record couple activity
    try {
        require_once __DIR__.'/includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $already = db()->prepare("SELECT 1 FROM couple_activities WHERE couple_id=? AND user_id=? AND activity_type='questionnaire' AND DATE(created_at)=?");
        $already->execute([$coupleId, $user['id'], $today]);
        if (!$already->fetch()) {
            $ce->recordActivity($coupleId, $user['id'], 'questionnaire',
                $user['display_name'].' a répondu à la question du jour',
                $user['display_name'].' ответил(а) на вопрос дня');
        }
    } catch (Exception $e) {}

    // Notify partner
    try {
        require_once __DIR__.'/includes/notifications.php';
        notifyOtherUser($user['id'], 'question',
            $user['display_name'].' a répondu à la question du jour 💭',
            $user['display_name'].' ответил(а) на вопрос дня 💭',
            BASE_URL.'/question_du_jour.php');
    } catch (Exception $e) {}

    // Check badges
    try {
        require_once __DIR__.'/includes/badge_checker.php';
        checkAndAwardBadges($user['id'], $coupleId);
    } catch (Exception $e) {}

    echo json_encode(['ok' => true]);
    exit;
}

// ═══ Load today's answers ═══
$myAnswer = null;
$partnerAnswer = null;
$bothAnswered = false;

if ($todayQuestion) {
    $qId = $todayQuestion['question_id'];

    // My answer
    $stmt = db()->prepare("SELECT * FROM daily_question_answers WHERE question_id=? AND user_id=? AND answer_date=?");
    $stmt->execute([$qId, $user['id'], $today]);
    $myAnswer = $stmt->fetch();

    // Partner's answer
    $stmt = db()->prepare("SELECT a.*, u.display_name, u.avatar FROM daily_question_answers a JOIN users u ON u.id=a.user_id WHERE a.question_id=? AND a.user_id!=? AND a.answer_date=?");
    $stmt->execute([$qId, $user['id'], $today]);
    $partnerAnswer = $stmt->fetch();

    $bothAnswered = ($myAnswer && $partnerAnswer);
}

// Load reactions for answers
$reactionCounts = [];
$myReactions = [];
$answerIds = [];
if ($myAnswer) $answerIds[] = $myAnswer['id'];
if ($partnerAnswer) $answerIds[] = $partnerAnswer['id'];

if (!empty($answerIds)) {
    $ph = implode(',', array_fill(0, count($answerIds), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='question_answer' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($answerIds);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];
        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='question_answer' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $answerIds));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

// ═══ Past questions (last 14 days) ═══
$pastQuestions = db()->prepare("SELECT ql.question_date, ql.question_id, q.question_fr, q.question_ru, q.categorie
    FROM daily_question_log ql
    JOIN daily_questions q ON q.id=ql.question_id
    WHERE ql.question_date < ?
    ORDER BY ql.question_date DESC
    LIMIT 14");
$pastQuestions->execute([$today]);
$pastQuestions = $pastQuestions->fetchAll();

// Load answers for past questions
$pastAnswers = [];
if (!empty($pastQuestions)) {
    $pastQIds = array_column($pastQuestions, 'question_id');
    $pastDates = array_column($pastQuestions, 'question_date');

    // Build conditions for each question+date pair
    $conditions = [];
    $params = [];
    foreach ($pastQuestions as $pq) {
        $conditions[] = "(a.question_id=? AND a.answer_date=?)";
        $params[] = $pq['question_id'];
        $params[] = $pq['question_date'];
    }
    if (!empty($conditions)) {
        $where = implode(' OR ', $conditions);
        $stmt = db()->prepare("SELECT a.*, u.display_name, u.avatar FROM daily_question_answers a JOIN users u ON u.id=a.user_id WHERE $where ORDER BY a.user_id");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $a) {
            $pastAnswers[$a['question_id'].'_'.$a['answer_date']][] = $a;
        }
    }
}

// Load reactions for past answers
$pastReactionCounts = [];
$pastMyReactions = [];
$allPastAnswerIds = [];
foreach ($pastAnswers as $answers) {
    foreach ($answers as $a) $allPastAnswerIds[] = $a['id'];
}
if (!empty($allPastAnswerIds)) {
    $ph = implode(',', array_fill(0, count($allPastAnswerIds), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='question_answer' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($allPastAnswerIds);
        foreach ($rc->fetchAll() as $r) $pastReactionCounts[$r['item_id']] = $r['cnt'];
        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='question_answer' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $allPastAnswerIds));
        foreach ($mr->fetchAll() as $r) $pastMyReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

// Category config
$catColors = [
    'souvenir' => '#6e9dc9',
    'futur' => '#6ec98a',
    'fun' => '#c9a96e',
    'profond' => '#8b6ec9',
    'romantique' => '#c96e9d',
];
$catLabels = [
    'souvenir' => t('Souvenir','Воспоминание'),
    'futur' => t('Futur','Будущее'),
    'fun' => t('Fun','Веселье'),
    'profond' => t('Profond','Глубокий'),
    'romantique' => t('Romantique','Романтика'),
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Question du Jour','Вопрос Дня') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
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
.wrap{max-width:650px;margin:0 auto;padding:2rem 1.5rem}

/* Header */
.page-header{text-align:center;margin-bottom:2.5rem}
.page-emoji{font-size:2.5rem;margin-bottom:.5rem}
.page-title{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.3rem}
.page-sub{font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}

/* Category badge */
.cat-badge{display:inline-block;font-size:.45rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .6rem;border:1px solid;border-radius:2px;font-family:'DM Mono',monospace}

/* Today's question card */
.question-card{background:var(--s);border:1px solid var(--border);padding:2rem;margin-bottom:2rem;text-align:center;position:relative}
.question-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;opacity:.6}
.question-label{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem}
.question-text{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;font-style:italic;color:var(--text);line-height:1.6;margin-bottom:1rem}
.question-cat{margin-bottom:0}

/* Answer form */
.answer-section{margin-bottom:2.5rem}
.answer-area{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1rem;padding:1.2rem;min-height:100px;resize:vertical;outline:none;transition:border .3s;line-height:1.7}
.answer-area:focus{border-color:var(--accent)}
.answer-area::placeholder{color:var(--muted);font-style:italic}
.answer-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.6rem}
.char-count{font-size:.5rem;color:var(--muted)}
.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn.filled{background:var(--accent);color:var(--bg)}
.btn.filled:hover{opacity:.85}
.success-msg{text-align:center;color:var(--accent);font-size:.7rem;margin-top:.8rem;display:none}

/* Already answered */
.my-answer-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:.8rem;position:relative}
.my-answer-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.4}
.answer-label{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.5rem}
.answer-text{font-family:'Cormorant Garamond',serif;font-size:.95rem;color:var(--text);line-height:1.7;font-style:italic}
.answer-trad{font-family:'Cormorant Garamond',serif;font-size:.8rem;color:var(--muted);line-height:1.6;font-style:italic;margin-top:.3rem;opacity:.7}

/* Waiting state */
.waiting-card{background:var(--s);border:1px dashed var(--border);padding:1.5rem;text-align:center;margin-bottom:1.5rem}
.waiting-text{font-size:.65rem;color:var(--muted);font-style:italic}
.waiting-dots{font-size:1.2rem;color:var(--accent);animation:pulse 1.5s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:.3}50%{opacity:1}}

/* Reveal: side by side */
.reveal-section{margin-bottom:2.5rem}
.reveal-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);text-align:center;margin-bottom:1rem}
.reveal-grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.reveal-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;position:relative;transition:border-color .3s}
.reveal-card:hover{border-color:var(--accent)}
.reveal-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;opacity:.4}
.reveal-author{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem}
.reveal-avatar{width:20px;height:20px;border-radius:50%;object-fit:cover;border:1px solid var(--border)}
.answer-footer-row{display:flex;justify-content:space-between;align-items:center;margin-top:.5rem}
.answer-time{font-size:.45rem;color:var(--muted)}
.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}

/* Past questions */
.past-section{margin-top:3rem;padding-top:2rem;border-top:1px solid var(--border)}
.past-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);text-align:center;margin-bottom:1.5rem}
.past-item{margin-bottom:2rem}
.past-date{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem;display:flex;align-items:center;gap:.6rem}
.past-question{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:300;font-style:italic;color:var(--text);margin-bottom:.8rem;padding:.8rem;background:var(--s);border-left:3px solid var(--border);line-height:1.6}
.past-answers{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.past-answer-card{background:var(--s);border:1px solid var(--border);padding:1rem;position:relative}
.past-answer-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.3}
.past-no-answer{font-size:.6rem;color:var(--muted);font-style:italic;text-align:center;padding:.8rem}

.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .reveal-grid,.past-answers{grid-template-columns:1fr}
    .question-text{font-size:1.15rem}
    .page-title{font-size:1.4rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Question du Jour','Вопрос Дня') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <!-- Header -->
    <div class="page-header">
        <div class="page-emoji">💭</div>
        <div class="page-title"><?= t('Question du Jour','Вопрос Дня') ?></div>
        <div class="page-sub"><?= t('Répondez chacun, puis découvrez vos réponses','Ответьте каждый, а потом откройте ответы') ?></div>
    </div>

    <?php if ($todayQuestion): ?>
    <?php
        $cat = $todayQuestion['categorie'];
        $catColor = $catColors[$cat] ?? '#c9a96e';
        $catLabel = $catLabels[$cat] ?? $cat;
    ?>

    <!-- Today's Question -->
    <div class="question-card" style="border-top:3px solid <?= $catColor ?>">
        <div class="question-label"><?= t('Question du jour','Вопрос дня') ?></div>
        <div class="question-text"><?= h($lang === 'ru' ? $todayQuestion['question_ru'] : $todayQuestion['question_fr']) ?></div>
        <div class="question-cat">
            <span class="cat-badge" style="color:<?= $catColor ?>;border-color:<?= $catColor ?>"><?= h($catLabel) ?></span>
        </div>
    </div>

    <?php if ($bothAnswered): ?>
    <!-- Both answered: Reveal -->
    <div class="reveal-section">
        <div class="reveal-title"><?= t('Vos réponses','Ваши ответы') ?> ✨</div>
        <div class="reveal-grid">
            <?php
            // Show my answer and partner's answer side by side
            $answers = [$myAnswer, $partnerAnswer];
            foreach ($answers as $a):
                $isMe = ($a['user_id'] == $user['id']);
                $authorName = $isMe ? $user['display_name'] : ($partnerAnswer['display_name'] ?? '');
                $authorAvatar = $isMe ? ($user['avatar'] ?? '') : ($partnerAnswer['avatar'] ?? '');
                $aLang = $a['answer_lang'] ?? 'fr';
                $aTrad = $a['answer_translated'] ?? '';
                if ($lang !== $aLang && $aTrad) {
                    $primaryA = $aTrad;
                    $secondaryA = $a['answer'];
                } else {
                    $primaryA = $a['answer'];
                    $secondaryA = $aTrad;
                }
            ?>
            <div class="reveal-card" style="border-top:2px solid <?= $catColor ?>">
                <div class="reveal-author">
                    <?php if ($authorAvatar): ?>
                    <img class="reveal-avatar" src="<?= BASE_URL ?>/uploads/<?= h($authorAvatar) ?>" alt="">
                    <?php endif; ?>
                    <?= h($authorName) ?>
                </div>
                <div class="answer-text">&laquo; <?= h($primaryA) ?> &raquo;</div>
                <?php if ($secondaryA && $secondaryA !== $primaryA): ?>
                <div class="answer-trad">&laquo; <?= h($secondaryA) ?> &raquo;</div>
                <?php endif; ?>
                <div class="answer-footer-row">
                    <div class="answer-time"><?= date('H:i', strtotime($a['created_at'])) ?></div>
                    <button class="heart-btn <?= isset($reactionCounts[$a['id']]) || isset($myReactions[$a['id']]) ? '' : '' ?><?= isset($myReactions[$a['id']]) ? 'liked' : '' ?>"
                        onclick="toggleHeart(this,'question_answer',<?= $a['id'] ?>)">
                        <?= isset($myReactions[$a['id']]) ? '❤️' : '🤍' ?>
                        <span class="heart-count"><?= $reactionCounts[$a['id']] ?? '' ?></span>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php elseif ($myAnswer): ?>
    <!-- I answered, waiting for partner -->
    <div class="my-answer-card">
        <div class="answer-label"><?= t('Ta réponse','Твой ответ') ?></div>
        <div class="answer-text">&laquo; <?= h($myAnswer['answer']) ?> &raquo;</div>
        <?php if ($myAnswer['answer_translated']): ?>
        <div class="answer-trad">&laquo; <?= h($myAnswer['answer_translated']) ?> &raquo;</div>
        <?php endif; ?>
    </div>
    <div class="waiting-card">
        <div class="waiting-dots">...</div>
        <div class="waiting-text"><?= t('En attente de la réponse de ton/ta partenaire...','Ожидаем ответа партнёра...') ?></div>
    </div>

    <?php else: ?>
    <!-- Answer form -->
    <div class="answer-section">
        <?php if ($partnerAnswer): ?>
        <div style="text-align:center;margin-bottom:1rem">
            <span class="cat-badge" style="color:var(--accent);border-color:var(--accent)">
                <?= t('Ton/ta partenaire a déjà répondu !','Твой партнёр уже ответил(а)!') ?>
            </span>
        </div>
        <?php endif; ?>

        <textarea class="answer-area" id="answerText" maxlength="1000"
            placeholder="<?= t('Écris ta réponse ici...','Напиши свой ответ здесь...') ?>"></textarea>
        <div class="answer-footer">
            <span class="char-count"><span id="charCount">0</span>/1000</span>
            <button class="btn filled" id="saveBtn" onclick="saveAnswer()" disabled><?= t('Répondre','Ответить') ?></button>
        </div>
        <div class="success-msg" id="successMsg"><?= t('Réponse envoyée !','Ответ отправлен!') ?></div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty"><?= t('Aucune question disponible...','Нет доступных вопросов...') ?></div>
    <?php endif; ?>

    <!-- Past Questions -->
    <?php if (!empty($pastQuestions)): ?>
    <div class="past-section">
        <div class="past-title"><?= t('Questions précédentes','Предыдущие вопросы') ?></div>

        <?php
        $todayDt = new DateTime($today);
        $yesterdayStr = (clone $todayDt)->modify('-1 day')->format('Y-m-d');
        foreach ($pastQuestions as $pq):
            $pDate = $pq['question_date'];
            $pCat = $pq['categorie'];
            $pCatColor = $catColors[$pCat] ?? '#c9a96e';
            $pCatLabel = $catLabels[$pCat] ?? $pCat;
            $key = $pq['question_id'].'_'.$pDate;
            $pAnswers = $pastAnswers[$key] ?? [];
        ?>
        <div class="past-item">
            <div class="past-date">
                <?php
                if ($pDate === $yesterdayStr) echo t('Hier','Вчера');
                else echo (new DateTime($pDate))->format('d/m/Y');
                ?>
                <span class="cat-badge" style="color:<?= $pCatColor ?>;border-color:<?= $pCatColor ?>"><?= h($pCatLabel) ?></span>
            </div>
            <div class="past-question" style="border-left-color:<?= $pCatColor ?>">
                <?= h($lang === 'ru' ? $pq['question_ru'] : $pq['question_fr']) ?>
            </div>
            <?php if (!empty($pAnswers)): ?>
            <div class="past-answers">
                <?php foreach ($pAnswers as $pa):
                    $paLang = $pa['answer_lang'] ?? 'fr';
                    $paTrad = $pa['answer_translated'] ?? '';
                    if ($lang !== $paLang && $paTrad) {
                        $paPrimary = $paTrad;
                        $paSecondary = $pa['answer'];
                    } else {
                        $paPrimary = $pa['answer'];
                        $paSecondary = $paTrad;
                    }
                ?>
                <div class="past-answer-card">
                    <div class="reveal-author">
                        <?php if ($pa['avatar']): ?>
                        <img class="reveal-avatar" src="<?= BASE_URL ?>/uploads/<?= h($pa['avatar']) ?>" alt="">
                        <?php endif; ?>
                        <?= h($pa['display_name']) ?>
                    </div>
                    <div class="answer-text">&laquo; <?= h($paPrimary) ?> &raquo;</div>
                    <?php if ($paSecondary && $paSecondary !== $paPrimary): ?>
                    <div class="answer-trad">&laquo; <?= h($paSecondary) ?> &raquo;</div>
                    <?php endif; ?>
                    <div class="answer-footer-row">
                        <div class="answer-time"><?= date('H:i', strtotime($pa['created_at'])) ?></div>
                        <button class="heart-btn <?= isset($pastMyReactions[$pa['id']]) ? 'liked' : '' ?>"
                            onclick="toggleHeart(this,'question_answer',<?= $pa['id'] ?>)">
                            <?= isset($pastMyReactions[$pa['id']]) ? '❤️' : '🤍' ?>
                            <span class="heart-count"><?= $pastReactionCounts[$pa['id']] ?? '' ?></span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="past-no-answer"><?= t('Aucune réponse ce jour-là','Нет ответов в этот день') ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;

<?php if ($todayQuestion && !$myAnswer): ?>
const textarea = document.getElementById('answerText');
const charCount = document.getElementById('charCount');
const saveBtn = document.getElementById('saveBtn');

textarea.addEventListener('input', () => {
    charCount.textContent = textarea.value.length;
    saveBtn.disabled = textarea.value.trim().length < 2;
});

function saveAnswer() {
    const answer = textarea.value.trim();
    if (!answer) return;
    saveBtn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('answer', answer);
    fd.append('question_id', <?= json_encode($todayQuestion['question_id']) ?>);
    fetch(BASE + '/question_du_jour.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('successMsg').style.display = 'block';
                setTimeout(() => location.reload(), 1200);
            } else {
                alert(data.error || 'Erreur');
                saveBtn.disabled = false;
            }
        })
        .catch(() => { saveBtn.disabled = false; });
}

textarea.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !saveBtn.disabled) saveAnswer();
});
<?php endif; ?>

function toggleHeart(btn, type, id) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('item_type', type);
    fd.append('item_id', id);
    fetch(BASE + '/api/react.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                btn.querySelector('.heart-count').textContent = data.count || '';
                btn.childNodes[0].textContent = data.liked ? '❤️' : '🤍';
            }
        });
}
</script>
</body>
</html>
