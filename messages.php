<?php
/**
 * NATACHA — Messagerie E2EE (chiffrée de bout en bout)
 * Tout le chiffrement se fait dans le navigateur (Web Crypto API).
 * Le serveur (api/messages.php) ne voit jamais le texte en clair ni la phrase secrète.
 */
require_once __DIR__.'/config.php';
requireLogin();
securityHeaders();
$user = currentUser();
$lang = $user['lang'] ?? 'fr';

$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $s = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $s->execute([$user['id']]);
    $coupleId = $s->fetchColumn() ?: null;
}
if (!$coupleId) { header('Location: '.BASE_URL.'/signup.php'); exit; }

$partner = db()->prepare("SELECT display_name FROM users WHERE couple_id=? AND id!=? LIMIT 1");
$partner->execute([$coupleId, $user['id']]);
$partnerName = $partner->fetchColumn() ?: ($lang==='ru'?'Партнёр':'Partenaire');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Natacha — <?= t('Messages','Сообщения') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<?php include __DIR__.'/includes/pwa_head.php'; ?>
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.1);--text:#e8e0d5;--muted:#7a7268;--me:#2a2318;--them:#141210}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;height:100dvh;display:flex;flex-direction:column;overflow:hidden}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1.2rem;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.back{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .7rem}
.back:hover{border-color:var(--accent);color:var(--accent)}
.topbar-title{font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-style:italic;color:var(--accent);display:flex;align-items:center;gap:.4rem}
.lock-ico{font-size:.7rem;color:var(--muted)}

/* Overlay (setup / unlock) */
.overlay{position:fixed;inset:0;background:var(--bg);z-index:100;display:flex;align-items:center;justify-content:center;padding:2rem}
.overlay.hidden{display:none}
.ov-card{max-width:360px;width:100%;text-align:center}
.ov-ico{font-size:2.6rem;margin-bottom:1.2rem}
.ov-card h2{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.6rem}
.ov-card p{font-size:.62rem;color:var(--muted);line-height:1.8;margin-bottom:1.5rem}
.ov-card input{width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.9rem;padding:.8rem 1rem;outline:none;text-align:center;margin-bottom:.8rem}
.ov-card input:focus{border-color:var(--accent)}
.ov-btn{width:100%;background:var(--accent);border:none;color:var(--bg);font-family:'DM Mono',monospace;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;padding:.9rem;cursor:pointer;transition:opacity .2s}
.ov-btn:hover{opacity:.85}
.ov-btn:disabled{opacity:.4;cursor:not-allowed}
.ov-err{font-size:.62rem;color:#c96e6e;margin-top:.8rem;min-height:1em}
.ov-warn{font-size:.55rem;color:var(--muted);margin-top:1rem;border:1px dashed var(--border);padding:.6rem;line-height:1.7}

/* Conversation */
.chat{flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.5rem}
.day-sep{text-align:center;font-size:.5rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin:.8rem 0 .3rem}
.msg{max-width:78%;padding:.6rem .8rem;font-size:.82rem;line-height:1.5;word-break:break-word;position:relative;animation:msgIn .2s ease}
@keyframes msgIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.msg.me{align-self:flex-end;background:var(--me);border:1px solid rgba(201,169,110,.25)}
.msg.them{align-self:flex-start;background:var(--them);border:1px solid var(--border)}
.msg .meta{font-size:.45rem;color:var(--muted);margin-top:.3rem;display:flex;gap:.5rem;align-items:center;justify-content:flex-end}
.msg.them .meta{justify-content:flex-start}
.msg .eph{color:var(--accent)}
.msg-empty{text-align:center;color:var(--muted);font-size:.7rem;font-style:italic;margin:auto;font-family:'Cormorant Garamond',serif}
.tick{font-size:.5rem}
.tick.read{color:var(--accent)}

/* Composer */
.composer{flex-shrink:0;border-top:1px solid var(--border);background:var(--bg);padding:.6rem;display:flex;gap:.5rem;align-items:flex-end}
.composer.locked{opacity:.5;pointer-events:none}
.msg-input{flex:1;background:var(--s);border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.85rem;padding:.6rem .8rem;outline:none;resize:none;max-height:120px;line-height:1.4}
.msg-input:focus{border-color:var(--accent)}
.send-btn{background:var(--accent);border:none;color:var(--bg);font-size:1rem;width:42px;height:42px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.send-btn:disabled{opacity:.4;cursor:not-allowed}
.eph-toggle{background:transparent;border:1px solid var(--border);color:var(--muted);font-size:.9rem;width:42px;height:42px;cursor:pointer;flex-shrink:0}
.eph-toggle.on{border-color:var(--accent);color:var(--accent)}
</style>
</head>
<body>

<!-- Overlay setup/unlock (masqué une fois déverrouillé) -->
<div class="overlay" id="overlay">
  <div class="ov-card">
    <div class="ov-ico">🔐</div>
    <h2 id="ovTitle"><?= t('Messagerie chiffrée','Шифрованные сообщения') ?></h2>
    <p id="ovDesc"><?= t('Chargement…','Загрузка…') ?></p>
    <input type="password" id="passInput" autocomplete="off" placeholder="<?= t('Phrase secrète','Секретная фраза') ?>" style="display:none">
    <input type="password" id="passInput2" autocomplete="off" placeholder="<?= t('Confirme la phrase','Подтверди фразу') ?>" style="display:none">
    <button class="ov-btn" id="ovBtn" style="display:none"></button>
    <div class="ov-err" id="ovErr"></div>
    <div class="ov-warn" id="ovWarn" style="display:none">
      ⚠️ <?= t('Cette phrase déverrouille ta clé de déchiffrement. Si tu l\'oublies et que tu perds ton appareil, tu perds l\'accès à la conversation. Choisis-en une que tu retiendras.','Эта фраза разблокирует твой ключ. Если забудешь её и потеряешь устройство, доступ к переписке будет утерян.') ?>
    </div>
  </div>
</div>

<div class="topbar">
  <a class="back" href="<?= BASE_URL ?>/dashboard.php">&larr; <?= t('Accueil','Главная') ?></a>
  <div class="topbar-title"><span class="lock-ico">🔒</span> <?= h($partnerName) ?></div>
  <span style="width:60px"></span>
</div>

<div class="chat" id="chat">
  <div class="msg-empty" id="chatEmpty" style="display:none"><?= t('Vos messages chiffrés apparaîtront ici.','Ваши шифрованные сообщения появятся здесь.') ?></div>
</div>

<div class="composer locked" id="composer">
  <button class="eph-toggle" id="ephBtn" title="<?= t('Message éphémère','Исчезающее сообщение') ?>" onclick="toggleEph()">⏱️</button>
  <textarea class="msg-input" id="msgInput" rows="1" placeholder="<?= t('Message chiffré…','Шифрованное сообщение…') ?>"></textarea>
  <button class="send-btn" id="sendBtn" onclick="sendMsg()" disabled>➤</button>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const API  = BASE + '/api/messages.php';
const LANG = <?= json_encode($lang) ?>;
const ME   = <?= (int)$user['id'] ?>;
const T = (fr,ru) => LANG==='ru'?ru:fr;

// ─── Helpers base64 ⇄ ArrayBuffer (sûr pour gros buffers) ───
function b64(buf){
  const bytes = new Uint8Array(buf); let s='';
  const chunk = 0x8000;
  for (let i=0;i<bytes.length;i+=chunk) s += String.fromCharCode.apply(null, bytes.subarray(i,i+chunk));
  return btoa(s);
}
function unb64(str){
  const bin = atob(str); const bytes = new Uint8Array(bin.length);
  for (let i=0;i<bin.length;i++) bytes[i]=bin.charCodeAt(i);
  return bytes.buffer;
}
const ENC = new TextEncoder(), DEC = new TextDecoder();

// ─── État crypto (en mémoire uniquement) ───
let myPrivateKey = null;   // CryptoKey ECDH privée (déchiffrée cette session)
let sharedKey    = null;   // CryptoKey AES-GCM (secret partagé avec le partenaire)
let lastId       = 0;
let ephOn        = false;

// ─── Primitives ───
async function wrapKeyFromPass(pass, saltBuf){
  const base = await crypto.subtle.importKey('raw', ENC.encode(pass), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name:'PBKDF2', salt: saltBuf, iterations:200000, hash:'SHA-256' },
    base, { name:'AES-GCM', length:256 }, false, ['encrypt','decrypt']
  );
}
async function deriveShared(privateKey, partnerPubB64){
  const partnerPub = await crypto.subtle.importKey('spki', unb64(partnerPubB64),
    { name:'ECDH', namedCurve:'P-256' }, false, []);
  return crypto.subtle.deriveKey(
    { name:'ECDH', public: partnerPub }, privateKey,
    { name:'AES-GCM', length:256 }, false, ['encrypt','decrypt']
  );
}
async function api(action, method='GET', body=null){
  const opt = { method, headers:{} };
  if (body){ opt.headers['Content-Type']='application/json'; body.csrf_token=CSRF; opt.body=JSON.stringify(body); }
  const r = await fetch(API + '?action=' + action + (method==='GET'&&body===null?'':''), opt);
  return r.json();
}
async function apiGet(qs){ const r = await fetch(API + '?' + qs); return r.json(); }

// ─── Flux d'initialisation ───
async function init(){
  if (!('crypto' in window) || !crypto.subtle){
    return showOverlay(T('Non supporté','Не поддерживается'),
      T('Ce navigateur ne supporte pas le chiffrement. Utilise Chrome/Safari récent en HTTPS.','Браузер не поддерживает шифрование.'), false);
  }
  const mine = await apiGet('action=get_my_keys');
  if (!mine.ok){ return showOverlay('Erreur','—', false); }

  if (!mine.keys){
    // Première configuration → créer une phrase secrète
    setupCreate();
  } else {
    // Clés existantes → déverrouiller avec la phrase
    setupUnlock(mine.keys);
  }
}

function showOverlay(title, desc, showInputs){
  document.getElementById('overlay').classList.remove('hidden');
  document.getElementById('ovTitle').textContent = title;
  document.getElementById('ovDesc').textContent  = desc;
}

function setupCreate(){
  const ov=document.getElementById('overlay');
  ov.classList.remove('hidden');
  document.getElementById('ovTitle').textContent = T('Crée ta phrase secrète','Создай секретную фразу');
  document.getElementById('ovDesc').textContent  = T('Choisis une phrase que toi seul connais. Elle protège ta clé de déchiffrement — le serveur ne la voit jamais. (Marina aura la sienne, différente.)','Выбери фразу, известную только тебе. Она защищает твой ключ — сервер её не видит.');
  const p1=document.getElementById('passInput'), p2=document.getElementById('passInput2');
  const btn=document.getElementById('ovBtn'), warn=document.getElementById('ovWarn');
  p1.style.display='block'; p2.style.display='block'; warn.style.display='block';
  btn.style.display='block'; btn.textContent=T('Créer','Создать');
  p1.focus();
  btn.onclick = async () => {
    const a=p1.value, b=p2.value;
    if (a.length < 6){ return err(T('Au moins 6 caractères','Минимум 6 символов')); }
    if (a !== b){ return err(T('Les phrases ne correspondent pas','Фразы не совпадают')); }
    btn.disabled=true;
    try {
      const kp = await crypto.subtle.generateKey({ name:'ECDH', namedCurve:'P-256' }, true, ['deriveKey','deriveBits']);
      const pubRaw  = await crypto.subtle.exportKey('spki', kp.publicKey);
      const privRaw = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
      const salt = crypto.getRandomValues(new Uint8Array(16));
      const iv   = crypto.getRandomValues(new Uint8Array(12));
      const wk   = await wrapKeyFromPass(a, salt);
      const wrapped = await crypto.subtle.encrypt({ name:'AES-GCM', iv }, wk, privRaw);
      // wrapped_private_key = base64(iv) + '.' + base64(wrapped)
      const wpk = b64(iv) + '.' + b64(wrapped);
      const res = await api('register_keys','POST',{ public_key:b64(pubRaw), wrapped_private_key:wpk, salt:b64(salt) });
      if (!res.ok){ btn.disabled=false; return err(T('Échec de l\'enregistrement','Ошибка сохранения')); }
      myPrivateKey = kp.privateKey;
      await afterUnlock();
    } catch(e){ btn.disabled=false; err(T('Erreur crypto : ','Ошибка: ')+e.message); }
  };
}

function setupUnlock(keys){
  const ov=document.getElementById('overlay');
  ov.classList.remove('hidden');
  document.getElementById('ovTitle').textContent = T('Déverrouiller','Разблокировать');
  document.getElementById('ovDesc').textContent  = T('Entre votre phrase secrète pour lire vos messages.','Введи секретную фразу для чтения сообщений.');
  const p1=document.getElementById('passInput'), p2=document.getElementById('passInput2');
  const btn=document.getElementById('ovBtn');
  p1.style.display='block'; p2.style.display='none'; document.getElementById('ovWarn').style.display='none';
  btn.style.display='block'; btn.textContent=T('Déverrouiller','Разблокировать');
  p1.value=''; p1.focus();
  const doUnlock = async () => {
    const pass=p1.value;
    if (!pass){ return; }
    btn.disabled=true;
    try {
      const salt = new Uint8Array(unb64(keys.salt));
      const [ivB64, ctB64] = keys.wrapped_private_key.split('.');
      const iv = new Uint8Array(unb64(ivB64));
      const wk = await wrapKeyFromPass(pass, salt);
      const privRaw = await crypto.subtle.decrypt({ name:'AES-GCM', iv }, wk, unb64(ctB64));
      myPrivateKey = await crypto.subtle.importKey('pkcs8', privRaw,
        { name:'ECDH', namedCurve:'P-256' }, false, ['deriveKey','deriveBits']);
      await afterUnlock();
    } catch(e){
      btn.disabled=false;
      err(T('Phrase incorrecte','Неверная фраза'));
    }
  };
  btn.onclick = doUnlock;
  p1.onkeydown = (e)=>{ if(e.key==='Enter') doUnlock(); };
}

function err(m){ document.getElementById('ovErr').textContent = m; }

async function afterUnlock(){
  // Récupérer la clé publique du partenaire
  const p = await apiGet('action=get_partner_key');
  if (!p.ok || !p.partner || !p.partner.public_key){
    showOverlay(T('En attente de '+<?= json_encode($partnerName) ?>, <?= json_encode($partnerName) ?>+' ждём'),
      T(<?= json_encode($partnerName) ?>+' doit d\'abord ouvrir la messagerie et créer sa phrase secrète.',
        <?= json_encode($partnerName) ?>+' должен(на) сначала открыть чат и создать фразу.'), false);
    document.getElementById('passInput').style.display='none';
    document.getElementById('passInput2').style.display='none';
    document.getElementById('ovBtn').style.display='none';
    document.getElementById('ovWarn').style.display='none';
    // Réessaie périodiquement
    setTimeout(afterUnlock, 5000);
    return;
  }
  sharedKey = await deriveShared(myPrivateKey, p.partner.public_key);
  // Déverrouillé !
  document.getElementById('overlay').classList.add('hidden');
  document.getElementById('composer').classList.remove('locked');
  document.getElementById('msgInput').focus();
  await loadMessages(true);
  startPolling();
}

// ─── Chiffrer / déchiffrer un texte ───
async function encryptText(txt){
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt({ name:'AES-GCM', iv }, sharedKey, ENC.encode(txt));
  return { ciphertext:b64(ct), iv:b64(iv) };
}
async function decryptText(ctB64, ivB64){
  try {
    const pt = await crypto.subtle.decrypt({ name:'AES-GCM', iv:new Uint8Array(unb64(ivB64)) }, sharedKey, unb64(ctB64));
    return DEC.decode(pt);
  } catch(e){ return '🔒 ' + T('[non déchiffrable]','[не расшифровано]'); }
}

// ─── Rendu ───
const chatEl = document.getElementById('chat');
function fmtTime(iso){ const d=new Date(iso.replace(' ','T')); return d.toLocaleTimeString(LANG==='ru'?'ru-RU':'fr-FR',{hour:'2-digit',minute:'2-digit'}); }

async function renderMsg(m){
  const mine = (+m.sender_id === ME);
  const div = document.createElement('div');
  div.className = 'msg ' + (mine?'me':'them');
  div.dataset.id = m.id;
  let text = '';
  if (m.msg_type === 'text'){ text = await decryptText(m.ciphertext, m.iv); }
  else { text = '📎 ' + T('média','медиа'); } // Phase 2/3
  const body = document.createElement('div');
  body.textContent = text;
  div.appendChild(body);
  const meta = document.createElement('div');
  meta.className='meta';
  let metaHtml = fmtTime(m.created_at);
  if (m.expires_at) metaHtml = '⏱️ ' + metaHtml;
  meta.innerHTML = '<span>'+metaHtml+'</span>' + (mine ? '<span class="tick '+(m.read_at?'read':'')+'">'+(m.read_at?'✓✓':'✓')+'</span>' : '');
  div.appendChild(meta);
  chatEl.appendChild(div);
}

async function loadMessages(scroll){
  const res = await apiGet('action=fetch&since=0');
  if (!res.ok) return;
  document.getElementById('chatEmpty').style.display = res.messages.length ? 'none' : 'block';
  chatEl.querySelectorAll('.msg').forEach(e=>e.remove());
  for (const m of res.messages){ await renderMsg(m); lastId = Math.max(lastId, +m.id); }
  if (scroll) chatEl.scrollTop = chatEl.scrollHeight;
  markRead();
}

async function fetchNew(){
  if (!sharedKey) return;
  const res = await apiGet('action=fetch&since='+lastId);
  if (!res.ok || !res.messages.length) return;
  const nearBottom = chatEl.scrollHeight - chatEl.scrollTop - chatEl.clientHeight < 120;
  for (const m of res.messages){ await renderMsg(m); lastId = Math.max(lastId, +m.id); }
  document.getElementById('chatEmpty').style.display='none';
  if (nearBottom) chatEl.scrollTop = chatEl.scrollHeight;
  markRead();
}

// Met à jour les accusés de lecture des messages déjà affichés
async function refreshTicks(){
  if (!sharedKey) return;
  // léger : on refait un fetch complet des ticks via les read_at (recharge silencieuse)
  const res = await apiGet('action=fetch&since=0');
  if (!res.ok) return;
  for (const m of res.messages){
    if (+m.sender_id===ME && m.read_at){
      const el = chatEl.querySelector('.msg[data-id="'+m.id+'"] .tick');
      if (el && !el.classList.contains('read')){ el.classList.add('read'); el.textContent='✓✓'; }
    }
  }
}

let markTimer=null;
function markRead(){
  if (!lastId) return;
  clearTimeout(markTimer);
  markTimer=setTimeout(()=>{ api('mark_read','POST',{ up_to_id:lastId }); }, 400);
}

// ─── Envoi ───
const input = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
input.addEventListener('input', ()=>{
  sendBtn.disabled = input.value.trim().length===0;
  input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,120)+'px';
});
input.addEventListener('keydown', e=>{
  if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); if(!sendBtn.disabled) sendMsg(); }
});

async function sendMsg(){
  const txt = input.value.trim();
  if (!txt || !sharedKey) return;
  sendBtn.disabled=true;
  try {
    const { ciphertext, iv } = await encryptText(txt);
    const body = { ciphertext, iv, type:'text' };
    if (ephOn) body.expires_in = 24*3600; // éphémère : 24h
    const res = await api('send','POST', body);
    if (res.ok){
      input.value=''; input.style.height='auto';
      await fetchNew();
      chatEl.scrollTop = chatEl.scrollHeight;
    } else {
      alert(T('Échec de l\'envoi','Ошибка отправки'));
    }
  } catch(e){ alert(T('Erreur de chiffrement','Ошибка шифрования')); }
  sendBtn.disabled = input.value.trim().length===0;
}

function toggleEph(){
  ephOn = !ephOn;
  const b=document.getElementById('ephBtn');
  b.classList.toggle('on', ephOn);
  input.placeholder = ephOn ? T('Message éphémère (24h)…','Исчезающее (24ч)…') : T('Message chiffré…','Шифрованное сообщение…');
}

// ─── Polling léger ───
function startPolling(){
  setInterval(fetchNew, 4000);       // nouveaux messages
  setInterval(refreshTicks, 12000);  // accusés de lecture
  // Re-fetch au retour au premier plan
  document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) fetchNew(); });
}

init();
</script>
</body>
</html>
