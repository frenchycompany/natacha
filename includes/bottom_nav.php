<?php
/**
 * Barre de navigation mobile fixe (bottom tab bar) — version éditoriale.
 * À inclure juste avant </body> sur les pages applicatives authentifiées.
 *
 * - Icônes SVG inline dessinées à la main (traits fins, cohérentes iOS/Android, offline PWA, zéro dépendance).
 * - Palette codée en dur ici pour ne dépendre d'aucune variable CSS de la page hôte.
 * - Labels bilingues via t() (FR/RU), dispo après requireLogin().
 * - Pour changer les onglets : éditer le tableau $BNAV_TABS ci-dessous, rien d'autre.
 */

$bnav_cur = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

// Icônes SVG (viewBox 24, trait = currentColor). Dessinées pour l'esthétique or/éditoriale.
$BNAV_ICONS = [
    'home'  => '<path d="M4 12l8-7 8 7"/><path d="M6 10.6V19a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-8.4"/><path d="M10.2 20v-4.4a1.8 1.8 0 0 1 3.6 0V20"/>',
    'book'  => '<path d="M12 6.6C9.7 5.1 6.4 5.1 4.4 6.1v12c2-1 5.3-1 7.6.5 2.3-1.5 5.6-1.5 7.6-.5v-12c-2-1-5.3-1-7.6.5Z"/><path d="M12 6.6V19.1"/>',
    'heart' => '<path d="M12 20.2C12 20.2 3.8 15.3 3.8 9.3 3.8 6.4 6 4.6 8.4 4.6c1.7 0 3.1 1.1 3.6 2 .5-.9 1.9-2 3.6-2 2.4 0 4.6 1.8 4.6 4.7 0 6-8.2 10.9-8.2 10.9Z"/>',
    'dice'  => '<rect x="4.6" y="4.6" width="14.8" height="14.8" rx="3.6"/><circle cx="9" cy="9" r="1.15" fill="currentColor" stroke="none"/><circle cx="15" cy="15" r="1.15" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.15" fill="currentColor" stroke="none"/>',
    'map'   => '<path d="M12 20.8s6.8-5.4 6.8-10.8a6.8 6.8 0 1 0-13.6 0c0 5.4 6.8 10.8 6.8 10.8Z"/><circle cx="12" cy="9.8" r="2.4"/>',
];

// key => [href, icone, label_fr, label_ru, center, matches[]]
$BNAV_TABS = [
    'accueil'  => ['dashboard.php', 'home',  'Accueil',  'Главная', false, ['dashboard.php']],
    'histoire' => ['histoire.php',  'book',  'Histoire', 'История', false, ['histoire.php']],
    'couple'   => ['couple.php',    'heart', 'Couple',   'Пара',    true,  ['couple.php']],
    'jeux'     => ['jeux.php',      'dice',  'Jeux',     'Игры',    false, ['jeux.php','jeux_action_verite.php','jeux_connaissance.php','jeux_couple_quiz.php','jeux_quiz.php','defis.php','questionnaires.php']],
    'carte'    => ['carte.php',     'map',   'Carte',    'Карта',   false, ['carte.php']],
];

if (!function_exists('bnav_t')) {
    function bnav_t($fr, $ru) { return function_exists('t') ? t($fr, $ru) : $fr; }
}
?>
<style id="bnav-style">
:root{--bnav-h:64px}
body{padding-bottom:calc(var(--bnav-h) + env(safe-area-inset-bottom, 0px) + 8px)}
.bnav{position:fixed;left:0;right:0;bottom:0;z-index:90;display:flex;align-items:flex-end;justify-content:space-around;
  height:calc(var(--bnav-h) + env(safe-area-inset-bottom, 0px));padding:0 6px env(safe-area-inset-bottom, 0px);
  background:rgba(15,13,11,.86);backdrop-filter:blur(14px) saturate(1.2);-webkit-backdrop-filter:blur(14px) saturate(1.2);
  font-family:'DM Mono',monospace;animation:bnavUp .45s cubic-bezier(.2,.8,.2,1) both}
.bnav::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(201,169,110,.55),transparent)}
@keyframes bnavUp{from{transform:translateY(105%)}to{transform:translateY(0)}}
.bnav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;height:var(--bnav-h);
  text-decoration:none;color:#6f675d;transition:color .25s;-webkit-tap-highlight-color:transparent;position:relative}
.bnav-item svg{width:23px;height:23px;display:block;fill:none;stroke:currentColor;stroke-width:1.5;
  stroke-linecap:round;stroke-linejoin:round;transition:transform .25s ease,filter .25s ease}
.bnav-item:active svg{transform:scale(.86)}
.bnav-lbl{font-size:.5rem;letter-spacing:.13em;text-transform:uppercase;transition:color .25s,letter-spacing .25s}
.bnav-item.active{color:#c9a96e}
.bnav-item.active svg{filter:drop-shadow(0 0 7px rgba(201,169,110,.45))}
.bnav-item.active .bnav-lbl{color:#c9a96e;letter-spacing:.18em}
/* point lumineux sous l'onglet actif */
.bnav-item.active:not(.center)::after{content:'';position:absolute;bottom:6px;width:4px;height:4px;border-radius:50%;
  background:#c9a96e;box-shadow:0 0 6px 1px rgba(201,169,110,.7);animation:bnavDot .3s ease both}
@keyframes bnavDot{from{opacity:0;transform:scale(0)}to{opacity:1;transform:scale(1)}}
/* Onglet central surélevé */
.bnav-item.center{justify-content:flex-end}
.bnav-item.center .bnav-ring{width:50px;height:50px;margin-top:-24px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:radial-gradient(circle at 50% 38%,rgba(201,169,110,.22),rgba(20,18,16,.95));
  border:1px solid rgba(201,169,110,.85);box-shadow:0 -3px 16px rgba(201,169,110,.22),inset 0 0 12px rgba(201,169,110,.12);
  color:#c9a96e;animation:bnavBreathe 4.5s ease-in-out infinite}
.bnav-item.center .bnav-ring svg{width:24px;height:24px}
.bnav-item.center.active .bnav-ring,.bnav-item.center:active .bnav-ring{
  background:radial-gradient(circle at 50% 38%,rgba(201,169,110,.4),rgba(20,18,16,.95))}
.bnav-item.center .bnav-lbl{color:#c9a96e;margin-top:4px}
@keyframes bnavBreathe{0%,100%{box-shadow:0 -3px 16px rgba(201,169,110,.18),inset 0 0 12px rgba(201,169,110,.1)}
  50%{box-shadow:0 -3px 24px rgba(201,169,110,.34),inset 0 0 14px rgba(201,169,110,.18)}}
@media(prefers-reduced-motion:reduce){.bnav,.bnav-item.center .bnav-ring,.bnav-item.active::after{animation:none}}
@media(min-width:760px){.bnav{max-width:480px;margin:0 auto;border-left:1px solid #2e2a25;border-right:1px solid #2e2a25}}
</style>
<nav class="bnav" aria-label="<?= h(bnav_t('Navigation principale','Основная навигация')) ?>">
<?php foreach ($BNAV_TABS as $key => $tab):
    [$href, $ico, $lfr, $lru, $center, $matches] = $tab;
    $active = in_array($bnav_cur, $matches, true);
    $svg = '<svg viewBox="0 0 24 24" aria-hidden="true">'.$BNAV_ICONS[$ico].'</svg>';
?>
  <a class="bnav-item<?= $center ? ' center' : '' ?><?= $active ? ' active' : '' ?>"
     href="<?= BASE_URL ?>/<?= $href ?>"<?= $active ? ' aria-current="page"' : '' ?>>
    <?php if ($center): ?>
      <span class="bnav-ring"><?= $svg ?></span>
    <?php else: ?>
      <?= $svg ?>
    <?php endif; ?>
    <span class="bnav-lbl"><?= h(bnav_t($lfr, $lru)) ?></span>
  </a>
<?php endforeach; ?>
</nav>
