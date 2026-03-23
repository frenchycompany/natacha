<?php
require_once __DIR__ . '/../config.php';

session_name(ADMIN_SESSION_NAME);
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

// Auth check
if (empty($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
if (time() - ($_SESSION['admin_time'] ?? 0) > ADMIN_SESSION_LIFETIME) { session_destroy(); header('Location: login.php?expired=1'); exit; }
$_SESSION['admin_time'] = time();

// Récupérer les soumissions
$submissions = [];
$detail = null;

try {
    $pdo = getDB();

    // Détail d'une soumission
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $sid = (int)$_GET['id'];
        $s = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
        $s->execute([$sid]);
        $sub = $s->fetch();
        if ($sub) {
            $a = $pdo->prepare("SELECT question_index, answer_index FROM answers WHERE submission_id = ? ORDER BY question_index");
            $a->execute([$sid]);
            $detail = ['sub' => $sub, 'answers' => $a->fetchAll()];
        }
    }

    // Liste
    $submissions = $pdo->query("SELECT * FROM v_submissions LIMIT 100")->fetchAll();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$questions_fr = ["Comment es-tu passée du statut de « simple curiosité » à « vraiment intéressante » ?","Comment tu comptes gérer un homme comme moi ?","Comment tu réagis à mon imprévisibilité ?","Comment tu imagines le moment idéal avec moi ?","Comment tu comptes me conquérir définitivement ?","Comment tu gères le fait que je peux être… séduisant ?","Comment tu décrirais nos moments à deux ?","Comment tu te sens quand on est ensemble ?","Comment tu vis le fait qu'on se rapproche ?","Comment tu vois ma place dans ta vie en ce moment ?","Comment tu définis notre connexion ?","Comment tu te comportes quand tu commences à t'attacher ?","Comment tu sais qu'une personne est devenue importante pour toi ?","Comment tu vis le contrôle dans une relation ?","Comment tu vis les surprises ?","Comment tu réagis quand tu te sens comprise ?","Quel est mon principal défaut ?","Comment tu réagis quand quelqu'un prend beaucoup de place dans ta vie ?","Comment tu te comportes quand tu es vraiment bien avec quelqu'un ?","Comment tu vois la suite de ce qui se passe entre nous ?","Comment tu te comportes quand quelqu'un te manque mais que tu ne veux pas le montrer ?","Comment tu équilibres jeu et sérieux ?","Comment tu évalues ton « niveau de danger » émotionnel ?","Comment tu réagis quand ça devient vraiment sérieux ?","Comment tu évalues ta « candidature » en ce moment ?"];
$options_fr = [["Progressivement","Je ne m'en suis pas rendu compte","Grâce à toi","J'ai toujours été comme ça"],["Avec patience","Avec intelligence","Avec humour","C'est impossible… je m'adapte juste"],["Je tiens bon","Je m'ajuste","J'en rajoute","Ça me plaît"],["Simple et calme","Spontané","Léger et fun","Avec un soupçon de danger 😏"],["En étant moi-même","En jouant un peu","En te laissant faire le premier pas","C'est déjà fait"],["Je résiste","J'observe","Je cède un peu","Il est déjà trop tard pour moi"],["Simples mais importants","Légers et naturels","Intenses mais pas compliqués","Trop prenants"],["Bien","Apaisée","Vivante","Dangereusement bien"],["Je laisse faire","Je prends parfois mes distances","J'en profite simplement","Prudemment… mais ça me plaît"],["Importante","Elle devient importante","Elle se construit","Elle est déjà prise"],["Naturelle","Rare","Évidente","Difficile à expliquer"],["Avec retenue","Avec prudence","Naturellement","Je me rapproche"],["Je pense à elle","Je lui fais de la place","Je m'investis","Je le sens, c'est tout"],["J'aime contrôler","Je lâche prise progressivement","Ça dépend","Je me laisse porter"],["J'adore","Avec modération","Si elles sont bonnes","J'en veux plus"],["Ça m'apaise","Ça m'étonne","Je m'ouvre","Je m'attache"],["Trop… (complète 😏)","Pas assez…","Difficile à cerner","Aucun (suspect)"],["Je régule","Je m'adapte","J'accepte","Qu'il reste"],["Je me détends","Je m'ouvre davantage","Je suis plus tactile","Je deviens un peu dépendante"],["On continue comme ça","On approfondit","On laisse évoluer","On verra"],["Je me retiens","Je compense","Ça se voit quand même","Je passe vite à autre chose"],["Plutôt jeu","Plutôt sérieux","Équilibre","Ça dépend de la personne"],["Faible","Moyen","Élevé","À tes risques et périls 😏"],["Je ralentis","J'observe","J'accepte","Je plonge"],["En phase de test","À confirmer","Prometteuse","Déjà validée 😏"]];
$questions_ru = ["Как тебе удалось перейти из статуса «просто любопытство» в «действительно интересная»?","Как ты собираешься справляться с таким мужчиной, как я?","Как ты реагируешь на мою непредсказуемость?","Как ты представляешь идеальный момент со мной?","Как ты собираешься окончательно меня покорить?","Как ты справляешься с тем, что я… могу быть привлекательным?","Как бы ты описала наши моменты вдвоём?","Как ты себя чувствуешь, когда мы вместе?","Как ты относишься к тому, что мы сближаемся?","Как ты видишь моё место в своей жизни сейчас?","Как ты определяешь нашу связь?","Как ты ведёшь себя, когда начинаешь привязываться?","Как ты понимаешь, что человек стал для тебя важным?","Как ты относишься к контролю в отношениях?","Как ты относишься к сюрпризам?","Как ты реагируешь, когда тебя понимают?","Какой мой главный недостаток?","Как ты реагируешь, когда кто-то занимает много места в твоей жизни?","Как ты ведёшь себя, когда тебе действительно хорошо с кем-то?","Как ты видишь продолжение того, что происходит между нами?","Как ты ведёшь себя, когда кто-то тебе не хватает, но ты не хочешь это показывать?","Как ты балансируешь между игрой и серьёзностью?","Как ты оцениваешь свою эмоциональную «опасность»?","Как ты реагируешь, когда всё становится по-настоящему серьёзным?","Как ты оцениваешь свою «кандидатуру» сейчас?"];
$options_ru = [["Постепенно","Я сама не заметила","Благодаря тебе","Я всегда такой была"],["С терпением","С умом","С юмором","Это невозможно… я просто адаптируюсь"],["Держусь","Подстраиваюсь","Добавляю ещё","Мне это нравится"],["Простой и спокойный","Спонтанный","Лёгкий и весёлый","С лёгкой опасностью 😏"],["Буду собой","Немного поиграю","Дам тебе сделать шаг","Уже получилось"],["Сопротивляюсь","Наблюдаю","Немного сдаюсь","Уже поздно для меня"],["Простые, но важные","Лёгкие и естественные","Интенсивные, но не сложные","Слишком затягивающие"],["Хорошо","Спокойно","Живо","Опасно приятно"],["Пусть всё идёт само","Иногда беру дистанцию","Просто наслаждаюсь","Осторожно… но мне нравится"],["Важное","Становится важным","Пока строится","Уже занято"],["Естественная","Редкая","Очевидная","Сложно объяснить"],["Сдержанно","Осторожно","Естественно","Становлюсь ближе"],["Думаю о нём","Освобождаю для него место","Вкладываюсь","Просто чувствую"],["Люблю контролировать","Постепенно отпускаю","Зависит","Плыву по течению"],["Обожаю","В меру","Если они хорошие","Хочу больше"],["Это успокаивает","Удивляет","Я открываюсь","Я привязываюсь"],["Слишком… (дополни 😏)","Недостаточно…","Сложно понять","Нет (подозрительно)"],["Регулирую","Подстраиваюсь","Принимаю","Пусть будет"],["Расслабленно","Более открыто","Более тактильно","Немного зависима"],["Продолжаем так","Углубляем","Пусть развивается","Посмотрим"],["Сдерживаюсь","Компенсирую","Это всё равно видно","Быстро отпускаю"],["Больше игра","Больше серьёзность","Баланс","Зависит от человека"],["Низкая","Средняя","Высокая","На твой риск 😏"],["Замедляюсь","Наблюдаю","Принимаю","Погружаюсь"],["На этапе теста","Нужно подтвердить","Многообещающая","Уже одобрена 😏"]];
$letters = ['A','B','C','D'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@300;400&family=Cormorant+Garamond:ital,wght@0,300;1,300&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--surface:#1a1714;--border:#2e2a25;--accent:#c9a96e;--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;padding:2rem}
.wrap{max-width:900px;margin:0 auto}
header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:1.5rem;margin-bottom:2rem}
h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent)}
.logout{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.4rem .8rem;transition:all .2s}
.logout:hover{border-color:var(--accent);color:var(--accent)}
.section-title{font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem}
table{width:100%;border-collapse:collapse;margin-bottom:3rem}
th{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-align:left;padding:.6rem .8rem;border-bottom:1px solid var(--border)}
td{font-size:.75rem;padding:.7rem .8rem;border-bottom:1px solid var(--border);vertical-align:middle}
tr:hover td{background:rgba(201,169,110,.04)}
.badge{display:inline-block;font-size:.6rem;padding:.15rem .4rem;letter-spacing:.08em;text-transform:uppercase}
.badge.fr{color:#6eb5c9;border:1px solid rgba(110,181,201,.3);background:rgba(110,181,201,.08)}
.badge.ru{color:#c96e6e;border:1px solid rgba(201,110,110,.3);background:rgba(201,110,110,.08)}
.badge.yes{color:#6ec98a;border:1px solid rgba(110,201,138,.3);background:rgba(110,201,138,.08)}
.badge.no{color:var(--muted);border:1px solid var(--border)}
a.view{color:var(--accent);text-decoration:none;font-size:.65rem;letter-spacing:.1em;border:1px solid rgba(201,169,110,.3);padding:.2rem .5rem;transition:all .2s}
a.view:hover{background:rgba(201,169,110,.1)}
.detail{background:var(--surface);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.detail h2{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.4rem}
.detail .meta{font-size:.65rem;color:var(--muted);margin-bottom:1.5rem;letter-spacing:.08em}
.ans{padding:.9rem 0;border-bottom:1px solid var(--border);display:flex;gap:1rem;align-items:flex-start}
.ans:last-child{border:none}
.ans .num{font-size:.6rem;color:var(--muted);min-width:1.8rem;padding-top:.1rem}
.ans .q{font-size:.75rem;color:var(--muted);margin-bottom:.2rem}
.ans .a{font-size:.85rem;color:var(--text)}
.ans .l{font-size:.6rem;color:var(--accent);background:rgba(201,169,110,.1);border:1px solid rgba(201,169,110,.25);padding:.15rem .4rem;white-space:nowrap;margin-left:auto;flex-shrink:0}
.back{display:inline-block;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.4rem .8rem;margin-bottom:1.5rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.empty{text-align:center;padding:3rem;font-size:.75rem;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>💌 Natacha — Admin</h1>
    <a class="logout" href="login.php?logout=1">Déconnexion</a>
  </header>

  <?php if (isset($db_error)): ?>
    <div style="color:#c96e6e;font-size:.75rem;margin-bottom:1rem">Erreur DB : <?= htmlspecialchars($db_error) ?></div>
  <?php endif; ?>

  <?php if ($detail): ?>
    <!-- Détail d'une soumission -->
    <a class="back" href="index.php">← Retour à la liste</a>
    <div class="detail">
      <h2>Soumission #<?= $detail['sub']['id'] ?></h2>
      <div class="meta">
        <?= $detail['sub']['submitted_at'] ?> &nbsp;·&nbsp;
        <?= strtoupper($detail['sub']['lang']) ?> &nbsp;·&nbsp;
        <?= $detail['sub']['nb_answers'] ?> réponses &nbsp;·&nbsp;
        Mail : <?= $detail['sub']['mail_sent'] ? '✓ envoyé' : '✗ non envoyé' ?>
      </div>
      <?php
        $qs = $detail['sub']['lang'] === 'ru' ? $questions_ru : $questions_fr;
        $os = $detail['sub']['lang'] === 'ru' ? $options_ru   : $options_fr;
        foreach ($detail['answers'] as $row):
            $qi = $row['question_index'];
            $ai = $row['answer_index'];
            $l  = $letters[$ai] ?? '?';
            $q  = $qs[$qi] ?? "Q$qi";
            $a  = $os[$qi][$ai] ?? '—';
      ?>
      <div class="ans">
        <span class="num"><?= str_pad($qi+1,2,'0',STR_PAD_LEFT) ?></span>
        <div style="flex:1">
          <div class="q"><?= htmlspecialchars($q) ?></div>
          <div class="a"><?= htmlspecialchars($a) ?></div>
        </div>
        <span class="l"><?= $l ?></span>
      </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <!-- Liste des soumissions -->
    <div class="section-title">Soumissions reçues (<?= count($submissions) ?>)</div>
    <?php if (empty($submissions)): ?>
      <div class="empty">Aucune soumission pour le moment.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Langue</th>
          <th>Réponses</th>
          <th>Mail</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submissions as $s): ?>
        <tr>
          <td><?= $s['id'] ?></td>
          <td><?= $s['submitted_at'] ?></td>
          <td><span class="badge <?= $s['lang'] ?>"><?= strtoupper($s['lang']) ?></span></td>
          <td><?= $s['nb_answers'] ?> / 25</td>
          <td><span class="badge <?= $s['mail_sent'] ? 'yes' : 'no' ?>"><?= $s['mail_sent'] ? '✓' : '✗' ?></span></td>
          <td><a class="view" href="index.php?id=<?= $s['id'] ?>">Voir</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
