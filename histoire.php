<?php
require_once __DIR__.'/config.php';
requireLogin();
$user = currentUser();
$lang = $user['lang'];

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $titre   = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');
        $date    = $_POST['event_date'] ?? null;
        if ($titre && $contenu) {
            // Traduction automatique selon la langue de l'utilisateur
            $fromLang = $user['lang'] === 'ru' ? 'ru' : 'fr';
            $toLang   = $fromLang === 'fr' ? 'ru' : 'fr';
            $titre_traduit   = translateText($titre, $fromLang, $toLang);
            $contenu_traduit = translateText($contenu, $fromLang, $toLang);
            db()->prepare("INSERT INTO histoire_chapitres (user_id, titre, contenu, event_date, langue, titre_traduit, contenu_traduit) VALUES (?,?,?,?,?,?,?)")
               ->execute([$user['id'], $titre, $contenu, $date ?: null, $fromLang, $titre_traduit, $contenu_traduit]);
        }
        header('Location: '.BASE_URL.'/histoire.php'); exit;
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Seul l'auteur peut supprimer
        db()->prepare("DELETE FROM histoire_chapitres WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
        header('Location: '.BASE_URL.'/histoire.php'); exit;
    }
}

$view = $_GET['view'] ?? null;
$chapitre = null;
if ($view) {
    $s = db()->prepare("SELECT h.*, u.display_name, u.avatar FROM histoire_chapitres h JOIN users u ON u.id=h.user_id WHERE h.id=?");
    $s->execute([(int)$view]);
    $chapitre = $s->fetch();
}

$chapitres = db()->query("SELECT h.*, u.display_name, u.avatar FROM histoire_chapitres h JOIN users u ON u.id=h.user_id ORDER BY h.created_at DESC")->fetchAll();
$mode = $_GET['mode'] ?? 'list';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Notre Histoire','Наша История') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}
.wrap{max-width:760px;margin:0 auto;padding:2.5rem 2rem}

/* Liste */
.chap-item{padding:1.5rem 0;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr auto;gap:1rem;align-items:start}
.chap-avatar{width:32px;height:32px;border-radius:50%;background:var(--as);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--accent);flex-shrink:0;margin-top:.2rem}
.chap-meta{font-size:.6rem;letter-spacing:.1em;color:var(--muted);margin-bottom:.4rem}
.chap-titre{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:400;color:var(--text);margin-bottom:.3rem}
.chap-preview{font-size:.72rem;color:var(--muted);line-height:1.6;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.chap-actions{display:flex;flex-direction:column;gap:.3rem}
.btn-sm{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.25rem .5rem;cursor:pointer;text-decoration:none;display:inline-block;transition:all .2s;text-align:center}
.btn-sm:hover{border-color:var(--accent);color:var(--accent)}
.btn-del:hover{border-color:#c96e6e;color:#c96e6e}
.empty{text-align:center;padding:4rem 2rem;font-size:.72rem;color:var(--muted)}

/* Form */
.form-group{margin-bottom:1.5rem}
label{display:block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
input[type=text],input[type=date],textarea{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.82rem;padding:.8rem 1rem;outline:none;transition:border .2s}
textarea{min-height:220px;resize:vertical;font-family:'Cormorant Garamond',serif;font-size:1rem;line-height:1.7}
input:focus,textarea:focus{border-color:var(--accent)}

/* Detail */
.chap-detail-header{margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border)}
.chap-detail-titre{font-family:'Cormorant Garamond',serif;font-size:clamp(1.8rem,4vw,2.4rem);font-weight:300;line-height:1.3;margin-bottom:.5rem}
.chap-detail-body{font-family:'Cormorant Garamond',serif;font-size:1.1rem;line-height:1.9;color:var(--text);white-space:pre-wrap}
.traduction{margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)}
.trad-label{font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;display:flex;align-items:center;gap:.5rem}
.trad-label::before{content:'';flex:0 0 12px;height:1px;background:var(--muted)}
.trad-titre{font-family:'Cormorant Garamond',serif;font-size:clamp(1.3rem,3vw,1.7rem);font-weight:300;font-style:italic;color:var(--muted);line-height:1.3;margin-bottom:.8rem}
.trad-body{font-family:'Cormorant Garamond',serif;font-size:1rem;line-height:1.8;color:var(--muted);white-space:pre-wrap;font-style:italic}
.lang-badge{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;border:1px solid var(--border);padding:.15rem .4rem;color:var(--muted);display:inline-block;margin-left:.5rem;vertical-align:middle}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">← <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">📖 <?= t('Notre Histoire','Наша История') ?></div>
  <a class="btn" href="?mode=new">+ <?= t('Écrire','Написать') ?></a>
</div>

<div class="wrap">

<?php if ($mode === 'new'): ?>
  <!-- Formulaire nouveau chapitre -->
  <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:2rem"><?= t('Nouveau chapitre','Новая глава') ?></h2>
  <form method="POST">
    <input type="hidden" name="action" value="create">
    <div class="form-group">
      <label><?= t('Titre','Заголовок') ?></label>
      <input type="text" name="titre" required placeholder="<?= t('Donne un titre à ce moment…','Дай название этому моменту…') ?>">
    </div>
    <div class="form-group">
      <label><?= t('Date du moment (optionnel)','Дата события (необязательно)') ?></label>
      <input type="date" name="event_date" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group">
      <label><?= t('Ce qui s\'est passé','Что произошло') ?></label>
      <textarea name="contenu" required placeholder="<?= t('Écris librement…','Пиши свободно…') ?>"></textarea>
    </div>
    <div style="display:flex;gap:1rem">
      <button type="submit" class="btn primary"><?= t('Publier','Опубликовать') ?></button>
      <a class="btn" href="<?= BASE_URL ?>/histoire.php"><?= t('Annuler','Отмена') ?></a>
    </div>
  </form>

<?php elseif ($chapitre): ?>
  <!-- Détail chapitre -->
  <?php
    $chapLang = $chapitre['langue'] ?? 'fr';
    $langLabel = $chapLang === 'ru' ? 'RU' : 'FR';
    $tradLangLabel = $chapLang === 'ru' ? 'FR' : 'RU';
    $hasTrad = !empty($chapitre['contenu_traduit']);
  ?>
  <div class="chap-detail-header">
    <div style="font-size:.6rem;letter-spacing:.12em;color:var(--muted);margin-bottom:.6rem">
      <?= h($chapitre['display_name']) ?> · <?= $chapitre['event_date'] ?? date('Y-m-d', strtotime($chapitre['created_at'])) ?>
      <span class="lang-badge"><?= $langLabel ?></span>
    </div>
    <div class="chap-detail-titre"><?= h($chapitre['titre']) ?></div>
  </div>
  <div class="chap-detail-body"><?= h($chapitre['contenu']) ?></div>

  <?php if ($hasTrad): ?>
  <div class="traduction">
    <div class="trad-label"><?= t('Traduction','Перевод') ?> <span class="lang-badge"><?= $tradLangLabel ?></span></div>
    <?php if (!empty($chapitre['titre_traduit'])): ?>
    <div class="trad-titre"><?= h($chapitre['titre_traduit']) ?></div>
    <?php endif; ?>
    <div class="trad-body"><?= h($chapitre['contenu_traduit']) ?></div>
  </div>
  <?php endif; ?>

  <div style="margin-top:2rem">
    <a class="btn" href="<?= BASE_URL ?>/histoire.php">← <?= t('Retour','Назад') ?></a>
  </div>

<?php else: ?>
  <!-- Liste des chapitres -->
  <?php if (empty($chapitres)): ?>
    <div class="empty">
      <?= t('Aucun chapitre encore.<br>Commence à écrire notre histoire.','Глав пока нет.<br>Начни писать нашу историю.') ?>
    </div>
  <?php else: ?>
    <?php foreach ($chapitres as $c): ?>
    <div class="chap-item">
      <div class="chap-avatar"><?= h($c['avatar']) ?></div>
      <div>
        <div class="chap-meta"><?= h($c['display_name']) ?> · <?= $c['event_date'] ?? date('d/m/Y', strtotime($c['created_at'])) ?> <span class="lang-badge"><?= ($c['langue'] ?? 'fr') === 'ru' ? 'RU' : 'FR' ?></span></div>
        <div class="chap-titre"><?= h($c['titre']) ?></div>
        <div class="chap-preview"><?= h(mb_substr($c['contenu'], 0, 150)) ?>…</div>
      </div>
      <div class="chap-actions">
        <a class="btn-sm" href="?view=<?= $c['id'] ?>"><?= t('Lire','Читать') ?></a>
        <?php if ($c['user_id'] == $user['id']): ?>
        <form method="POST" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn-sm btn-del"><?= t('✗','✗') ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
