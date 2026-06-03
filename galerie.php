<?php
/**
 * galerie.php — Galerie photo protégée (intégrée au coffre-fort)
 * Requires active vault session (PIN verified)
 * Photos displayed via canvas with watermark, anti-capture measures
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

// Check vault session — redirect if not unlocked
$session = $coffre->verifierSession();
if (!$session) {
    header('Location: ' . BASE_URL . '/coffre_fort.php?from=galerie');
    exit;
}

$coffre->prolongerSession($session['token']);
$tempsRestant = $coffre->tempsRestant();
$watermark = strtoupper($user['display_name']) . ' — ' . date('d/m/Y H:i') . ' — ' . t('CONFIDENTIEL', 'КОНФИДЕНЦИАЛЬНО');

// AJAX: stream a single photo as base64
if (isset($_GET['stream']) && isset($_GET['id'])) {
    $s = $coffre->verifierSession();
    if (!$s) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('Session expirée', 'Сессия истекла')]);
        exit;
    }
    $fid = (int)$_GET['id'];
    $fichier = $coffre->getFichier($fid);
    if (!$fichier || $fichier['categorie'] !== 'photo' || !str_starts_with($fichier['type_mime'], 'image/')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('Photo introuvable', 'Фото не найдено')]);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $b64 = $coffre->streamImageBase64($fid, $userId);
    if (!$b64) {
        http_response_code(500);
        echo json_encode(['error' => $coffre->lastError ?? t('Erreur de déchiffrement', 'Ошибка расшифровки')]);
        exit;
    }
    echo json_encode(['data' => $b64]);
    exit;
}

// AJAX: stream thumbnail (same as full for now, but could be optimized)
if (isset($_GET['thumb']) && isset($_GET['id'])) {
    $s = $coffre->verifierSession();
    if (!$s) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('Session expirée', 'Сессия истекла')]);
        exit;
    }
    $fid = (int)$_GET['id'];
    $fichier = $coffre->getFichier($fid);
    if (!$fichier || $fichier['categorie'] !== 'photo' || !str_starts_with($fichier['type_mime'], 'image/')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('Photo introuvable', 'Фото не найдено')]);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $b64 = $coffre->streamImageBase64($fid, $userId);
    if (!$b64) {
        http_response_code(500);
        echo json_encode(['error' => $coffre->lastError ?? t('Erreur', 'Ошибка')]);
        exit;
    }
    echo json_encode(['data' => $b64]);
    exit;
}

// Get all photos
$filtreTag = trim($_GET['tag'] ?? '');
$photos = $coffre->lister('photo', $filtreTag);

// Collect unique tags for filter
$allTags = [];
foreach ($photos as $p) {
    if ($p['tags']) {
        foreach (explode(',', $p['tags']) as $tag) {
            $tag = trim($tag);
            if ($tag && !in_array($tag, $allTags)) $allTags[] = $tag;
        }
    }
}
sort($allTags);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Galerie', 'Галерея') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--red:#c96e6e;--green:#6ec98a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;-webkit-user-select:none;-moz-user-select:none;user-select:none}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}

.wrap{max-width:1200px;margin:0 auto;padding:2rem}

/* Session bar */
.session-bar{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1.2rem;border:1px solid var(--border);margin-bottom:1.5rem;font-size:.65rem}
.timer{font-weight:700;font-size:.9rem;color:var(--accent);font-variant-numeric:tabular-nums}
.timer.warning{color:var(--red)}

/* Header area */
.galerie-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
.galerie-header h1{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;font-style:italic;color:var(--accent)}
.galerie-header .count{font-size:.65rem;color:var(--muted);letter-spacing:.1em;margin-top:.3rem}

/* Tag filters */
.tag-filters{display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:1.5rem}
.pill{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .7rem;text-decoration:none;transition:all .2s;cursor:pointer}
.pill:hover{border-color:var(--accent);color:var(--accent)}
.pill.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Photo grid */
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.8rem}
@media(max-width:500px){.photo-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.5rem}}

.photo-cell{position:relative;aspect-ratio:1;background:var(--s);border:1px solid var(--border);overflow:hidden;cursor:pointer;transition:all .3s}
.photo-cell:hover{border-color:var(--accent);transform:scale(1.02)}
.photo-cell canvas{width:100%;height:100%;object-fit:cover;display:block}
.photo-cell .photo-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted)}
.photo-cell .photo-loading i{font-size:1.2rem;color:var(--accent);animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.photo-cell .photo-info{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.85));padding:.8rem .6rem .5rem;opacity:0;transition:opacity .3s}
.photo-cell:hover .photo-info{opacity:1}
.photo-info .photo-name{font-size:.6rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.photo-info .photo-date{font-size:.5rem;color:var(--muted);margin-top:.15rem;letter-spacing:.05em}
.photo-info .photo-tags{margin-top:.3rem}
.photo-info .photo-tags span{font-size:.45rem;letter-spacing:.08em;border:1px solid rgba(201,169,110,.3);padding:.1rem .3rem;color:var(--accent);margin-right:.2rem}

.empty{text-align:center;padding:4rem;font-size:.72rem;color:var(--muted)}
.empty i{font-size:3rem;display:block;margin-bottom:1rem;color:var(--border)}

/* ═══════════ LIGHTBOX ═══════════ */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:1000;display:none;align-items:center;justify-content:center;flex-direction:column}
.lightbox.active{display:flex}
.lightbox-close{position:absolute;top:1.2rem;right:1.5rem;background:transparent;border:1px solid rgba(255,255,255,.2);color:#fff;width:40px;height:40px;font-size:1.2rem;cursor:pointer;transition:all .2s;z-index:1010;display:flex;align-items:center;justify-content:center}
.lightbox-close:hover{border-color:var(--accent);color:var(--accent)}
.lightbox-nav{position:absolute;top:50%;transform:translateY(-50%);background:transparent;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.6);width:50px;height:50px;font-size:1.2rem;cursor:pointer;transition:all .2s;z-index:1010;display:flex;align-items:center;justify-content:center}
.lightbox-nav:hover{border-color:var(--accent);color:var(--accent);background:rgba(201,169,110,.1)}
.lightbox-nav.prev{left:1rem}
.lightbox-nav.next{right:1rem}
.lightbox-canvas-wrap{max-width:calc(100vw - 120px);max-height:calc(100vh - 100px);display:flex;align-items:center;justify-content:center;position:relative}
.lightbox-canvas-wrap canvas{max-width:100%;max-height:calc(100vh - 100px)}
.lightbox-loading{color:var(--muted);text-align:center}
.lightbox-loading i{font-size:2rem;color:var(--accent);animation:spin 1s linear infinite}
.lightbox-info{position:absolute;bottom:1.2rem;left:50%;transform:translateX(-50%);text-align:center;font-size:.6rem;color:var(--muted);letter-spacing:.08em;z-index:1010;background:rgba(0,0,0,.7);padding:.4rem 1rem;border:1px solid rgba(255,255,255,.08)}
.lightbox-watermark{position:absolute;inset:0;pointer-events:none;z-index:1005;opacity:.04;overflow:hidden}
.lightbox-watermark span{font-size:14px;font-weight:700;color:#fff;white-space:nowrap;transform:rotate(-30deg);position:absolute;letter-spacing:2px}
</style>
</head>
<body oncontextmenu="return false" ondragstart="return false">

<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title">📸 <?= t('Galerie','Галерея') ?></div>
    <span style="font-size:.6rem;color:var(--green);letter-spacing:.1em"><i class="fas fa-lock-open"></i> <?= t('Protégé', 'Защищено') ?></span>
</div>

<div class="wrap">

<!-- Session bar -->
<div class="session-bar">
    <div>
        <i class="fas fa-clock" style="color:var(--muted);margin-right:.3rem"></i>
        <?= t('Session active —', 'Активная сессия —') ?>
        <span class="timer" id="timer" data-seconds="<?= $tempsRestant ?>"><?= gmdate('i:s', $tempsRestant) ?></span>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        <a href="<?= BASE_URL ?>/coffre_fort.php" class="btn" style="font-size:.5rem;padding:.25rem .5rem"><i class="fas fa-upload"></i> <?= t('Ajouter des photos', 'Добавить фото') ?></a>
    </div>
</div>

<!-- Header -->
<div class="galerie-header">
    <div>
        <h1><i class="fas fa-camera-retro" style="font-size:1.5rem;margin-right:.5rem"></i><?= t('Galerie Photos', 'Фотогалерея') ?></h1>
        <div class="count"><?= count($photos) ?> <?= t('photos dans le coffre-fort', 'фото в сейфе') ?></div>
    </div>
</div>

<!-- Tag filters -->
<?php if (!empty($allTags)): ?>
<div class="tag-filters">
    <a href="galerie.php" class="pill <?= !$filtreTag ? 'active' : '' ?>"><?= t('Toutes', 'Все') ?></a>
    <?php foreach ($allTags as $tag): ?>
    <a href="?tag=<?= urlencode($tag) ?>" class="pill <?= $filtreTag === $tag ? 'active' : '' ?>"><?= h($tag) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Photo Grid -->
<?php if (empty($photos)): ?>
<div class="empty">
    <i class="fas fa-images"></i>
    <?= t('Aucune photo dans le coffre-fort.', 'В сейфе нет фотографий.') ?><br>
    <a href="<?= BASE_URL ?>/coffre_fort.php" class="btn" style="margin-top:1rem"><?= t('Ajouter des photos', 'Добавить фото') ?></a>
</div>
<?php else: ?>
<div class="photo-grid" id="photoGrid">
    <?php foreach ($photos as $i => $p): ?>
    <div class="photo-cell" data-id="<?= $p['id'] ?>" data-index="<?= $i ?>" data-name="<?= h($p['nom_original']) ?>" data-date="<?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>" onclick="openLightbox(<?= $i ?>)">
        <div class="photo-loading" id="loading-<?= $p['id'] ?>"><i class="fas fa-circle-notch"></i></div>
        <canvas id="thumb-<?= $p['id'] ?>" style="display:none"></canvas>
        <div class="photo-info">
            <div class="photo-name"><?= h($p['nom_original']) ?></div>
            <div class="photo-date"><?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
            <?php if ($p['tags']): ?>
            <div class="photo-tags">
                <?php foreach (explode(',', $p['tags']) as $tag): ?>
                <span><?= h(trim($tag)) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox">
    <button class="lightbox-close" id="lbClose" title="<?= t('Fermer', 'Закрыть') ?>"><i class="fas fa-times"></i></button>
    <?php if (count($photos) > 1): ?>
    <button class="lightbox-nav prev" id="lbPrev" title="<?= t('Précédent', 'Предыдущее') ?>"><i class="fas fa-chevron-left"></i></button>
    <button class="lightbox-nav next" id="lbNext" title="<?= t('Suivant', 'Следующее') ?>"><i class="fas fa-chevron-right"></i></button>
    <?php endif; ?>
    <div class="lightbox-watermark" id="lbWatermark"></div>
    <div class="lightbox-canvas-wrap">
        <div class="lightbox-loading" id="lbLoading" style="display:none">
            <i class="fas fa-circle-notch"></i>
            <p style="margin-top:1rem;font-size:.7rem"><?= t('Déchiffrement…', 'Расшифровка…') ?></p>
        </div>
        <canvas id="lbCanvas" style="display:none"></canvas>
    </div>
    <div class="lightbox-info" id="lbInfo"></div>
</div>

<script>
// ═══════════ Anti-capture ═══════════
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && (e.key==='s'||e.key==='p'||e.key==='u'||e.key==='S'||e.key==='P'||e.key==='U')) ||
        (e.ctrlKey && e.shiftKey && (e.key==='I'||e.key==='i')) ||
        e.key==='F12' || e.key==='PrintScreen') {
        e.preventDefault(); return false;
    }
});
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        document.querySelectorAll('canvas').forEach(c => c.style.filter = 'blur(20px)');
    } else {
        document.querySelectorAll('canvas').forEach(c => c.style.filter = 'none');
    }
});

// ═══════════ Timer ═══════════
const timerEl = document.getElementById('timer');
if (timerEl) {
    let seconds = parseInt(timerEl.dataset.seconds);
    setInterval(() => {
        seconds--;
        if (seconds <= 0) { window.location.href = '<?= BASE_URL ?>/coffre_fort.php'; return; }
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        timerEl.textContent = m + ':' + s;
        if (seconds < 120) timerEl.classList.add('warning');
    }, 1000);
}

// ═══════════ Photo data ═══════════
const photos = <?= json_encode(array_map(fn($p) => [
    'id' => $p['id'],
    'name' => $p['nom_original'],
    'date' => date('d/m/Y H:i', strtotime($p['created_at'])),
    'description' => $p['description'] ?? '',
], $photos)) ?>;

const watermarkText = <?= json_encode($watermark) ?>;
const imageCache = {}; // id -> base64 data URI

// ═══════════ Load thumbnails ═══════════
function loadThumb(photo) {
    if (imageCache[photo.id]) return;
    fetch('galerie.php?thumb=1&id=' + photo.id)
        .then(r => r.json())
        .then(data => {
            if (!data.data) return;
            imageCache[photo.id] = data.data;
            const img = new Image();
            img.onload = function() {
                const canvas = document.getElementById('thumb-' + photo.id);
                if (!canvas) return;
                // Draw as square crop (cover)
                const size = Math.min(img.width, img.height);
                const sx = (img.width - size) / 2;
                const sy = (img.height - size) / 2;
                canvas.width = 400;
                canvas.height = 400;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, sx, sy, size, size, 0, 0, 400, 400);
                // Mini watermark on thumb
                ctx.save();
                ctx.globalAlpha = 0.03;
                ctx.fillStyle = '#ffffff';
                ctx.font = '14px system-ui';
                ctx.rotate(-0.5);
                for (let y = 0; y < 500; y += 80)
                    for (let x = -100; x < 500; x += 300)
                        ctx.fillText(watermarkText, x, y);
                ctx.restore();
                const loadEl = document.getElementById('loading-' + photo.id);
                if (loadEl) loadEl.style.display = 'none';
                canvas.style.display = 'block';
            };
            img.src = data.data;
        })
        .catch(() => {});
}

// Lazy-load thumbnails with IntersectionObserver
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const id = parseInt(entry.target.dataset.id);
            const photo = photos.find(p => p.id === id);
            if (photo) loadThumb(photo);
            observer.unobserve(entry.target);
        }
    });
}, { rootMargin: '200px' });

document.querySelectorAll('.photo-cell').forEach(cell => observer.observe(cell));

// ═══════════ Lightbox ═══════════
let currentIndex = -1;
const lightbox = document.getElementById('lightbox');
const lbCanvas = document.getElementById('lbCanvas');
const lbLoading = document.getElementById('lbLoading');
const lbInfo = document.getElementById('lbInfo');
const lbWatermark = document.getElementById('lbWatermark');

// Generate watermark grid
(function() {
    let h = '';
    for (let y = -100; y < 2000; y += 120)
        for (let x = -200; x < 2000; x += 400)
            h += '<span style="left:'+x+'px;top:'+y+'px">'+watermarkText+'</span>';
    lbWatermark.innerHTML = h;
})();

function openLightbox(index) {
    currentIndex = index;
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
    showPhoto(index);
}

function closeLightbox() {
    lightbox.classList.remove('active');
    document.body.style.overflow = '';
    lbCanvas.style.display = 'none';
    currentIndex = -1;
}

function showPhoto(index) {
    if (index < 0 || index >= photos.length) return;
    currentIndex = index;
    const photo = photos[index];
    lbCanvas.style.display = 'none';
    lbLoading.style.display = 'block';
    lbInfo.textContent = photo.name + ' — ' + photo.date;

    function render(dataUri) {
        const img = new Image();
        img.onload = function() {
            const maxW = window.innerWidth - 120;
            const maxH = window.innerHeight - 100;
            const ratio = Math.min(maxW / img.width, maxH / img.height, 1);
            lbCanvas.width = img.width;
            lbCanvas.height = img.height;
            lbCanvas.style.width = (img.width * ratio) + 'px';
            lbCanvas.style.height = (img.height * ratio) + 'px';
            const ctx = lbCanvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            // Watermark on canvas
            ctx.save();
            ctx.globalAlpha = 0.04;
            ctx.fillStyle = '#ffffff';
            ctx.font = '24px system-ui';
            ctx.rotate(-0.5);
            for (let y = 0; y < img.height + 200; y += 100)
                for (let x = -200; x < img.width + 200; x += 500)
                    ctx.fillText(watermarkText, x, y);
            ctx.restore();
            lbLoading.style.display = 'none';
            lbCanvas.style.display = 'block';
        };
        img.onerror = function() {
            lbLoading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--red);animation:none;font-size:2rem"></i><p style="margin-top:1rem;font-size:.7rem"><?= t("Erreur de chargement.","Ошибка загрузки.") ?></p>';
        };
        img.src = dataUri;
    }

    if (imageCache[photo.id]) {
        render(imageCache[photo.id]);
    } else {
        fetch('galerie.php?stream=1&id=' + photo.id)
            .then(r => r.json())
            .then(data => {
                if (data.data) {
                    imageCache[photo.id] = data.data;
                    render(data.data);
                } else {
                    lbLoading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--red);animation:none"></i><p style="margin-top:1rem;font-size:.7rem">' + (data.error || <?= json_encode(t('Erreur','Ошибка')) ?>) + '</p>';
                }
            })
            .catch(err => {
                lbLoading.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--red);animation:none"></i><p style="margin-top:1rem;font-size:.7rem"><?= t("Erreur réseau.","Ошибка сети.") ?></p>';
            });
    }
}

function navPrev() { if (currentIndex > 0) showPhoto(currentIndex - 1); else showPhoto(photos.length - 1); }
function navNext() { if (currentIndex < photos.length - 1) showPhoto(currentIndex + 1); else showPhoto(0); }

// Event listeners
document.getElementById('lbClose').addEventListener('click', closeLightbox);
const prevBtn = document.getElementById('lbPrev');
const nextBtn = document.getElementById('lbNext');
if (prevBtn) prevBtn.addEventListener('click', navPrev);
if (nextBtn) nextBtn.addEventListener('click', navNext);

// Click outside to close
lightbox.addEventListener('click', function(e) {
    if (e.target === lightbox || e.target === lbWatermark) closeLightbox();
});

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    if (!lightbox.classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') navPrev();
    if (e.key === 'ArrowRight') navNext();
});
</script>
</body>
</html>
