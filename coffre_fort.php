<?php
/**
 * coffre_fort.php — Coffre-fort numérique Natacha
 * PIN-based access, AES-256 encrypted file storage
 */
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/includes/coffre_fort_helper.php';

securityHeaders();
$user = currentUser();
$lang = $user['lang'];
$userId = $user['id'];
$coffre = new CoffreFort();
$coffre->cleanExpiredSessions();

$message = '';
$messageType = '';

// ════════════════════════════════════════════════════
// POST Actions
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    // Set PIN (first time)
    if ($action === 'set_pin') {
        $pin = trim($_POST['pin'] ?? '');
        $confirm = trim($_POST['pin_confirm'] ?? '');
        if ($pin !== $confirm) {
            $message = t('Les PIN ne correspondent pas.', 'PIN-коды не совпадают.');
            $messageType = 'error';
        } elseif ($coffre->setPin($userId, $pin)) {
            $message = t('PIN défini avec succès.', 'PIN успешно установлен.');
            $messageType = 'success';
        } else {
            $message = $coffre->lastError;
            $messageType = 'error';
        }
    }

    // Verify PIN
    if ($action === 'verify_pin') {
        $pin = trim($_POST['pin'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $pinAllowed = true;
        try {
            if (!checkRateLimit('coffre_pin', $ip, 5, 300)) {
                $pinAllowed = false;
                $message = t('Trop de tentatives. Attendez 5 minutes.', 'Слишком много попыток. Подождите 5 минут.');
                $messageType = 'error';
            }
        } catch (Exception $e) {}
        if ($pinAllowed) {
            $result = $coffre->verifyPin($userId, $pin);
            error_log("COFFRE DEBUG: verifyPin result=" . json_encode($result) . " session_token=" . ($_SESSION['coffre_fort_token'] ?? 'NONE') . " return_to=" . ($_POST['return_to'] ?? $_GET['from'] ?? 'NONE'));
            if ($result['success']) {
                $returnTo = $_POST['return_to'] ?? $_GET['from'] ?? '';
                if ($returnTo === 'galerie') {
                    header('Location: '.BASE_URL.'/galerie.php');
                    exit;
                }
                header('Location: '.BASE_URL.'/coffre_fort.php');
                exit;
            } else {
                try { recordRateLimit('coffre_pin', $ip); } catch (Exception $e) {}
                $message = $result['error'] ?? t('PIN incorrect.', 'Неверный PIN.');
                $messageType = 'error';
            }
        }
    }

    // Upload
    if ($action === 'upload') {
        $session = $coffre->verifierSession();
        if (!$session) {
            $message = t('Session expirée.', 'Сессия истекла.');
            $messageType = 'error';
        } elseif (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $categorie = $_POST['categorie'] ?? 'autre';
            $description = trim($_POST['description'] ?? '');
            $tags = trim($_POST['tags'] ?? '');
            $result = $coffre->upload($_FILES['fichier'], $categorie, $userId, $description, $tags);
            if ($result['success']) {
                $message = t('Fichier chiffré et ajouté au coffre-fort.', 'Файл зашифрован и добавлен в сейф.');
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
        }
    }

    // Delete
    if ($action === 'supprimer') {
        $session = $coffre->verifierSession();
        if ($session) {
            $fichierId = (int)($_POST['fichier_id'] ?? 0);
            $coffre->supprimer($fichierId, $userId);
            $message = t('Fichier supprimé.', 'Файл удалён.');
            $messageType = 'success';
        }
    }

    // Lock
    if ($action === 'verrouiller') {
        $token = $_SESSION['coffre_fort_token'] ?? '';
        if ($token) $coffre->invaliderSession($token);
        $message = t('Coffre-fort verrouillé.', 'Сейф заблокирован.');
        $messageType = 'success';
    }
}

// ════════════════════════════════════════════════════
// State
// ════════════════════════════════════════════════════
$sessionCoffre = $coffre->verifierSession();
$isUnlocked = $sessionCoffre !== null;
$tempsRestant = $coffre->tempsRestant();
$hasPin = $coffre->hasPin($userId);

$filtreCategorie = $_GET['categorie'] ?? '';
$filtreRecherche = $_GET['q'] ?? '';
$fichiers = $isUnlocked ? $coffre->lister($filtreCategorie, $filtreRecherche) : [];
$stats = $isUnlocked ? $coffre->getStats() : [];
$logs = ($isUnlocked && isset($_GET['logs'])) ? $coffre->getLogs(30) : [];

$categories = [
    'photo'    => ['label_fr' => 'Photos',    'label_ru' => 'Фото',       'icon' => 'fa-image'],
    'video'    => ['label_fr' => 'Vidéos',    'label_ru' => 'Видео',      'icon' => 'fa-video'],
    'document' => ['label_fr' => 'Documents', 'label_ru' => 'Документы',  'icon' => 'fa-file-pdf'],
    'contrat'  => ['label_fr' => 'Contrats',  'label_ru' => 'Контракты',  'icon' => 'fa-file-contract'],
    'identite' => ['label_fr' => 'Identité',  'label_ru' => 'Документы',  'icon' => 'fa-id-card'],
    'autre'    => ['label_fr' => 'Autres',    'label_ru' => 'Другое',     'icon' => 'fa-folder'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Coffre-Fort', 'Сейф') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--red:#c96e6e;--green:#6ec98a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}
.btn.danger{border-color:var(--red);color:var(--red)}
.btn.danger:hover{background:var(--red);color:#0f0d0b}
.btn.secondary{border-color:var(--border);color:var(--muted)}
.btn.secondary:hover{border-color:var(--accent);color:var(--accent);background:transparent}
.wrap{max-width:900px;margin:0 auto;padding:2.5rem 2rem}

/* Alert */
.alert{padding:.8rem 1.2rem;border:1px solid;font-size:.7rem;margin-bottom:1.5rem;letter-spacing:.05em}
.alert-success{border-color:rgba(110,201,138,.3);color:var(--green);background:rgba(110,201,138,.08)}
.alert-error{border-color:rgba(201,110,110,.3);color:var(--red);background:rgba(201,110,110,.08)}

/* Lock screen */
.lock-screen{max-width:400px;margin:4rem auto;text-align:center}
.lock-icon{font-size:4rem;color:var(--accent);margin-bottom:1.5rem}
.lock-screen h2{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.lock-screen p{font-size:.65rem;color:var(--muted);margin-bottom:2rem;letter-spacing:.08em}
.pin-input{font-size:2rem;letter-spacing:.8rem;text-align:center;font-weight:700;max-width:220px;margin:0 auto;background:transparent;border:1px solid var(--border);color:var(--accent);padding:.8rem;font-family:'DM Mono',monospace;outline:none;display:block;width:100%}
.pin-input:focus{border-color:var(--accent)}
.pin-input::placeholder{color:var(--border);letter-spacing:.3rem;font-size:1.2rem}

/* Session bar */
.session-bar{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1.2rem;border:1px solid var(--border);margin-bottom:1.5rem;font-size:.65rem}
.timer{font-weight:700;font-size:.9rem;color:var(--accent);font-variant-numeric:tabular-nums}
.timer.warning{color:var(--red)}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin-bottom:1.5rem}
@media(max-width:600px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;text-align:center}
.stat-value{font-size:1.6rem;font-weight:700;color:var(--accent);font-family:'Cormorant Garamond',serif}
.stat-label{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}

/* Upload */
.upload-section{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:1.5rem}
.upload-section h3{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;color:var(--accent);margin-bottom:1rem}
.upload-zone{border:1px dashed var(--border);padding:2rem;text-align:center;cursor:pointer;transition:all .2s}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--accent);background:var(--as)}
.upload-zone i{font-size:2rem;color:var(--accent);display:block;margin-bottom:.5rem}
.upload-zone span{font-size:.65rem;color:var(--muted)}
.upload-meta{display:grid;grid-template-columns:auto 1fr 1fr auto;gap:.5rem;margin-top:1rem;align-items:end}
@media(max-width:700px){.upload-meta{grid-template-columns:1fr 1fr}}
label.field-label{display:block;font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
select,input[type=text]{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.72rem;padding:.5rem .7rem;outline:none}
select{appearance:none;cursor:pointer}
select:focus,input[type=text]:focus{border-color:var(--accent)}
select option{background:var(--bg);color:var(--text)}

/* Filters */
.filters{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;flex-wrap:wrap;gap:.5rem}
.filter-pills{display:flex;flex-wrap:wrap;gap:.3rem}
.pill{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .7rem;text-decoration:none;transition:all .2s;cursor:pointer}
.pill:hover{border-color:var(--accent);color:var(--accent)}
.pill.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* File cards */
.files-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.8rem}
.file-card{background:var(--s);border:1px solid var(--border);padding:1.2rem;transition:border-color .2s}
.file-card:hover{border-color:var(--accent)}
.file-top{display:flex;gap:.8rem;align-items:flex-start}
.file-icon{width:40px;height:40px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--accent);flex-shrink:0}
.file-name{font-size:.72rem;font-weight:700;word-break:break-all;line-height:1.4}
.file-meta{font-size:.55rem;color:var(--muted);margin-top:.2rem;letter-spacing:.05em}
.file-tags{margin-top:.4rem}
.file-tags span{font-size:.5rem;letter-spacing:.1em;border:1px solid var(--border);padding:.1rem .4rem;color:var(--muted);margin-right:.2rem}
.file-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:.8rem;padding-top:.8rem;border-top:1px solid var(--border)}
.file-cat{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);border:1px solid rgba(201,169,110,.2);padding:.15rem .5rem}
.file-actions{display:flex;gap:.3rem}
.file-actions a,.file-actions button{background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .5rem;font-size:.6rem;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Mono',monospace}
.file-actions a:hover{border-color:var(--accent);color:var(--accent)}
.file-actions button:hover{border-color:var(--red);color:var(--red)}

.empty{text-align:center;padding:4rem;font-size:.72rem;color:var(--muted)}
.empty i{font-size:2rem;display:block;margin-bottom:1rem;color:var(--border)}

/* Logs */
.logs-section{margin-top:2rem;border:1px solid var(--border);background:var(--s)}
.logs-header{padding:.8rem 1.2rem;border-bottom:1px solid var(--border);font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent)}
.log-entry{padding:.6rem 1.2rem;border-bottom:1px solid var(--border);font-size:.62rem;display:flex;justify-content:space-between;align-items:center}
.log-entry:last-child{border:none}
.log-action{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;border:1px solid var(--border);padding:.1rem .4rem;color:var(--accent);margin-right:.5rem}
.log-action.fail{color:var(--red);border-color:rgba(201,110,110,.3)}
.log-action.delete{color:var(--red);border-color:rgba(201,110,110,.3)}
.log-time{color:var(--muted);font-size:.55rem;flex-shrink:0}
#fileName{color:var(--accent);font-size:.7rem;font-weight:700;margin-top:.5rem}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title">🔐 <?= t('Coffre-Fort','Сейф') ?></div>
    <?php if ($isUnlocked): ?>
        <span style="font-size:.6rem;color:var(--green);letter-spacing:.1em"><i class="fas fa-lock-open"></i> <?= t('Ouvert', 'Открыт') ?></span>
    <?php else: ?>
        <span style="font-size:.6rem;color:var(--red);letter-spacing:.1em"><i class="fas fa-lock"></i> <?= t('Verrouillé', 'Закрыт') ?></span>
    <?php endif; ?>
</div>

<div class="wrap">

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>">
    <?= h($message) ?>
</div>
<?php endif; ?>

<?php if (!$isUnlocked): ?>
<!-- ═══════════ LOCK SCREEN ═══════════ -->
<div class="lock-screen">
    <div class="lock-icon"><i class="fas fa-shield-halved"></i></div>

    <?php if (!$hasPin): ?>
        <!-- First time: set PIN -->
        <h2><?= t('Définir votre PIN', 'Установите PIN') ?></h2>
        <p><?= t('Choisissez un code PIN (4-8 chiffres) pour sécuriser votre coffre-fort.', 'Выберите PIN-код (4-8 цифр) для защиты вашего сейфа.') ?></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_pin">
            <input type="password" name="pin" class="pin-input" maxlength="8" placeholder="····" inputmode="numeric" pattern="[0-9]{4,8}" required autofocus>
            <div style="margin-top:.8rem;font-size:.55rem;color:var(--muted);letter-spacing:.08em"><?= t('Confirmer', 'Подтвердить') ?></div>
            <input type="password" name="pin_confirm" class="pin-input" maxlength="8" placeholder="····" inputmode="numeric" pattern="[0-9]{4,8}" required style="margin-top:.3rem">
            <button type="submit" class="btn primary" style="margin-top:1.5rem"><i class="fas fa-key"></i> <?= t('Définir le PIN', 'Установить PIN') ?></button>
        </form>
    <?php else: ?>
        <!-- Enter PIN -->
        <h2><?= t('Accès Sécurisé', 'Безопасный доступ') ?></h2>
        <p><?= t('Entrez votre PIN pour déverrouiller le coffre-fort.', 'Введите PIN для разблокировки сейфа.') ?></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="verify_pin">
            <?php if (($_GET['from'] ?? '') === 'galerie'): ?>
            <input type="hidden" name="return_to" value="galerie">
            <?php endif; ?>
            <input type="password" name="pin" class="pin-input" maxlength="8" placeholder="····" inputmode="numeric" pattern="[0-9]{4,8}" required autofocus>
            <button type="submit" class="btn primary" style="margin-top:1.5rem"><i class="fas fa-lock-open"></i> <?= t('Déverrouiller', 'Разблокировать') ?></button>
        </form>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════ VAULT UNLOCKED ═══════════ -->

<!-- Session bar -->
<div class="session-bar">
    <div>
        <i class="fas fa-clock" style="color:var(--muted);margin-right:.3rem"></i>
        <?= t('Session active —', 'Активная сессия —') ?>
        <span class="timer" id="timer" data-seconds="<?= $tempsRestant ?>"><?= gmdate('i:s', $tempsRestant) ?></span>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        <a href="?logs=1" class="btn secondary" style="font-size:.5rem;padding:.25rem .5rem"><i class="fas fa-history"></i></a>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="verrouiller">
            <button type="submit" class="btn danger" style="font-size:.5rem;padding:.25rem .5rem"><i class="fas fa-lock"></i> <?= t('Verrouiller', 'Закрыть') ?></button>
        </form>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $stats['total_fichiers'] ?? 0 ?></div>
        <div class="stat-label"><?= t('Fichiers', 'Файлов') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= CoffreFort::formatTaille($stats['taille_totale'] ?? 0) ?></div>
        <div class="stat-label"><?= t('Espace', 'Место') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['par_categorie']['photo'] ?? 0 ?></div>
        <div class="stat-label"><?= t('Photos', 'Фото') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['par_categorie']['video'] ?? 0 ?></div>
        <div class="stat-label"><?= t('Vidéos', 'Видео') ?></div>
    </div>
</div>

<!-- Upload -->
<div class="upload-section">
    <h3><i class="fas fa-upload" style="margin-right:.5rem"></i><?= t('Ajouter un fichier', 'Добавить файл') ?></h3>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload">
        <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <i class="fas fa-cloud-arrow-up"></i>
            <span><?= t('Glissez un fichier ou cliquez pour sélectionner', 'Перетащите файл или нажмите для выбора') ?></span>
            <div style="font-size:.5rem;color:var(--border);margin-top:.3rem"><?= t('Images, vidéos, PDF, documents — Max 200 Mo', 'Изображения, видео, PDF, документы — Макс 200 Мб') ?></div>
            <input type="file" name="fichier" id="fileInput" style="display:none" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx">
            <div id="fileName" style="display:none"></div>
        </div>
        <div class="upload-meta">
            <div>
                <label class="field-label"><?= t('Catégorie', 'Категория') ?></label>
                <select name="categorie">
                    <?php foreach ($categories as $key => $cat): ?>
                    <option value="<?= $key ?>"><?= t($cat['label_fr'], $cat['label_ru']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label"><?= t('Description', 'Описание') ?></label>
                <input type="text" name="description" placeholder="<?= t('Optionnel', 'Необязательно') ?>">
            </div>
            <div>
                <label class="field-label">Tags</label>
                <input type="text" name="tags" placeholder="<?= t('ex: paris, voyage', 'напр: париж, поездка') ?>">
            </div>
            <div>
                <button type="submit" class="btn primary" id="uploadBtn" disabled style="white-space:nowrap">
                    <i class="fas fa-lock"></i> <?= t('Chiffrer', 'Зашифровать') ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Filters -->
<div class="filters">
    <div class="filter-pills">
        <a href="coffre_fort.php" class="pill <?= !$filtreCategorie ? 'active' : '' ?>"><?= t('Tout', 'Все') ?></a>
        <?php foreach ($categories as $key => $cat): ?>
        <a href="?categorie=<?= $key ?>" class="pill <?= $filtreCategorie === $key ? 'active' : '' ?>">
            <i class="fas <?= $cat['icon'] ?>"></i> <?= t($cat['label_fr'], $cat['label_ru']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" style="display:flex;gap:.3rem">
        <?php if ($filtreCategorie): ?><input type="hidden" name="categorie" value="<?= h($filtreCategorie) ?>"><?php endif; ?>
        <input type="text" name="q" placeholder="<?= t('Rechercher…', 'Поиск…') ?>" value="<?= h($filtreRecherche) ?>" style="width:150px;font-size:.6rem;padding:.3rem .5rem">
        <button type="submit" class="btn secondary" style="font-size:.5rem;padding:.3rem .5rem"><i class="fas fa-search"></i></button>
    </form>
</div>

<!-- Files -->
<?php if (empty($fichiers)): ?>
<div class="empty">
    <i class="fas fa-vault"></i>
    <?= t('Aucun fichier dans le coffre-fort.', 'В сейфе нет файлов.') ?>
</div>
<?php else: ?>
<div class="files-grid">
    <?php foreach ($fichiers as $f):
        $cat = $categories[$f['categorie']] ?? $categories['autre'];
        $isImage = str_starts_with($f['type_mime'], 'image/');
        $isVideo = str_starts_with($f['type_mime'], 'video/');
        $isPdf = $f['type_mime'] === 'application/pdf';
    ?>
    <div class="file-card">
        <div class="file-top">
            <div class="file-icon"><i class="fas <?= $cat['icon'] ?>"></i></div>
            <div style="min-width:0;flex:1">
                <div class="file-name"><?= h($f['nom_original']) ?></div>
                <div class="file-meta">
                    <?= CoffreFort::formatTaille($f['taille']) ?> — <?= date('d/m/Y H:i', strtotime($f['created_at'])) ?>
                </div>
                <?php if ($f['description']): ?>
                <div class="file-meta"><?= h($f['description']) ?></div>
                <?php endif; ?>
                <?php if ($f['tags']): ?>
                <div class="file-tags">
                    <?php foreach (explode(',', $f['tags']) as $tag): ?>
                    <span><?= h(trim($tag)) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="file-bottom">
            <span class="file-cat"><?= t($cat['label_fr'], $cat['label_ru']) ?></span>
            <div class="file-actions">
                <?php if ($isImage || $isVideo || $isPdf): ?>
                <a href="coffre_fort_viewer.php?id=<?= $f['id'] ?>" title="<?= t('Consulter', 'Просмотр') ?>"><i class="fas fa-eye"></i></a>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Supprimer ce fichier ?', 'Удалить файл?') ?>')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="fichier_id" value="<?= $f['id'] ?>">
                    <button type="submit" title="<?= t('Supprimer', 'Удалить') ?>"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Logs -->
<?php if (isset($_GET['logs']) && $logs): ?>
<div class="logs-section">
    <div class="logs-header"><i class="fas fa-history"></i> <?= t('Journal d\'accès', 'Журнал доступа') ?></div>
    <?php foreach ($logs as $log): ?>
    <div class="log-entry">
        <div>
            <span class="log-action <?= in_array($log['action'], ['verification_fail','suppression']) ? 'fail' : '' ?>">
                <?= h($log['action']) ?>
            </span>
            <strong><?= h($log['user_nom'] ?? '?') ?></strong>
            <?php if ($log['fichier_nom']): ?> — <?= h($log['fichier_nom']) ?><?php endif; ?>
            <?php if ($log['details']): ?> <span style="color:var(--muted)">— <?= h($log['details']) ?></span><?php endif; ?>
        </div>
        <span class="log-time"><?= date('d/m H:i:s', strtotime($log['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; /* fin isUnlocked */ ?>

</div>

<script>
// Timer
const timerEl = document.getElementById('timer');
if (timerEl) {
    let seconds = parseInt(timerEl.dataset.seconds);
    setInterval(() => {
        seconds--;
        if (seconds <= 0) { location.reload(); return; }
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        timerEl.textContent = m + ':' + s;
        if (seconds < 120) timerEl.classList.add('warning');
    }, 1000);
}

// Upload zone
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileNameEl = document.getElementById('fileName');
const uploadBtn = document.getElementById('uploadBtn');
if (dropZone) {
    ['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); }));
    dropZone.addEventListener('drop', ev => { fileInput.files = ev.dataTransfer.files; showFile(); });
    fileInput.addEventListener('change', showFile);
}
function showFile() {
    if (fileInput.files.length > 0) {
        fileNameEl.textContent = fileInput.files[0].name;
        fileNameEl.style.display = 'block';
        uploadBtn.disabled = false;
    }
}
</script>
</body>
</html>
