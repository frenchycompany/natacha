<?php
/**
 * Barre de navigation mobile fixe (bottom tab bar).
 * À inclure juste avant </body> sur les pages applicatives authentifiées.
 *
 * - Aucune dépendance externe (emojis natifs, cohérent avec le reste de l'app, offline-ready PWA).
 * - Palette codée en dur ici pour ne dépendre d'aucune variable CSS de la page hôte.
 * - Labels bilingues via t() (FR/RU), dispo après requireLogin().
 * - Pour changer les onglets : éditer le tableau $BNAV_TABS ci-dessous, rien d'autre.
 */

// Page courante (pour l'état actif)
$bnav_cur = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

// key => [href, emoji, label_fr, label_ru, center, matches[]]
$BNAV_TABS = [
    'accueil'  => ['dashboard.php', '🏠', 'Accueil',  'Главная', false, ['dashboard.php']],
    'histoire' => ['histoire.php',  '📖', 'Histoire', 'История', false, ['histoire.php']],
    'couple'   => ['couple.php',    '❤',  'Couple',   'Пара',    true,  ['couple.php']],
    'jeux'     => ['jeux.php',      '🎲', 'Jeux',     'Игры',    false, ['jeux.php','jeux_action_verite.php','jeux_connaissance.php','jeux_couple_quiz.php','jeux_quiz.php','defis.php','questionnaires.php']],
    'carte'    => ['carte.php',     '🗺', 'Carte',    'Карта',   false, ['carte.php']],
];

// helper t() peut ne pas exister sur certaines pages très spécifiques : fallback FR
if (!function_exists('bnav_t')) {
    function bnav_t($fr, $ru) {
        return function_exists('t') ? t($fr, $ru) : $fr;
    }
}
?>
<style id="bnav-style">
:root{--bnav-h:62px}
body{padding-bottom:calc(var(--bnav-h) + env(safe-area-inset-bottom, 0px) + 6px)}
.bnav{position:fixed;left:0;right:0;bottom:0;z-index:90;display:flex;align-items:flex-end;justify-content:space-around;
  height:calc(var(--bnav-h) + env(safe-area-inset-bottom, 0px));padding:0 4px env(safe-area-inset-bottom, 0px);
  background:rgba(15,13,11,.97);border-top:1px solid #2e2a25;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  font-family:'DM Mono',monospace}
.bnav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;height:var(--bnav-h);
  text-decoration:none;color:#7a7268;transition:color .2s;-webkit-tap-highlight-color:transparent;position:relative}
.bnav-item:active{transform:scale(.94)}
.bnav-ico{font-size:1.2rem;line-height:1}
.bnav-lbl{font-size:.5rem;letter-spacing:.08em;text-transform:uppercase}
.bnav-item.active{color:#c9a96e}
.bnav-item.active .bnav-dot{position:absolute;bottom:7px;width:4px;height:4px;border-radius:50%;background:#c9a96e}
/* Onglet central surélevé */
.bnav-item.center .bnav-ico{width:46px;height:46px;margin-top:-22px;border-radius:50%;
  background:rgba(201,169,110,.12);border:1px solid #c9a96e;color:#c9a96e;
  display:flex;align-items:center;justify-content:center;font-size:1.25rem;
  box-shadow:0 -2px 14px rgba(201,169,110,.18);transition:background .2s}
.bnav-item.center.active .bnav-ico,.bnav-item.center:active .bnav-ico{background:rgba(201,169,110,.22)}
.bnav-item.center .bnav-lbl{color:#c9a96e}
@media(min-width:760px){.bnav{max-width:480px;margin:0 auto;border-left:1px solid #2e2a25;border-right:1px solid #2e2a25}}
</style>
<nav class="bnav" aria-label="<?= h(bnav_t('Navigation principale','Основная навигация')) ?>">
<?php foreach ($BNAV_TABS as $key => $tab):
    [$href, $emoji, $lfr, $lru, $center, $matches] = $tab;
    $active = in_array($bnav_cur, $matches, true);
?>
  <a class="bnav-item<?= $center ? ' center' : '' ?><?= $active ? ' active' : '' ?>"
     href="<?= BASE_URL ?>/<?= $href ?>"<?= $active ? ' aria-current="page"' : '' ?>>
    <span class="bnav-ico"><?= $emoji ?></span>
    <span class="bnav-lbl"><?= h(bnav_t($lfr, $lru)) ?></span>
    <?php if ($active && !$center): ?><span class="bnav-dot"></span><?php endif; ?>
  </a>
<?php endforeach; ?>
</nav>
