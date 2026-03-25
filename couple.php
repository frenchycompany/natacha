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
    header('Location: '.BASE_URL.'/signup.php');
    exit;
}

$ce = new CoupleEntity(db());

// Apply daily decay
$ce->applyDailyDecay($coupleId);

// Get couple data
$couple = $ce->getCouple($coupleId);
if (!$couple) {
    header('Location: '.BASE_URL.'/signup.php');
    exit;
}

$suggestion = $ce->getSuggestion($couple, $lang === 'ru' ? 'fr' : $lang);
$activities = $ce->getRecentActivities($coupleId, 8);
$avatarSvg = $ce->getAvatarSvg($couple['level'], $couple['mood']);
$moodLabel = CoupleEntity::getMoodLabel($couple['mood'], $lang === 'ru' ? 'fr' : $lang);
$moodEmoji = CoupleEntity::getMoodEmoji($couple['mood']);

// Level info
$levelNameKey = ($lang === 'en') ? 'level_name_en' : 'level_name_fr';
$levelName = $couple[$levelNameKey] ?? $couple['level_name_fr'] ?? 'Étincelle';
$levelEmoji = $couple['level_emoji'] ?? '🌱';

// Age
$ageLabel = ($lang === 'en') ? $couple['age_label_en'] : $couple['age_label_fr'];

// Notifications
$unreadNotifs = 0;
try {
    require_once __DIR__.'/includes/notifications.php';
    $unreadNotifs = getUnreadCount($user['id']);
} catch (Exception $e) {}

// Gauge labels
$gaugeLabels = [
    'communication' => $lang==='fr'?'Communication':'Communication',
    'adventure'     => $lang==='fr'?'Aventure':'Adventure',
    'tenderness'    => $lang==='fr'?'Tendresse':'Tenderness',
    'surprise'      => $lang==='fr'?'Surprise':'Surprise',
    'complicity'    => $lang==='fr'?'Complicité':'Complicity',
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
    'film'=>'🎬','questionnaire'=>'❓'
];

// Navigation items
$navItems = [
    ['href'=>'histoire.php','icon'=>'📖','label'=>$lang==='fr'?'Histoire':'Story'],
    ['href'=>'jeux.php','icon'=>'🎮','label'=>$lang==='fr'?'Jeux':'Games'],
    ['href'=>'defis.php','icon'=>'🎯','label'=>$lang==='fr'?'Défis':'Challenges'],
    ['href'=>'gratitude.php','icon'=>'🙏','label'=>$lang==='fr'?'Merci':'Thanks'],
    ['href'=>'reves_projets.php','icon'=>'✨','label'=>$lang==='fr'?'Rêves':'Dreams'],
    ['href'=>'livre_secret.php','icon'=>'🌹','label'=>$lang==='fr'?'Secret':'Secret'],
    ['href'=>'calendrier.php','icon'=>'📅','label'=>$lang==='fr'?'Calendrier':'Calendar'],
    ['href'=>'carte.php','icon'=>'📍','label'=>$lang==='fr'?'Carte':'Map'],
    ['href'=>'medias.php','icon'=>'🎵','label'=>$lang==='fr'?'Médias':'Media'],
    ['href'=>'coffre_fort.php','icon'=>'🔒','label'=>$lang==='fr'?'Coffre':'Vault'],
    ['href'=>'profil.php','icon'=>'👤','label'=>$lang==='fr'?'Profil':'Profile'],
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
.topbar-btn{background:none;border:none;color:var(--muted);font-size:1rem;cursor:pointer;position:relative}
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
    <div class="topbar-title">Natacha</div>
    <div class="topbar-right">
        <a href="<?= BASE_URL ?>/notifications.php" class="topbar-btn">
            🔔<?php if($unreadNotifs>0): ?><span class="badge"><?= $unreadNotifs ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/dashboard.php?logout" class="topbar-btn">↗</a>
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
        <div class="couple-age"><?= h($ageLabel) ?></div>
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
            ⏳ <?= $lang==='fr'?'En attente de votre partenaire...':'Waiting for your partner...' ?>
        </div>
        <div id="inviteReminder" style="display:none;margin-top:.5rem;font-size:.6rem;color:var(--accent);word-break:break-all;padding:.5rem;border:1px dashed var(--accent)">
            <?= h((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].BASE_URL.'/invite.php?code='.$couple['invite_code']) ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ═══ GAUGES ═══ -->
    <section class="gauges">
        <div class="gauges-title"><?= $lang==='fr'?'Besoins':'Needs' ?></div>
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
            <?= $lang==='fr'?'Y aller':'Go' ?> →
        </a>
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
        <div class="xp-label"><?= $lang==='fr'?'Expérience':'Experience' ?></div>
        <div class="xp-bar"><div class="xp-fill" style="width:<?= round($xpProgress) ?>%"></div></div>
        <div class="xp-text"><?= number_format($couple['xp']) ?> XP</div>
    </section>

    <!-- ═══ QUICK ACTIONS ═══ -->
    <div class="actions-title"><?= $lang==='fr'?'Nourrir votre couple':'Feed your couple' ?></div>
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
        <div class="feed-title"><?= $lang==='fr'?'Activité récente':'Recent activity' ?></div>
        <?php if (empty($activities)): ?>
            <div class="feed-empty"><?= $lang==='fr'
                ?'Aucune activité pour le moment. Commencez à prendre soin de '.$couple['name'].' !'
                :'No activity yet. Start taking care of '.$couple['name'].'!' ?></div>
        <?php else: ?>
            <?php foreach ($activities as $act): ?>
            <div class="feed-item">
                <div class="feed-icon"><?= $actIcons[$act['activity_type']] ?? '📌' ?></div>
                <div class="feed-content">
                    <div class="feed-text">
                        <strong><?= h($act['display_name']) ?></strong>
                        — <?= h($act[$lang==='en'?'description_en':'description_fr'] ?? $act['description_fr'] ?? $act['activity_type']) ?>
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
    <a href="<?= BASE_URL ?>/histoire.php" class="bnav-item">
        <span class="bnav-icon">📖</span>
        <?= $lang==='fr'?'Histoire':'Story' ?>
    </a>
    <a href="<?= BASE_URL ?>/jeux.php" class="bnav-item">
        <span class="bnav-icon">🎮</span>
        <?= $lang==='fr'?'Jeux':'Games' ?>
    </a>
    <a href="<?= BASE_URL ?>/defis.php" class="bnav-item">
        <span class="bnav-icon">🎯</span>
        <?= $lang==='fr'?'Défis':'Challenges' ?>
    </a>
    <a href="<?= BASE_URL ?>/dashboard.php" class="bnav-item">
        <span class="bnav-icon">☰</span>
        <?= $lang==='fr'?'Plus':'More' ?>
    </a>
</nav>

</body>
</html>
