<!-- PWA Meta Tags -->
<link rel="manifest" href="/natacha/manifest.json">
<meta name="theme-color" content="#c9a96e">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Natacha">
<link rel="apple-touch-icon" href="/natacha/assets/icons/icon-192x192.svg">
<link rel="icon" type="image/svg+xml" href="/natacha/assets/icons/icon-192x192.svg">

<!-- Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/natacha/sw.js', { scope: '/natacha/' })
      .then(function(registration) {
        console.log('SW registered:', registration.scope);
      })
      .catch(function(error) {
        console.log('SW registration failed:', error);
      });
  });
}
</script>
