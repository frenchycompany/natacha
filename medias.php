<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    $action = $_POST['action'] ?? '';

    // Add music
    if ($action === 'add_music') {
        $titre = trim($_POST['titre'] ?? '');
        $artiste = trim($_POST['artiste'] ?? '');
        $deezer_url = trim($_POST['deezer_url'] ?? '');
        $commentaire = trim($_POST['commentaire'] ?? '');
        if ($titre) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $comm_traduit = $commentaire ? translateText($commentaire, $fromLang, $toLang) : '';
            $comm_fr = $fromLang === 'fr' ? $commentaire : $comm_traduit;
            $comm_ru = $fromLang === 'ru' ? $commentaire : $comm_traduit;
            db()->prepare("INSERT INTO musiques (user_id, titre, artiste, deezer_url, commentaire_fr, commentaire_ru) VALUES (?,?,?,?,?,?)")
               ->execute([$user['id'], $titre, $artiste, $deezer_url ?: null, $comm_fr, $comm_ru]);
        }
        header('Location: '.BASE_URL.'/medias.php#musique'); exit;
    }

    // Delete music
    if ($action === 'delete_music') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM musiques WHERE id=?")->execute([$id]);
        }
        header('Location: '.BASE_URL.'/medias.php#musique'); exit;
    }

    // Add film
    if ($action === 'add_film') {
        $titre = trim($_POST['titre'] ?? '');
        $annee = $_POST['annee'] ?? null;
        $statut = in_array($_POST['statut'] ?? '', ['vu','a_voir']) ? $_POST['statut'] : 'a_voir';
        $note = ($statut === 'vu' && isset($_POST['note'])) ? max(1, min(5, (int)$_POST['note'])) : null;
        $commentaire = trim($_POST['commentaire'] ?? '');
        if ($titre) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $comm_traduit = $commentaire ? translateText($commentaire, $fromLang, $toLang) : '';
            $comm_fr = $fromLang === 'fr' ? $commentaire : $comm_traduit;
            $comm_ru = $fromLang === 'ru' ? $commentaire : $comm_traduit;
            db()->prepare("INSERT INTO films (user_id, titre, annee, statut, note, commentaire_fr, commentaire_ru) VALUES (?,?,?,?,?,?,?)")
               ->execute([$user['id'], $titre, $annee ?: null, $statut, $note, $comm_fr, $comm_ru]);
        }
        header('Location: '.BASE_URL.'/medias.php#films'); exit;
    }

    // Delete film
    if ($action === 'delete_film') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM films WHERE id=?")->execute([$id]);
        }
        header('Location: '.BASE_URL.'/medias.php#films'); exit;
    }

    // Mark film as seen
    if ($action === 'mark_seen') {
        $id = (int)($_POST['id'] ?? 0);
        $note = max(1, min(5, (int)($_POST['note'] ?? 3)));
        $commentaire = trim($_POST['commentaire'] ?? '');
        if ($id) {
            $fromLang = $lang === 'ru' ? 'ru' : 'fr';
            $toLang = $fromLang === 'fr' ? 'ru' : 'fr';
            $comm_traduit = $commentaire ? translateText($commentaire, $fromLang, $toLang) : '';
            $comm_fr = $fromLang === 'fr' ? $commentaire : $comm_traduit;
            $comm_ru = $fromLang === 'ru' ? $commentaire : $comm_traduit;
            db()->prepare("UPDATE films SET statut='vu', note=?, commentaire_fr=?, commentaire_ru=? WHERE id=?")
               ->execute([$note, $comm_fr, $comm_ru, $id]);
        }
        header('Location: '.BASE_URL.'/medias.php#films'); exit;
    }
}

// Fetch data
$musiques = db()->query("SELECT m.*, u.display_name, u.avatar FROM musiques m JOIN users u ON u.id=m.user_id ORDER BY m.created_at DESC")->fetchAll();
$films_a_voir = db()->query("SELECT f.*, u.display_name, u.avatar FROM films f JOIN users u ON u.id=f.user_id WHERE f.statut='a_voir' ORDER BY f.created_at DESC")->fetchAll();
$films_vus = db()->query("SELECT f.*, u.display_name, u.avatar FROM films f JOIN users u ON u.id=f.user_id WHERE f.statut='vu' ORDER BY f.created_at DESC")->fetchAll();

function extractDeezerTrackId(?string $url): ?string {
    if (!$url) return null;
    if (preg_match('#deezer\.com/(?:\w+/)?track/(\d+)#', $url, $m)) return $m[1];
    return null;
}

function renderStars(int $note, int $max = 5): string {
    $out = '';
    for ($i = 1; $i <= $max; $i++) {
        $out .= $i <= $note ? '<span class="star filled">&#9733;</span>' : '<span class="star empty">&#9734;</span>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Nos Médias', 'Наши Медиа') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<script src="<?= BASE_URL ?>/includes/autotranslate.js"></script>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.back-link:hover{color:var(--accent)}

.wrap{max-width:900px;margin:0 auto;padding:2rem}
.page-title{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,3.5vw,2.2rem);font-weight:300;font-style:italic;color:var(--accent);margin-bottom:2rem;text-align:center}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:2rem}
.tab-btn{flex:1;padding:.8rem 1rem;font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-style:italic;background:transparent;border:none;border-bottom:2px solid transparent;color:var(--muted);cursor:pointer;transition:all .3s;text-align:center}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);background:var(--as)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* Section headers */
.section-title{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent);margin:2rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.section-title:first-child{margin-top:0}

/* Cards */
.media-card{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:1rem;position:relative;transition:border-color .3s}
.media-card:hover{border-color:var(--accent)}
.media-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:.8rem}
.media-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:400;font-style:italic;color:var(--text)}
.media-subtitle{font-size:.65rem;color:var(--muted);letter-spacing:.08em;margin-top:.2rem}
.media-comment{font-size:.7rem;color:var(--muted);line-height:1.6;margin-top:.6rem;font-style:italic}
.media-meta{display:flex;align-items:center;gap:.5rem;margin-top:.8rem;font-size:.58rem;color:var(--muted);letter-spacing:.08em}
.media-meta .avatar-tiny{width:18px;height:18px;border-radius:50%;background:var(--as);border:1px solid rgba(201,169,110,.3);display:inline-flex;align-items:center;justify-content:center;font-size:.5rem;color:var(--accent)}

/* Stars */
.stars{display:inline-flex;gap:.1rem}
.star{font-size:1rem}
.star.filled{color:var(--accent)}
.star.empty{color:var(--border)}

/* Deezer */
.deezer-link{display:inline-block;font-size:.65rem;color:var(--accent);text-decoration:none;border:1px solid rgba(201,169,110,.3);padding:.25rem .6rem;margin-top:.5rem;transition:all .2s;letter-spacing:.06em}
.deezer-link:hover{background:var(--as);border-color:var(--accent)}
.deezer-embed{margin-top:.8rem;border-radius:8px;overflow:hidden;border:1px solid var(--accent)}
.deezer-embed iframe{display:block;width:100%;border:none;border-radius:8px}

/* Forms */
.add-form{background:var(--s);border:1px solid var(--border);padding:1.5rem;margin-bottom:2rem}
.add-form summary{font-family:'Cormorant Garamond',serif;font-size:1rem;font-style:italic;color:var(--accent);cursor:pointer;list-style:none;padding:.3rem 0}
.add-form summary::-webkit-details-marker{display:none}
.add-form summary::before{content:'+ '}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-top:1rem}
.form-grid.single{grid-template-columns:1fr}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
.form-group{display:flex;flex-direction:column;gap:.3rem}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:.58rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.trad-preview{font-size:.6rem;color:var(--muted);font-style:italic;margin-top:.2rem;padding:.2rem .4rem;border-left:2px solid rgba(201,169,110,.3);opacity:0;transition:opacity .3s}
.trad-preview.visible{opacity:1}
.form-group input,.form-group select,.form-group textarea{background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;padding:.5rem .7rem;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--accent)}
.form-group textarea{resize:vertical;min-height:60px}
.form-group select{appearance:none;cursor:pointer}
.form-group select option{background:var(--bg);color:var(--text)}
.btn-submit{margin-top:.8rem;padding:.5rem 1.5rem;background:transparent;border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;cursor:pointer;transition:all .2s}
.btn-submit:hover{background:var(--as)}

/* Delete */
.btn-delete{background:transparent;border:1px solid var(--border);color:var(--muted);font-size:.55rem;padding:.2rem .5rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.btn-delete:hover{border-color:#c96e6e;color:#c96e6e}

/* Mark as seen inline form */
.seen-form{margin-top:.8rem;padding-top:.8rem;border-top:1px solid var(--border);display:none}
.seen-form.open{display:block}
.seen-form .form-grid{margin-top:.5rem}
.btn-mark{font-size:.6rem;color:var(--accent);background:transparent;border:1px solid rgba(201,169,110,.3);padding:.25rem .6rem;cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s}
.btn-mark:hover{background:var(--as);border-color:var(--accent)}

/* Star rating input */
.star-rating{display:flex;flex-direction:row-reverse;gap:.2rem;justify-content:flex-end}
.star-rating input{display:none}
.star-rating label{font-size:1.3rem;color:var(--border);cursor:pointer;transition:color .2s}
.star-rating input:checked ~ label,.star-rating label:hover,.star-rating label:hover ~ label{color:var(--accent)}

.empty-state{text-align:center;padding:2rem;color:var(--muted);font-size:.7rem;font-style:italic}
</style>
</head>
<body>

<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title">🎵 <?= t('Nos Médias','Наши Медиа') ?></div>
  <span style="width:80px"></span>
</div>

<div class="wrap">
  <div class="page-title"><?= t('Nos Médias','Наши Медиа') ?></div>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="musique"><?= t('&#127925; Musique','&#127925; Музыка') ?></button>
    <button class="tab-btn" data-tab="films"><?= t('&#127916; Films','&#127916; Фильмы') ?></button>
  </div>

  <!-- ==================== MUSIC TAB ==================== -->
  <div class="tab-panel active" id="tab-musique">

    <details class="add-form">
      <summary><?= t('Ajouter une chanson','Добавить песню') ?></summary>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_music">
        <div class="form-grid">
          <div class="form-group">
            <label><?= t('Titre','Название') ?> *</label>
            <input type="text" name="titre" required>
          </div>
          <div class="form-group">
            <label><?= t('Artiste','Исполнитель') ?></label>
            <input type="text" name="artiste">
          </div>
          <div class="form-group full">
            <label><?= t('Lien Deezer (optionnel)','Ссылка Deezer (необязательно)') ?></label>
            <input type="url" name="deezer_url" placeholder="https://www.deezer.com/track/...">
          </div>
          <div class="form-group full">
            <label><?= t('Commentaire','Комментарий') ?></label>
            <textarea name="commentaire" rows="2" class="autotranslate-comment"></textarea>
            <div class="trad-preview"></div>
          </div>
        </div>
        <button type="submit" class="btn-submit"><?= t('Ajouter','Добавить') ?></button>
      </form>
    </details>

    <?php if (empty($musiques)): ?>
      <div class="empty-state"><?= t('Aucune chanson pour le moment.','Пока нет песен.') ?></div>
    <?php endif; ?>

    <?php foreach ($musiques as $m): ?>
    <div class="media-card">
      <div class="media-card-header">
        <div>
          <div class="media-title"><?= h($m['titre']) ?></div>
          <?php if ($m['artiste']): ?>
            <div class="media-subtitle"><?= h($m['artiste']) ?></div>
          <?php endif; ?>
        </div>
        <form method="POST" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_music">
          <input type="hidden" name="id" value="<?= $m['id'] ?>">
          <button type="submit" class="btn-delete">&times;</button>
        </form>
      </div>

      <?php $comment = $lang === 'ru' ? $m['commentaire_ru'] : $m['commentaire_fr']; ?>
      <?php if ($comment): ?>
        <div class="media-comment">&laquo; <?= h($comment) ?> &raquo;</div>
      <?php endif; ?>

      <?php if ($m['deezer_url']): ?>
        <a class="deezer-link" href="<?= h($m['deezer_url']) ?>" target="_blank" rel="noopener">&#9654; <?= t('Écouter sur Deezer','Слушать на Deezer') ?></a>
        <?php $trackId = extractDeezerTrackId($m['deezer_url']); ?>
        <?php if ($trackId): ?>
          <div class="deezer-embed">
            <iframe src="https://widget.deezer.com/widget/dark/track/<?= h($trackId) ?>" width="100%" height="100" allow="encrypted-media; clipboard-write" loading="lazy"></iframe>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="media-meta">
        <span class="avatar-tiny"><?= h($m['avatar']) ?></span>
        <?= h($m['display_name']) ?> &middot; <?= date('d/m/Y', strtotime($m['created_at'])) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ==================== FILMS TAB ==================== -->
  <div class="tab-panel" id="tab-films">

    <details class="add-form">
      <summary><?= t('Ajouter un film','Добавить фильм') ?></summary>
      <form method="POST" id="film-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_film">
        <div class="form-grid">
          <div class="form-group">
            <label><?= t('Titre','Название') ?> *</label>
            <input type="text" name="titre" required>
          </div>
          <div class="form-group">
            <label><?= t('Année','Год') ?></label>
            <input type="number" name="annee" min="1900" max="2030">
          </div>
          <div class="form-group">
            <label><?= t('Statut','Статус') ?></label>
            <select name="statut" id="film-statut" onchange="toggleFilmNote()">
              <option value="a_voir"><?= t('À voir','К просмотру') ?></option>
              <option value="vu"><?= t('Déjà vu','Просмотрен') ?></option>
            </select>
          </div>
          <div class="form-group" id="film-note-group" style="display:none">
            <label><?= t('Note','Оценка') ?> (1-5)</label>
            <div class="star-rating">
              <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" name="note" value="<?= $i ?>" id="add-star-<?= $i ?>">
              <label for="add-star-<?= $i ?>">&#9733;</label>
              <?php endfor; ?>
            </div>
          </div>
          <div class="form-group full">
            <label><?= t('Commentaire','Комментарий') ?></label>
            <textarea name="commentaire" rows="2" class="autotranslate-comment"></textarea>
            <div class="trad-preview"></div>
          </div>
        </div>
        <button type="submit" class="btn-submit"><?= t('Ajouter','Добавить') ?></button>
      </form>
    </details>

    <!-- A voir -->
    <div class="section-title"><?= t('À voir &#127871;','К просмотру &#127871;') ?></div>
    <?php if (empty($films_a_voir)): ?>
      <div class="empty-state"><?= t('Aucun film à voir.','Нет фильмов к просмотру.') ?></div>
    <?php endif; ?>

    <?php foreach ($films_a_voir as $f): ?>
    <div class="media-card">
      <div class="media-card-header">
        <div>
          <div class="media-title"><?= h($f['titre']) ?></div>
          <?php if ($f['annee']): ?>
            <div class="media-subtitle"><?= h($f['annee']) ?></div>
          <?php endif; ?>
        </div>
        <form method="POST" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_film">
          <input type="hidden" name="id" value="<?= $f['id'] ?>">
          <button type="submit" class="btn-delete">&times;</button>
        </form>
      </div>

      <?php $comment = $lang === 'ru' ? $f['commentaire_ru'] : $f['commentaire_fr']; ?>
      <?php if ($comment): ?>
        <div class="media-comment">&laquo; <?= h($comment) ?> &raquo;</div>
      <?php endif; ?>

      <div class="media-meta">
        <span class="avatar-tiny"><?= h($f['avatar']) ?></span>
        <?= h($f['display_name']) ?> &middot; <?= date('d/m/Y', strtotime($f['created_at'])) ?>
      </div>

      <button class="btn-mark" onclick="this.nextElementSibling.classList.toggle('open');this.style.display='none'">&#10003; <?= t('Marquer comme vu','Отметить как просмотренный') ?></button>
      <div class="seen-form">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_seen">
          <input type="hidden" name="id" value="<?= $f['id'] ?>">
          <div class="form-grid">
            <div class="form-group">
              <label><?= t('Note','Оценка') ?></label>
              <div class="star-rating">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" name="note" value="<?= $i ?>" id="seen-star-<?= $f['id'] ?>-<?= $i ?>" <?= $i===3?'checked':'' ?>>
                <label for="seen-star-<?= $f['id'] ?>-<?= $i ?>">&#9733;</label>
                <?php endfor; ?>
              </div>
            </div>
            <div class="form-group full">
              <label><?= t('Commentaire','Комментарий') ?></label>
              <textarea name="commentaire" rows="2" class="autotranslate-comment"><?= h($comment ?? '') ?></textarea>
              <div class="trad-preview"></div>
            </div>
          </div>
          <button type="submit" class="btn-submit"><?= t('Confirmer','Подтвердить') ?></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Deja vus -->
    <div class="section-title"><?= t('Déjà vus &#10003;','Просмотренные &#10003;') ?></div>
    <?php if (empty($films_vus)): ?>
      <div class="empty-state"><?= t('Aucun film vu.','Нет просмотренных фильмов.') ?></div>
    <?php endif; ?>

    <?php foreach ($films_vus as $f): ?>
    <div class="media-card">
      <div class="media-card-header">
        <div>
          <div class="media-title"><?= h($f['titre']) ?></div>
          <?php if ($f['annee']): ?>
            <div class="media-subtitle"><?= h($f['annee']) ?></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:.8rem">
          <div class="stars"><?= renderStars((int)$f['note']) ?></div>
          <form method="POST" onsubmit="return confirm('<?= t('Supprimer ?','Удалить?') ?>')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_film">
            <input type="hidden" name="id" value="<?= $f['id'] ?>">
            <button type="submit" class="btn-delete">&times;</button>
          </form>
        </div>
      </div>

      <?php $comment = $lang === 'ru' ? $f['commentaire_ru'] : $f['commentaire_fr']; ?>
      <?php if ($comment): ?>
        <div class="media-comment">&laquo; <?= h($comment) ?> &raquo;</div>
      <?php endif; ?>

      <div class="media-meta">
        <span class="avatar-tiny"><?= h($f['avatar']) ?></span>
        <?= h($f['display_name']) ?> &middot; <?= date('d/m/Y', strtotime($f['created_at'])) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
// Tab switching with URL hash
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
    history.replaceState(null, '', '#' + name);
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

// Restore tab from hash
const hash = location.hash.replace('#', '');
if (hash === 'films' || hash === 'musique') switchTab(hash);

// Toggle note field in add film form
function toggleFilmNote() {
    const s = document.getElementById('film-statut');
    const g = document.getElementById('film-note-group');
    g.style.display = s.value === 'vu' ? '' : 'none';
}

// Auto-translate comment previews
(function() {
    const LANG = <?= json_encode($lang) ?>;
    const BASE = <?= json_encode(BASE_URL) ?>;
    const fromLang = LANG === 'ru' ? 'ru' : 'fr';
    const toLang = fromLang === 'fr' ? 'ru' : 'fr';
    let timers = {};

    document.querySelectorAll('.autotranslate-comment').forEach(function(textarea, idx) {
        const preview = textarea.parentElement.querySelector('.trad-preview');
        if (!preview) return;

        textarea.addEventListener('input', function() {
            clearTimeout(timers['comm_' + idx]);
            const text = textarea.value.trim();
            if (text.length < 3) { preview.classList.remove('visible'); preview.textContent = ''; return; }
            timers['comm_' + idx] = setTimeout(function() {
                fetch(BASE + '/api/translate.php?q=' + encodeURIComponent(text) + '&from=' + fromLang + '&to=' + toLang)
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok && data.text) {
                            preview.textContent = (toLang === 'ru' ? '🇷🇺 ' : '🇫🇷 ') + data.text;
                            preview.classList.add('visible');
                        }
                    }).catch(() => {});
            }, 800);
        });
    });
})();
</script>
</body>
</html>
