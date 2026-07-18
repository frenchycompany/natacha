/**
 * Auto-translate FR ↔ RU for paired form fields.
 *
 * Usage: call autoTranslate(pairs, userLang, baseUrl) once per page.
 *
 * pairs = [
 *   { fr: '#nomFr',   ru: '#nomRu'   },           // CSS selectors
 *   { fr: '[name="description_fr"]', ru: '[name="description_ru"]' },
 * ]
 *
 * Also supports dynamic pairs via data attributes:
 *   <input data-autotranslate="fr" data-pair="group1">
 *   <input data-autotranslate="ru" data-pair="group1">
 */
(function () {
    'use strict';

    const DEBOUNCE_MS = 600;
    const MIN_LENGTH  = 2;
    let timers = {};
    let BASE = '';
    let USER_LANG = 'fr';

    // Small in-progress indicator
    function setLoading(el, on) {
        if (on) {
            el.style.opacity = '0.5';
            el.style.borderColor = 'var(--accent, #c9a96e)';
        } else {
            el.style.opacity = '';
            el.style.borderColor = '';
        }
    }

    function translate(text, from, to, callback) {
        const url = BASE + '/api/translate.php?'
            + 'q=' + encodeURIComponent(text)
            + '&from=' + encodeURIComponent(from)
            + '&to=' + encodeURIComponent(to);
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.text) callback(data.text);
                else callback(null);
            })
            .catch(() => callback(null));
    }

    function bindPair(srcEl, dstEl, fromLang, toLang, key) {
        if (!srcEl || !dstEl) return;

        // Track if user has manually edited the target field
        let userEdited = false;
        dstEl.addEventListener('input', function () {
            userEdited = true;
        });

        srcEl.addEventListener('input', function () {
            const text = srcEl.value.trim();
            clearTimeout(timers[key]);

            if (text.length < MIN_LENGTH) return;

            // Don't overwrite if user manually edited and source is short
            if (userEdited && dstEl.value.trim().length > 0) return;

            timers[key] = setTimeout(function () {
                setLoading(dstEl, true);
                translate(text, fromLang, toLang, function (result) {
                    setLoading(dstEl, false);
                    if (result && !userEdited) {
                        dstEl.value = result;
                        // Trigger input event for any other listeners
                        dstEl.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            }, DEBOUNCE_MS);
        });

        // Reset userEdited when source changes significantly (allows re-auto)
        srcEl.addEventListener('focus', function () {
            if (!dstEl.value.trim()) userEdited = false;
        });
    }

    window.autoTranslate = function (pairs, userLang, baseUrl) {
        BASE = baseUrl || '';
        USER_LANG = userLang || 'fr';

        pairs.forEach(function (pair, i) {
            const frEl = typeof pair.fr === 'string' ? document.querySelector(pair.fr) : pair.fr;
            const ruEl = typeof pair.ru === 'string' ? document.querySelector(pair.ru) : pair.ru;
            if (!frEl || !ruEl) return;

            // FR → RU
            bindPair(frEl, ruEl, 'fr', 'ru', 'fr2ru_' + i);
            // RU → FR
            bindPair(ruEl, frEl, 'ru', 'fr', 'ru2fr_' + i);
        });
    };

    /**
     * For dynamically added fields (like questionnaire questions).
     * Call autoTranslateDynamic(container, userLang, baseUrl) and it will
     * observe new [data-autotranslate] elements.
     */
    window.autoTranslateDynamic = function (containerSel, userLang, baseUrl) {
        BASE = baseUrl || '';
        USER_LANG = userLang || 'fr';
        let pairCounter = 1000;

        function bindAllInContainer(container) {
            const groups = {};
            container.querySelectorAll('[data-autotranslate]').forEach(function (el) {
                if (el.dataset.atBound) return;
                const group = el.dataset.pair || 'auto_' + pairCounter++;
                if (!groups[group]) groups[group] = {};
                groups[group][el.dataset.autotranslate] = el;
            });

            Object.keys(groups).forEach(function (group) {
                const frEl = groups[group]['fr'];
                const ruEl = groups[group]['ru'];
                if (frEl && ruEl) {
                    bindPair(frEl, ruEl, 'fr', 'ru', 'dyn_fr2ru_' + group);
                    bindPair(ruEl, frEl, 'ru', 'fr', 'dyn_ru2fr_' + group);
                    frEl.dataset.atBound = '1';
                    ruEl.dataset.atBound = '1';
                }
            });
        }

        const container = document.querySelector(containerSel);
        if (!container) return;

        // Bind existing
        bindAllInContainer(container);

        // Watch for new elements
        const observer = new MutationObserver(function () {
            bindAllInContainer(container);
        });
        observer.observe(container, { childList: true, subtree: true });
    };
})();
