<?php
/**
 * coffre_fort_viewer.php — Secure file viewer
 * Images via Canvas (no download), video streaming, PDF inline
 */
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/includes/coffre_fort_helper.php';

$user = currentUser();
$lang = $user['lang'];
$userId = $user['id'];
$coffre = new CoffreFort();

// Check vault session
$session = $coffre->verifierSession();
if (!$session) {
    header('Location: ' . BASE_URL . '/coffre_fort.php');
    exit;
}

$fichierId = (int)($_GET['id'] ?? 0);
$fichier = $coffre->getFichier($fichierId);
if (!$fichier) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$isImage = str_starts_with($fichier['type_mime'], 'image/');
$isVideo = str_starts_with($fichier['type_mime'], 'video/');
$isPdf = $fichier['type_mime'] === 'application/pdf';

// AJAX stream endpoint
if (isset($_GET['stream'])) {
    $s = $coffre->verifierSession();
    if (!$s) {
        http_response_code(403);
        die('Session expirée');
    }

    if ($isImage) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $b64 = $coffre->streamImageBase64($fichierId, $userId);
        if (!$b64) {
            http_response_code(500);
            echo json_encode(['error' => $coffre->lastError ?? 'Erreur de déchiffrement', 'data' => null]);
            exit;
        }
        echo json_encode(['data' => $b64]);
        exit;
    }
    if ($isVideo) {
        $coffre->streamVideo($fichierId, $userId);
        exit;
    }
    if ($isPdf) {
        $coffre->streamDocument($fichierId, $userId);
        exit;
    }
    http_response_code(400);
    exit;
}

$coffre->prolongerSession($session['token']);
$tempsRestant = $coffre->tempsRestant();
$watermark = strtoupper($user['display_name']) . ' — ' . date('d/m/Y H:i') . ' — ' . t('CONFIDENTIEL', 'КОНФИДЕНЦИАЛЬНО');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($fichier['nom_original']) ?> — <?= t('Coffre-Fort', 'Сейф') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0a0a;color:#e8e0d5;font-family:'DM Mono',monospace;overflow:hidden;height:100vh;-webkit-user-select:none;-moz-user-select:none;user-select:none}
.viewer-header{position:fixed;top:0;left:0;right:0;background:rgba(15,13,11,.95);backdrop-filter:blur(10px);padding:.8rem 1.5rem;display:flex;justify-content:space-between;align-items:center;z-index:100;border-bottom:1px solid #2e2a25}
.file-info{font-size:.7rem;display:flex;align-items:center;gap:.8rem}
.file-info i{color:#c9a96e}
.file-info .fname{font-weight:700;font-size:.72rem}
.file-info .fmeta{color:#7a7268;font-size:.55rem;letter-spacing:.05em}
.controls{display:flex;align-items:center;gap:.8rem}
.timer-badge{background:rgba(201,169,110,.15);border:1px solid rgba(201,169,110,.3);padding:.25rem .7rem;font-size:.65rem;font-weight:700;color:#c9a96e;font-variant-numeric:tabular-nums}
.timer-badge.warning{background:rgba(201,110,110,.15);border-color:rgba(201,110,110,.3);color:#c96e6e}
.btn-v{background:transparent;border:1px solid #2e2a25;color:#7a7268;padding:.3rem .7rem;font-size:.55rem;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Mono',monospace;letter-spacing:.1em}
.btn-v:hover{border-color:#c9a96e;color:#c9a96e}
.viewer-container{position:fixed;top:50px;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;overflow:hidden}
#imageCanvas{max-width:100%;max-height:100%}
#videoPlayer{max-width:100%;max-height:100%}
#pdfFrame{width:100%;height:100%;border:none}
.watermark-overlay{position:fixed;top:50px;left:0;right:0;bottom:0;pointer-events:none;z-index:50;opacity:.05;overflow:hidden}
.watermark-text{font-size:14px;font-weight:700;color:#fff;white-space:nowrap;transform:rotate(-30deg);position:absolute;letter-spacing:2px}
.loading{text-align:center;color:#7a7268}
.loading i{font-size:2rem;color:#c9a96e;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.zoom-controls{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(15,13,11,.9);border:1px solid #2e2a25;padding:.4rem .8rem;display:flex;gap:.8rem;align-items:center;z-index:60}
.zoom-controls button{background:none;border:none;color:#c9a96e;font-size:1rem;cursor:pointer;padding:.2rem .4rem}
.zoom-controls button:hover{color:#e8e0d5}
.zoom-level{font-size:.65rem;min-width:45px;text-align:center;color:#7a7268}
</style>
</head>
<body oncontextmenu="return false" ondragstart="return false">

<div class="viewer-header">
    <div class="file-info">
        <i class="fas <?= $isImage ? 'fa-image' : ($isVideo ? 'fa-video' : 'fa-file-pdf') ?>"></i>
        <div>
            <div class="fname"><?= h($fichier['nom_original']) ?></div>
            <div class="fmeta"><?= CoffreFort::formatTaille($fichier['taille']) ?> — <?= t('Chiffré AES-256', 'Шифрование AES-256') ?></div>
        </div>
    </div>
    <div class="controls">
        <span class="timer-badge" id="timer" data-seconds="<?= $tempsRestant ?>">
            <i class="fas fa-clock"></i> <span id="timerText"><?= gmdate('i:s', $tempsRestant) ?></span>
        </span>
        <a href="<?= BASE_URL ?>/coffre_fort.php" class="btn-v"><i class="fas fa-arrow-left"></i> <?= t('Retour', 'Назад') ?></a>
    </div>
</div>

<div class="watermark-overlay" id="watermarkOverlay"></div>

<div class="viewer-container" id="viewerContainer">
    <div class="loading" id="loading">
        <i class="fas fa-circle-notch"></i>
        <p style="margin-top:1rem;font-size:.7rem"><?= t('Déchiffrement en cours…', 'Расшифровка…') ?></p>
    </div>
    <?php if ($isImage): ?>
        <canvas id="imageCanvas" style="display:none"></canvas>
    <?php elseif ($isVideo): ?>
        <video id="videoPlayer" style="display:none" controls controlsList="nodownload noremoteplayback" disablePictureInPicture oncontextmenu="return false"></video>
    <?php elseif ($isPdf): ?>
        <iframe id="pdfFrame" style="display:none" sandbox="allow-same-origin"></iframe>
    <?php endif; ?>
</div>

<?php if ($isImage): ?>
<div class="zoom-controls" id="zoomControls" style="display:none">
    <button onclick="zoomChange(-0.25)" title="Zoom -"><i class="fas fa-minus"></i></button>
    <span class="zoom-level" id="zoomLevel">100%</span>
    <button onclick="zoomChange(0.25)" title="Zoom +"><i class="fas fa-plus"></i></button>
    <button onclick="zoomReset()" title="Reset"><i class="fas fa-expand"></i></button>
</div>
<?php endif; ?>

<script>
// Anti-capture
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && (e.key==='s'||e.key==='p'||e.key==='u')) ||
        (e.ctrlKey && e.shiftKey && e.key==='I') ||
        e.key==='F12' || e.key==='PrintScreen') {
        e.preventDefault(); return false;
    }
});
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        const c = document.getElementById('imageCanvas');
        const v = document.getElementById('videoPlayer');
        if (c) c.style.filter = 'blur(20px)';
        if (v) v.pause();
    } else {
        const c = document.getElementById('imageCanvas');
        if (c) c.style.filter = 'none';
    }
});

// Watermark
(function() {
    const o = document.getElementById('watermarkOverlay');
    const t = <?= json_encode($watermark) ?>;
    let h = '';
    for (let y = -100; y < window.innerHeight + 200; y += 120)
        for (let x = -200; x < window.innerWidth + 400; x += 400)
            h += '<span class="watermark-text" style="left:'+x+'px;top:'+y+'px">'+t+'</span>';
    o.innerHTML = h;
})();

// Timer
const timerEl = document.getElementById('timer');
const timerText = document.getElementById('timerText');
let seconds = parseInt(timerEl.dataset.seconds);
setInterval(() => {
    seconds--;
    if (seconds <= 0) { window.location.href = '<?= BASE_URL ?>/coffre_fort.php'; return; }
    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
    const s = (seconds % 60).toString().padStart(2, '0');
    timerText.textContent = m + ':' + s;
    if (seconds < 120) timerEl.classList.add('warning');
}, 1000);

// Load content
const loading = document.getElementById('loading');
const streamUrl = 'coffre_fort_viewer.php?id=<?= $fichierId ?>&stream=1';

<?php if ($isImage): ?>
function showError(msg) {
    loading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#c96e6e;animation:none;font-size:2rem"></i><p style="margin-top:1rem;font-size:.7rem">'+msg+'</p><a href="<?= BASE_URL ?>/coffre_fort.php" class="btn-v" style="margin-top:1rem;display:inline-block"><?= t('Retour','Назад') ?></a>';
}
fetch(streamUrl)
    .then(r => r.json().then(data => ({ok:r.ok, data})))
    .then(({ok, data}) => {
        if (!ok || !data.data) { showError(data.error || 'Erreur'); return; }
        const img = new Image();
        img.onerror = () => showError('<?= t("Impossible de charger l\'image.","Не удалось загрузить изображение.") ?>');
        img.onload = function() {
            const canvas = document.getElementById('imageCanvas');
            const container = document.getElementById('viewerContainer');
            const maxW = container.clientWidth - 40;
            const maxH = container.clientHeight - 40;
            const ratio = Math.min(maxW / img.width, maxH / img.height, 1);
            canvas.width = img.width;
            canvas.height = img.height;
            canvas.style.width = (img.width * ratio) + 'px';
            canvas.style.height = (img.height * ratio) + 'px';
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            // Watermark on canvas
            ctx.save();
            ctx.globalAlpha = 0.04;
            ctx.fillStyle = '#ffffff';
            ctx.font = '24px system-ui';
            ctx.rotate(-0.5);
            const wText = <?= json_encode($watermark) ?>;
            for (let y = 0; y < img.height + 200; y += 100)
                for (let x = -200; x < img.width + 200; x += 500)
                    ctx.fillText(wText, x, y);
            ctx.restore();
            loading.style.display = 'none';
            canvas.style.display = 'block';
            document.getElementById('zoomControls').style.display = 'flex';
            window._zoom = ratio;
            window._baseRatio = ratio;
            window._imgW = img.width;
            window._imgH = img.height;
        };
        img.src = data.data;
    })
    .catch(err => showError('<?= t("Erreur réseau.","Ошибка сети.") ?> ('+err.message+')'));

function zoomChange(d) { window._zoom = Math.max(0.1, Math.min(5, window._zoom + d)); applyZoom(); }
function zoomReset() { window._zoom = window._baseRatio; applyZoom(); }
function applyZoom() {
    const c = document.getElementById('imageCanvas');
    c.style.width = (window._imgW * window._zoom) + 'px';
    c.style.height = (window._imgH * window._zoom) + 'px';
    document.getElementById('zoomLevel').textContent = Math.round(window._zoom / window._baseRatio * 100) + '%';
}

<?php elseif ($isVideo): ?>
const video = document.getElementById('videoPlayer');
video.src = streamUrl;
video.addEventListener('loadeddata', () => { loading.style.display = 'none'; video.style.display = 'block'; });
video.addEventListener('error', () => { loading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#c96e6e;animation:none"></i><p style="margin-top:1rem;font-size:.7rem"><?= t("Erreur de lecture.","Ошибка воспроизведения.") ?></p>'; });
video.addEventListener('enterpictureinpicture', (e) => { e.preventDefault(); document.exitPictureInPicture(); });

<?php elseif ($isPdf): ?>
const frame = document.getElementById('pdfFrame');
frame.src = streamUrl;
frame.addEventListener('load', () => { loading.style.display = 'none'; frame.style.display = 'block'; });
<?php endif; ?>
</script>
</body>
</html>
