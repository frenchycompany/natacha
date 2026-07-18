<?php
require_once __DIR__.'/config.php';
startSession();
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"><title>Reset cache</title>
<style>body{background:#0f0d0b;color:#c9a96e;font-family:monospace;padding:2rem;text-align:center}
pre{text-align:left;background:#141210;padding:1rem;font-size:.7rem;color:#e8e0d5;margin:1rem auto;max-width:400px;overflow:auto}
a{color:#c9a96e}</style>
</head>
<body>
<h2>🔧 Reset PWA Cache</h2>
<pre id="log">Starting...</pre>
<script>
const log = document.getElementById('log');
function l(msg) { log.textContent += '\n' + msg; }

async function reset() {
    // 1. Unregister all service workers
    if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        l('Service workers found: ' + regs.length);
        for (const reg of regs) {
            await reg.unregister();
            l('Unregistered: ' + reg.scope);
        }
    }

    // 2. Clear all caches
    if ('caches' in window) {
        const keys = await caches.keys();
        l('Caches found: ' + keys.length);
        for (const key of keys) {
            await caches.delete(key);
            l('Deleted cache: ' + key);
        }
    }

    l('\n✅ Done! Redirecting in 3s...');
    setTimeout(() => { window.location.href = '<?= BASE_URL ?>/couple.php'; }, 3000);
}

reset().catch(e => l('Error: ' + e.message));
</script>
</body>
</html>
