<?php
require_once __DIR__.'/config.php';
requireLogin();
$user = currentUser();
$lang = $user['lang'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Nos Souvenirs','Наши Воспоминания') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1.2rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-left{display:flex;align-items:center;gap:1.2rem}
.logo{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-style:italic;color:var(--accent);text-decoration:none}
.back{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.25rem .55rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.wrap{max-width:900px;margin:0 auto;padding:3rem 2rem}
h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,3vw,2.2rem);font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.sub{font-size:.65rem;color:var(--muted);letter-spacing:.1em;margin-bottom:2rem}
.empty{text-align:center;padding:4rem 2rem;font-size:.75rem;color:var(--muted);border:1px dashed var(--border)}
.empty p{margin-bottom:.5rem}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-left">
    <a class="logo" href="<?= BASE_URL ?>/dashboard.php">💌 Natacha</a>
  </div>
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">← <?= t('Retour','Назад') ?></a>
</div>
<div class="wrap">
  <h2>📸 <?= t('Nos Souvenirs','Наши Воспоминания') ?></h2>
  <p class="sub"><?= t('Photos, moments et souvenirs partagés.','Фотографии, моменты и общие воспоминания.') ?></p>
  <div class="empty">
    <p><?= t('Aucun souvenir pour le moment.','Пока нет воспоминаний.') ?></p>
    <p><?= t('Cette section arrive bientôt !','Этот раздел скоро появится!') ?></p>
  </div>
</div>
</body>
</html>
