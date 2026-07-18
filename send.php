<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée / Метод не разрешён']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['answers']) || !is_array($data['answers'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données invalides / Недействительные данные']);
    exit;
}

$lang    = in_array($data['lang'] ?? 'fr', ['fr', 'ru']) ? $data['lang'] : 'fr';
$answers = array_map('intval', $data['answers']);
$ip      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$letters = ['A', 'B', 'C', 'D'];

$questions_fr = [
    "Comment es-tu passée du statut de « simple curiosité » à « vraiment intéressante » ?",
    "Comment tu comptes gérer un homme comme moi ?",
    "Comment tu réagis à mon imprévisibilité ?",
    "Comment tu imagines le moment idéal avec moi ?",
    "Comment tu comptes me conquérir définitivement ?",
    "Comment tu gères le fait que je peux être… séduisant ?",
    "Comment tu décrirais nos moments à deux ?",
    "Comment tu te sens quand on est ensemble ?",
    "Comment tu vis le fait qu'on se rapproche ?",
    "Comment tu vois ma place dans ta vie en ce moment ?",
    "Comment tu définis notre connexion ?",
    "Comment tu te comportes quand tu commences à t'attacher ?",
    "Comment tu sais qu'une personne est devenue importante pour toi ?",
    "Comment tu vis le contrôle dans une relation ?",
    "Comment tu vis les surprises ?",
    "Comment tu réagis quand tu te sens comprise ?",
    "Quel est mon principal défaut ?",
    "Comment tu réagis quand quelqu'un prend beaucoup de place dans ta vie ?",
    "Comment tu te comportes quand tu es vraiment bien avec quelqu'un ?",
    "Comment tu vois la suite de ce qui se passe entre nous ?",
    "Comment tu te comportes quand quelqu'un te manque mais que tu ne veux pas le montrer ?",
    "Comment tu équilibres jeu et sérieux ?",
    "Comment tu évalues ton « niveau de danger » émotionnel ?",
    "Comment tu réagis quand ça devient vraiment sérieux ?",
    "Comment tu évalues ta « candidature » en ce moment ?"
];
$options_fr = [
    ["Progressivement","Je ne m'en suis pas rendu compte","Grâce à toi","J'ai toujours été comme ça"],
    ["Avec patience","Avec intelligence","Avec humour","C'est impossible… je m'adapte juste"],
    ["Je tiens bon","Je m'ajuste","J'en rajoute","Ça me plaît"],
    ["Simple et calme","Spontané","Léger et fun","Avec un soupçon de danger 😏"],
    ["En étant moi-même","En jouant un peu","En te laissant faire le premier pas","C'est déjà fait"],
    ["Je résiste","J'observe","Je cède un peu","Il est déjà trop tard pour moi"],
    ["Simples mais importants","Légers et naturels","Intenses mais pas compliqués","Trop prenants"],
    ["Bien","Apaisée","Vivante","Dangereusement bien"],
    ["Je laisse faire","Je prends parfois mes distances","J'en profite simplement","Prudemment… mais ça me plaît"],
    ["Importante","Elle devient importante","Elle se construit","Elle est déjà prise"],
    ["Naturelle","Rare","Évidente","Difficile à expliquer"],
    ["Avec retenue","Avec prudence","Naturellement","Je me rapproche"],
    ["Je pense à elle","Je lui fais de la place","Je m'investis","Je le sens, c'est tout"],
    ["J'aime contrôler","Je lâche prise progressivement","Ça dépend","Je me laisse porter"],
    ["J'adore","Avec modération","Si elles sont bonnes","J'en veux plus"],
    ["Ça m'apaise","Ça m'étonne","Je m'ouvre","Je m'attache"],
    ["Trop… (complète 😏)","Pas assez…","Difficile à cerner","Aucun (suspect)"],
    ["Je régule","Je m'adapte","J'accepte","Qu'il reste"],
    ["Je me détends","Je m'ouvre davantage","Je suis plus tactile","Je deviens un peu dépendante"],
    ["On continue comme ça","On approfondit","On laisse évoluer","On verra"],
    ["Je me retiens","Je compense","Ça se voit quand même","Je passe vite à autre chose"],
    ["Plutôt jeu","Plutôt sérieux","Équilibre","Ça dépend de la personne"],
    ["Faible","Moyen","Élevé","À tes risques et périls 😏"],
    ["Je ralentis","J'observe","J'accepte","Je plonge"],
    ["En phase de test","À confirmer","Prometteuse","Déjà validée 😏"]
];

$questions_ru = [
    "Как тебе удалось перейти из статуса «просто любопытство» в «действительно интересная»?",
    "Как ты собираешься справляться с таким мужчиной, как я?",
    "Как ты реагируешь на мою непредсказуемость?",
    "Как ты представляешь идеальный момент со мной?",
    "Как ты собираешься окончательно меня покорить?",
    "Как ты справляешься с тем, что я… могу быть привлекательным?",
    "Как бы ты описала наши моменты вдвоём?",
    "Как ты себя чувствуешь, когда мы вместе?",
    "Как ты относишься к тому, что мы сближаемся?",
    "Как ты видишь моё место в своей жизни сейчас?",
    "Как ты определяешь нашу связь?",
    "Как ты ведёшь себя, когда начинаешь привязываться?",
    "Как ты понимаешь, что человек стал для тебя важным?",
    "Как ты относишься к контролю в отношениях?",
    "Как ты относишься к сюрпризам?",
    "Как ты реагируешь, когда тебя понимают?",
    "Какой мой главный недостаток?",
    "Как ты реагируешь, когда кто-то занимает много места в твоей жизни?",
    "Как ты ведёшь себя, когда тебе действительно хорошо с кем-то?",
    "Как ты видишь продолжение того, что происходит между нами?",
    "Как ты ведёшь себя, когда кто-то тебе не хватает, но ты не хочешь это показывать?",
    "Как ты балансируешь между игрой и серьёзностью?",
    "Как ты оцениваешь свою эмоциональную «опасность»?",
    "Как ты реагируешь, когда всё становится по-настоящему серьёзным?",
    "Как ты оцениваешь свою «кандидатуру» сейчас?"
];
$options_ru = [
    ["Постепенно","Я сама не заметила","Благодаря тебе","Я всегда такой была"],
    ["С терпением","С умом","С юмором","Это невозможно… я просто адаптируюсь"],
    ["Держусь","Подстраиваюсь","Добавляю ещё","Мне это нравится"],
    ["Простой и спокойный","Спонтанный","Лёгкий и весёлый","С лёгкой опасностью 😏"],
    ["Буду собой","Немного поиграю","Дам тебе сделать шаг","Уже получилось"],
    ["Сопротивляюсь","Наблюдаю","Немного сдаюсь","Уже поздно для меня"],
    ["Простые, но важные","Лёгкие и естественные","Интенсивные, но не сложные","Слишком затягивающие"],
    ["Хорошо","Спокойно","Живо","Опасно приятно"],
    ["Пусть всё идёт само","Иногда беру дистанцию","Просто наслаждаюсь","Осторожно… но мне нравится"],
    ["Важное","Становится важным","Пока строится","Уже занято"],
    ["Естественная","Редкая","Очевидная","Сложно объяснить"],
    ["Сдержанно","Осторожно","Естественно","Становлюсь ближе"],
    ["Думаю о нём","Освобождаю для него место","Вкладываюсь","Просто чувствую"],
    ["Люблю контролировать","Постепенно отпускаю","Зависит","Плыву по течению"],
    ["Обожаю","В меру","Если они хорошие","Хочу больше"],
    ["Это успокаивает","Удивляет","Я открываюсь","Я привязываюсь"],
    ["Слишком… (дополни 😏)","Недостаточно…","Сложно понять","Нет (подозрительно)"],
    ["Регулирую","Подстраиваюсь","Принимаю","Пусть будет"],
    ["Расслабленно","Более открыто","Более тактильно","Немного зависима"],
    ["Продолжаем так","Углубляем","Пусть развивается","Посмотрим"],
    ["Сдерживаюсь","Компенсирую","Это всё равно видно","Быстро отпускаю"],
    ["Больше игра","Больше серьёзность","Баланс","Зависит от человека"],
    ["Низкая","Средняя","Высокая","На твой риск 😏"],
    ["Замедляюсь","Наблюдаю","Принимаю","Погружаюсь"],
    ["На этапе теста","Нужно подтвердить","Многообещающая","Уже одобрена 😏"]
];

$questions = $lang === 'ru' ? $questions_ru : $questions_fr;
$options   = $lang === 'ru' ? $options_ru   : $options_fr;

// ── MySQL ──────────────────────────────────────────────────────────────────────
$submission_id = null;
try {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO submissions (lang, ip, user_agent) VALUES (?,?,?)")->execute([$lang, $ip, $ua]);
    $submission_id = $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO answers (submission_id, question_index, answer_index) VALUES (?,?,?)");
    foreach ($answers as $qi => $ai) {
        if ($qi >= 0 && $qi < 25 && $ai >= 0 && $ai <= 3) $stmt->execute([$submission_id, $qi, $ai]);
    }
    $pdo->commit();
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

// ── Mail ───────────────────────────────────────────────────────────────────────
$date    = date('d/m/Y à H:i');
$subject = '💌 Natacha — Candidature #' . $submission_id . ' (' . strtoupper($lang) . ')';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Georgia,serif;background:#0f0d0b;color:#e8e0d5;margin:0;padding:0}
.w{max-width:620px;margin:0 auto;padding:2rem}
h1{font-size:1.5rem;font-weight:300;font-style:italic;color:#c9a96e;border-bottom:1px solid #2e2a25;padding-bottom:.8rem;margin-bottom:.4rem}
.m{font-family:monospace;font-size:.68rem;color:#7a7268;margin-bottom:1.8rem;letter-spacing:.08em}
.i{padding:.9rem 0;border-bottom:1px solid #2e2a25;display:flex;gap:.8rem}
.n{font-family:monospace;font-size:.6rem;color:#7a7268;min-width:1.8rem;padding-top:.15rem}
.c{flex:1}.q{font-size:.85rem;color:#7a7268;margin-bottom:.25rem}
.a{font-size:.98rem;color:#e8e0d5}
.b{font-family:monospace;font-size:.62rem;color:#c9a96e;background:rgba(201,169,110,.1);border:1px solid rgba(201,169,110,.25);padding:.18rem .45rem;white-space:nowrap}
.f{text-align:center;margin-top:1.5rem;font-family:monospace;font-size:.62rem;color:#7a7268;letter-spacing:.12em}
</style></head><body><div class="w"><h1>💌 Candidature Natacha</h1>
<div class="m">Soumis le ' . $date . ' &nbsp;·&nbsp; ' . strtoupper($lang) . ' &nbsp;·&nbsp; #' . $submission_id . '</div>';

foreach ($answers as $i => $ai) {
    if (!isset($questions[$i])) continue;
    $l = $letters[$ai] ?? '?';
    $a = htmlspecialchars($options[$i][$ai] ?? '—');
    $q = htmlspecialchars($questions[$i]);
    $n = str_pad($i+1,2,'0',STR_PAD_LEFT);
    $html .= "<div class=\"i\"><span class=\"n\">$n</span><div class=\"c\"><div class=\"q\">$q</div><div class=\"a\">$a</div></div><span class=\"b\">$l</span></div>";
}
$html .= '<div class="f">— Natacha · #' . $submission_id . ' —</div></div></body></html>';

$txt = "Candidature Natacha — $date (ID #$submission_id)\n\n";
foreach ($answers as $i => $ai) {
    if (!isset($questions[$i])) continue;
    $txt .= ($i+1).". ".$questions[$i]."\n→ ".($letters[$ai]??'?').". ".($options[$i][$ai]??'—')."\n\n";
}

$b = md5(uniqid());
$headers = "From: ".MAIL_FROM."\r\nReply-To: ".MAIL_FROM."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
$msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$txt\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n--$b--";
$sent = mail(MAIL_TO, '=?UTF-8?B?'.base64_encode($subject).'?=', $msg, $headers);

try { $pdo->prepare("UPDATE submissions SET mail_sent=? WHERE id=?")->execute([(int)$sent, $submission_id]); } catch(Exception $e){}

echo json_encode(['success' => true, 'id' => $submission_id, 'mail' => $sent]);
