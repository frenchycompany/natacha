<?php
/**
 * NATACHA — Page Couple (Avatar vivant)
 * Le coeur émotionnel de l'app : l'entité couple avec ses jauges,
 * son humeur, son évolution, et ses suggestions.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
require_once __DIR__.'/includes/couple_helper.php';

$user = currentUser();
$lang = $user['lang'] ?? 'fr';
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) {
    header('Location: '.BASE_URL.'/signup.php');
    exit;
}

$ce = new CoupleEntity(db());

// Ensure quick_actions table
try { db()->query("SELECT 1 FROM couple_quick_actions LIMIT 1"); } catch (Exception $e) {
    db()->exec("CREATE TABLE IF NOT EXISTS couple_quick_actions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        couple_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(200) NOT NULL,
        emoji VARCHAR(10) DEFAULT '📝',
        content_translated VARCHAR(200) DEFAULT NULL,
        content_lang CHAR(2) DEFAULT 'fr',
        photo TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_couple_date (couple_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// POST: add quick action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_birth_date') {
        $date = $_POST['birth_date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
            db()->prepare("UPDATE couples SET birth_date=? WHERE id=?")->execute([$date, $coupleId]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => t('Date invalide','Неверная дата')]);
        }
        exit;
    }

    if ($action === 'add_moment') {
        $content = trim($_POST['content'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '📝');
        if ($content && mb_strlen($content) <= 200) {
            // Handle multiple photo uploads
            $photos = [];
            if (!empty($_FILES['photos']['name'][0])) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $dir = __DIR__.'/uploads/moments/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $count = min(count($_FILES['photos']['name']), 5); // Max 5 photos
                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK
                        && in_array($_FILES['photos']['type'][$i], $allowed)
                        && $_FILES['photos']['size'][$i] <= 5*1024*1024) {
                        $ext = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION) ?: 'jpg';
                        $fname = 'moment_'.time().'_'.bin2hex(random_bytes(4)).'_'.$i.'.'.$ext;
                        move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dir.$fname);
                        $photos[] = $fname;
                    }
                }
            }
            $photoJson = !empty($photos) ? json_encode($photos) : null;
            db()->prepare("INSERT INTO couple_quick_actions (couple_id, user_id, content, emoji, photo) VALUES (?,?,?,?,?)")
                ->execute([$coupleId, $user['id'], $content, $emoji, $photoJson]);
            // Record couple activity
            $ce->recordActivity($coupleId, $user['id'], 'mot',
                $user['display_name'].': '.$content,
                $user['display_name'].': '.$content);
            // Auto-translate
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $translated = translateText($content, $fromLang, $toLang);
            $newId = db()->lastInsertId();
            if ($translated) {
                db()->prepare("UPDATE couple_quick_actions SET content_translated=?, content_lang=? WHERE id=?")
                    ->execute([$translated, $fromLang, $newId]);
            }

            // Notify partner
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($user['id'], 'moment',
                    $user['display_name'].': '.$content,
                    $user['display_name'].': '.$content,
                    BASE_URL.'/couple.php');
            } catch (Exception $e) {}

            // Check badges
            try {
                require_once __DIR__.'/includes/badge_checker.php';
                $newBadges = checkAndAwardBadges($user['id'], $coupleId);
            } catch (Exception $e) {}

            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

// Apply daily decay
$ce->applyDailyDecay($coupleId);

// Get couple data
$couple = $ce->getCouple($coupleId);
if (!$couple) {
    header('Location: '.BASE_URL.'/signup.php');
    exit;
}

$suggestion = $ce->getSuggestion($couple, $lang);
$activities = $ce->getRecentActivities($coupleId, 8);

// Today's moments
$todayMoments = db()->prepare("SELECT qa.*, u.display_name FROM couple_quick_actions qa
    JOIN users u ON u.id=qa.user_id WHERE qa.couple_id=? AND DATE(qa.created_at)=CURDATE()
    ORDER BY qa.created_at DESC");
$todayMoments->execute([$coupleId]);
$todayMoments = $todayMoments->fetchAll();

$avatarSvg = $ce->getAvatarSvg($couple['level'], $couple['mood']);
$moodLabel = CoupleEntity::getMoodLabel($couple['mood'], $lang);
$moodEmoji = CoupleEntity::getMoodEmoji($couple['mood']);

// Level info — with FR/RU support
$levelNamesRu = [1=>'Искра',2=>'Пламя',3=>'Корни',4=>'Дерево',5=>'Лес',6=>'Легенда'];
$lvl = $couple['level'] ?? 1;
$levelName = $lang === 'ru'
    ? ($couple['level_name_ru'] ?? $levelNamesRu[$lvl] ?? 'Искра')
    : ($couple['level_name_fr'] ?? 'Étincelle');
$levelEmoji = $couple['level_emoji'] ?? '🌱';

// Age
$ageLabel = ($lang === 'ru') ? $couple['age_label_ru'] : $couple['age_label_fr'];

// Notifications
$unreadNotifs = 0;
try {
    require_once __DIR__.'/includes/notifications.php';
    $unreadNotifs = getUnreadCount($user['id']);
} catch (Exception $e) {}

// Gauge labels
$gaugeLabels = [
    'communication' => $lang==='ru'?'Общение':'Communication',
    'adventure'     => $lang==='ru'?'Приключения':'Aventure',
    'tenderness'    => $lang==='ru'?'Нежность':'Tendresse',
    'surprise'      => $lang==='ru'?'Сюрприз':'Surprise',
    'complicity'    => $lang==='ru'?'Близость':'Complicité',
];

// Gauge colors based on value
function gaugeColor(int $val): string {
    if ($val >= 70) return '#6ec96e';
    if ($val >= 40) return '#c9a96e';
    return '#c96e6e';
}

// Activity type icons
$actIcons = [
    'histoire'=>'📖','defi'=>'🎯','jeu'=>'🎮','lieu'=>'📍',
    'mot'=>'💌','photo'=>'📸','calendrier'=>'📅','musique'=>'🎵',
    'film'=>'🎬','questionnaire'=>'❓','reaction'=>'❤️','gratitude'=>'🙏'
];

// Navigation items — only top 4 quick actions
$navItems = [
    ['href'=>'gratitude.php','icon'=>'🙏','label'=>$lang==='ru'?'Спасибо':'Merci'],
    ['href'=>'jeux.php','icon'=>'🎮','label'=>$lang==='ru'?'Игры':'Jeux'],
    ['href'=>'defis.php','icon'=>'🎯','label'=>$lang==='ru'?'Вызовы':'Défis'],
    ['href'=>'histoire.php','icon'=>'📖','label'=>$lang==='ru'?'История':'Histoire'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Natacha — <?= h($couple['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;padding-bottom:5rem;font-size:14px}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

/* ── Topbar ── */
.topbar{position:sticky;top:0;z-index:50;background:var(--bg);border-bottom:1px solid var(--border);padding:.8rem 1.5rem;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--accent)}
.topbar-right{display:flex;gap:1rem;align-items:center}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-btn{background:none;border:none;color:var(--muted);font-size:1rem;cursor:pointer;position:relative;text-decoration:none}
.topbar-btn .badge{position:absolute;top:-4px;right:-6px;background:var(--accent);color:var(--bg);font-size:.45rem;padding:1px 4px;border-radius:8px;font-family:'DM Mono',monospace}

.wrap{max-width:480px;margin:0 auto;padding:1rem 1.5rem}

/* ── Avatar section ── */
.avatar-section{text-align:center;padding:2rem 0 1.5rem}
.avatar-container{width:160px;height:160px;margin:0 auto 1.5rem;position:relative}
.avatar-container svg{width:100%;height:100%;animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.avatar-level-badge{position:absolute;bottom:-5px;left:50%;transform:translateX(-50%);background:var(--s);border:1px solid var(--border);padding:.2rem .6rem;font-size:.55rem;color:var(--accent);letter-spacing:.1em;white-space:nowrap}
.couple-name{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.3rem}
.couple-age{font-size:.65rem;color:var(--muted);letter-spacing:.1em;margin-bottom:.3rem}
.couple-mood{font-size:.7rem;color:var(--text);display:flex;align-items:center;justify-content:center;gap:.4rem}

/* ── Gauges ── */
.gauges{margin:2rem 0}
.gauges-title{font-size:.55rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem}
.gauge-row{display:flex;align-items:center;gap:.6rem;margin-bottom:.7rem}
.gauge-icon{font-size:.8rem;width:20px;text-align:center}
.gauge-label{font-size:.6rem;letter-spacing:.08em;color:var(--muted);width:90px;flex-shrink:0}
.gauge-track{flex:1;height:6px;background:var(--border);position:relative;overflow:hidden}
.gauge-fill{height:100%;transition:width 1.2s cubic-bezier(.4,0,.2,1)}
.gauge-val{font-size:.6rem;width:32px;text-align:right;font-weight:400}
.gauge-warning{animation:pulseWarn 2s infinite}
@keyframes pulseWarn{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Suggestion card ── */
.suggestion{border:1px solid var(--border);padding:1.2rem;margin:1.5rem 0;position:relative;overflow:hidden;transition:border-color .3s}
.suggestion:hover{border-color:var(--accent)}
.suggestion::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;background:var(--accent)}
.suggestion-icon{font-size:1.5rem;margin-bottom:.5rem}
.suggestion-text{font-size:.72rem;color:var(--text);line-height:1.7;margin-bottom:.8rem}
.suggestion-btn{display:inline-block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);text-decoration:none;border:1px solid var(--accent);padding:.4rem .8rem;transition:all .2s}
.suggestion-btn:hover{background:var(--as)}

/* ── Quick actions grid ── */
.actions-title{font-size:.55rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin:2rem 0 1rem}
.actions-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem}
.action-card{border:1px solid var(--border);padding:1rem .5rem;text-align:center;text-decoration:none;transition:all .25s;cursor:pointer}
.action-card:hover{border-color:var(--accent);transform:translateY(-2px);background:var(--as)}
.action-icon{font-size:1.3rem;margin-bottom:.4rem}
.action-label{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.action-card:hover .action-label{color:var(--accent)}

/* ── Activity feed ── */
.feed{margin:2rem 0}
.feed-title{font-size:.55rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem}
.feed-item{display:flex;gap:.8rem;padding:.6rem 0;border-bottom:1px solid var(--border)}
.feed-item:last-child{border-bottom:none}
.feed-icon{font-size:.9rem;margin-top:.1rem}
.feed-content{flex:1}
.feed-text{font-size:.68rem;color:var(--text);line-height:1.5}
.feed-meta{font-size:.55rem;color:var(--muted);margin-top:.2rem}
.feed-empty{font-size:.68rem;color:var(--muted);font-style:italic;text-align:center;padding:2rem 0}

/* ── XP bar ── */
.xp-section{margin:1.5rem 0;text-align:center}
.xp-label{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
.xp-bar{height:4px;background:var(--border);max-width:200px;margin:0 auto}
.xp-fill{height:100%;background:var(--accent);transition:width 1s}
.xp-text{font-size:.55rem;color:var(--accent);margin-top:.3rem}

/* ── Moments du jour ── */
.moments-section{margin:2rem 0}
.moments-title{font-size:.55rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem}
.moment-add{margin-bottom:1rem}
.emoji-picker{display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.5rem}
.emoji-opt{background:none;border:1px solid transparent;font-size:1rem;cursor:pointer;padding:.2rem .3rem;transition:all .2s;border-radius:2px}
.emoji-opt:hover,.emoji-opt.active{border-color:var(--accent);background:var(--as)}
.moment-input-row{display:flex;align-items:center;gap:.4rem}
.moment-emoji-display{font-size:1.2rem;width:28px;text-align:center}
.moment-input{flex:1;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:.5rem .7rem;outline:none;transition:border .2s}
.moment-input:focus{border-color:var(--accent)}
.moment-input::placeholder{color:var(--muted)}
.moment-send{background:var(--as);border:1px solid var(--accent);color:var(--accent);font-size:.9rem;width:32px;height:32px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace}
.moment-send:hover{background:var(--accent);color:var(--bg)}
.moment-send:disabled{opacity:.3;cursor:not-allowed}
.moments-list{display:flex;flex-direction:column;gap:.4rem}
.moment-item{display:flex;align-items:flex-start;gap:.6rem;padding:.5rem .7rem;border:1px solid var(--border);background:var(--s);transition:border-color .2s}
.moment-item:hover{border-color:var(--accent)}
.moment-icon{font-size:.9rem;margin-top:.1rem}
.moment-content{flex:1;min-width:0}
.moment-text{font-size:.68rem;color:var(--text);line-height:1.5}
.moment-meta{font-size:.48rem;color:var(--muted);display:block;margin-top:.15rem}
.moment-trad{font-size:.55rem;color:var(--muted);font-style:italic;display:block;margin-top:.1rem;opacity:.7}
.moment-photo-btn{font-size:1rem;cursor:pointer;padding:.2rem;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;width:32px;height:32px;transition:all .2s}
.moment-photo-btn:hover{border-color:var(--accent)}
.moment-photo{max-width:100%;max-height:150px;border:1px solid var(--border);margin-top:.3rem;display:block}
.moment-photos{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.3rem}
.moment-photos .moment-photo{max-width:calc(50% - .15rem);max-height:120px;object-fit:cover}

/* ── Bottom nav ── */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--bg);border-top:1px solid var(--border);display:flex;justify-content:space-around;padding:.5rem 0;padding-bottom:max(.5rem,env(safe-area-inset-bottom));z-index:50}
.bnav-item{display:flex;flex-direction:column;align-items:center;gap:.2rem;text-decoration:none;color:var(--muted);font-size:.5rem;letter-spacing:.08em;transition:color .2s;padding:.2rem .5rem}
.bnav-item.active,.bnav-item:hover{color:var(--accent)}
.bnav-icon{font-size:1.2rem}

/* ── Members ── */
.members{display:flex;justify-content:center;gap:1.5rem;margin:.8rem 0}
.member{display:flex;align-items:center;gap:.4rem;font-size:.65rem;color:var(--muted)}
.member-dot{width:6px;height:6px;border-radius:50%;background:var(--accent)}

@media(max-width:400px){
    .actions-grid{grid-template-columns:repeat(3,1fr)}
}
</style>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
<div class="topbar">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= $lang==='ru'?'Главная':'Accueil' ?></a>
    <div class="topbar-title"><?= h($couple['name']) ?></div>
    <div class="topbar-right">
        <a href="<?= BASE_URL ?>/notifications.php" class="topbar-btn">
            🔔<?php if($unreadNotifs>0): ?><span class="badge"><?= $unreadNotifs ?></span><?php endif; ?>
        </a>
    </div>
</div>

<div class="wrap">

    <!-- ═══ AVATAR ═══ -->
    <section class="avatar-section">
        <div class="avatar-container">
            <?= $avatarSvg ?>
            <div class="avatar-level-badge"><?= $levelEmoji ?> <?= h($levelName) ?></div>
        </div>
        <div class="couple-name"><?= h($couple['name']) ?></div>
        <div class="couple-age">
            <?= h($ageLabel) ?>
            <span id="dateEditBtn" style="cursor:pointer;opacity:.4;margin-left:.3rem;transition:opacity .2s" onmouseenter="this.style.opacity='1'" onmouseleave="this.style.opacity='.4'" onclick="document.getElementById('dateEditor').style.display='flex';this.style.display='none'">✏</span>
        </div>
        <div id="dateEditor" style="display:none;justify-content:center;align-items:center;gap:.4rem;margin-top:.3rem">
            <input type="date" id="birthDateInput" value="<?= h($couple['birth_date'] ?? '') ?>" style="background:var(--s);border:1px solid var(--border);color:var(--accent);font-family:'DM Mono',monospace;font-size:.6rem;padding:.25rem .4rem;outline:none">
            <button onclick="saveBirthDate()" style="background:var(--as);border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.55rem;padding:.25rem .5rem;cursor:pointer">OK</button>
            <button onclick="document.getElementById('dateEditor').style.display='none';document.getElementById('dateEditBtn').style.display=''" style="background:transparent;border:1px solid var(--border);color:var(--muted);font-family:'DM Mono',monospace;font-size:.55rem;padding:.25rem .5rem;cursor:pointer"><?= $lang==='ru'?'✗':'✗' ?></button>
        </div>
        <div class="couple-mood"><?= $moodEmoji ?> <?= h($moodLabel) ?></div>

        <?php if (count($couple['members']) > 0): ?>
        <div class="members">
            <?php foreach ($couple['members'] as $m): ?>
            <div class="member"><span class="member-dot"></span> <?= h($m['display_name']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$couple['invite_accepted']): ?>
        <div style="margin-top:.5rem;font-size:.6rem;color:var(--muted);border:1px dashed var(--border);padding:.5rem;cursor:pointer" onclick="document.getElementById('inviteReminder').style.display='block';this.style.display='none'">
            ⏳ <?= $lang==='ru'?'Ожидание партнёра...':'En attente de votre partenaire...' ?>
        </div>
        <div id="inviteReminder" style="display:none;margin-top:.5rem;font-size:.6rem;color:var(--accent);word-break:break-all;padding:.5rem;border:1px dashed var(--accent)">
            <?= h((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].BASE_URL.'/invite.php?code='.$couple['invite_code']) ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ═══ GAUGES ═══ -->
    <section class="gauges">
        <div class="gauges-title"><?= $lang==='ru'?'Потребности':'Besoins' ?></div>
        <?php
        $gaugeIcons = ['communication'=>'💬','adventure'=>'🗺️','tenderness'=>'💕','surprise'=>'🎁','complicity'=>'🤝'];
        $gaugeKeys = ['communication','adventure','tenderness','surprise','complicity'];
        foreach ($gaugeKeys as $gk):
            $val = $couple['gauge_'.$gk];
            $color = gaugeColor($val);
            $warn = $val < 30;
        ?>
        <div class="gauge-row">
            <span class="gauge-icon"><?= $gaugeIcons[$gk] ?></span>
            <span class="gauge-label"><?= $gaugeLabels[$gk] ?></span>
            <div class="gauge-track">
                <div class="gauge-fill" style="width:<?= $val ?>%;background:<?= $color ?>"></div>
            </div>
            <span class="gauge-val <?= $warn?'gauge-warning':'' ?>" style="color:<?= $color ?>"><?= $val ?>%</span>
        </div>
        <?php endforeach; ?>
    </section>

    <!-- ═══ SUGGESTION ═══ -->
    <section class="suggestion">
        <div class="suggestion-icon"><?= $suggestion['icon'] ?></div>
        <div class="suggestion-text"><?= h($suggestion['text']) ?></div>
        <?php
        $actionLinks = [
            'histoire'=>'histoire.php','carte'=>'carte.php','mot'=>'dashboard.php',
            'defis'=>'defis.php','jeux'=>'jeux.php'
        ];
        $link = $actionLinks[$suggestion['action']] ?? 'dashboard.php';
        ?>
        <a href="<?= BASE_URL ?>/<?= $link ?>" class="suggestion-btn">
            <?= $lang==='ru'?'Перейти':'Y aller' ?> →
        </a>
    </section>

    <!-- ═══ MOMENTS DU JOUR ═══ -->
    <section class="moments-section">
        <div class="moments-title"><?= $lang==='ru'?'Моменты дня':'Moments du jour' ?></div>

        <!-- Quick add -->
        <div class="moment-add">
            <div class="emoji-picker">
                <?php
                $quickEmojis = ['🌿','🍽️','🎬','🏃','☕','🛍️','🎵','❤️','📚','🏖️','🎮','🧹'];
                foreach ($quickEmojis as $em): ?>
                <button class="emoji-opt" data-emoji="<?= $em ?>" onclick="pickEmoji(this)"><?= $em ?></button>
                <?php endforeach; ?>
            </div>
            <div class="moment-input-row">
                <span class="moment-emoji-display" id="selectedEmoji">📝</span>
                <input type="text" class="moment-input" id="momentInput" maxlength="200"
                    placeholder="<?= $lang==='ru'?'Что мы делали сегодня...':'Ce qu\'on a fait aujourd\'hui...' ?>">
                <label class="moment-photo-btn" title="<?= $lang==='ru'?'Фото':'Photo' ?>">
                    📷
                    <input type="file" id="momentPhoto" accept="image/*" multiple style="display:none" onchange="photosSelected(this)">
                </label>
                <button class="moment-send" id="momentSend" onclick="addMoment()" disabled>+</button>
            </div>
            <div id="photoPreview" style="display:none;margin-top:.4rem;display:flex;gap:.3rem;flex-wrap:wrap"></div>
        </div>

        <!-- Today's moments list -->
        <?php if (!empty($todayMoments)): ?>
        <div class="moments-list">
            <?php foreach ($todayMoments as $m): ?>
            <div class="moment-item">
                <span class="moment-icon"><?= h($m['emoji']) ?></span>
                <div class="moment-content">
                    <?php
                    $mLang = $m['content_lang'] ?? 'fr';
                    $mText = ($lang !== $mLang && !empty($m['content_translated'])) ? $m['content_translated'] : $m['content'];
                    $mAlt = ($lang !== $mLang && !empty($m['content_translated'])) ? $m['content'] : ($m['content_translated'] ?? '');
                    ?>
                    <span class="moment-text"><?= h($mText) ?></span>
                    <?php if ($mAlt && $mAlt !== $mText): ?>
                    <span class="moment-trad"><?= h($mAlt) ?></span>
                    <?php endif; ?>
                    <span class="moment-meta"><?= h($m['display_name']) ?> · <?= date('H:i', strtotime($m['created_at'])) ?></span>
                    <?php
                    $mPhotos = [];
                    if (!empty($m['photo'])) {
                        $decoded = json_decode($m['photo'], true);
                        $mPhotos = is_array($decoded) ? $decoded : [$m['photo']]; // Backwards compat
                    }
                    if (!empty($mPhotos)): ?>
                    <div class="moment-photos">
                        <?php foreach ($mPhotos as $ph): ?>
                        <img src="<?= BASE_URL ?>/api/moment_photo.php?f=<?= h($ph) ?>" class="moment-photo" alt="">
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ═══ XP ═══ -->
    <section class="xp-section">
        <?php
        // Calculate XP progress to next level
        $nextLevelXp = [1=>500,2=>1500,3=>4000,4=>10000,5=>25000,6=>99999];
        $currentLevelXp = [1=>0,2=>500,3=>1500,4=>4000,5=>10000,6=>25000];
        $xpForCurrent = $currentLevelXp[$couple['level']] ?? 0;
        $xpForNext = $nextLevelXp[$couple['level']] ?? 99999;
        $xpProgress = min(100, max(0, (($couple['xp'] - $xpForCurrent) / max(1, $xpForNext - $xpForCurrent)) * 100));
        ?>
        <div class="xp-label"><?= $lang==='ru'?'Опыт':'Expérience' ?></div>
        <div class="xp-bar"><div class="xp-fill" style="width:<?= round($xpProgress) ?>%"></div></div>
        <div class="xp-text"><?= number_format($couple['xp']) ?> XP</div>
    </section>

    <!-- ═══ QUICK ACTIONS ═══ -->
    <div class="actions-title"><?= $lang==='ru'?'Заботиться о паре':'Nourrir votre couple' ?></div>
    <div class="actions-grid">
        <?php foreach ($navItems as $nav): ?>
        <a href="<?= BASE_URL ?>/<?= $nav['href'] ?>" class="action-card">
            <div class="action-icon"><?= $nav['icon'] ?></div>
            <div class="action-label"><?= $nav['label'] ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ═══ ACTIVITY FEED ═══ -->
    <section class="feed">
        <div class="feed-title"><?= $lang==='ru'?'Последняя активность':'Activité récente' ?></div>
        <?php if (empty($activities)): ?>
            <div class="feed-empty"><?= $lang==='ru'
                ?'Пока нет активности. Начните заботиться о '.$couple['name'].' !'
                :'Aucune activité pour le moment. Commencez à prendre soin de '.$couple['name'].' !' ?></div>
        <?php else: ?>
            <?php foreach ($activities as $act): ?>
            <div class="feed-item">
                <div class="feed-icon"><?= $actIcons[$act['activity_type']] ?? '📌' ?></div>
                <div class="feed-content">
                    <div class="feed-text">
                        <strong><?= h($act['display_name']) ?></strong>
                        — <?= h($lang==='ru' ? ($act['description_en'] ?? $act['description_fr'] ?? $act['activity_type']) : ($act['description_fr'] ?? $act['activity_type'])) ?>
                    </div>
                    <div class="feed-meta">
                        +<?= $act['xp_earned'] ?> XP
                        · <?= date('d/m H:i', strtotime($act['created_at'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</div>

<!-- ═══ BOTTOM NAV ═══ -->
<nav class="bottom-nav">
    <a href="<?= BASE_URL ?>/couple.php" class="bnav-item active">
        <span class="bnav-icon"><?= $levelEmoji ?></span>
        <?= h($couple['name']) ?>
    </a>
    <a href="<?= BASE_URL ?>/gratitude.php" class="bnav-item">
        <span class="bnav-icon">📝</span>
        <?= $lang==='ru'?'Журнал':'Journal' ?>
    </a>
    <a href="<?= BASE_URL ?>/jeux.php" class="bnav-item">
        <span class="bnav-icon">🎮</span>
        <?= $lang==='ru'?'Игры':'Jeux' ?>
    </a>
    <a href="<?= BASE_URL ?>/livre_secret.php" class="bnav-item">
        <span class="bnav-icon">🌹</span>
        <?= $lang==='ru'?'Интим':'Intime' ?>
    </a>
    <a href="<?= BASE_URL ?>/dashboard.php" class="bnav-item">
        <span class="bnav-icon">☰</span>
        <?= $lang==='ru'?'Ещё':'Plus' ?>
    </a>
</nav>

<script>
let selectedEmoji = '📝';
const momentInput = document.getElementById('momentInput');
const momentSend = document.getElementById('momentSend');

momentInput.addEventListener('input', () => {
    momentSend.disabled = momentInput.value.trim().length < 2;
});
momentInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !momentSend.disabled) addMoment();
});

function pickEmoji(btn) {
    document.querySelectorAll('.emoji-opt').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedEmoji = btn.dataset.emoji;
    document.getElementById('selectedEmoji').textContent = selectedEmoji;
}

function addMoment() {
    const content = momentInput.value.trim();
    if (!content) return;
    momentSend.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fd.append('action', 'add_moment');
    fd.append('content', content);
    fd.append('emoji', selectedEmoji);
    const photoFiles = document.getElementById('momentPhoto').files;
    for (let i = 0; i < Math.min(photoFiles.length, 5); i++) {
        fd.append('photos[]', photoFiles[i]);
    }
    fetch('<?= BASE_URL ?>/couple.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) location.reload();
            else momentSend.disabled = false;
        })
        .catch(() => { momentSend.disabled = false; });
}

function photosSelected(input) {
    const preview = document.getElementById('photoPreview');
    preview.innerHTML = '';
    if (input.files.length > 0) {
        preview.style.display = 'flex';
        Array.from(input.files).slice(0, 5).forEach((file, i) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const wrap = document.createElement('div');
                wrap.style.cssText = 'position:relative';
                wrap.innerHTML = '<img src="'+e.target.result+'" style="max-height:50px;border:1px solid var(--border)">';
                preview.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
    } else {
        preview.style.display = 'none';
    }
}
function removePhoto() {
    document.getElementById('momentPhoto').value = '';
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPreview').innerHTML = '';
}

function saveBirthDate() {
    const date = document.getElementById('birthDateInput').value;
    if (!date) return;
    const fd = new FormData();
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fd.append('action', 'update_birth_date');
    fd.append('birth_date', date);
    fetch('<?= BASE_URL ?>/couple.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
}
</script>

</body>
</html>
