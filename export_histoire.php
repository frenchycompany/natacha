<?php
require_once __DIR__.'/config.php';
requireLogin();
// Custom security headers allowing CDN scripts for jsPDF
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com");

$user = currentUser();
$lang = $user['lang'];

// Fetch ALL chapters ordered by date
$stmt = db()->prepare("SELECT h.*, u.display_name, u.avatar FROM histoire_chapitres h JOIN users u ON u.id=h.user_id ORDER BY COALESCE(h.event_date, h.created_at) ASC");
$stmt->execute();
$allChapitres = $stmt->fetchAll();

// Get date range
$firstDate = null;
$lastDate = null;
if (!empty($allChapitres)) {
    $firstDate = $allChapitres[0]['event_date'] ?? date('Y-m-d', strtotime($allChapitres[0]['created_at']));
    $last = end($allChapitres);
    $lastDate = $last['event_date'] ?? date('Y-m-d', strtotime($last['created_at']));
}

// Prepare photo data (base64 for PDF embedding)
function getPhotoBase64($filename) {
    $path = __DIR__ . '/uploads/histoire/' . $filename;
    if (!$filename || !file_exists($path)) return null;
    $mime = mime_content_type($path);
    $data = base64_encode(file_get_contents($path));
    return "data:$mime;base64,$data";
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Natacha — <?= t('Exporter Notre Histoire','Экспорт Нашей Истории') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
/* ═══ Screen Styles (Dark/Gold Theme) ═══ */
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}

.topbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 2rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem;transition:all .2s}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-style:italic;color:var(--accent)}

/* Controls panel */
.controls{max-width:800px;margin:2rem auto;padding:0 2rem}
.controls-inner{border:1px solid var(--border);padding:1.5rem;background:var(--s)}
.controls h3{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--accent);margin-bottom:1rem}
.control-row{display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start;margin-bottom:1rem}
.control-group label{display:block;font-size:.55rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.control-group select{background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.7rem;padding:.4rem .6rem;outline:none}
.control-group select:focus{border-color:var(--accent)}

.chapter-select{margin-top:1rem;max-height:200px;overflow-y:auto;border:1px solid var(--border);padding:.8rem;background:var(--bg)}
.chapter-select label{display:flex;align-items:center;gap:.5rem;font-size:.65rem;color:var(--text);padding:.3rem 0;cursor:pointer;text-transform:none;letter-spacing:0}
.chapter-select input[type=checkbox]{accent-color:var(--accent);cursor:pointer}
.select-actions{display:flex;gap:.5rem;margin-bottom:.6rem}
.select-actions button{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.2rem .5rem;cursor:pointer;font-family:'DM Mono',monospace;transition:all .2s}
.select-actions button:hover{border-color:var(--accent);color:var(--accent)}

.btn{font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--accent);color:var(--accent);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;font-family:'DM Mono',monospace}
.btn:hover,.btn.primary{background:var(--accent);color:#0f0d0b}
.btn-row{display:flex;flex-wrap:wrap;gap:.8rem;margin-top:1.2rem}

.generating{display:none;align-items:center;gap:.6rem;font-size:.65rem;color:var(--accent);margin-top:1rem}
.generating.show{display:flex}
.spinner{width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* ═══ Book Preview / Print Content ═══ */
#book-content{max-width:800px;margin:2rem auto;padding:0 2rem}
.book{background:#fffef9;color:#1a1816;font-family:'Cormorant Garamond',serif}

/* Title page */
.title-page{text-align:center;padding:4rem 2rem;min-height:600px;display:flex;flex-direction:column;justify-content:center;align-items:center;border:1px solid var(--border)}
.title-page .ornament{font-size:2rem;color:#c9a96e;margin-bottom:2rem;letter-spacing:.5rem}
.title-page h1{font-family:'Cormorant Garamond',serif;font-size:2.6rem;font-weight:300;color:#1a1816;line-height:1.3;margin-bottom:.5rem}
.title-page h2{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:#7a7268;margin-bottom:2rem}
.title-page .dates{font-size:.85rem;color:#7a7268;letter-spacing:.15em;margin-top:1rem}
.title-page .ornament-bottom{font-size:1.5rem;color:#c9a96e;margin-top:2.5rem;letter-spacing:1rem}

/* TOC */
.toc-page{padding:2.5rem;border:1px solid var(--border);border-top:none}
.toc-page h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;color:#c9a96e;text-align:center;margin-bottom:2rem;letter-spacing:.1em}
.toc-item{display:flex;align-items:baseline;padding:.5rem 0;border-bottom:1px dotted #d4cfc6}
.toc-num{font-size:.85rem;color:#c9a96e;min-width:2rem;font-weight:500}
.toc-title{flex:1;font-size:.95rem;color:#1a1816}
.toc-title-ru{font-style:italic;color:#7a7268;font-size:.85rem;margin-left:.3rem}
.toc-date{font-size:.75rem;color:#7a7268;margin-left:1rem;white-space:nowrap}

/* Chapter pages */
.chapter-page{padding:2.5rem;border:1px solid var(--border);border-top:none;position:relative}
.chapter-number{font-family:'Cormorant Garamond',serif;font-size:3rem;font-weight:300;color:#c9a96e;line-height:1;margin-bottom:.3rem}
.chapter-title-fr{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:400;color:#1a1816;line-height:1.3;margin-bottom:.2rem}
.chapter-title-ru{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;font-style:italic;color:#7a7268;line-height:1.3;margin-bottom:1rem}
.chapter-meta{font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.1em;color:#9a9490;margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem}
.chapter-meta .dot{color:#c9a96e}
.chapter-divider{width:3rem;height:1px;background:linear-gradient(90deg,#c9a96e,transparent);margin-bottom:1.5rem}
.chapter-body-fr{font-family:'Cormorant Garamond',serif;font-size:1.1rem;line-height:1.85;color:#2a2622;white-space:pre-wrap;margin-bottom:1.5rem}
.chapter-body-ru{font-family:'Cormorant Garamond',serif;font-size:1rem;line-height:1.8;color:#7a7268;font-style:italic;white-space:pre-wrap;padding-top:1rem;border-top:1px solid #e8e0d5}
.chapter-photo{width:100%;max-height:400px;object-fit:cover;margin:1.5rem 0;border:1px solid #e8e0d5}
.chapter-separator{text-align:center;padding:1rem 0;color:#c9a96e;font-size:1.2rem;letter-spacing:1rem}

/* Footer on each chapter */
.chapter-footer{margin-top:2rem;padding-top:1rem;border-top:1px solid #e8e0d5;font-family:'DM Mono',monospace;font-size:.55rem;color:#b0a89e;letter-spacing:.12em;text-align:center}

/* Colophon */
.colophon{text-align:center;padding:4rem 2rem;border:1px solid var(--border);border-top:none}
.colophon .ornament{font-size:1.5rem;color:#c9a96e;letter-spacing:1rem;margin-bottom:1.5rem}
.colophon p{font-family:'Cormorant Garamond',serif;font-size:1rem;color:#7a7268;font-style:italic;line-height:1.6}

/* ═══ Print Styles ═══ */
@media print {
  body{background:#fff!important;color:#000!important}
  body::before{display:none!important}
  .topbar,.controls,.no-print{display:none!important}
  #book-content{max-width:none;margin:0;padding:0}
  .book{background:#fff;border:none}
  .title-page,.toc-page,.chapter-page,.colophon{border:none!important;padding:2rem 3rem}
  .title-page{min-height:100vh;page-break-after:always}
  .toc-page{page-break-after:always}
  .chapter-page{page-break-before:always}
  .colophon{page-break-before:always}
  .chapter-photo{max-height:300px}
  @page{margin:1.5cm 2cm;size:A4}
}

/* Language visibility */
.book.lang-fr .ru-only{display:none!important}
.book.lang-ru .fr-only{display:none!important}
.book.lang-fr .chapter-body-ru{display:none!important}
.book.lang-fr .chapter-title-ru{display:none!important}
.book.lang-fr .toc-title-ru{display:none!important}
.book.lang-ru .chapter-body-fr{display:none!important}
.book.lang-ru .chapter-title-fr{display:none!important}
.book.lang-ru .toc-title .main{display:none!important}
</style>
</head>
<body>

<div class="topbar no-print">
  <a class="back" href="<?= BASE_URL ?>/histoire.php">&larr; <?= t('Retour','Назад') ?></a>
  <div class="topbar-title">📖 <?= t('Exporter en livre','Экспорт в книгу') ?></div>
  <span style="width:80px"></span>
</div>

<!-- ═══ Controls Panel ═══ -->
<div class="controls no-print">
  <div class="controls-inner">
    <h3><?= t('Options d\'export','Настройки экспорта') ?></h3>
    <div class="control-row">
      <div class="control-group">
        <label><?= t('Langues','Языки') ?></label>
        <select id="langChoice" onchange="updateLanguage()">
          <option value="both" selected><?= t('Fran&ccedil;ais + Russe','Французский + Русский') ?></option>
          <option value="fr"><?= t('Fran&ccedil;ais seulement','Только французский') ?></option>
          <option value="ru"><?= t('Russe seulement','Только русский') ?></option>
        </select>
      </div>
    </div>

    <div class="chapter-select">
      <div class="select-actions">
        <button onclick="selectAll(true)"><?= t('Tout','Все') ?></button>
        <button onclick="selectAll(false)"><?= t('Rien','Ничего') ?></button>
      </div>
      <?php foreach ($allChapitres as $i => $c):
        $chapLang = $c['langue'] ?? 'fr';
        $title = h($c['titre']);
        $date = $c['event_date'] ?? date('d/m/Y', strtotime($c['created_at']));
      ?>
      <label>
        <input type="checkbox" class="chap-check" data-index="<?= $i ?>" checked onchange="toggleChapter(<?= $i ?>)">
        <span style="color:var(--accent);min-width:1.5rem"><?= $i + 1 ?>.</span>
        <?= $title ?> <span style="color:var(--muted);margin-left:.3rem">(<?= $date ?>)</span>
      </label>
      <?php endforeach; ?>
      <?php if (empty($allChapitres)): ?>
      <p style="color:var(--muted);font-size:.65rem;padding:.5rem 0"><?= t('Aucun chapitre disponible.','Нет доступных глав.') ?></p>
      <?php endif; ?>
    </div>

    <div class="btn-row">
      <button class="btn primary" onclick="window.print()">
        <?= t('Imprimer / PDF','Печать / PDF') ?>
      </button>
      <button class="btn" id="btnJsPdf" onclick="generatePDF()">
        <?= t('T&eacute;l&eacute;charger PDF','Скачать PDF') ?>
      </button>
    </div>
    <div class="generating" id="generating">
      <div class="spinner"></div>
      <span><?= t('G&eacute;n&eacute;ration du PDF en cours...','Генерация PDF...') ?></span>
    </div>
  </div>
</div>

<!-- ═══ Book Content ═══ -->
<div id="book-content">
<div class="book lang-both" id="book">

  <!-- Title Page -->
  <div class="title-page">
    <div class="ornament">&loz; &mdash; &loz;</div>
    <h1 class="fr-only">Notre Histoire</h1>
    <h2 class="fr-only" style="color:#7a7268">Наша История</h2>
    <h1 class="ru-only">Наша История</h1>
    <h2 class="ru-only" style="color:#7a7268">Notre Histoire</h2>
    <?php if ($firstDate && $lastDate): ?>
    <div class="dates"><?= date('d.m.Y', strtotime($firstDate)) ?> &mdash; <?= date('d.m.Y', strtotime($lastDate)) ?></div>
    <?php endif; ?>
    <div class="ornament-bottom">&bull; &bull; &bull;</div>
  </div>

  <!-- Table of Contents -->
  <?php if (!empty($allChapitres)): ?>
  <div class="toc-page">
    <h2><?= t('Table des mati&egrave;res','Содержание') ?></h2>
    <?php foreach ($allChapitres as $i => $c):
      $chapLang = $c['langue'] ?? 'fr';
      $titleOrig = h($c['titre']);
      $titleTrad = h($c['titre_traduit'] ?? '');
      if ($chapLang === 'fr') {
          $titleFr = $titleOrig;
          $titleRu = $titleTrad;
      } else {
          $titleRu = $titleOrig;
          $titleFr = $titleTrad;
      }
      $date = $c['event_date'] ?? date('d.m.Y', strtotime($c['created_at']));
      if (strpos($date, '-') !== false) $date = date('d.m.Y', strtotime($date));
    ?>
    <div class="toc-item" data-chapter-index="<?= $i ?>">
      <span class="toc-num"><?= $i + 1 ?></span>
      <span class="toc-title">
        <span class="main fr-only"><?= $titleFr ?></span>
        <span class="main ru-only"><?= $titleRu ?></span>
        <?php if ($titleRu): ?><span class="toc-title-ru fr-only"> &mdash; <?= $titleRu ?></span><?php endif; ?>
        <?php if ($titleFr): ?><span class="toc-title-ru ru-only"> &mdash; <?= $titleFr ?></span><?php endif; ?>
      </span>
      <span class="toc-date"><?= $date ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Chapter Pages -->
  <?php foreach ($allChapitres as $i => $c):
    $chapLang = $c['langue'] ?? 'fr';
    $titleOrig = $c['titre'];
    $titleTrad = $c['titre_traduit'] ?? '';
    $contentOrig = $c['contenu'];
    $contentTrad = $c['contenu_traduit'] ?? '';
    if ($chapLang === 'fr') {
        $titleFr = $titleOrig; $titleRu = $titleTrad;
        $contentFr = $contentOrig; $contentRu = $contentTrad;
    } else {
        $titleRu = $titleOrig; $titleFr = $titleTrad;
        $contentRu = $contentOrig; $contentFr = $contentTrad;
    }
    $date = $c['event_date'] ?? date('d.m.Y', strtotime($c['created_at']));
    if (strpos($date, '-') !== false) $date = date('d.m.Y', strtotime($date));
    $photoData = !empty($c['photo']) ? getPhotoBase64($c['photo']) : null;
  ?>
  <div class="chapter-page" data-chapter-index="<?= $i ?>">
    <div class="chapter-number"><?= $i + 1 ?></div>
    <?php if ($titleFr): ?><div class="chapter-title-fr"><?= h($titleFr) ?></div><?php endif; ?>
    <?php if ($titleRu): ?><div class="chapter-title-ru"><?= h($titleRu) ?></div><?php endif; ?>
    <div class="chapter-meta">
      <span><?= h($c['display_name']) ?></span>
      <span class="dot">&bull;</span>
      <span><?= $date ?></span>
    </div>
    <div class="chapter-divider"></div>
    <?php if ($photoData): ?>
    <img class="chapter-photo" src="<?= $photoData ?>" alt="">
    <?php endif; ?>
    <?php if ($contentFr): ?><div class="chapter-body-fr"><?= h($contentFr) ?></div><?php endif; ?>
    <?php if ($contentRu): ?><div class="chapter-body-ru"><?= h($contentRu) ?></div><?php endif; ?>
    <div class="chapter-footer">Natacha &mdash; Notre Espace Priv&eacute;</div>
  </div>
  <?php endforeach; ?>

  <!-- Colophon -->
  <div class="colophon">
    <div class="ornament">&loz; &mdash; &loz;</div>
    <p><?= t('Ce livre a &eacute;t&eacute; g&eacute;n&eacute;r&eacute; avec amour','Эта книга была создана с любовью') ?></p>
    <p style="margin-top:.5rem;font-size:.85rem"><?= date('d F Y') ?></p>
    <p style="margin-top:1rem;font-size:.8rem;color:#b0a89e">Natacha &mdash; Notre Espace Priv&eacute;</p>
  </div>

</div>
</div>

<!-- jsPDF + html2canvas -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js"></script>
<script>
function updateLanguage() {
  const choice = document.getElementById('langChoice').value;
  const book = document.getElementById('book');
  book.className = 'book lang-' + choice;
}

function selectAll(checked) {
  document.querySelectorAll('.chap-check').forEach(cb => {
    cb.checked = checked;
    toggleChapter(parseInt(cb.dataset.index), checked);
  });
}

function toggleChapter(index, forceState) {
  const state = forceState !== undefined ? forceState : document.querySelector('.chap-check[data-index="'+index+'"]').checked;
  // Toggle chapter page
  document.querySelectorAll('.chapter-page[data-chapter-index="'+index+'"]').forEach(el => {
    el.style.display = state ? '' : 'none';
  });
  // Toggle TOC entry
  document.querySelectorAll('.toc-item[data-chapter-index="'+index+'"]').forEach(el => {
    el.style.display = state ? '' : 'none';
  });
}

async function generatePDF() {
  const btn = document.getElementById('btnJsPdf');
  const gen = document.getElementById('generating');
  btn.disabled = true;
  gen.classList.add('show');

  try {
    const { jsPDF } = window.jspdf;
    const book = document.getElementById('book');

    // Temporarily make book look print-ready
    const origBg = book.style.background;
    book.style.background = '#fffef9';

    // Get all visible sections
    const sections = [];
    const titlePage = book.querySelector('.title-page');
    if (titlePage) sections.push(titlePage);
    const tocPage = book.querySelector('.toc-page');
    if (tocPage) sections.push(tocPage);
    book.querySelectorAll('.chapter-page').forEach(cp => {
      if (cp.style.display !== 'none') sections.push(cp);
    });
    const colophon = book.querySelector('.colophon');
    if (colophon) sections.push(colophon);

    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageWidth = 210;
    const pageHeight = 297;
    const margin = 10;
    const contentWidth = pageWidth - margin * 2;

    for (let i = 0; i < sections.length; i++) {
      if (i > 0) pdf.addPage();

      const canvas = await html2canvas(sections[i], {
        scale: 2,
        useCORS: true,
        backgroundColor: '#fffef9',
        logging: false,
        windowWidth: 800
      });

      const imgData = canvas.toDataURL('image/jpeg', 0.92);
      const imgWidth = contentWidth;
      const imgHeight = (canvas.height * imgWidth) / canvas.width;

      // If content is taller than one page, scale to fit or split
      if (imgHeight > pageHeight - margin * 2) {
        const scale = (pageHeight - margin * 2) / imgHeight;
        const scaledWidth = imgWidth * scale;
        const scaledHeight = imgHeight * scale;
        const xOffset = margin + (contentWidth - scaledWidth) / 2;
        pdf.addImage(imgData, 'JPEG', xOffset, margin, scaledWidth, scaledHeight);
      } else {
        pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
      }
    }

    book.style.background = origBg;
    pdf.save('Notre_Histoire.pdf');
  } catch (err) {
    console.error('PDF generation error:', err);
    alert('<?= t("Erreur lors de la génération du PDF. Essayez \"Imprimer / PDF\" à la place.","Ошибка при генерации PDF. Попробуйте «Печать / PDF».") ?>');
  }

  btn.disabled = false;
  gen.classList.remove('show');
}
</script>
</body>
</html>
