<?php
/**
 * NATACHA — Page d'invitation partenaire
 * Le partenaire reçoit un lien unique, crée son compte et rejoint le couple.
 */
require_once __DIR__.'/config.php';
startSession();
securityHeaders();

$lang = $_GET['lang'] ?? $_COOKIE['natacha_lang'] ?? 'fr';
if (!in_array($lang, ['fr','ru'])) $lang = 'fr';
setcookie('natacha_lang', $lang, time()+86400*365, '/');

$code = $_GET['code'] ?? '';
$error = '';
$couple = null;
$creator = null;

if (!$code) {
    header('Location: '.BASE_URL.'/landing.php');
    exit;
}

// Find couple by invite code
$stmt = db()->prepare("SELECT * FROM couples WHERE invite_code = ?");
$stmt->execute([$code]);
$couple = $stmt->fetch();

if (!$couple) {
    $error = $lang==='fr' ? 'Lien d\'invitation invalide ou expiré.' : 'Ссылка приглашения недействительна или истекла.';
} elseif ($couple['invite_accepted']) {
    $error = $lang==='fr' ? 'Cette invitation a déjà été acceptée.' : 'Это приглашение уже принято.';
} else {
    // Find creator
    $stmt = db()->prepare("SELECT display_name FROM users WHERE couple_id = ? AND role = 'creator' LIMIT 1");
    $stmt->execute([$couple['id']]);
    $creator = $stmt->fetch();
}

// Already logged in? Just link to couple
if (!empty($_SESSION['user_id']) && $couple && !$couple['invite_accepted']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify() && ($_POST['action'] ?? '') === 'join') {
        db()->prepare("UPDATE users SET couple_id = ?, role = 'partner', onboarding_step = 10 WHERE id = ?")
            ->execute([$couple['id'], $_SESSION['user_id']]);
        db()->prepare("UPDATE couples SET invite_accepted = 1 WHERE id = ?")
            ->execute([$couple['id']]);
        $_SESSION['user']['couple_id'] = $couple['id'];
        $_SESSION['user']['role'] = 'partner';
        session_write_close();
        header('Location: '.BASE_URL.'/couple.php');
        exit;
    }
}

// New user signup as partner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify() && ($_POST['action'] ?? '') === 'signup_partner' && $couple && !$couple['invite_accepted']) {
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $username = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['username'] ?? '')));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$email) {
        $error = $lang==='fr' ? 'Email invalide.' : 'Неверный email.';
    } elseif (strlen($username) < 3) {
        $error = $lang==='fr' ? 'Pseudo trop court (3 min.).' : 'Имя слишком короткое (мин. 3).';
    } elseif (strlen($password) < 6) {
        $error = $lang==='fr' ? 'Mot de passe trop court (6 min.).' : 'Пароль слишком короткий (мин. 6).';
    } elseif ($password !== $confirm) {
        $error = $lang==='fr' ? 'Les mots de passe ne correspondent pas.' : 'Пароли не совпадают.';
    } else {
        $stmt = db()->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = $lang==='fr' ? 'Ce pseudo ou email est déjà utilisé.' : 'Имя или email уже используется.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare("INSERT INTO users (username, email, display_name, password_hash, lang, avatar, couple_id, role, onboarding_step) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$username, $email, $username, $hash, $lang, 'M', $couple['id'], 'partner', 10]);
            $userId = db()->lastInsertId();

            db()->prepare("UPDATE couples SET invite_accepted = 1 WHERE id = ?")->execute([$couple['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['user'] = [
                'id'=>$userId, 'username'=>$username, 'email'=>$email,
                'display_name'=>$username, 'lang'=>$lang,
                'avatar'=>'M', 'role'=>'partner', 'couple_id'=>$couple['id']
            ];
            $_SESSION['last_active'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            session_write_close();
            header('Location: '.BASE_URL.'/couple.php');
            exit;
        }
    }
}

$personalities = [
    'adventurer'=>['emoji'=>'🧭','name_fr'=>'Aventurier Passionné','name_ru'=>'Страстный авантюрист'],
    'romantic'=>['emoji'=>'🌹','name_fr'=>'Romantique Rêveur','name_ru'=>'Мечтательный романтик'],
    'complice'=>['emoji'=>'🔗','name_fr'=>'Complice Fusionnel','name_ru'=>'Родственные души'],
    'creative'=>['emoji'=>'⚡','name_fr'=>'Créatif Électrique','name_ru'=>'Электрический творец'],
    'sage'=>['emoji'=>'🧘','name_fr'=>'Sage Profond','name_ru'=>'Глубокий мудрец'],
];
$pData = $personalities[$couple['personality'] ?? 'romantic'] ?? $personalities['romantic'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= $lang==='fr' ? 'Rejoindre votre couple' : 'Присоединиться к паре' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.wrap{width:100%;max-width:420px;animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.invite-hero{text-align:center;margin-bottom:2rem}
.invite-emoji{font-size:4rem;margin-bottom:1rem;animation:breathe 3s ease-in-out infinite}
@keyframes breathe{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
.invite-hero h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.invite-hero p{font-size:.75rem;color:var(--muted);line-height:1.7}
.couple-card{border:1px solid var(--border);padding:1.5rem;text-align:center;margin-bottom:2rem}
.cc-name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--accent);margin-bottom:.3rem}
.cc-personality{font-size:.65rem;color:var(--muted);letter-spacing:.1em}
.cc-creator{font-size:.7rem;color:var(--muted);margin-top:.5rem}
label{display:block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
input[type=text],input[type=email],input[type=password]{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.85rem;padding:.8rem 1rem;margin-bottom:1.2rem;outline:none;transition:border .2s}
input:focus{border-color:var(--accent)}
button[type=submit]{width:100%;background:var(--accent);border:none;color:#0f0d0b;font-family:'DM Mono',monospace;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;padding:1rem;cursor:pointer;transition:opacity .2s}
button:hover{opacity:.85}
.err{font-size:.68rem;color:#c96e6e;text-align:center;margin-bottom:1rem;padding:.5rem;border:1px solid rgba(201,110,110,.2);background:rgba(201,110,110,.06)}
</style>
</head>
<body>
<div class="wrap">

<?php if ($error && !$couple): ?>
    <div class="invite-hero">
        <div class="invite-emoji">💔</div>
        <h1><?= $lang==='fr'?'Oups...':'Ой...' ?></h1>
        <p><?= h($error) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/landing.php" style="display:block;text-align:center;color:var(--accent);font-size:.7rem;margin-top:1rem"><?= $lang==='fr'?'Créer votre propre couple →':'Создайте свою пару →' ?></a>

<?php elseif ($couple && !$couple['invite_accepted']): ?>
    <div class="invite-hero">
        <div class="invite-emoji">💛</div>
        <h1><?= $lang==='fr'?'Vous êtes invité(e) !':'Вы приглашены!' ?></h1>
        <p><?= $lang==='fr'
            ? ($creator ? h($creator['display_name']).' vous invite à' : 'Quelqu\'un vous invite à').' rejoindre votre couple.'
            : ($creator ? h($creator['display_name']).' приглашает вас' : 'Кто-то приглашает вас').' присоединиться к паре.' ?></p>
    </div>

    <div class="couple-card">
        <div style="font-size:2rem;margin-bottom:.5rem"><?= $pData['emoji'] ?></div>
        <div class="cc-name"><?= h($couple['name']) ?></div>
        <div class="cc-personality"><?= h($pData[$lang==='fr'?'name_fr':'name_ru']) ?></div>
    </div>

    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

    <?php if (!empty($_SESSION['user_id'])): ?>
        <!-- Already logged in, just join -->
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="join">
            <button type="submit"><?= $lang==='fr'?'Rejoindre le couple':'Присоединиться к паре' ?></button>
        </form>
    <?php else: ?>
        <!-- Create account and join -->
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="signup_partner">
            <label>Email</label>
            <input type="email" name="email" required>
            <label><?= $lang==='fr'?'Pseudo':'Имя пользователя' ?></label>
            <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,30}">
            <label><?= $lang==='fr'?'Mot de passe':'Пароль' ?></label>
            <input type="password" name="password" required minlength="6">
            <label><?= $lang==='fr'?'Confirmer':'Подтвердить' ?></label>
            <input type="password" name="confirm" required minlength="6">
            <button type="submit"><?= $lang==='fr'?'Créer mon compte et rejoindre':'Создать аккаунт и присоединиться' ?></button>
        </form>
    <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
