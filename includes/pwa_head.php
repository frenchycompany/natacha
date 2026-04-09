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

<!-- Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('<?=BASE_URL?>/sw.js', { scope: '<?=BASE_URL?>/' })
      .then(function(reg) {
        // Check for updates every 30 min
        setInterval(function(){ reg.update(); }, 1800000);
      })
      .catch(function(err) {
        console.log('SW error:', err);
      });
  });
}
</script>
