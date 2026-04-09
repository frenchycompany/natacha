<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'] ?? 'fr';

$setup = isset($_GET['setup']);
$error = '';

// Check if user has coffre-fort PIN
$stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$hasPin = (bool)$stmt->fetchColumn();

if (!$hasPin) $setup = true;

// POST: verify or set PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = $_POST['pin'] ?? '';

    if ($setup) {
        // Setting new PIN (same as coffre-fort)
        if (preg_match('/^\d{4,8}$/', $pin)) {
            $hash = password_hash($pin, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET coffre_pin=? WHERE id=?")->execute([$hash, $user['id']]);
            $_SESSION['app_unlocked_at'] = time();
            $return = $_SESSION['app_lock_return'] ?? BASE_URL.'/couple.php';
            unset($_SESSION['app_lock_return']);
            header('Location: '.$return);
            exit;
        } else {
            $error = $lang === 'ru' ? 'Введите от 4 до 8 цифр' : 'Entrez entre 4 et 8 chiffres';
        }
    } else {
        // Verifying PIN
        $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if (password_verify($pin, $hash)) {
            $_SESSION['app_unlocked_at'] = time();
            $return = $_SESSION['app_lock_return'] ?? BASE_URL.'/couple.php';
            unset($_SESSION['app_lock_return']);
            header('Location: '.$return);
            exit;
        } else {
            $error = $lang === 'ru' ? 'Неверный код' : 'Code incorrect';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.lock-card{text-align:center;max-width:320px;width:100%;position:relative;z-index:1}
.lock-icon{font-size:3rem;margin-bottom:1.5rem;animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.lock-title{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.lock-sub{font-size:.6rem;color:var(--muted);letter-spacing:.08em;margin-bottom:2rem;line-height:1.7}
.pin-dots{display:flex;justify-content:center;gap:1rem;margin-bottom:1.5rem}
.pin-dot{width:16px;height:16px;border:2px solid var(--border);border-radius:50%;transition:all .2s}
.pin-dot.filled{background:var(--accent);border-color:var(--accent)}
.pin-dot.error{border-color:#c96e6e;background:#c96e6e}
.numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;max-width:240px;margin:0 auto}
.num-btn{background:var(--s);border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1.5rem;padding:1rem;cursor:pointer;transition:all .2s;border-radius:50%;width:64px;height:64px;display:flex;align-items:center;justify-content:center;margin:0 auto}
.num-btn:hover,.num-btn:active{border-color:var(--accent);background:rgba(201,169,110,.1)}
.num-btn.del{font-size:.8rem;font-family:'DM Mono',monospace;color:var(--muted)}
.num-btn.empty{visibility:hidden;cursor:default}
.error-msg{font-size:.6rem;color:#c96e6e;margin-bottom:1rem;letter-spacing:.08em;min-height:1em}
</style>
</head>
<body>
<div class="lock-card">
    <div class="lock-icon">🔒</div>
    <div class="lock-title">Natacha</div>
    <div class="lock-sub">
        <?php if ($setup): ?>
            <?= $lang==='ru' ? 'Создайте код для защиты приложения' : 'Créez un code pour protéger l\'appli' ?>
        <?php else: ?>
            <?= $lang==='ru' ? 'Введите ваш код' : 'Entrez votre code' ?>
        <?php endif; ?>
    </div>
    <div class="error-msg"><?= h($error) ?></div>
    <div class="pin-dots" id="dotsContainer">
        <div class="pin-dot" id="d0"></div>
        <div class="pin-dot" id="d1"></div>
        <div class="pin-dot" id="d2"></div>
        <div class="pin-dot" id="d3"></div>
    </div>
    <form method="POST" id="pinForm" style="display:none">
        <input type="hidden" name="pin" id="pinInput">
    </form>
    <div class="numpad">
        <button class="num-btn" onclick="press('1')">1</button>
        <button class="num-btn" onclick="press('2')">2</button>
        <button class="num-btn" onclick="press('3')">3</button>
        <button class="num-btn" onclick="press('4')">4</button>
        <button class="num-btn" onclick="press('5')">5</button>
        <button class="num-btn" onclick="press('6')">6</button>
        <button class="num-btn" onclick="press('7')">7</button>
        <button class="num-btn" onclick="press('8')">8</button>
        <button class="num-btn" onclick="press('9')">9</button>
        <button class="num-btn empty"></button>
        <button class="num-btn" onclick="press('0')">0</button>
        <button class="num-btn del" onclick="del()">⌫</button>
    </div>
</div>
<script>
let pin = '';
const dots = [0,1,2,3].map(i => document.getElementById('d'+i));
const isSetup = <?= $setup ? 'true' : 'false' ?>;
const minLen = 4;

function press(n) {
    if (pin.length >= 8) return;
    pin += n;
    updateDots();
    // Auto-submit at 4 digits (unless setup where they might want more)
    if (!isSetup && pin.length >= minLen) {
        setTimeout(submit, 200);
    }
}

function del() {
    pin = pin.slice(0, -1);
    updateDots();
}

function submit() {
    document.getElementById('pinInput').value = pin;
    document.getElementById('pinForm').submit();
}

function updateDots() {
    // Show the right number of dots
    const container = document.getElementById('dotsContainer');
    const currentDots = container.querySelectorAll('.pin-dot');
    // Add dots if needed (for PINs longer than 4)
    while (currentDots.length < Math.max(4, pin.length + 1) && currentDots.length < 8) {
        // Keep it at 4 dots max display for simplicity
        break;
    }
    dots.forEach((d, i) => {
        d.classList.toggle('filled', i < pin.length);
        d.classList.remove('error');
    });
    // If setup and reached 4+, show submit hint
    if (isSetup && pin.length >= 4) {
        const existing = document.getElementById('submitHint');
        if (!existing) {
            const hint = document.createElement('button');
            hint.id = 'submitHint';
            hint.textContent = '<?= $lang==="ru" ? "OK ✓" : "OK ✓" ?>';
            hint.style.cssText = 'margin-top:1rem;background:transparent;border:1px solid var(--accent);color:var(--accent);font-family:DM Mono,monospace;font-size:.7rem;padding:.5rem 1.5rem;cursor:pointer;letter-spacing:.1em';
            hint.onclick = submit;
            document.querySelector('.numpad').after(hint);
        }
    }
}

<?php if ($error): ?>
dots.forEach(d => d.classList.add('error'));
setTimeout(() => dots.forEach(d => d.classList.remove('error')), 800);
<?php endif; ?>

document.addEventListener('keydown', (e) => {
    if (e.key >= '0' && e.key <= '9') press(e.key);
    if (e.key === 'Backspace') del();
    if (e.key === 'Enter' && pin.length >= 4) submit();
});
</script>
</body>
</html>
