<?php
/**
 * profil.php — User profile page
 * Change display name, avatar, password, coffre-fort PIN
 */
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/includes/coffre_fort_helper.php';

securityHeaders();
$user = currentUser();
$lang = $user['lang'];
$userId = $user['id'];
$coffre = new CoffreFort();

$message = '';
$messageType = '';

// ════════════════════════════════════════════════════
// POST Actions
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    // ── Update display name ──
    if ($action === 'update_name') {
        $name = trim($_POST['display_name'] ?? '');
        if ($name === '') {
            $message = t('Le nom ne peut pas être vide.', 'Имя не может быть пустым.');
            $messageType = 'error';
        } elseif (mb_strlen($name) > 50) {
            $message = t('Le nom est trop long (50 caractères max).', 'Имя слишком длинное (макс. 50 символов).');
            $messageType = 'error';
        } else {
            db()->prepare("UPDATE users SET display_name = ? WHERE id = ?")->execute([$name, $userId]);
            $_SESSION['user']['display_name'] = $name;
            $user['display_name'] = $name;
            $message = t('Nom mis à jour.', 'Имя обновлено.');
            $messageType = 'success';
        }
    }

    // ── Update avatar ──
    if ($action === 'update_avatar') {
        $avatar = trim($_POST['avatar'] ?? '');
        if ($avatar === '' || mb_strlen($avatar) > 2) {
            $message = t('L\'avatar doit être un seul emoji.', 'Аватар должен быть одним эмодзи.');
            $messageType = 'error';
        } else {
            db()->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatar, $userId]);
            $_SESSION['user']['avatar'] = $avatar;
            $user['avatar'] = $avatar;
            $message = t('Avatar mis à jour.', 'Аватар обновлён.');
            $messageType = 'success';
        }
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Fetch current hash
        $stmt = db()->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $message = t('Mot de passe actuel incorrect.', 'Текущий пароль неверен.');
            $messageType = 'error';
        } elseif (strlen($newPass) < 6) {
            $message = t('Le nouveau mot de passe doit contenir au moins 6 caractères.', 'Новый пароль должен содержать не менее 6 символов.');
            $messageType = 'error';
        } elseif ($newPass !== $confirm) {
            $message = t('Les mots de passe ne correspondent pas.', 'Пароли не совпадают.');
            $messageType = 'error';
        } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
            $message = t('Mot de passe modifié avec succès.', 'Пароль успешно изменён.');
            $messageType = 'success';
        }
    }

    // ── Update date de naissance ──
    if ($action === 'update_birthday') {
        $dateNaissance = trim($_POST['date_naissance'] ?? '');
        if ($dateNaissance !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNaissance)) {
            $message = t('Format de date invalide.', 'Неверный формат даты.');
            $messageType = 'error';
        } else {
            $val = $dateNaissance === '' ? null : $dateNaissance;
            db()->prepare("UPDATE users SET date_naissance = ? WHERE id = ?")->execute([$val, $userId]);

            // Auto-create or update birthday event in calendrier_events
            if ($val !== null) {
                $displayName = $user['display_name'];
                $titreFr = "Anniversaire de " . $displayName;
                $titreRu = "День рождения " . $displayName;
                $descFr = "🎂 Anniversaire";
                $descRu = "🎂 День рождения";

                // Check if a birthday event already exists for this user
                $stmtCheck = db()->prepare("SELECT id FROM calendrier_events WHERE user_id = ? AND categorie = 'anniversaire' AND description_fr = '🎂 Anniversaire'");
                $stmtCheck->execute([$userId]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    db()->prepare("UPDATE calendrier_events SET titre_fr = ?, titre_ru = ?, date_event = ?, recurrent = 1, couleur = '#c9a96e', description_fr = ?, description_ru = ? WHERE id = ?")
                        ->execute([$titreFr, $titreRu, $val, $descFr, $descRu, $existingId]);
                } else {
                    db()->prepare("INSERT INTO calendrier_events (user_id, titre_fr, titre_ru, description_fr, description_ru, date_event, recurrent, categorie, couleur) VALUES (?, ?, ?, ?, ?, ?, 1, 'anniversaire', '#c9a96e')")
                        ->execute([$userId, $titreFr, $titreRu, $descFr, $descRu, $val]);
                }
            }

            $message = t('Date de naissance mise à jour.', 'Дата рождения обновлена.');
            $messageType = 'success';
        }
    }

    // ── Change coffre-fort PIN ──
    if ($action === 'change_pin') {
        $currentPin = trim($_POST['current_pin'] ?? '');
        $newPin = trim($_POST['new_pin'] ?? '');
        $confirmPin = trim($_POST['confirm_pin'] ?? '');

        $result = $coffre->verifyPin($userId, $currentPin);
        if (!$result['success']) {
            $message = t('PIN actuel incorrect.', 'Текущий PIN неверен.');
            $messageType = 'error';
        } elseif ($newPin !== $confirmPin) {
            $message = t('Les PIN ne correspondent pas.', 'PIN-коды не совпадают.');
            $messageType = 'error';
        } elseif (!$coffre->setPin($userId, $newPin)) {
            $message = $coffre->lastError;
            $messageType = 'error';
        } else {
            $message = t('PIN du coffre-fort modifié avec succès.', 'PIN сейфа успешно изменён.');
            $messageType = 'success';
        }
    }
}

// Reload user data for display
$stmt = db()->prepare("SELECT username, display_name, avatar, created_at, date_naissance FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();
$hasPin = $coffre->hasPin($userId);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Profil', 'Профиль') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--red:#c96e6e;--green:#6ec98a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:640px;margin:0 auto;padding:2.5rem 2rem}

/* Alert */
.alert{padding:.8rem 1.2rem;border:1px solid;font-size:.7rem;margin-bottom:1.5rem;letter-spacing:.05em}
.alert-success{border-color:rgba(110,201,138,.3);color:var(--green);background:rgba(110,201,138,.08)}
.alert-error{border-color:rgba(201,110,110,.3);color:var(--red);background:rgba(201,110,110,.08)}

/* Section heading */
.section{margin-bottom:2.5rem}
.section-title{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.section-desc{font-size:.6rem;color:var(--muted);letter-spacing:.08em;margin-bottom:1rem}

/* Account info */
.info-row{display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid var(--border);font-size:.7rem}
.info-label{color:var(--muted);letter-spacing:.08em}
.info-value{color:var(--text)}

/* Forms */
.form-row{margin-bottom:1rem}
.form-label{display:block;font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.form-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);padding:.55rem .8rem;font-family:'DM Mono',monospace;font-size:.75rem;outline:none;transition:border-color .2s}
.form-input:focus{border-color:var(--accent)}
.form-input::placeholder{color:var(--border)}
.form-inline{display:flex;gap:.8rem;align-items:flex-end}
.form-inline .form-row{flex:1;margin-bottom:0}

.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.45rem .9rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:#0f0d0b}

/* Avatar preview */
.avatar-preview{width:48px;height:48px;border-radius:50%;background:var(--as);border:1px solid var(--accent);display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-right:1rem;flex-shrink:0}
.avatar-row{display:flex;align-items:center}
.avatar-input{max-width:80px;text-align:center;font-size:1.2rem}

/* Page header */
.page-header{margin-bottom:2.5rem;text-align:center}
.page-header h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,3.5vw,2.2rem);font-weight:300;font-style:italic;color:var(--text)}
.page-header h2 em{color:var(--accent)}
.page-header p{font-size:.6rem;color:var(--muted);letter-spacing:.1em;margin-top:.5rem}

@media(max-width:500px){
  .form-inline{flex-direction:column}
  .form-inline .form-row{width:100%}
}
</style>
</head>
<body>

<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">⚙ <?= t('Mon Profil','Мой Профиль') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>">
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="page-header">
  <h2><?= t('Mon', 'Мой') ?> <em><?= t('Profil', 'Профиль') ?></em></h2>
  <p><?= t('Gérer vos informations personnelles', 'Управление личной информацией') ?></p>
</div>

<!-- ═══ Account Info ═══ -->
<div class="section">
  <div class="section-title"><?= t('Informations du compte', 'Информация об аккаунте') ?></div>
  <div class="info-row">
    <span class="info-label"><?= t('Nom d\'utilisateur', 'Имя пользователя') ?></span>
    <span class="info-value"><?= h($profile['username']) ?></span>
  </div>
  <?php if (!empty($profile['created_at'])): ?>
  <div class="info-row">
    <span class="info-label"><?= t('Membre depuis', 'Участник с') ?></span>
    <span class="info-value"><?= date('d/m/Y', strtotime($profile['created_at'])) ?></span>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ Display Name ═══ -->
<div class="section">
  <div class="section-title"><?= t('Nom affiché', 'Отображаемое имя') ?></div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_name">
    <div class="form-inline">
      <div class="form-row">
        <input type="text" name="display_name" class="form-input" value="<?= h($profile['display_name']) ?>" maxlength="50" required>
      </div>
      <button type="submit" class="btn"><?= t('Enregistrer', 'Сохранить') ?></button>
    </div>
  </form>
</div>

<!-- ═══ Avatar ═══ -->
<div class="section">
  <div class="section-title"><?= t('Avatar', 'Аватар') ?></div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_avatar">
    <div class="avatar-row">
      <div class="avatar-preview"><?= h($profile['avatar']) ?></div>
      <div class="form-row" style="flex:0">
        <input type="text" name="avatar" class="form-input avatar-input" value="<?= h($profile['avatar']) ?>" maxlength="2" required>
      </div>
      <button type="submit" class="btn" style="margin-left:.8rem"><?= t('Changer', 'Изменить') ?></button>
    </div>
    <div class="section-desc" style="margin-top:.6rem"><?= t('Un seul emoji (ex: 🦊 🌸 🎭)', 'Один эмодзи (напр: 🦊 🌸 🎭)') ?></div>
  </form>
</div>

<!-- ═══ Date de naissance ═══ -->
<div class="section">
  <div class="section-title"><?= t('Date de naissance', 'Дата рождения') ?></div>
  <div class="section-desc"><?= t('Un événement anniversaire sera automatiquement ajouté au calendrier.', 'Событие дня рождения будет автоматически добавлено в календарь.') ?></div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_birthday">
    <div class="form-inline">
      <div class="form-row">
        <input type="date" name="date_naissance" class="form-input" value="<?= h($profile['date_naissance'] ?? '') ?>">
      </div>
      <button type="submit" class="btn"><?= t('Enregistrer', 'Сохранить') ?></button>
    </div>
  </form>
</div>

<!-- ═══ Change Password ═══ -->
<div class="section">
  <div class="section-title"><?= t('Changer le mot de passe', 'Изменить пароль') ?></div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="form-row">
      <label class="form-label"><?= t('Mot de passe actuel', 'Текущий пароль') ?></label>
      <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
    </div>
    <div class="form-row">
      <label class="form-label"><?= t('Nouveau mot de passe', 'Новый пароль') ?></label>
      <input type="password" name="new_password" class="form-input" required minlength="6" autocomplete="new-password">
    </div>
    <div class="form-row">
      <label class="form-label"><?= t('Confirmer le nouveau mot de passe', 'Подтвердите новый пароль') ?></label>
      <input type="password" name="confirm_password" class="form-input" required minlength="6" autocomplete="new-password">
    </div>
    <button type="submit" class="btn"><?= t('Modifier le mot de passe', 'Изменить пароль') ?></button>
  </form>
</div>

<!-- ═══ Change Coffre-Fort PIN ═══ -->
<?php if ($hasPin): ?>
<div class="section">
  <div class="section-title"><?= t('Changer le PIN du coffre-fort', 'Изменить PIN сейфа') ?></div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="change_pin">
    <div class="form-row">
      <label class="form-label"><?= t('PIN actuel', 'Текущий PIN') ?></label>
      <input type="password" name="current_pin" class="form-input" required minlength="4" maxlength="8" inputmode="numeric" autocomplete="off">
    </div>
    <div class="form-row">
      <label class="form-label"><?= t('Nouveau PIN', 'Новый PIN') ?></label>
      <input type="password" name="new_pin" class="form-input" required minlength="4" maxlength="8" inputmode="numeric" autocomplete="off">
    </div>
    <div class="form-row">
      <label class="form-label"><?= t('Confirmer le nouveau PIN', 'Подтвердите новый PIN') ?></label>
      <input type="password" name="confirm_pin" class="form-input" required minlength="4" maxlength="8" inputmode="numeric" autocomplete="off">
    </div>
    <div class="section-desc"><?= t('Le PIN doit contenir entre 4 et 8 chiffres.', 'PIN должен содержать от 4 до 8 цифр.') ?></div>
    <button type="submit" class="btn"><?= t('Modifier le PIN', 'Изменить PIN') ?></button>
  </form>
</div>
<?php else: ?>
<div class="section">
  <div class="section-title"><?= t('PIN du coffre-fort', 'PIN сейфа') ?></div>
  <div class="section-desc"><?= t('Aucun PIN défini. Rendez-vous dans le coffre-fort pour en créer un.', 'PIN не установлен. Перейдите в сейф, чтобы создать его.') ?></div>
</div>
<?php endif; ?>

</div>
</body>
</html>
