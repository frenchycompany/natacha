<!-- PWA Meta Tags -->
<link rel="manifest" href="<?=BASE_URL?>/manifest.json">
<meta name="theme-color" content="#c9a96e">
<meta name="mobile-web-app-capable" content="yes">

<!-- iOS PWA Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Natacha">
<link rel="apple-touch-icon" href="<?=BASE_URL?>/assets/icons/icon-152x152.png">
<link rel="apple-touch-icon" sizes="192x192" href="<?=BASE_URL?>/assets/icons/icon-192x192.png">
<link rel="apple-touch-icon" sizes="512x512" href="<?=BASE_URL?>/assets/icons/icon-512x512.png">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="96x96" href="<?=BASE_URL?>/assets/icons/icon-96x96.png">
<link rel="icon" type="image/svg+xml" href="<?=BASE_URL?>/assets/icons/favicon.svg">

<!-- Service Worker + Push -->
<script>
const VAPID_PUBLIC = '<?= defined("VAPID_PUBLIC") ? VAPID_PUBLIC : "" ?>';
const BASE_URL_JS = '<?= BASE_URL ?>';

if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register(BASE_URL_JS + '/sw.js', { scope: BASE_URL_JS + '/' })
      .then(function(reg) {
        setInterval(function(){ reg.update(); }, 1800000);

        // Auto-subscribe to push if permission already granted
        if (VAPID_PUBLIC && Notification.permission === 'granted') {
          subscribePush(reg);
        }
      });
  });
}

async function subscribePush(reg) {
  try {
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC)
    });
    await fetch(BASE_URL_JS + '/api/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
    });
  } catch (e) {}
}

async function askNotifPermission() {
  if (!('Notification' in window) || !('serviceWorker' in navigator)) return false;
  const perm = await Notification.requestPermission();
  if (perm === 'granted') {
    const reg = await navigator.serviceWorker.ready;
    await subscribePush(reg);
    return true;
  }
  return false;
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const arr = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
  return arr;
}
</script>
