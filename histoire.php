<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/image_helper.php';
require_once __DIR__.'/includes/notifications.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// Get couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $coupleId = $stmt->fetchColumn() ?: null;
}

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $titre   = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');
        $date    = $_POST['event_date'] ?? null;
        if ($titre && $contenu) {
            $fromLang = $user['lang'] === 'ru' ? 'ru' : 'fr';
            $toLang   = $fromLang === 'fr' ? 'ru' : 'fr';
            $titre_traduit   = translateText($titre, $fromLang, $toLang);
            $contenu_traduit = translateText($contenu, $fromLang, $toLang);
            // Photo
            $photo = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $photo = handleChapterPhoto($_FILES['photo']);
            }
            db()->prepare("INSERT INTO histoire_chapitres (user_id, titre, contenu, event_date, langue, titre_traduit, contenu_traduit, photo) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$user['id'], $titre, $contenu, $date ?: null, $fromLang, $titre_traduit, $contenu_traduit, $photo]);
            // Notify
            try {
                notifyOtherUser($user['id'], 'chapitre',
                    $user['display_name'].' a écrit un nouveau chapitre : '.$titre,
                    $user['display_name'].' написал(а) новую главу: '.$titre,
                    BASE_URL.'/histoire.php?view='.db()->lastInsertId());
            } catch (Exception $e) {}
            // Record couple activity
            if ($coupleId) {
                require_once __DIR__.'/includes/couple_helper.php';
                $ce = new CoupleEntity(db());
                $ce->recordActivity($coupleId, $user['id'], 'histoire',
                    $user['display_name'].' a écrit un nouveau chapitre',
                    $user['display_name'].' написал(а) новую главу');
            }
        }
        header('Location: '.BASE_URL.'/histoire.php'); exit;
    }
    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $titre   = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');
        $date    = $_POST['event_date'] ?? null;
        if ($id && $titre && $contenu) {
            $fromLang = $user['lang'] === 'ru' ? 'ru' : 'fr';
            $toLang   = $fromLang === 'fr' ? 'ru' : 'fr';
            $titre_traduit   = translateText($titre, $fromLang, $toLang);
            $contenu_traduit = translateText($contenu, $fromLang, $toLang);
            // Photo
            $photoSql = '';
            $params = [$titre, $contenu, $date ?: null, $titre_traduit, $contenu_traduit];
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $newPhoto = handleChapterPhoto($_FILES['photo']);
                if ($newPhoto) {
                    $photoSql = ', photo=?';
                    $params[] = $newPhoto;
                    // Delete old photo
                    $old = db()->prepare("SELECT photo FROM histoire_chapitres WHERE id=? AND user_id=?");
                    $old->execute([$id, $user['id']]);
                    $oldPhoto = $old->fetchColumn();
                    if ($oldPhoto) deleteChapterPhoto($oldPhoto);
                }
            }
            $params[] = $id;
            $params[] = $user['id'];
            db()->prepare("UPDATE histoire_chapitres SET titre=?, contenu=?, event_date=?, titre_traduit=?, contenu_traduit=?{$photoSql} WHERE id=? AND user_id=?")
               ->execute($params);
        }
        header('Location: '.BASE_URL.'/histoire.php?view='.$id); exit;
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM histoire_chapitres WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
        header('Location: '.BASE_URL.'/histoire.php'); exit;
    }
}

// View single chapter
$view = $_GET['view'] ?? null;
$chapitre = null;
if ($view) {
    $s = db()->prepare("SELECT h.*, u.display_name, u.avatar FROM histoire_chapitres h JOIN users u ON u.id=h.user_id WHERE h.id=?");
    $s->execute([(int)$view]);
    $chapitre = $s->fetch();
}

// Load reactions
$reactionCounts = [];
$myReactions = [];
if ($chapitre) {
    try {
        $rc = db()->prepare("SELECT COUNT(*) FROM reactions WHERE item_type='histoire' AND item_id=?");
        $rc->execute([$chapitre['id']]);
        $reactionCounts[$chapitre['id']] = (int)$rc->fetchColumn();
        $mr = db()->prepare("SELECT 1 FROM reactions WHERE user_id=? AND item_type='histoire' AND item_id=?");
        $mr->execute([$user['id'], $chapitre['id']]);
        if ($mr->fetch()) $myReactions[$chapitre['id']] = true;
    } catch (Exception $e) {}
}

// Search
$search = trim($_GET['q'] ?? '');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClause = '';
$params = [];
if ($search) {
    $whereClause = "WHERE (h.titre LIKE ? OR h.contenu LIKE ? OR h.titre_traduit LIKE ? OR h.contenu_traduit LIKE ?)";
    $like = '%'.$search.'%';
    $params = [$like, $like, $like, $like];
}

$countSql = "SELECT COUNT(*) FROM histoire_chapitres h $whereClause";
$stmtCount = db()->prepare($countSql);
$stmtCount->execute($params);
$totalChapitres = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($totalChapitres / $perPage));

$sql = "SELECT h.*, u.display_name, u.avatar FROM histoire_chapitres h JOIN users u ON u.id=h.user_id $whereClause ORDER BY h.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$chapitres = $stmt->fetchAll();

// Load reactions for chapters
if (!empty($chapitres)) {
    $cids = array_column($chapitres, 'id');
    $ph = implode(',', array_fill(0, count($cids), '?'));
    try {
        $rc = db()->prepare("SELECT item_id, COUNT(*) as cnt FROM reactions WHERE item_type='histoire' AND item_id IN ($ph) GROUP BY item_id");
        $rc->execute($cids);
        foreach ($rc->fetchAll() as $r) $reactionCounts[$r['item_id']] = $r['cnt'];
        $mr = db()->prepare("SELECT item_id FROM reactions WHERE user_id=? AND item_type='histoire' AND item_id IN ($ph)");
        $mr->execute(array_merge([$user['id']], $cids));
        foreach ($mr->fetchAll() as $r) $myReactions[$r['item_id']] = true;
    } catch (Exception $e) {}
}

$mode = $_GET['mode'] ?? 'list';
$editMode = isset($_GET['edit']) && $chapitre && $chapitre['user_id'] == $user['id'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Notre Histoire','Наша История') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<script src="<?= BASE_URL ?>/includes/autotranslate.js"></script>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.4rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'DM Mono',monospace}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}
.wrap{max-width:760px;margin:0 auto;padding:2.5rem 2rem}

/* Search */
.search-bar{display:flex;gap:.5rem;margin-bottom:1.5rem}
.search-bar input{flex:1;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.72rem;padding:.5rem .8rem;outline:none;transition:border .2s}
.search-bar input:focus{border-color:var(--accent)}
.search-bar button{font-size:.6rem;letter-spacing:.1em;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.5rem .7rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.search-bar button:hover{border-color:var(--accent);color:var(--accent)}
.search-info{font-size:.6rem;color:var(--muted);margin-bottom:1rem;letter-spacing:.08em}

/* Liste */
.chap-item{padding:1.5rem 0;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr auto;gap:1rem;align-items:start}
.chap-avatar{width:32px;height:32px;border-radius:50%;background:var(--as);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--accent);flex-shrink:0;margin-top:.2rem}
.chap-meta{font-size:.6rem;letter-spacing:.1em;color:var(--muted);margin-bottom:.4rem}
.chap-titre{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:400;color:var(--text);margin-bottom:.3rem}
.chap-preview{font-size:.72rem;color:var(--muted);line-height:1.6;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.chap-actions{display:flex;flex-direction:column;gap:.3rem}
.btn-sm{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.25rem .5rem;cursor:pointer;text-decoration:none;display:inline-block;transition:all .2s;text-align:center;font-family:'DM Mono',monospace}
.btn-sm:hover{border-color:var(--accent);color:var(--accent)}
.btn-del:hover{border-color:#c96e6e;color:#c96e6e}
.empty{text-align:center;padding:4rem 2rem;font-size:.72rem;color:var(--muted)}

/* Form */
.form-group{margin-bottom:1.5rem}
.trad-preview{font-size:.65rem;color:var(--muted);font-style:italic;margin-top:.3rem;padding:.3rem .5rem;border-left:2px solid rgba(201,169,110,.3);min-height:0;transition:all .3s;opacity:0}
.trad-preview.visible{opacity:1}
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

/* Photo */
.chap-photo{width:100%;max-height:500px;object-fit:cover;border:1px solid var(--border);margin:1.5rem 0}
.photo-upload{margin-top:.5rem}
.photo-upload input[type=file]{font-size:.65rem;color:var(--muted)}

/* Pagination */
.pagination{display:flex;justify-content:center;gap:.3rem;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)}
.pagination a,.pagination span{font-size:.6rem;letter-spacing:.1em;padding:.35rem .6rem;border:1px solid var(--border);color:var(--muted);text-decoration:none;transition:all .2s}
.pagination a:hover{border-color:var(--accent);color:var(--accent)}
.pagination .current{border-color:var(--accent);color:var(--accent);background:var(--as)}
.heart-btn{background:none;border:none;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.3rem;padding:.2rem;transition:transform .2s}
.heart-btn:hover{transform:scale(1.2)}
.heart-btn.liked{animation:heartPop .3s ease}
@keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
.heart-count{font-size:.5rem;color:var(--muted);font-family:'DM Mono',monospace}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">← <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">📖 <?= t('Notre Histoire','Наша История') ?></div>
  <div style="display:flex;gap:.5rem">
    <a class="btn" href="<?= BASE_URL ?>/export_histoire.php" style="font-size:.55rem"><?= t('Export PDF','Экспорт PDF') ?></a>
    <a class="btn" href="?mode=new">+ <?= t('Écrire','Написать') ?></a>
  </div>
</div>

<div class="wrap">

<?php if ($mode === 'new'): ?>
  <!-- Formulaire nouveau chapitre -->
  <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:2rem"><?= t('Nouveau chapitre','Новая глава') ?></h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-group">
      <label><?= t('Titre','Заголовок') ?></label>
      <input type="text" name="titre" required placeholder="<?= t('Donne un titre à ce moment…','Дай название этому моменту…') ?>">
      <div class="trad-preview" id="titrePreview"></div>
    </div>
    <div class="form-group">
      <label><?= t('Date du moment (optionnel)','Дата события (необязательно)') ?></label>
      <input type="date" name="event_date" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group">
      <label><?= t('Ce qui s\'est passé','Что произошло') ?></label>
      <textarea name="contenu" required placeholder="<?= t('Écris librement…','Пиши свободно…') ?>"></textarea>
      <div class="trad-preview" id="contenuPreview"></div>
    </div>
    <div class="form-group">
      <label><?= t('Photo (optionnel, max 5 Mo)','Фото (необязательно, макс 5 Мб)') ?></label>
      <div class="photo-upload"><input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"></div>
    </div>
    <div style="display:flex;gap:1rem">
      <button type="submit" class="btn primary"><?= t('Publier','Опубликовать') ?></button>
      <a class="btn" href="<?= BASE_URL ?>/histoire.php"><?= t('Annuler','Отмена') ?></a>
    </div>
  </form>

<?php elseif ($chapitre && $editMode): ?>
  <!-- Formulaire édition -->
  <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:2rem"><?= t('Modifier le chapitre','Редактировать главу') ?></h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= $chapitre['id'] ?>">
    <div class="form-group">
      <label><?= t('Titre','Заголовок') ?></label>
      <input type="text" name="titre" required value="<?= h($chapitre['titre']) ?>">
      <div class="trad-preview" id="titrePreviewEdit"></div>
    </div>
    <div class="form-group">
      <label><?= t('Date du moment','Дата события') ?></label>
      <input type="date" name="event_date" value="<?= $chapitre['event_date'] ?? date('Y-m-d', strtotime($chapitre['created_at'])) ?>">
    </div>
    <div class="form-group">
      <label><?= t('Contenu','Содержание') ?></label>
      <textarea name="contenu" required><?= h($chapitre['contenu']) ?></textarea>
      <div class="trad-preview" id="contenuPreviewEdit"></div>
    </div>
    <div class="form-group">
      <label><?= t('Photo (remplacer ou ajouter)','Фото (заменить или добавить)') ?></label>
      <?php if (!empty($chapitre['photo'])): ?>
      <img class="chap-photo" src="<?= BASE_URL ?>/uploads/histoire/<?= h($chapitre['photo']) ?>" alt="" style="max-height:200px;margin-bottom:.5rem">
      <?php endif; ?>
      <div class="photo-upload"><input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"></div>
    </div>
    <div style="display:flex;gap:1rem">
      <button type="submit" class="btn primary"><?= t('Enregistrer','Сохранить') ?></button>
      <a class="btn" href="?view=<?= $chapitre['id'] ?>"><?= t('Annuler','Отмена') ?></a>
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
  <?php if (!empty($chapitre['photo'])): ?>
  <img class="chap-photo" src="<?= BASE_URL ?>/uploads/histoire/<?= h($chapitre['photo']) ?>" alt="">
  <?php endif; ?>
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

  <div style="margin-top:2rem;display:flex;gap:.8rem;align-items:center">
    <a class="btn" href="<?= BASE_URL ?>/histoire.php">← <?= t('Retour','Назад') ?></a>
    <?php if ($chapitre['user_id'] == $user['id']): ?>
    <a class="btn" href="?view=<?= $chapitre['id'] ?>&edit=1"><?= t('Modifier','Редактировать') ?></a>
    <?php endif; ?>
    <button class="heart-btn <?= isset($myReactions[$chapitre['id']]) ? 'liked' : '' ?>"
        onclick="toggleHeart(this,'histoire',<?= $chapitre['id'] ?>)">
        <?= isset($myReactions[$chapitre['id']]) ? '❤️' : '🤍' ?>
        <span class="heart-count"><?= $reactionCounts[$chapitre['id']] ?? '' ?></span>
    </button>
  </div>

<?php else: ?>
  <!-- Recherche -->
  <form class="search-bar" method="GET">
    <input type="text" name="q" placeholder="<?= t('Rechercher un chapitre…','Искать главу…') ?>" value="<?= h($search) ?>">
    <button type="submit"><?= t('Chercher','Найти') ?></button>
    <?php if ($search): ?><a href="<?= BASE_URL ?>/histoire.php" class="btn" style="font-size:.55rem;padding:.5rem .7rem"><?= t('Effacer','Сбросить') ?></a><?php endif; ?>
  </form>

  <?php if ($search): ?>
  <div class="search-info"><?= $totalChapitres ?> <?= t('résultat(s) pour','результат(ов) для') ?> « <?= h($search) ?> »</div>
  <?php endif; ?>

  <!-- Liste des chapitres -->
  <?php if (empty($chapitres)): ?>
    <div class="empty">
      <?php if ($search): ?>
        <?= t('Aucun résultat pour cette recherche.','Нет результатов для этого поиска.') ?>
      <?php else: ?>
        <?= t('Aucun chapitre encore.<br>Commence à écrire notre histoire.','Глав пока нет.<br>Начни писать нашу историю.') ?>
      <?php endif; ?>
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
        <button class="heart-btn <?= isset($myReactions[$c['id']]) ? 'liked' : '' ?>"
            onclick="toggleHeart(this,'histoire',<?= $c['id'] ?>)">
            <?= isset($myReactions[$c['id']]) ? '❤️' : '🤍' ?>
            <span class="heart-count"><?= $reactionCounts[$c['id']] ?? '' ?></span>
        </button>
        <a class="btn-sm" href="?view=<?= $c['id'] ?>"><?= t('Lire','Читать') ?></a>
        <?php if ($c['user_id'] == $user['id']): ?>
        <a class="btn-sm" href="?view=<?= $c['id'] ?>&edit=1"><?= t('Modifier','Изменить') ?></a>
        <form method="POST" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn-sm btn-del"><?= t('✗','✗') ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?><?= $search ? '&q='.urlencode($search) : '' ?>">←</a>
      <?php endif; ?>
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i == $page): ?>
        <span class="current"><?= $i ?></span>
        <?php else: ?>
        <a href="?page=<?= $i ?><?= $search ? '&q='.urlencode($search) : '' ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?><?= $search ? '&q='.urlencode($search) : '' ?>">→</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</div>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

function toggleHeart(btn, type, id) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('item_type', type);
    fd.append('item_id', id);
    fetch(<?= json_encode(BASE_URL) ?> + '/api/react.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                btn.querySelector('.heart-count').textContent = data.count || '';
                btn.childNodes[0].textContent = data.liked ? '❤️' : '🤍';
            }
        });
}

(function() {
    const LANG = <?= json_encode($lang) ?>;
    const BASE = <?= json_encode(BASE_URL) ?>;
    const fromLang = LANG === 'ru' ? 'ru' : 'fr';
    const toLang = fromLang === 'fr' ? 'ru' : 'fr';
    const DEBOUNCE = 800;
    let timers = {};

    function livePreview(inputSel, previewId, key) {
        const input = document.querySelector(inputSel);
        const preview = document.getElementById(previewId);
        if (!input || !preview) return;

        input.addEventListener('input', function() {
            clearTimeout(timers[key]);
            const text = input.value.trim();
            if (text.length < 3) { preview.classList.remove('visible'); preview.textContent = ''; return; }
            timers[key] = setTimeout(function() {
                fetch(BASE + '/api/translate.php?q=' + encodeURIComponent(text) + '&from=' + fromLang + '&to=' + toLang)
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok && data.text) {
                            preview.textContent = (toLang === 'ru' ? '🇷🇺 ' : '🇫🇷 ') + data.text;
                            preview.classList.add('visible');
                        }
                    }).catch(() => {});
            }, DEBOUNCE);
        });
    }

    // New chapter form
    livePreview('form[action] input[name="titre"], form input[name="titre"]', 'titrePreview', 't1');
    livePreview('form textarea[name="contenu"]', 'contenuPreview', 'c1');
    // Edit form
    livePreview('form input[name="titre"]', 'titrePreviewEdit', 't2');
    livePreview('form textarea[name="contenu"]', 'contenuPreviewEdit', 'c2');
})();
</script>
</body>
</html>
