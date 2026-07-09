<?php
/**
 * NATACHA — Photo du jour
 * Chaque partenaire poste UNE photo de sa journée (façon BeReal pour couples).
 * Quand les deux ont posté aujourd'hui, ils voient leurs photos côte à côte.
 * Les jours passés sont affichés en timeline.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'] ?? 'fr';

// Refresh couple_id from DB (session may be stale)
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $s = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $s->execute([$user['id']]);
    $coupleId = $s->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

// Ensure table
try { db()->query("SELECT 1 FROM album_jour LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS album_jour (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        couple_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        photo VARCHAR(255) NOT NULL,
        caption VARCHAR(200) DEFAULT NULL,
        entry_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_date (user_id, entry_date),
        INDEX idx_couple_date (couple_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Partner info
$partner = db()->prepare("SELECT id, display_name, avatar FROM users WHERE couple_id=? AND id!=? LIMIT 1");
$partner->execute([$coupleId, $user['id']]);
$partner = $partner->fetch();

$uploadDir = __DIR__.'/uploads/album/';

// ─── POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrfVerify()) { echo json_encode(['ok'=>false,'error'=>t('Session expirée','Сессия истекла')]); exit; }
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');

    if ($action === 'upload') {
        if (!isset($_FILES['photo'])) { echo json_encode(['ok'=>false,'error'=>t('Aucune photo','Нет фото')]); exit; }

        $mimeToExt = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'error'=>t('Erreur de téléversement','Ошибка загрузки')]); exit;
        }
        if ($_FILES['photo']['size'] > 8*1024*1024) {
            echo json_encode(['ok'=>false,'error'=>t('Fichier trop volumineux (max 8 Mo)','Файл слишком большой (макс. 8 МБ)')]); exit;
        }
        if (!is_uploaded_file($_FILES['photo']['tmp_name'])) {
            echo json_encode(['ok'=>false,'error'=>t('Téléversement invalide','Недопустимая загрузка')]); exit;
        }
        $realMime = $finfo->file($_FILES['photo']['tmp_name']);
        if (!isset($mimeToExt[$realMime])) {
            echo json_encode(['ok'=>false,'error'=>t('Ce fichier n\'est pas une image valide','Этот файл не является допустимым изображением')]); exit;
        }
        $ext = $mimeToExt[$realMime];
        $fname = 'album_'.$coupleId.'_'.$user['id'].'_'.date('Ymd').'_'.bin2hex(random_bytes(4)).'.'.$ext;

        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir.$fname)) {
            echo json_encode(['ok'=>false,'error'=>t('Impossible d\'enregistrer la photo','Не удалось сохранить фото')]); exit;
        }

        $caption = trim($_POST['caption'] ?? '');
        if (mb_strlen($caption) > 200) $caption = mb_substr($caption, 0, 200);
        if ($caption === '') $caption = null;

        // Is this the first upload of the day (not a replace)?
        $existing = db()->prepare("SELECT photo FROM album_jour WHERE user_id=? AND entry_date=?");
        $existing->execute([$user['id'], $today]);
        $oldPhoto = $existing->fetchColumn();
        $isFirst = ($oldPhoto === false);

        db()->prepare("INSERT INTO album_jour (couple_id, user_id, photo, caption, entry_date)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE photo=VALUES(photo), caption=VALUES(caption), created_at=NOW()")
            ->execute([$coupleId, $user['id'], $fname, $caption, $today]);

        // Remove the previous file if it was replaced
        if ($oldPhoto && $oldPhoto !== $fname) {
            $old = $uploadDir.basename($oldPhoto);
            if (is_file($old)) @unlink($old);
        }

        // First upload of the day → record activity + notify partner
        if ($isFirst) {
            try {
                require_once __DIR__.'/includes/couple_helper.php';
                $ce = new CoupleEntity(db());
                $ce->recordActivity($coupleId, $user['id'], 'photo',
                    $user['display_name'].' a posté sa photo du jour',
                    $user['display_name'].' опубликовал(а) фото дня');
            } catch (Exception $e) {}
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($user['id'], 'album',
                    $user['display_name'].' a posté sa photo du jour',
                    $user['display_name'].' опубликовал(а) фото дня',
                    BASE_URL.'/album_jour.php');
            } catch (Exception $e) {}
        }

        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete') {
        $date = $_POST['date'] ?? $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok'=>false]); exit; }
        $row = db()->prepare("SELECT id, photo FROM album_jour WHERE user_id=? AND couple_id=? AND entry_date=?");
        $row->execute([$user['id'], $coupleId, $date]);
        $row = $row->fetch();
        if ($row) {
            db()->prepare("DELETE FROM album_jour WHERE id=? AND user_id=? AND couple_id=?")
                ->execute([$row['id'], $user['id'], $coupleId]);
            $f = $uploadDir.basename($row['photo']);
            if (is_file($f)) @unlink($f);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>t('Action inconnue','Неизвестное действие')]);
    exit;
}

// ─── GET render ───
$today = date('Y-m-d');

// My photo today
$myToday = db()->prepare("SELECT * FROM album_jour WHERE user_id=? AND entry_date=?");
$myToday->execute([$user['id'], $today]);
$myToday = $myToday->fetch();

// Partner photo today
$partnerToday = null;
if ($partner) {
    $pt = db()->prepare("SELECT * FROM album_jour WHERE user_id=? AND entry_date=?");
    $pt->execute([$partner['id'], $today]);
    $partnerToday = $pt->fetch();
}
$bothPostedToday = $myToday && $partnerToday;

// Timeline: past days (not today), most recent 30 dates
$dates = db()->prepare("SELECT DISTINCT entry_date FROM album_jour
    WHERE couple_id=? AND entry_date < ? ORDER BY entry_date DESC LIMIT 30");
$dates->execute([$coupleId, $today]);
$dates = $dates->fetchAll(PDO::FETCH_COLUMN);

$timeline = [];
if ($dates) {
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $rows = db()->prepare("SELECT * FROM album_jour WHERE couple_id=? AND entry_date IN ($ph)");
    $rows->execute(array_merge([$coupleId], $dates));
    foreach ($rows->fetchAll() as $r) {
        $timeline[$r['entry_date']][$r['user_id']] = $r;
    }
}

// Total count of days both posted
$totalDays = db()->prepare("SELECT COUNT(*) FROM (
    SELECT entry_date FROM album_jour WHERE couple_id=? GROUP BY entry_date HAVING COUNT(DISTINCT user_id) >= 2
) x");
$totalDays->execute([$coupleId]);
$totalDays = (int)$totalDays->fetchColumn();

$partnerName = $partner['display_name'] ?? t('ton/ta partenaire','твой партнёр');
$myName = $user['display_name'];

function albumDateLabel(string $date, string $today): string {
    $yesterday = (new DateTime($today))->modify('-1 day')->format('Y-m-d');
    if ($date === $today) return t('Aujourd\'hui','Сегодня');
    if ($date === $yesterday) return t('Hier','Вчера');
    return (new DateTime($date))->format('d/m/Y');
}
$photoUrl = BASE_URL.'/api/album_photo.php?f=';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Photo du jour','Фото дня') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.wrap{max-width:600px;margin:0 auto;padding:2rem 1.5rem}

.stats-bar{display:flex;justify-content:center;gap:2rem;margin-bottom:2rem;padding:1rem;border:1px solid var(--border);background:var(--s)}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--accent);text-align:center;line-height:1}
.stat-label{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-align:center;margin-top:.3rem}

.today-section{margin-bottom:2.5rem}
.today-prompt{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;font-style:italic;color:var(--accent);text-align:center;margin-bottom:.4rem}
.today-sub{text-align:center;font-size:.6rem;color:var(--muted);margin-bottom:1.4rem;letter-spacing:.08em}

/* Upload zone */
.upload-zone{border:1px dashed var(--border);background:var(--s);text-align:center;padding:2.5rem 1rem;cursor:pointer;transition:border-color .3s}
.upload-zone:hover{border-color:var(--accent)}
.upload-icon{font-size:2rem;margin-bottom:.6rem;opacity:.8}
.upload-label{font-size:.7rem;letter-spacing:.1em;color:var(--text)}
.upload-hint{font-size:.5rem;color:var(--muted);margin-top:.5rem;letter-spacing:.08em}
input[type=file]{display:none}
.caption-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-size:1rem;padding:.8rem 1rem;margin-top:.8rem;outline:none;transition:border .3s}
.caption-input:focus{border-color:var(--accent)}
.caption-input::placeholder{color:var(--muted);font-style:italic}
.preview-img{width:100%;max-height:340px;object-fit:cover;border:1px solid var(--border);margin-top:.8rem;display:block}

.btn{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn.filled{background:var(--accent);color:var(--bg)}
.btn.filled:hover{opacity:.85}
.btn.ghost{border-color:var(--border);color:var(--muted)}
.btn.ghost:hover{border-color:var(--accent);color:var(--accent);background:transparent}
.row-actions{display:flex;gap:.6rem;justify-content:center;margin-top:1rem;flex-wrap:wrap}

/* My posted card */
.my-photo-card{border:1px solid var(--border);background:var(--s);padding:1rem}
.my-photo-card img{width:100%;max-height:360px;object-fit:cover;display:block;border:1px solid var(--border)}
.photo-caption{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1rem;color:var(--text);margin-top:.7rem;text-align:center;line-height:1.6}
.photo-name{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);text-align:center;margin-top:.4rem}

/* Waiting state */
.waiting{text-align:center;border:1px solid var(--border);background:var(--s);padding:1.6rem 1rem;margin-top:1rem}
.waiting-icon{font-size:1.4rem;margin-bottom:.5rem;opacity:.7}
.waiting-text{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.05rem;color:var(--muted)}

/* Both revealed */
.reveal-title{font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--accent);text-align:center;font-size:1.15rem;margin:1.4rem 0 1rem}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.pair-cell{border:1px solid var(--border);background:var(--s);padding:.5rem;display:flex;flex-direction:column}
.pair-cell img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;border:1px solid var(--border)}
.pair-name{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);text-align:center;margin-top:.5rem}
.pair-cap{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:.85rem;color:var(--text);text-align:center;margin-top:.3rem;line-height:1.5}

/* Timeline */
.timeline-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent);margin:2.5rem 0 1.5rem;text-align:center}
.day-group{margin-bottom:1.6rem}
.day-date{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;padding-bottom:.3rem;border-bottom:1px solid var(--border)}
.day-pair{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.thumb-cell{border:1px solid var(--border);background:var(--s);padding:.4rem;display:flex;flex-direction:column}
.thumb-cell img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.thumb-name{font-size:.45rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);text-align:center;margin-top:.4rem}
.thumb-cap{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:.75rem;color:var(--muted);text-align:center;margin-top:.2rem;line-height:1.4}
.thumb-empty{border:1px dashed var(--border);background:var(--s);display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;flex-direction:column;padding:.4rem}
.thumb-empty-txt{font-size:.5rem;color:var(--muted);font-style:italic;text-align:center;font-family:'Cormorant Garamond',serif}
.empty{text-align:center;padding:3rem 1rem;font-size:.7rem;color:var(--muted);font-style:italic}

/* Lightbox */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:200;display:none;align-items:center;justify-content:center;padding:1rem;cursor:zoom-out}
.lightbox.open{display:flex}
.lightbox img{max-width:100%;max-height:90vh;object-fit:contain}

.success-msg{text-align:center;color:var(--accent);font-size:.7rem;margin-top:.8rem;display:none}

@media(max-width:600px){
    .topbar{padding:.8rem 1rem}
    .wrap{padding:1.5rem 1rem}
    .stats-bar{gap:1.5rem}
    .stat-num{font-size:1.4rem}
    .today-prompt{font-size:1.3rem}
}
</style>
</head>
<body>
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="topbar-title"><?= t('Photo du jour','Фото дня') ?></div>
    <span style="width:80px"></span>
</div>

<div class="wrap">

    <div class="stats-bar">
        <div>
            <div class="stat-num"><?= $totalDays ?></div>
            <div class="stat-label"><?= t('jours partagés','общих дней') ?></div>
        </div>
    </div>

    <!-- Today -->
    <div class="today-section">
        <div class="today-prompt"><?= t('Ta photo d\'aujourd\'hui','Твоё фото сегодня') ?></div>
        <div class="today-sub"><?= t('Une photo de ta journée, comme un instant partagé','Одно фото твоего дня, как общий момент') ?></div>

        <?php if ($bothPostedToday): ?>
            <!-- Both posted → reveal side by side -->
            <div class="reveal-title"><?= t('Vos photos du jour','Ваши фото дня') ?></div>
            <div class="pair">
                <div class="pair-cell">
                    <img src="<?= $photoUrl.h($myToday['photo']) ?>" alt="" onclick="lightbox(this.src)">
                    <div class="pair-name"><?= h($myName) ?> <?= t('(toi)','(ты)') ?></div>
                    <?php if ($myToday['caption']): ?><div class="pair-cap">&laquo; <?= h($myToday['caption']) ?> &raquo;</div><?php endif; ?>
                </div>
                <div class="pair-cell">
                    <img src="<?= $photoUrl.h($partnerToday['photo']) ?>" alt="" onclick="lightbox(this.src)">
                    <div class="pair-name"><?= h($partnerName) ?></div>
                    <?php if ($partnerToday['caption']): ?><div class="pair-cap">&laquo; <?= h($partnerToday['caption']) ?> &raquo;</div><?php endif; ?>
                </div>
            </div>
            <div class="row-actions">
                <button class="btn ghost" onclick="document.getElementById('replaceBlock').style.display='block';this.style.display='none'"><?= t('Remplacer ma photo','Заменить моё фото') ?></button>
                <button class="btn ghost" onclick="deletePhoto('<?= $today ?>')"><?= t('Supprimer','Удалить') ?></button>
            </div>
            <div id="replaceBlock" style="display:none;margin-top:1rem">
                <?php $showUploader = true; ?>
            </div>

        <?php elseif ($myToday): ?>
            <!-- Only I posted → waiting -->
            <div class="my-photo-card">
                <img src="<?= $photoUrl.h($myToday['photo']) ?>" alt="" onclick="lightbox(this.src)">
                <?php if ($myToday['caption']): ?><div class="photo-caption">&laquo; <?= h($myToday['caption']) ?> &raquo;</div><?php endif; ?>
                <div class="photo-name"><?= h($myName) ?> <?= t('(toi)','(ты)') ?></div>
            </div>
            <div class="waiting">
                <div class="waiting-icon">&#8987;</div>
                <div class="waiting-text"><?= t('En attente de','В ожидании') ?> <?= h($partnerName) ?>&hellip;</div>
            </div>
            <div class="row-actions">
                <button class="btn ghost" onclick="document.getElementById('replaceBlock').style.display='block';this.style.display='none'"><?= t('Remplacer ma photo','Заменить моё фото') ?></button>
                <button class="btn ghost" onclick="deletePhoto('<?= $today ?>')"><?= t('Supprimer','Удалить') ?></button>
            </div>
            <div id="replaceBlock" style="display:none;margin-top:1rem">
                <?php $showUploader = true; ?>
            </div>

        <?php else: ?>
            <!-- Not posted yet → uploader -->
            <?php $showUploader = true; ?>
        <?php endif; ?>

        <?php if (!empty($showUploader)): ?>
        <div id="uploaderInner">
            <label class="upload-zone" id="uploadZone" for="photoInput">
                <div class="upload-icon">&#128247;</div>
                <div class="upload-label"><?= t('Choisir une photo','Выбрать фото') ?></div>
                <div class="upload-hint"><?= t('Appareil photo ou galerie · max 8 Mo','Камера или галерея · макс. 8 МБ') ?></div>
            </label>
            <input type="file" id="photoInput" accept="image/*" capture="environment">
            <img id="previewImg" class="preview-img" style="display:none" alt="">
            <input type="text" class="caption-input" id="captionInput" maxlength="200" style="display:none"
                placeholder="<?= t('Une légende (facultatif)','Подпись (необязательно)') ?>">
            <div class="row-actions" id="uploadActions" style="display:none">
                <button class="btn filled" id="sendBtn" onclick="uploadPhoto()"><?= t('Publier','Опубликовать') ?></button>
                <button class="btn ghost" onclick="resetUploader()"><?= t('Annuler','Отмена') ?></button>
            </div>
            <div class="success-msg" id="successMsg"><?= t('Photo publiée','Фото опубликовано') ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Timeline -->
    <?php if (!empty($timeline)): ?>
    <div class="timeline-title"><?= t('Notre album','Наш альбом') ?></div>
    <?php foreach ($dates as $date):
        $day = $timeline[$date] ?? [];
        $mine = $day[$user['id']] ?? null;
        $theirs = $partner ? ($day[$partner['id']] ?? null) : null;
    ?>
    <div class="day-group">
        <div class="day-date"><?= albumDateLabel($date, $today) ?></div>
        <div class="day-pair">
            <?php if ($mine): ?>
            <div class="thumb-cell">
                <img src="<?= $photoUrl.h($mine['photo']) ?>" alt="" onclick="lightbox(this.src)">
                <div class="thumb-name"><?= h($myName) ?> <?= t('(toi)','(ты)') ?></div>
                <?php if ($mine['caption']): ?><div class="thumb-cap">&laquo; <?= h($mine['caption']) ?> &raquo;</div><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="thumb-empty"><div class="thumb-empty-txt"><?= h($myName) ?><br><?= t('pas de photo','нет фото') ?></div></div>
            <?php endif; ?>

            <?php if ($theirs): ?>
            <div class="thumb-cell">
                <img src="<?= $photoUrl.h($theirs['photo']) ?>" alt="" onclick="lightbox(this.src)">
                <div class="thumb-name"><?= h($partnerName) ?></div>
                <?php if ($theirs['caption']): ?><div class="thumb-cap">&laquo; <?= h($theirs['caption']) ?> &raquo;</div><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="thumb-empty"><div class="thumb-empty-txt"><?= h($partnerName) ?><br><?= t('pas de photo','нет фото') ?></div></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty"><?= t('Aucune photo pour l\'instant. Commencez votre album !','Пока нет фото. Начните свой альбом!') ?></div>
    <?php endif; ?>

</div>

<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
    <img id="lightboxImg" src="" alt="">
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
let selectedFile = null;

function lightbox(src){
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}

const photoInput = document.getElementById('photoInput');
if (photoInput) {
    photoInput.addEventListener('change', e => {
        const f = e.target.files[0];
        if (!f) return;
        if (f.size > 8*1024*1024) { alert(<?= json_encode(t('Fichier trop volumineux (max 8 Mo)','Файл слишком большой (макс. 8 МБ)')) ?>); return; }
        selectedFile = f;
        const url = URL.createObjectURL(f);
        const prev = document.getElementById('previewImg');
        prev.src = url; prev.style.display = 'block';
        document.getElementById('captionInput').style.display = 'block';
        document.getElementById('uploadActions').style.display = 'flex';
        document.getElementById('uploadZone').style.display = 'none';
    });
}

function resetUploader(){
    selectedFile = null;
    if (photoInput) photoInput.value = '';
    const prev = document.getElementById('previewImg');
    if (prev){ prev.src=''; prev.style.display='none'; }
    document.getElementById('captionInput').style.display='none';
    document.getElementById('captionInput').value='';
    document.getElementById('uploadActions').style.display='none';
    const z = document.getElementById('uploadZone');
    if (z) z.style.display='block';
}

function uploadPhoto(){
    if (!selectedFile) return;
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'upload');
    fd.append('photo', selectedFile);
    fd.append('caption', document.getElementById('captionInput').value.trim());
    fetch(BASE + '/album_jour.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('successMsg').style.display = 'block';
                setTimeout(() => location.reload(), 900);
            } else {
                alert(data.error || 'Error');
                btn.disabled = false;
            }
        })
        .catch(() => { btn.disabled = false; });
}

function deletePhoto(date){
    if (!confirm(<?= json_encode(t('Supprimer ta photo de ce jour ?','Удалить твоё фото за этот день?')) ?>)) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete');
    fd.append('date', date);
    fetch(BASE + '/album_jour.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(() => location.reload())
        .catch(() => location.reload());
}
</script>
<?php include __DIR__."/includes/bottom_nav.php"; ?>
</body>
</html>
