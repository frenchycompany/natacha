<?php
require_once __DIR__.'/config.php';
securityHeaders();

function t404($fr, $ru) { return $fr . ' / ' . $ru; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — 404</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.container{text-align:center;padding:2rem}
.code{font-family:'Cormorant Garamond',serif;font-size:clamp(6rem,15vw,12rem);font-weight:300;color:var(--accent);line-height:1;margin-bottom:1rem}
.message{font-size:.75rem;color:var(--muted);letter-spacing:.1em;margin-bottom:2.5rem;line-height:1.8}
.back-link{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.45rem 1rem;transition:all .2s;display:inline-block}
.back-link:hover{border-color:var(--accent);color:var(--accent);background:var(--as)}
</style>
</head>
<body>
<div class="container">
  <div class="code">404</div>
  <div class="message">Page introuvable / Страница не найдена</div>
  <a class="back-link" href="<?= BASE_URL ?>/dashboard.php">← Accueil / Главная</a>
</div>
</body>
</html>
