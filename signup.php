<?php
/**
 * NATACHA — Inscription + Création du couple
 * Flow en 3 étapes :
 *   1. Créer ton compte (email, mot de passe)
 *   2. Nommer ton couple (prénom, date de naissance)
 *   3. Inviter ton/ta partenaire (lien unique)
 */
require_once __DIR__.'/config.php';
startSession();
securityHeaders();

if (!empty($_SESSION['user_id'])) {
    header('Location: '.BASE_URL.'/dashboard.php');
    exit;
}

$lang = $_GET['lang'] ?? $_COOKIE['natacha_lang'] ?? 'fr';
if (!in_array($lang, ['fr','en'])) $lang = 'fr';

// Quiz data from landing
$personality = $_GET['personality'] ?? $_SESSION['signup_personality'] ?? 'romantic';
$quizScores  = $_GET['scores'] ? json_decode($_GET['scores'], true) : ($_SESSION['signup_scores'] ?? null);
$quizAnswers = $_GET['answers'] ? json_decode($_GET['answers'], true) : ($_SESSION['signup_answers'] ?? null);

// Persist in session
if (isset($_GET['personality'])) {
    $_SESSION['signup_personality'] = $personality;
    $_SESSION['signup_scores'] = $quizScores;
    $_SESSION['signup_answers'] = $quizAnswers;
}

$step = $_SESSION['signup_step'] ?? 1;
$error = '';
$success = '';

// ═══ STEP 1: Create account ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1' && csrfVerify()) {
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $username = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['username'] ?? '')));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$email) {
        $error = $lang==='fr' ? 'Email invalide.' : 'Invalid email.';
    } elseif (strlen($username) < 3) {
        $error = $lang==='fr' ? 'Pseudo trop court (3 caractères min.).' : 'Username too short (3 chars min.).';
    } elseif (strlen($password) < 6) {
        $error = $lang==='fr' ? 'Mot de passe trop court (6 caractères min.).' : 'Password too short (6 chars min.).';
    } elseif ($password !== $confirm) {
        $error = $lang==='fr' ? 'Les mots de passe ne correspondent pas.' : 'Passwords don\'t match.';
    } else {
        // Check uniqueness
        $stmt = db()->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = $lang==='fr' ? 'Ce pseudo ou email est déjà utilisé.' : 'This username or email is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare("INSERT INTO users (username, email, display_name, password_hash, lang, avatar, role, onboarding_step) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$username, $email, $username, $hash, $lang === 'en' ? 'fr' : $lang, 'R', 'creator', 2]);
            $userId = db()->lastInsertId();

            // Save quiz answers
            if ($quizAnswers && is_array($quizAnswers)) {
                $ins = db()->prepare("INSERT INTO onboarding_quiz (user_id, question_index, answer_index) VALUES (?,?,?)");
                foreach ($quizAnswers as $qi => $ai) {
                    $ins->execute([$userId, $qi, $ai]);
                }
            }

            // Log in
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['user'] = [
                'id' => $userId, 'username' => $username, 'email' => $email,
                'display_name' => $username, 'lang' => $lang === 'en' ? 'fr' : $lang,
                'avatar' => 'R', 'role' => 'creator'
            ];
            $_SESSION['last_active'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['signup_step'] = 2;
            $step = 2;
        }
    }
}

// ═══ STEP 2: Name your couple ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2' && csrfVerify()) {
    $coupleName = trim($_POST['couple_name'] ?? '');
    $birthDate  = $_POST['birth_date'] ?? '';

    if (mb_strlen($coupleName) < 2) {
        $error = $lang==='fr' ? 'Donnez un prénom à votre couple (2 caractères min.).' : 'Give your couple a name (2 chars min.).';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) || strtotime($birthDate) === false) {
        $error = $lang==='fr' ? 'Date invalide.' : 'Invalid date.';
    } else {
        $inviteCode = bin2hex(random_bytes(16));

        // Initial gauges from personality
        $defaultGauges = ['communication'=>50,'adventure'=>50,'tenderness'=>50,'surprise'=>50,'complicity'=>50];
        if ($quizScores && is_array($quizScores)) {
            $defaultGauges = array_merge($defaultGauges, $quizScores);
        }

        $stmt = db()->prepare("INSERT INTO couples (name, birth_date, personality, gauge_communication, gauge_adventure, gauge_tenderness, gauge_surprise, gauge_complicity, invite_code) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $coupleName, $birthDate, $personality,
            min(100, $defaultGauges['communication']),
            min(100, $defaultGauges['adventure']),
            min(100, $defaultGauges['tenderness']),
            min(100, $defaultGauges['surprise']),
            min(100, $defaultGauges['complicity']),
            $inviteCode
        ]);
        $coupleId = db()->lastInsertId();

        // Link user to couple
        db()->prepare("UPDATE users SET couple_id = ?, onboarding_step = 3 WHERE id = ?")
            ->execute([$coupleId, $_SESSION['user_id']]);

        $_SESSION['user']['couple_id'] = $coupleId;
        $_SESSION['signup_step'] = 3;
        $_SESSION['invite_code'] = $inviteCode;
        $step = 3;
    }
}

// Get invite code if on step 3
$inviteCode = $_SESSION['invite_code'] ?? '';
if ($step === 3 && !$inviteCode && !empty($_SESSION['user']['couple_id'])) {
    $stmt = db()->prepare("SELECT invite_code FROM couples WHERE id = ?");
    $stmt->execute([$_SESSION['user']['couple_id']]);
    $inviteCode = $stmt->fetchColumn() ?: '';
    $_SESSION['invite_code'] = $inviteCode;
}

$inviteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/invite.php?code=' . $inviteCode;

// Personality data for display
$personalities = [
    'adventurer' => ['emoji'=>'🧭', 'name_fr'=>'Aventurier Passionné', 'name_en'=>'Passionate Adventurer'],
    'romantic'   => ['emoji'=>'🌹', 'name_fr'=>'Romantique Rêveur', 'name_en'=>'Dreamy Romantic'],
    'complice'   => ['emoji'=>'🔗', 'name_fr'=>'Complice Fusionnel', 'name_en'=>'Soulmate Connection'],
    'creative'   => ['emoji'=>'⚡', 'name_fr'=>'Créatif Électrique', 'name_en'=>'Electric Creative'],
    'sage'       => ['emoji'=>'🧘', 'name_fr'=>'Sage Profond', 'name_en'=>'Deep Sage'],
];
$pData = $personalities[$personality] ?? $personalities['romantic'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= $lang==='fr' ? 'Créer votre couple' : 'Create your couple' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.wrap{width:100%;max-width:420px;animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* Steps indicator */
.steps{display:flex;justify-content:center;gap:1.5rem;margin-bottom:2.5rem}
.step-dot{display:flex;flex-direction:column;align-items:center;gap:.4rem}
.step-num{width:28px;height:28px;border-radius:50%;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.65rem;color:var(--muted);transition:all .3s}
.step-dot.active .step-num{border-color:var(--accent);color:var(--accent);background:var(--as)}
.step-dot.done .step-num{border-color:var(--accent);color:var(--bg);background:var(--accent)}
.step-label{font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted)}
.step-dot.active .step-label{color:var(--accent)}

/* Personality badge */
.personality-badge{text-align:center;margin-bottom:2rem;padding:1.2rem;border:1px solid var(--border)}
.pb-emoji{font-size:2rem;margin-bottom:.5rem}
.pb-type{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--accent)}

/* Form */
.form-title{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:300;color:var(--accent);text-align:center;margin-bottom:.5rem}
.form-sub{text-align:center;font-size:.68rem;color:var(--muted);margin-bottom:2rem;line-height:1.6}
label{display:block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
input[type=text],input[type=email],input[type=password],input[type=date]{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.85rem;padding:.8rem 1rem;margin-bottom:1.2rem;outline:none;transition:border .2s}
input:focus{border-color:var(--accent)}
input[type=date]::-webkit-calendar-picker-indicator{filter:invert(.7)}
button[type=submit]{width:100%;background:var(--accent);border:none;color:#0f0d0b;font-family:'DM Mono',monospace;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;padding:1rem;cursor:pointer;transition:opacity .2s}
button:hover{opacity:.85}
.err{font-size:.68rem;color:#c96e6e;text-align:center;margin-bottom:1rem;padding:.5rem;border:1px solid rgba(201,110,110,.2);background:rgba(201,110,110,.06)}

/* Step 3: Invite */
.invite-box{text-align:center;padding:2rem;border:1px solid var(--border);margin-bottom:1.5rem}
.invite-url{font-size:.65rem;color:var(--accent);word-break:break-all;padding:1rem;background:var(--as);border:1px dashed var(--accent);margin:1rem 0;cursor:pointer;transition:opacity .2s}
.invite-url:hover{opacity:.7}
.invite-url:active{opacity:.5}
.copied{font-size:.6rem;color:var(--accent);opacity:0;transition:opacity .3s}
.copied.show{opacity:1}
.skip-link{display:block;text-align:center;font-size:.65rem;color:var(--muted);margin-top:1.5rem;text-decoration:none;letter-spacing:.1em}
.skip-link:hover{color:var(--accent)}
.or-sep{text-align:center;font-size:.55rem;color:var(--muted);letter-spacing:.2em;text-transform:uppercase;margin:1.5rem 0}

/* Share buttons */
.share-btns{display:flex;gap:.6rem;justify-content:center;margin-top:1rem}
.share-btn{font-size:.6rem;letter-spacing:.1em;padding:.6rem 1rem;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s}
.share-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Birth illustration */
.birth-illust{text-align:center;font-size:4rem;margin-bottom:1.5rem;animation:breathe 3s ease-in-out infinite}
@keyframes breathe{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
</style>
</head>
<body>

<div class="wrap">

    <!-- Steps indicator -->
    <div class="steps">
        <div class="step-dot <?= $step>=1?($step>1?'done':'active'):'' ?>">
            <div class="step-num"><?= $step>1?'✓':'1' ?></div>
            <span class="step-label"><?= $lang==='fr'?'Compte':'Account' ?></span>
        </div>
        <div class="step-dot <?= $step>=2?($step>2?'done':'active'):'' ?>">
            <div class="step-num"><?= $step>2?'✓':'2' ?></div>
            <span class="step-label"><?= $lang==='fr'?'Naissance':'Birth' ?></span>
        </div>
        <div class="step-dot <?= $step>=3?'active':'' ?>">
            <div class="step-num">3</div>
            <span class="step-label"><?= $lang==='fr'?'Inviter':'Invite' ?></span>
        </div>
    </div>

    <!-- Personality badge -->
    <div class="personality-badge">
        <div class="pb-emoji"><?= $pData['emoji'] ?></div>
        <div class="pb-type"><?= h($pData[$lang==='fr'?'name_fr':'name_en']) ?></div>
    </div>

    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
    <!-- ═══ STEP 1: Account ═══ -->
    <div class="form-title"><?= $lang==='fr' ? 'Créez votre compte' : 'Create your account' ?></div>
    <p class="form-sub"><?= $lang==='fr' ? 'Pour donner vie à votre couple, commencez par vous.' : 'To bring your couple to life, start with you.' ?></p>

    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="1">
        <label>Email</label>
        <input type="email" name="email" required placeholder="you@email.com">
        <label><?= $lang==='fr'?'Pseudo':'Username' ?></label>
        <input type="text" name="username" required placeholder="<?= $lang==='fr'?'votre pseudo':'your username' ?>" pattern="[a-zA-Z0-9_]{3,30}">
        <label><?= $lang==='fr'?'Mot de passe':'Password' ?></label>
        <input type="password" name="password" required minlength="6">
        <label><?= $lang==='fr'?'Confirmer':'Confirm' ?></label>
        <input type="password" name="confirm" required minlength="6">
        <button type="submit"><?= $lang==='fr'?'Continuer':'Continue' ?></button>
    </form>

    <?php elseif ($step === 2): ?>
    <!-- ═══ STEP 2: Name your couple ═══ -->
    <div class="birth-illust">🌱</div>
    <div class="form-title"><?= $lang==='fr' ? 'Faites naître votre couple' : 'Give birth to your couple' ?></div>
    <p class="form-sub"><?= $lang==='fr'
        ? 'Donnez-lui un prénom. Ce sera son identité, l\'être qui vit entre vous deux.'
        : 'Give it a name. This will be its identity, the being that lives between you two.' ?></p>

    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="2">
        <label><?= $lang==='fr'?'Prénom de votre couple':'Your couple\'s name' ?></label>
        <input type="text" name="couple_name" required placeholder="<?= $lang==='fr'?'Luna, Oscar, Noa...':'Luna, Oscar, Noa...' ?>" maxlength="100">
        <label><?= $lang==='fr'?'Date de naissance (début de votre histoire)':'Birth date (start of your story)' ?></label>
        <input type="date" name="birth_date" required value="<?= date('Y-m-d') ?>">
        <button type="submit"><?= $lang==='fr'?'Donner vie':'Give life' ?></button>
    </form>

    <?php elseif ($step === 3): ?>
    <!-- ═══ STEP 3: Invite partner ═══ -->
    <div class="birth-illust">💛</div>
    <div class="form-title"><?= $lang==='fr' ? 'Invitez votre moitié' : 'Invite your other half' ?></div>
    <p class="form-sub"><?= $lang==='fr'
        ? 'Votre couple est né ! Mais il a besoin de vous deux pour grandir. Envoyez ce lien à votre partenaire.'
        : 'Your couple is born! But it needs both of you to grow. Send this link to your partner.' ?></p>

    <div class="invite-box">
        <div class="invite-url" id="inviteUrl" onclick="copyInvite()"><?= h($inviteUrl) ?></div>
        <div class="copied" id="copiedMsg"><?= $lang==='fr'?'Lien copié !':'Link copied!' ?></div>

        <div class="share-btns">
            <button class="share-btn" onclick="copyInvite()"><?= $lang==='fr'?'Copier':'Copy' ?></button>
            <button class="share-btn" onclick="shareWhatsApp()"><?= $lang==='fr'?'WhatsApp':'WhatsApp' ?></button>
            <button class="share-btn" onclick="shareSMS()">SMS</button>
        </div>
    </div>

    <a href="<?= BASE_URL ?>/couple.php" class="skip-link"><?= $lang==='fr'
        ? 'Continuer sans inviter pour le moment →'
        : 'Continue without inviting for now →' ?></a>

    <script>
    function copyInvite() {
        const url = document.getElementById('inviteUrl').textContent;
        navigator.clipboard.writeText(url).then(() => {
            document.getElementById('copiedMsg').classList.add('show');
            setTimeout(() => document.getElementById('copiedMsg').classList.remove('show'), 2000);
        });
    }
    function shareWhatsApp() {
        const url = document.getElementById('inviteUrl').textContent;
        const text = <?= json_encode($lang==='fr'
            ? 'Je viens de donner naissance à notre couple sur Natacha ! Rejoins-moi pour le faire grandir ensemble 💛 '
            : 'I just gave birth to our couple on Natacha! Join me to help it grow together 💛 ') ?>;
        window.open('https://wa.me/?text=' + encodeURIComponent(text + url));
    }
    function shareSMS() {
        const url = document.getElementById('inviteUrl').textContent;
        const text = <?= json_encode($lang==='fr'
            ? 'Je viens de créer notre couple sur Natacha ! Rejoins-moi : '
            : 'I just created our couple on Natacha! Join me: ') ?>;
        window.open('sms:?body=' + encodeURIComponent(text + url));
    }
    </script>

    <?php endif; ?>

    <a href="<?= BASE_URL ?>/landing.php" class="skip-link" style="margin-top:2rem">← <?= $lang==='fr'?'Retour':'Back' ?></a>
</div>

</body>
</html>
