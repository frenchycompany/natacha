<?php
/**
 * NATACHA — Les Paris
 * Suivi des paris du couple : énoncé + enjeu, on désigne le gagnant, score tenu.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
require_once __DIR__.'/includes/couple_helper.php';
require_once __DIR__.'/includes/notifications.php';

$user = currentUser();
$lang = $user['lang'] ?? 'fr';
$uid  = (int)($user['id'] ?? 0);

$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $stmt = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $coupleId = $stmt->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }
$coupleId = (int)$coupleId;

// Filet de sécurité : créer la table au premier accès (idempotent), comme le reste du repo.
if (empty($_SESSION['_tbl_paris'])) {
    try { db()->query("SELECT 1 FROM paris LIMIT 1"); } catch (Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS paris (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            couple_id         INT UNSIGNED NOT NULL,
            created_by        INT UNSIGNED NOT NULL,
            enonce            VARCHAR(500) NOT NULL,
            enonce_translated VARCHAR(500) DEFAULT NULL,
            enjeu             VARCHAR(300) DEFAULT NULL,
            enjeu_translated  VARCHAR(300) DEFAULT NULL,
            src_lang          CHAR(2) NOT NULL DEFAULT 'fr',
            statut            ENUM('ouvert','resolu') NOT NULL DEFAULT 'ouvert',
            winner_user_id    INT UNSIGNED DEFAULT NULL,
            created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at       DATETIME DEFAULT NULL,
            INDEX idx_couple_statut (couple_id, statut, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $_SESSION['_tbl_paris'] = 1;
}

// Les 2 membres du couple
$mstmt = db()->prepare("SELECT id, display_name, avatar FROM users WHERE couple_id=? ORDER BY id");
$mstmt->execute([$coupleId]);
$members = $mstmt->fetchAll();
$memberIds = array_map('intval', array_column($members, 'id'));

// ═══ Actions POST (JSON) ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $enonce = trim($_POST['enonce'] ?? '');
        if ($enonce === '' || mb_strlen($enonce) > 500) {
            echo json_encode(['ok'=>false,'error'=>t('Énoncé vide ou trop long','Текст пари пустой или слишком длинный')]);
            exit;
        }
        $src = $lang === 'ru' ? 'ru' : 'fr';
        $dst = $src === 'fr' ? 'ru' : 'fr';
        $enonceT = translateText($enonce, $src, $dst) ?: null;

        db()->prepare("INSERT INTO paris (couple_id, created_by, enonce, enonce_translated, src_lang)
            VALUES (?,?,?,?,?)")
            ->execute([$coupleId, $uid, $enonce, $enonceT, $src]);

        try {
            $name = $user['display_name'] ?? '';
            notifyOtherUser($uid, 'pari',
                ($name !== '' ? $name : 'Quelqu\'un').' a lancé un pari 🎲',
                ($name !== '' ? $name : 'Кто-то').' предложил пари 🎲',
                BASE_URL.'/paris.php');
        } catch (Exception $e) {}

        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'resolve') {
        $pid = (int)($_POST['pari_id'] ?? 0);
        $win = (int)($_POST['winner_user_id'] ?? 0);
        $enjeu = trim($_POST['enjeu'] ?? '');
        if (!in_array($win, $memberIds, true)) {
            echo json_encode(['ok'=>false,'error'=>t('Gagnant invalide','Неверный победитель')]); exit;
        }
        if ($enjeu === '' || mb_strlen($enjeu) > 300) {
            echo json_encode(['ok'=>false,'error'=>t('Enjeu vide ou trop long','Ставка пустая или слишком длинная')]); exit;
        }
        $chk = db()->prepare("SELECT id FROM paris WHERE id=? AND couple_id=? AND statut='ouvert'");
        $chk->execute([$pid, $coupleId]);
        if (!$chk->fetch()) {
            echo json_encode(['ok'=>false,'error'=>t('Pari introuvable ou déjà résolu','Пари не найдено или уже решено')]); exit;
        }
        $src = $lang === 'ru' ? 'ru' : 'fr';
        $dst = $src === 'fr' ? 'ru' : 'fr';
        $enjeuT = translateText($enjeu, $src, $dst) ?: null;
        db()->prepare("UPDATE paris SET statut='resolu', winner_user_id=?, enjeu=?, enjeu_translated=?, resolved_at=NOW() WHERE id=? AND couple_id=?")
            ->execute([$win, $enjeu, $enjeuT, $pid, $coupleId]);

        $winName = '';
        foreach ($members as $m) { if ((int)$m['id'] === $win) { $winName = $m['display_name']; break; } }

        try {
            notifyOtherUser($uid, 'pari',
                '🏆 '.$winName.' a gagné le pari',
                '🏆 '.$winName.' выиграл пари',
                BASE_URL.'/paris.php');
        } catch (Exception $e) {}
        try {
            (new CoupleEntity(db()))->recordActivity($coupleId, $uid, 'pari', $winName.' a gagné un pari', null);
        } catch (Exception $e) {}

        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown']);
    exit;
}

// ═══ Données d'affichage ═══
$scores = [];
foreach ($memberIds as $mid) { $scores[$mid] = 0; }
try {
    $sb = db()->prepare("SELECT winner_user_id, COUNT(*) c FROM paris WHERE couple_id=? AND statut='resolu' AND winner_user_id IS NOT NULL GROUP BY winner_user_id");
    $sb->execute([$coupleId]);
    foreach ($sb->fetchAll() as $r) { $scores[(int)$r['winner_user_id']] = (int)$r['c']; }
} catch (Exception $e) {}

$open = []; $resolved = [];
try {
    $o = db()->prepare("SELECT * FROM paris WHERE couple_id=? AND statut='ouvert' ORDER BY created_at DESC");
    $o->execute([$coupleId]); $open = $o->fetchAll();
    $rr = db()->prepare("SELECT p.*, u.display_name AS winner_name FROM paris p LEFT JOIN users u ON u.id=p.winner_user_id
        WHERE p.couple_id=? AND p.statut='resolu' ORDER BY p.resolved_at DESC LIMIT 30");
    $rr->execute([$coupleId]); $resolved = $rr->fetchAll();
} catch (Exception $e) {}

// Affiche un texte + sa traduction (en muted) si dispo et différente
function pari_bilingue(?string $main, ?string $trad): string {
    $out = '<span class="p-main">'.h($main ?? '').'</span>';
    if ($trad && trim($trad) !== '' && mb_strtolower(trim($trad)) !== mb_strtolower(trim((string)$main))) {
        $out .= '<span class="p-trad">'.h($trad).'</span>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Les Paris','Пари') ?> — Natacha</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
.topbar{display:flex;align-items:center;gap:1rem;padding:1.1rem 1.4rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:50}
.back{font-size:.7rem;color:var(--muted);text-decoration:none;letter-spacing:.05em}
.back:hover{color:var(--accent)}
.topbar-title{flex:1;text-align:center;font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--accent);font-size:1.4rem}
.spacer{width:3rem}
.wrap{max-width:640px;margin:0 auto;padding:1.8rem 1.2rem}

/* Score */
.score{display:flex;align-items:center;justify-content:center;gap:1.4rem;background:var(--s);border:1px solid rgba(201,169,110,.25);padding:1.4rem;margin-bottom:1.6rem}
.score-side{text-align:center;min-width:5rem}
.score-name{font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--text);font-size:1.05rem}
.score-num{font-family:'Cormorant Garamond',serif;color:var(--accent);font-size:2.4rem;line-height:1.05}
.score-sep{color:var(--muted);font-size:.8rem;letter-spacing:.1em}

/* Form */
.creer{background:var(--s);border:1px solid var(--border);padding:1.2rem;margin-bottom:1.8rem}
.creer-label{font-size:.55rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:.7rem}
.p-input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1rem;padding:.6rem .8rem;outline:none;transition:border-color .2s;resize:vertical}
.p-input:focus{border-color:var(--accent)}
.p-input::placeholder{color:var(--muted)}
.p-input.enjeu{font-family:'DM Mono',monospace;font-style:normal;font-size:.72rem;margin-top:.6rem}
.creer-foot{display:flex;justify-content:space-between;align-items:center;margin-top:.8rem}
.p-counter{font-size:.5rem;color:var(--muted)}
.btn{background:var(--as);border:1px solid var(--accent);color:var(--accent);font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.55rem 1.1rem;cursor:pointer;transition:all .2s}
.btn:hover{background:var(--accent);color:var(--bg)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.creer-hint{font-size:.55rem;color:var(--muted);font-style:italic;margin-top:.7rem;opacity:.8}
.enjeu-form{margin-top:.9rem;animation:enjeuIn .25s ease}
@keyframes enjeuIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.enjeu-form .enjeu-input{margin-top:0}
.enjeu-form-foot{display:flex;justify-content:flex-end;align-items:center;gap:.9rem;margin-top:.6rem}
.link-cancel{background:none;border:none;color:var(--muted);font-family:'DM Mono',monospace;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;transition:color .2s}
.link-cancel:hover{color:var(--accent)}
.done-trad{color:var(--muted);opacity:.7;font-size:.52rem}

.sec-label{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.15rem;margin:1.6rem 0 .9rem;padding-bottom:.4rem;border-bottom:1px solid var(--border)}
.sec-open{color:var(--accent)}
.sec-done{color:var(--muted)}
.empty{font-size:.62rem;color:var(--muted);font-style:italic;padding:.4rem 0}

/* Cards */
.pari{background:var(--s);border:1px solid var(--border);padding:1.1rem;margin-bottom:.9rem}
.pari.open{border-color:rgba(201,169,110,.22)}
.pari .p-main{display:block;font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--text);font-size:1.1rem;line-height:1.45}
.pari .p-trad{display:block;font-family:'Cormorant Garamond',serif;font-style:italic;color:var(--muted);font-size:.8rem;opacity:.7;margin-top:.2rem}
.pari-enjeu{font-size:.6rem;color:var(--muted);margin-top:.5rem;line-height:1.6}
.pari-enjeu .p-main{font-family:'DM Mono',monospace;font-style:normal;font-size:.62rem;color:#a89a7e}
.pari-enjeu .p-trad{font-family:'DM Mono',monospace;font-style:normal;font-size:.58rem}
.resolve-row{display:flex;gap:.6rem;margin-top:.9rem}
.resolve-btn{flex:1;text-align:center;background:transparent;border:1px solid var(--border);color:#a89a7e;font-family:'DM Mono',monospace;font-size:.58rem;letter-spacing:.04em;padding:.6rem .3rem;cursor:pointer;transition:all .2s}
.resolve-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--as)}

.pari.done{background:#100e0c;opacity:.9}
.pari.done .p-main{color:var(--muted);text-decoration:line-through}
.done-foot{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-top:.7rem;flex-wrap:wrap}
.winner-badge{border:1px solid var(--accent);color:var(--accent);font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .55rem;white-space:nowrap}
.done-enjeu{font-size:.58rem;color:var(--muted);text-align:right}
</style>
</head>
<body>
<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title"><?= t('Les Paris','Пари') ?></div>
  <div class="spacer"></div>
</div>

<div class="wrap">

  <!-- Score -->
  <div class="score">
    <?php foreach ($members as $i => $m): ?>
      <?php if ($i > 0): ?><div class="score-sep">—</div><?php endif; ?>
      <div class="score-side">
        <div class="score-name"><?= h($m['display_name']) ?></div>
        <div class="score-num"><?= (int)($scores[(int)$m['id']] ?? 0) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Lancer un pari -->
  <div class="creer">
    <div class="creer-label">✍ <?= t('Lancer un pari','Сделать пари') ?></div>
    <textarea class="p-input" id="enonce" rows="2" maxlength="500" placeholder="<?= t('Je parie que…','Спорим, что…') ?>"></textarea>
    <div class="creer-foot">
      <span class="p-counter" id="counter">0 / 500</span>
      <button class="btn" id="parier" disabled onclick="creerPari()"><?= t('Parier','Спорим') ?></button>
    </div>
    <div class="creer-hint"><?= t("L'enjeu sera choisi par le gagnant à la fin.",'Ставку выберет победитель в конце.') ?></div>
  </div>

  <!-- En cours -->
  <div class="sec-label sec-open"><?= t('En cours','Активные') ?></div>
  <?php if (!$open): ?>
    <div class="empty"><?= t('Aucun pari en cours. Lance le premier !','Нет активных пари. Сделай первое!') ?></div>
  <?php else: foreach ($open as $p): ?>
    <div class="pari open">
      <div class="pari-enonce"><?= pari_bilingue($p['enonce'], $p['enonce_translated']) ?></div>
      <div class="resolve-row">
        <?php foreach ($members as $m): ?>
          <button class="resolve-btn" onclick="pickWinner(this, <?= (int)$p['id'] ?>, <?= (int)$m['id'] ?>, <?= h(json_encode($m['display_name'], JSON_UNESCAPED_UNICODE)) ?>)">
            <?= t('Gagné par','Выиграл') ?> <?= h($m['display_name']) ?>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="enjeu-form" style="display:none">
        <input type="text" class="p-input enjeu enjeu-input" maxlength="300" placeholder="<?= t('Ce que le gagnant a décidé…','Что решил победитель…') ?>">
        <div class="enjeu-form-foot">
          <button class="link-cancel" onclick="cancelEnjeu(this)"><?= t('Annuler','Отмена') ?></button>
          <button class="btn" onclick="validerEnjeu(this)"><?= t('Valider','ОК') ?></button>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <!-- Terminés -->
  <?php if ($resolved): ?>
  <div class="sec-label sec-done"><?= t('Terminés','Завершённые') ?></div>
  <?php foreach ($resolved as $p): ?>
    <div class="pari done">
      <div class="pari-enonce"><?= pari_bilingue($p['enonce'], $p['enonce_translated']) ?></div>
      <div class="done-foot">
        <span class="winner-badge">🏆 <?= h($p['winner_name'] ?? '') ?> <?= t('a décidé','решил(а)') ?></span>
        <span class="done-enjeu">« <?= h($p['enjeu'] ?? '') ?> »<?php if (!empty($p['enjeu_translated']) && mb_strtolower(trim($p['enjeu_translated'])) !== mb_strtolower(trim((string)$p['enjeu']))): ?><br><span class="done-trad">« <?= h($p['enjeu_translated']) ?> »</span><?php endif; ?></span>
      </div>
    </div>
  <?php endforeach; endif; ?>

</div>

<?php include __DIR__.'/includes/bottom_nav.php'; ?>
<script>
const CSRF = '<?= csrfToken() ?>';
const NET  = <?= json_encode(t('Erreur réseau','Ошибка сети')) ?>;
const TPL_ENJEU = <?= json_encode(t('Ce que %s a décidé…','Что решил(а) %s…')) ?>;

(function(){
  const en = document.getElementById('enonce');
  const btn = document.getElementById('parier');
  const counter = document.getElementById('counter');
  en.addEventListener('input', function(){
    counter.textContent = en.value.length + ' / 500';
    btn.disabled = en.value.trim().length === 0;
  });
})();

function creerPari(){
  const en = document.getElementById('enonce').value.trim();
  if (!en) return;
  const btn = document.getElementById('parier');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action','create');
  fd.append('csrf_token', CSRF);
  fd.append('enonce', en);
  fetch('<?= BASE_URL ?>/paris.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{ if(d.ok){ location.reload(); } else { alert(d.error || 'Erreur'); btn.disabled=false; } })
    .catch(()=>{ alert(NET); btn.disabled=false; });
}

// Clic sur un gagnant : on révèle le champ « enjeu décidé par le gagnant »
function pickWinner(btn, pid, winnerId, winnerName){
  const card = btn.closest('.pari');
  card.querySelector('.resolve-row').style.display = 'none';
  const form = card.querySelector('.enjeu-form');
  form.style.display = 'block';
  form.dataset.pid = pid;
  form.dataset.winner = winnerId;
  const inp = form.querySelector('.enjeu-input');
  inp.placeholder = TPL_ENJEU.replace('%s', winnerName);
  inp.value = '';
  inp.focus();
}
function cancelEnjeu(btn){
  const card = btn.closest('.pari');
  card.querySelector('.enjeu-form').style.display = 'none';
  card.querySelector('.resolve-row').style.display = 'flex';
}
function validerEnjeu(btn){
  const form = btn.closest('.enjeu-form');
  const inp = form.querySelector('.enjeu-input');
  const enjeu = inp.value.trim();
  if (!enjeu){ inp.focus(); return; }
  form.querySelectorAll('button').forEach(b=>b.disabled=true);
  const fd = new FormData();
  fd.append('action','resolve');
  fd.append('csrf_token', CSRF);
  fd.append('pari_id', form.dataset.pid);
  fd.append('winner_user_id', form.dataset.winner);
  fd.append('enjeu', enjeu);
  fetch('<?= BASE_URL ?>/paris.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{ if(d.ok){ location.reload(); } else { alert(d.error || 'Erreur'); form.querySelectorAll('button').forEach(b=>b.disabled=false); } })
    .catch(()=>{ alert(NET); form.querySelectorAll('button').forEach(b=>b.disabled=false); });
}
</script>
</body>
</html>
