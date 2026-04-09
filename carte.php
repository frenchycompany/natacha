<?php
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'];

// ═══ POST Actions ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $nom_fr = trim($_POST['nom_fr'] ?? '');
        $nom_ru = trim($_POST['nom_ru'] ?? '');
        $lat = floatval($_POST['latitude'] ?? 0);
        $lng = floatval($_POST['longitude'] ?? 0);
        $desc_fr = trim($_POST['description_fr'] ?? '');
        $desc_ru = trim($_POST['description_ru'] ?? '');
        $date = $_POST['date_visite'] ?? null;
        $cat = $_POST['categorie'] ?? 'autre';
        $valid_cats = ['ville','restaurant','nature','monument','plage','autre'];
        if (!in_array($cat, $valid_cats)) $cat = 'autre';

        if ($nom_fr && $lat && $lng) {
            $stmt = db()->prepare("INSERT INTO lieux (user_id, nom_fr, nom_ru, latitude, longitude, description_fr, description_ru, date_visite, categorie) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$user['id'], $nom_fr, $nom_ru ?: null, $lat, $lng, $desc_fr ?: null, $desc_ru ?: null, $date ?: null, $cat]);
            $id = db()->lastInsertId();

            // Notify partner
            try {
                require_once __DIR__.'/includes/notifications.php';
                notifyOtherUser($user['id'], 'lieu',
                    $user['display_name'].' a ajouté un lieu : '.$nom_fr,
                    $user['display_name'].' добавил(а) место : '.($nom_ru ?: $nom_fr),
                    BASE_URL.'/carte.php');
            } catch (Exception $e) {}

            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            echo json_encode(['ok' => false, 'error' => t('Champs manquants','Отсутствуют поля')]);
        }
        exit;
    }

    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $nom_fr = trim($_POST['nom_fr'] ?? '');
        $nom_ru = trim($_POST['nom_ru'] ?? '');
        $lat = floatval($_POST['latitude'] ?? 0);
        $lng = floatval($_POST['longitude'] ?? 0);
        $desc_fr = trim($_POST['description_fr'] ?? '');
        $desc_ru = trim($_POST['description_ru'] ?? '');
        $date = $_POST['date_visite'] ?? null;
        $cat = $_POST['categorie'] ?? 'autre';
        $valid_cats = ['ville','restaurant','nature','monument','plage','autre'];
        if (!in_array($cat, $valid_cats)) $cat = 'autre';

        if ($id && $nom_fr && $lat && $lng) {
            $stmt = db()->prepare("UPDATE lieux SET nom_fr=?, nom_ru=?, latitude=?, longitude=?, description_fr=?, description_ru=?, date_visite=?, categorie=? WHERE id=?");
            $stmt->execute([$nom_fr, $nom_ru ?: null, $lat, $lng, $desc_fr ?: null, $desc_ru ?: null, $date ?: null, $cat, $id]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => t('Champs manquants','Отсутствуют поля')]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM lieux WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// ═══ GET: Load places ═══
$lieux = db()->query("SELECT * FROM lieux ORDER BY date_visite DESC, created_at DESC")->fetchAll();
$lieux_json = json_encode($lieux, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Notre Carte', 'Наша Карта') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= BASE_URL ?>/includes/autotranslate.js"></script>
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1.5rem;border-bottom:1px solid var(--border);background:var(--bg);z-index:1000;position:relative;height:56px}
.topbar-left{display:flex;align-items:center;gap:1rem}
.back{color:var(--muted);text-decoration:none;font-size:.7rem;letter-spacing:.1em;transition:color .2s;display:flex;align-items:center;gap:.4rem}
.back:hover{color:var(--accent)}
.page-title{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent)}
.topbar-right{display:flex;align-items:center;gap:.6rem}

.filters{display:flex;gap:.4rem;flex-wrap:wrap}
.filter-btn{font-size:.55rem;letter-spacing:.08em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.25rem .5rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.filter-btn.active{border-color:var(--accent);color:var(--accent);background:var(--as)}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Search */
.search-box{position:relative}
.search-input{width:200px;background:var(--s);border:1px solid var(--border);color:var(--text);padding:.35rem .7rem;padding-right:2rem;font-family:'DM Mono',monospace;font-size:.65rem;outline:none;transition:border-color .2s}
.search-input:focus{border-color:var(--accent)}
.search-input::placeholder{color:var(--muted)}
.search-btn{position:absolute;right:1px;top:1px;bottom:1px;background:transparent;border:none;color:var(--muted);padding:0 .5rem;cursor:pointer;font-size:.7rem}
.search-btn:hover{color:var(--accent)}
.search-results{position:absolute;top:100%;left:0;right:0;background:var(--bg);border:1px solid var(--border);border-top:none;max-height:250px;overflow-y:auto;display:none;z-index:3000}
.search-results.open{display:block}
.search-result{padding:.5rem .7rem;font-size:.6rem;color:var(--text);cursor:pointer;border-bottom:1px solid var(--border);transition:background .15s;line-height:1.4}
.search-result:hover{background:var(--as);color:var(--accent)}
.search-result:last-child{border-bottom:none}
.search-result small{display:block;color:var(--muted);font-size:.5rem;margin-top:.1rem}
.search-loading{padding:.5rem .7rem;font-size:.55rem;color:var(--muted);text-align:center}

.btn-add{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;background:var(--as);border:1px solid var(--accent);color:var(--accent);padding:.35rem .8rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace;white-space:nowrap}
.btn-add:hover{background:var(--accent);color:var(--bg)}

#map{width:100%;height:calc(100vh - 56px);z-index:1}

/* Leaflet popup override */
.leaflet-popup-content-wrapper{background:var(--s)!important;color:var(--text)!important;border:1px solid var(--border)!important;border-radius:0!important;box-shadow:0 4px 20px rgba(0,0,0,.5)!important;font-family:'DM Mono',monospace!important}
.leaflet-popup-tip{background:var(--s)!important;border:1px solid var(--border)!important}
.leaflet-popup-content{margin:12px 16px!important;font-size:.72rem!important;line-height:1.6!important}
.leaflet-popup-close-button{color:var(--muted)!important;font-size:18px!important}
.leaflet-popup-close-button:hover{color:var(--accent)!important}
.leaflet-container a{color:var(--accent)}

.popup-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.3rem}
.popup-cat{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.popup-desc{font-size:.65rem;color:var(--text);line-height:1.6;margin-bottom:.3rem}
.popup-date{font-size:.55rem;color:var(--muted);font-style:italic;margin-bottom:.6rem}
.popup-edit{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.2rem .5rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.popup-edit:hover{background:var(--accent);color:var(--bg)}
.popup-delete{font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid #c96e6e;color:#c96e6e;padding:.2rem .5rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace}
.popup-delete:hover{background:#c96e6e;color:var(--bg)}

/* Side panel */
.panel-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;opacity:0;pointer-events:none;transition:opacity .3s}
.panel-overlay.open{opacity:1;pointer-events:auto}
.panel{position:fixed;top:0;right:0;width:380px;max-width:100vw;height:100vh;background:var(--bg);border-left:1px solid var(--border);z-index:2001;transform:translateX(100%);transition:transform .3s ease;overflow-y:auto;padding:1.5rem}
.panel.open{transform:translateX(0)}
.panel h3{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:1.2rem}
.panel-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer}
.panel-close:hover{color:var(--accent)}

.field{margin-bottom:1rem}
.field label{display:block;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem}
.field input,.field select,.field textarea{width:100%;background:var(--s);border:1px solid var(--border);color:var(--text);padding:.5rem .7rem;font-family:'DM Mono',monospace;font-size:.7rem;outline:none;transition:border-color .2s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent)}
.field textarea{resize:vertical;min-height:60px}
.field select{cursor:pointer}
.field select option{background:var(--bg);color:var(--text)}
.field .hint{font-size:.5rem;color:var(--muted);margin-top:.2rem;font-style:italic}

.coords-display{font-size:.6rem;color:var(--accent);background:var(--as);border:1px solid rgba(201,169,110,.2);padding:.4rem .6rem;margin-bottom:1rem;text-align:center}
.coords-display.empty{color:var(--muted);border-color:var(--border);background:transparent}

.btn-submit{width:100%;font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;background:var(--accent);border:1px solid var(--accent);color:var(--bg);padding:.6rem;cursor:pointer;transition:all .2s;font-family:'DM Mono',monospace;font-weight:bold}
.btn-submit:hover{background:transparent;color:var(--accent)}
.btn-submit:disabled{opacity:.4;cursor:not-allowed}

/* Mobile bottom sheet */
@media(max-width:600px){
  .topbar{padding:.6rem .8rem;flex-wrap:wrap;height:auto;gap:.4rem}
  .filters{order:3;width:100%;justify-content:center}
  .panel{width:100%;height:70vh;top:auto;bottom:0;border-left:none;border-top:1px solid var(--border);transform:translateY(100%);border-radius:12px 12px 0 0}
  .panel.open{transform:translateY(0)}
  .filter-btn{font-size:.5rem;padding:.2rem .4rem}
  .search-input{width:140px;font-size:.6rem}
}

/* Gold marker */
.gold-marker{width:14px;height:14px;border-radius:50%;border:2px solid var(--accent);background:rgba(201,169,110,.3);box-shadow:0 0 8px rgba(201,169,110,.4);transition:transform .2s}
.gold-marker:hover{transform:scale(1.3)}
.gold-marker.cat-ville{background:rgba(201,169,110,.4);border-color:#c9a96e}
.gold-marker.cat-restaurant{background:rgba(220,80,80,.4);border-color:#dc5050}
.gold-marker.cat-nature{background:rgba(80,180,80,.4);border-color:#50b450}
.gold-marker.cat-monument{background:rgba(80,120,220,.4);border-color:#5078dc}
.gold-marker.cat-plage{background:rgba(80,200,210,.4);border-color:#50c8d2}
.gold-marker.cat-autre{background:rgba(120,120,120,.4);border-color:#888}

.pick-marker{width:18px;height:18px;border-radius:50%;border:2px solid var(--accent);background:var(--accent);box-shadow:0 0 12px rgba(201,169,110,.6);animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 8px rgba(201,169,110,.4)}50%{box-shadow:0 0 20px rgba(201,169,110,.7)}}

/* Attribution */
.leaflet-control-attribution{background:rgba(15,13,11,.8)!important;color:var(--muted)!important;font-size:.5rem!important}
.leaflet-control-attribution a{color:var(--accent)!important}
.leaflet-control-zoom a{background:var(--s)!important;color:var(--accent)!important;border-color:var(--border)!important}
.leaflet-control-zoom a:hover{background:var(--as)!important}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
    <div class="page-title">🗺 <?= t('Notre Carte','Наша Карта') ?></div>
  </div>
  <div class="topbar-right">
    <div class="search-box" id="searchBox">
      <input type="text" class="search-input" id="searchInput" placeholder="<?= t('Rechercher un lieu...','Поиск места...') ?>" autocomplete="off">
      <button class="search-btn" id="searchBtn" onclick="doSearch()">🔍</button>
      <div class="search-results" id="searchResults"></div>
    </div>
    <button class="btn-add" onclick="openPanel()">+ <?= t('Ajouter','Добавить') ?></button>
  </div>
  <div class="filters">
    <button class="filter-btn active" data-cat="all"><?= t('Tous','Все') ?></button>
    <button class="filter-btn" data-cat="ville">🏙 <?= t('Villes','Города') ?></button>
    <button class="filter-btn" data-cat="restaurant">🍽 <?= t('Restos','Рестораны') ?></button>
    <button class="filter-btn" data-cat="nature">🌿 <?= t('Nature','Природа') ?></button>
    <button class="filter-btn" data-cat="monument">🏛 <?= t('Monuments','Памятники') ?></button>
    <button class="filter-btn" data-cat="plage">🏖 <?= t('Plages','Пляжи') ?></button>
    <button class="filter-btn" data-cat="autre">📍 <?= t('Autre','Другое') ?></button>
  </div>
</div>

<div id="map"></div>

<!-- Side panel / Bottom sheet -->
<div class="panel-overlay" id="panelOverlay" onclick="closePanel()"></div>
<div class="panel" id="panel">
  <button class="panel-close" onclick="closePanel()">✕</button>
  <h3 id="panelTitle"><?= t('Ajouter un lieu','Добавить место') ?></h3>
  <div class="coords-display empty" id="coordsDisplay">
    <?= t('Cliquez sur la carte pour placer le marqueur','Нажмите на карту, чтобы поставить маркер') ?>
  </div>
  <form id="addForm" onsubmit="return submitPlace(event)">
    <input type="hidden" id="formLat" value="">
    <input type="hidden" id="formLng" value="">
    <input type="hidden" id="editId" value="">
    <div class="field">
      <label><?= t('Nom (français)','Название (французский)') ?> *</label>
      <input type="text" id="nomFr" required>
    </div>
    <div class="field">
      <label><?= t('Nom (russe)','Название (русский)') ?></label>
      <input type="text" id="nomRu">
    </div>
    <div class="field">
      <label><?= t('Description (français)','Описание (французский)') ?></label>
      <textarea id="descFr"></textarea>
    </div>
    <div class="field">
      <label><?= t('Description (russe)','Описание (русский)') ?></label>
      <textarea id="descRu"></textarea>
    </div>
    <div class="field">
      <label><?= t('Date de visite','Дата посещения') ?></label>
      <input type="date" id="dateVisite">
    </div>
    <div class="field">
      <label><?= t('Catégorie','Категория') ?></label>
      <select id="categorie">
        <option value="ville">🏙 <?= t('Ville','Город') ?></option>
        <option value="restaurant">🍽 <?= t('Restaurant','Ресторан') ?></option>
        <option value="nature">🌿 <?= t('Nature','Природа') ?></option>
        <option value="monument">🏛 <?= t('Monument','Памятник') ?></option>
        <option value="plage">🏖 <?= t('Plage','Пляж') ?></option>
        <option value="autre" selected>📍 <?= t('Autre','Другое') ?></option>
      </select>
    </div>
    <button type="submit" class="btn-submit" id="btnSubmit" disabled>
      <?= t('Enregistrer le lieu','Сохранить место') ?>
    </button>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const LANG = <?= json_encode($lang) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const LIEUX = <?= $lieux_json ?>;

const catIcons = {ville:'🏙',restaurant:'🍽',nature:'🌿',monument:'🏛',plage:'🏖',autre:'📍'};
const catColors = {ville:'#c9a96e',restaurant:'#dc5050',nature:'#50b450',monument:'#5078dc',plage:'#50c8d2',autre:'#888'};

// Init map
const map = L.map('map', {zoomControl: true}).setView([48.8566, 2.3522], 5);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    subdomains: 'abcd',
    maxZoom: 19
}).addTo(map);

// Markers layer
let markers = [];
let pickMarker = null;
let activeFilter = 'all';

function makeIcon(cat) {
    return L.divIcon({
        className: '',
        html: '<div class="gold-marker cat-' + cat + '"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
        popupAnchor: [0, -10]
    });
}

function pickIcon() {
    return L.divIcon({
        className: '',
        html: '<div class="pick-marker"></div>',
        iconSize: [18, 18],
        iconAnchor: [9, 9]
    });
}

function formatDate(d) {
    if (!d) return '';
    const dt = new Date(d);
    return dt.toLocaleDateString(LANG === 'ru' ? 'ru-RU' : 'fr-FR', {year:'numeric',month:'long',day:'numeric'});
}

function getName(l) {
    if (LANG === 'ru' && l.nom_ru) return l.nom_ru;
    return l.nom_fr;
}

function getDesc(l) {
    if (LANG === 'ru' && l.description_ru) return l.description_ru;
    return l.description_fr || '';
}

function popupContent(l) {
    let html = '<div class="popup-name">' + catIcons[l.categorie] + ' ' + escH(getName(l)) + '</div>';
    if (l.nom_ru && l.nom_fr && LANG !== 'ru') html += '<div style="font-size:.55rem;color:var(--muted);margin-bottom:.3rem">' + escH(l.nom_ru) + '</div>';
    if (l.nom_fr && LANG === 'ru') html += '<div style="font-size:.55rem;color:var(--muted);margin-bottom:.3rem">' + escH(l.nom_fr) + '</div>';
    html += '<div class="popup-cat">' + l.categorie + '</div>';
    const desc = getDesc(l);
    if (desc) html += '<div class="popup-desc">' + escH(desc) + '</div>';
    if (l.date_visite) html += '<div class="popup-date">📅 ' + formatDate(l.date_visite) + '</div>';
    html += '<div style="display:flex;gap:.4rem;margin-top:.4rem">';
    html += '<button class="popup-edit" onclick="editPlace(' + l.id + ')">' + (LANG==='ru'?'✏ Изменить':'✏ Modifier') + '</button>';
    html += '<button class="popup-delete" onclick="deletePlace(' + l.id + ')">' + (LANG==='ru'?'Удалить':'Supprimer') + '</button>';
    html += '</div>';
    return html;
}

function escH(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function renderMarkers() {
    markers.forEach(m => map.removeLayer(m.layer));
    markers = [];
    LIEUX.forEach(l => {
        if (activeFilter !== 'all' && l.categorie !== activeFilter) return;
        const m = L.marker([parseFloat(l.latitude), parseFloat(l.longitude)], {icon: makeIcon(l.categorie)});
        m.bindPopup(popupContent(l), {maxWidth: 260});
        m.addTo(map);
        markers.push({layer: m, data: l});
    });
    fitMap();
}

function fitMap() {
    if (markers.length === 0) return;
    const group = L.featureGroup(markers.map(m => m.layer));
    map.fitBounds(group.getBounds().pad(0.15));
}

renderMarkers();

// Filters
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.cat;
        renderMarkers();
    });
});

// Panel
let panelOpen = false;

function openPanel() {
    if (panelOpen) return;
    panelOpen = true;
    document.getElementById('panel').classList.add('open');
    document.getElementById('panelOverlay').classList.add('open');
    map.on('click', onMapClick);
}

function closePanel() {
    panelOpen = false;
    document.getElementById('panel').classList.remove('open');
    document.getElementById('panelOverlay').classList.remove('open');
    map.off('click', onMapClick);
    if (pickMarker) { map.removeLayer(pickMarker); pickMarker = null; }
    document.getElementById('coordsDisplay').className = 'coords-display empty';
    document.getElementById('coordsDisplay').textContent = LANG === 'ru' ? 'Нажмите на карту, чтобы поставить маркер' : 'Cliquez sur la carte pour placer le marqueur';
    document.getElementById('formLat').value = '';
    document.getElementById('formLng').value = '';
    document.getElementById('nomFr').value = '';
    document.getElementById('nomRu').value = '';
    document.getElementById('descFr').value = '';
    document.getElementById('descRu').value = '';
    document.getElementById('dateVisite').value = '';
    document.getElementById('categorie').value = 'autre';
    document.getElementById('editId').value = '';
    document.getElementById('btnSubmit').disabled = true;
    document.getElementById('btnSubmit').textContent = LANG === 'ru' ? 'Сохранить место' : 'Enregistrer le lieu';
    document.getElementById('panelTitle').textContent = LANG === 'ru' ? 'Добавить место' : 'Ajouter un lieu';
}

function onMapClick(e) {
    const {lat, lng} = e.latlng;
    document.getElementById('formLat').value = lat.toFixed(8);
    document.getElementById('formLng').value = lng.toFixed(8);
    document.getElementById('coordsDisplay').className = 'coords-display';
    document.getElementById('coordsDisplay').textContent = '📍 ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    document.getElementById('btnSubmit').disabled = false;
    if (pickMarker) map.removeLayer(pickMarker);
    pickMarker = L.marker([lat, lng], {icon: pickIcon()}).addTo(map);
}

// Edit place — open panel pre-filled
function editPlace(id) {
    const lieu = LIEUX.find(l => l.id == id);
    if (!lieu) return;
    map.closePopup();

    // Open panel in edit mode
    openPanel();
    document.getElementById('panelTitle').textContent = LANG === 'ru' ? 'Изменить место' : 'Modifier le lieu';
    document.getElementById('btnSubmit').textContent = LANG === 'ru' ? 'Сохранить изменения' : 'Enregistrer les modifications';

    // Fill form
    document.getElementById('editId').value = lieu.id;
    document.getElementById('nomFr').value = lieu.nom_fr || '';
    document.getElementById('nomRu').value = lieu.nom_ru || '';
    document.getElementById('descFr').value = lieu.description_fr || '';
    document.getElementById('descRu').value = lieu.description_ru || '';
    document.getElementById('dateVisite').value = lieu.date_visite || '';
    document.getElementById('categorie').value = lieu.categorie || 'autre';
    document.getElementById('formLat').value = lieu.latitude;
    document.getElementById('formLng').value = lieu.longitude;

    // Show coords and marker
    const lat = parseFloat(lieu.latitude), lng = parseFloat(lieu.longitude);
    document.getElementById('coordsDisplay').className = 'coords-display';
    document.getElementById('coordsDisplay').textContent = '📍 ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    document.getElementById('btnSubmit').disabled = false;
    if (pickMarker) map.removeLayer(pickMarker);
    pickMarker = L.marker([lat, lng], {icon: pickIcon()}).addTo(map);
    map.flyTo([lat, lng], 14, {duration: 0.8});
}

// Submit (add or edit)
function submitPlace(e) {
    e.preventDefault();
    const editId = document.getElementById('editId').value;
    const isEdit = editId !== '';
    const fd = new FormData();
    fd.append('action', isEdit ? 'edit' : 'add');
    fd.append('csrf_token', CSRF);
    if (isEdit) fd.append('id', editId);
    fd.append('nom_fr', document.getElementById('nomFr').value);
    fd.append('nom_ru', document.getElementById('nomRu').value);
    fd.append('latitude', document.getElementById('formLat').value);
    fd.append('longitude', document.getElementById('formLng').value);
    fd.append('description_fr', document.getElementById('descFr').value);
    fd.append('description_ru', document.getElementById('descRu').value);
    fd.append('date_visite', document.getElementById('dateVisite').value);
    fd.append('categorie', document.getElementById('categorie').value);

    fetch(BASE + '/carte.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                if (isEdit) {
                    // Update local array
                    const idx = LIEUX.findIndex(l => l.id == editId);
                    if (idx !== -1) {
                        LIEUX[idx].nom_fr = document.getElementById('nomFr').value;
                        LIEUX[idx].nom_ru = document.getElementById('nomRu').value;
                        LIEUX[idx].latitude = document.getElementById('formLat').value;
                        LIEUX[idx].longitude = document.getElementById('formLng').value;
                        LIEUX[idx].description_fr = document.getElementById('descFr').value;
                        LIEUX[idx].description_ru = document.getElementById('descRu').value;
                        LIEUX[idx].date_visite = document.getElementById('dateVisite').value;
                        LIEUX[idx].categorie = document.getElementById('categorie').value;
                    }
                } else {
                    LIEUX.unshift({
                        id: data.id,
                        user_id: null,
                        nom_fr: document.getElementById('nomFr').value,
                        nom_ru: document.getElementById('nomRu').value,
                        latitude: document.getElementById('formLat').value,
                        longitude: document.getElementById('formLng').value,
                        description_fr: document.getElementById('descFr').value,
                        description_ru: document.getElementById('descRu').value,
                        date_visite: document.getElementById('dateVisite').value,
                        categorie: document.getElementById('categorie').value
                    });
                }
                renderMarkers();
                closePanel();
            } else {
                alert(data.error || <?= json_encode(t('Erreur','Ошибка')) ?>);
            }
        })
        .catch(() => alert(<?= json_encode(t('Erreur réseau','Ошибка сети')) ?>));
    return false;
}

// ═══ Search (Nominatim geocoding) ═══
let searchTimeout = null;
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');

searchInput.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    const q = searchInput.value.trim();
    if (q.length < 2) { closeSearch(); return; }
    searchTimeout = setTimeout(() => doSearch(), 400);
});

searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimeout); doSearch(); }
    if (e.key === 'Escape') closeSearch();
});

document.addEventListener('click', (e) => {
    if (!document.getElementById('searchBox').contains(e.target)) closeSearch();
});

function closeSearch() {
    searchResults.classList.remove('open');
    searchResults.innerHTML = '';
}

function doSearch() {
    const q = searchInput.value.trim();
    if (q.length < 2) return;
    searchResults.innerHTML = '<div class="search-loading">⏳ ' + (LANG === 'ru' ? 'Поиск...' : 'Recherche...') + '</div>';
    searchResults.classList.add('open');

    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=6&accept-language=' + LANG + '&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(results => {
            if (!results.length) {
                searchResults.innerHTML = '<div class="search-loading">' + (LANG === 'ru' ? 'Ничего не найдено' : 'Aucun résultat') + '</div>';
                return;
            }
            searchResults.innerHTML = '';
            results.forEach(r => {
                const div = document.createElement('div');
                div.className = 'search-result';
                const parts = r.display_name.split(',');
                div.innerHTML = escH(parts[0]) + (parts.length > 1 ? '<small>' + escH(parts.slice(1).join(',').trim()) + '</small>' : '');
                div.addEventListener('click', () => selectSearchResult(r));
                searchResults.appendChild(div);
            });
        })
        .catch(() => {
            searchResults.innerHTML = '<div class="search-loading">' + (LANG === 'ru' ? 'Ошибка сети' : 'Erreur réseau') + '</div>';
        });
}

function selectSearchResult(r) {
    const lat = parseFloat(r.lat);
    const lng = parseFloat(r.lon);
    const name = r.display_name.split(',')[0];
    const zoom = (r.type === 'city' || r.type === 'administrative' || r.class === 'place') ? 12 : 15;

    closeSearch();
    searchInput.value = name;

    // Always open the panel and fill everything
    if (!document.getElementById('panel').classList.contains('open')) {
        openPanel();
    }

    // Fly to location
    map.flyTo([lat, lng], zoom, {duration: 1.2});

    // Set coordinates
    document.getElementById('formLat').value = lat.toFixed(8);
    document.getElementById('formLng').value = lng.toFixed(8);
    document.getElementById('coordsDisplay').className = 'coords-display';
    document.getElementById('coordsDisplay').textContent = '📍 ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    document.getElementById('btnSubmit').disabled = false;

    // Place pick marker
    if (pickMarker) map.removeLayer(pickMarker);
    pickMarker = L.marker([lat, lng], {icon: pickIcon()}).addTo(map);

    // Auto-fill name (FR)
    if (!document.getElementById('nomFr').value) {
        document.getElementById('nomFr').value = name;
        // Trigger auto-translate to fill RU
        document.getElementById('nomFr').dispatchEvent(new Event('input', {bubbles: true}));
    }

    // Auto-detect category from Nominatim type
    const catMap = {
        'city': 'ville', 'town': 'ville', 'village': 'ville', 'hamlet': 'ville',
        'restaurant': 'restaurant', 'cafe': 'restaurant', 'bar': 'restaurant', 'fast_food': 'restaurant',
        'peak': 'nature', 'wood': 'nature', 'forest': 'nature', 'park': 'nature', 'garden': 'nature', 'nature_reserve': 'nature',
        'monument': 'monument', 'museum': 'monument', 'castle': 'monument', 'church': 'monument', 'cathedral': 'monument', 'memorial': 'monument', 'ruins': 'monument', 'archaeological_site': 'monument',
        'beach': 'plage'
    };
    const detectedCat = catMap[r.type] || catMap[r.class] || null;
    if (detectedCat) {
        document.getElementById('categorie').value = detectedCat;
    }
}

// ═══ Auto-translate ═══
autoTranslate([
    { fr: '#nomFr',  ru: '#nomRu'  },
    { fr: '#descFr', ru: '#descRu' }
], LANG, BASE);

// Delete
function deletePlace(id) {
    if (!confirm(LANG === 'ru' ? 'Удалить это место?' : 'Supprimer ce lieu ?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fetch(BASE + '/carte.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const idx = LIEUX.findIndex(l => l.id == id);
                if (idx !== -1) LIEUX.splice(idx, 1);
                renderMarkers();
                map.closePopup();
            }
        });
}
</script>
</body>
</html>
