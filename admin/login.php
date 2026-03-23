<?php
require_once __DIR__.'/config.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    header('Location: '.BASE_URL.'/dashboard.php'); exit;
}

$lang  = $_GET['lang'] ?? $_COOKIE['natacha_lang'] ?? 'fr';
if (!in_array($lang, ['fr','ru'])) $lang = 'fr';
setcookie('natacha_lang', $lang, time()+60*60*24*365, '/');

$error = '';
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $lang     = in_array($_POST['lang'] ?? 'fr', ['fr','ru']) ? $_POST['lang'] : 'fr';
    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['user']        = $user;
            $_SESSION['last_active'] = time();
            // log
            db()->prepare("INSERT INTO sessions_log (user_id, ip) VALUES (?,?)")
               ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
            header('Location: '.BASE_URL.'/dashboard.php'); exit;
        }
    } catch (Exception $e) {}
    $error = $lang === 'ru' ? 'Неверные данные.' : 'Identifiants incorrects.';
}

$T = [
    'fr' => ['title'=>'Notre espace', 'sub'=>'Espace privé · Raphaël & Marina', 'user'=>'Identifiant', 'pass'=>'Mot de passe', 'btn'=>'Entrer', 'err_expired'=>'Session expirée, reconnecte-toi.'],
    'ru' => ['title'=>'Наше пространство', 'sub'=>'Личное пространство · Рафаэль & Марина', 'user'=>'Логин', 'pass'=>'Пароль', 'btn'=>'Войти', 'err_expired'=>'Сессия истекла, войди снова.'],
];
$tx = $T[$lang];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= $tx['title'] ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.lang-bar{position:fixed;top:1rem;right:1.5rem;display:flex;gap:.4rem}
.lb{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .6rem;cursor:pointer;transition:all .2s;text-decoration:none}
.lb.active{border-color:var(--accent);color:var(--accent);background:var(--as)}
.box{width:100%;max-width:340px;animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.logo{text-align:center;margin-bottom:2.5rem}
.logo h1{font-family:'Cormorant Garamond',serif;font-size:2.2rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.3rem}
.logo p{font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
label{display:block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
input[type=text],input[type=password]{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.85rem;padding:.8rem 1rem;margin-bottom:1.2rem;outline:none;transition:border .2s}
input:focus{border-color:var(--accent)}
button[type=submit]{width:100%;background:var(--accent);border:none;color:#0f0d0b;font-family:'DM Mono',monospace;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;padding:1rem;cursor:pointer;transition:opacity .2s}
button:hover{opacity:.85}
.err{font-size:.68rem;color:#c96e6e;text-align:center;margin-bottom:1rem;letter-spacing:.05em;padding:.5rem;border:1px solid rgba(201,110,110,.2);background:rgba(201,110,110,.06)}
.info{font-size:.68rem;color:var(--accent);text-align:center;margin-bottom:1rem;letter-spacing:.05em}
</style>
</head>
<body>
<div class="lang-bar">
  <a class="lb <?= $lang==='fr'?'active':'' ?>" href="?lang=fr">FR</a>
  <a class="lb <?= $lang==='ru'?'active':'' ?>" href="?lang=ru">RU</a>
</div>
<div class="box">
  <div class="logo">
    <h1>💌 Natacha</h1>
    <p><?= $tx['sub'] ?></p>
  </div>
  <?php if ($expired): ?><div class="info"><?= $tx['err_expired'] ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" autocomplete="off">
    <input type="hidden" name="lang" value="<?= $lang ?>">
    <label><?= $tx['user'] ?></label>
    <input type="text" name="username" autocomplete="username" required>
    <label><?= $tx['pass'] ?></label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit"><?= $tx['btn'] ?></button>
  </form>
</div>
</body>
</html>
