/**
 * Live preview for the newsletter editor. Docks a preview iframe to the right of
 * the issue edit form, offers Desktop/Tablet/Mobile widths, and re-renders as
 * blocks are added/edited/reordered.
 *
 * Per-keystroke (pre-save) preview isn't possible without replacing Elemental's
 * React editor, so this reacts to the editor DOM changing — i.e. it refreshes
 * after each element is added, saved or reordered — plus a fallback interval,
 * focus, and a manual Refresh button.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 1200;
    var FALLBACK_MS = 5000;
    var DEVICES = { desktop: 600, tablet: 480, mobile: 375 };

    function findArea() {
        return document.querySelector(
            '.element-editor, .elemental-editor, .ElementEditor, [data-elemental-area]'
        );
    }

    function init(panel) {
        if (panel.dataset.nlInit) {
            return;
        }
        panel.dataset.nlInit = '1';

        var url = panel.getAttribute('data-preview-url');
        var iframe = panel.querySelector('iframe');
        var viewport = panel.querySelector('.newsletter-preview__viewport');
        var bar = panel.querySelector('.newsletter-preview__bar');
        var device = 'desktop';
        var lastSignature = '';
        var timer = null;

        // Give the edit form room so the docked panel doesn't cover it.
        var form = panel.closest('.cms-edit-form') || document.querySelector('.cms-edit-form');
        if (form) {
            form.classList.add('newsletter-has-preview');
        }

        // Fill the editor's right column: align the panel's top (and its bar) with
        // the CMS content toolbar, and its bottom with the south action bar.
        function position() {
            var header = document.querySelector('.toolbar--content, .cms-content-header, .cms-content-toolbar');
            var south = document.querySelector('.toolbar--south, .cms-content-actions');
            if (header) {
                var h = header.getBoundingClientRect();
                panel.style.top = Math.max(h.top, 0) + 'px';
                if (bar) {
                    bar.style.height = h.height + 'px';
                }
            } else {
                panel.style.top = '64px';
            }
            panel.style.bottom = south ? Math.max(window.innerHeight - south.getBoundingClientRect().top, 0) + 'px' : '0px';
        }

        // Size the iframe to the chosen device width and scale it to fit the column.
        function fit() {
            var width = DEVICES[device] || 600;
            iframe.style.width = width + 'px';
            var available = Math.max(120, viewport.clientWidth - 24);
            iframe.style.zoom = Math.min(1, available / width);
            try {
                var doc = iframe.contentWindow.document;
                var h = doc && doc.body ? doc.body.scrollHeight : 0;
                if (h) {
                    iframe.style.height = h + 'px';
                }
            } catch (e) {
                /* same-origin only; ignore */
            }
        }

        function reload() {
            if (!url) {
                return;
            }
            iframe.src = url + (url.indexOf('?') > -1 ? '&' : '?') + 'ts=' + Date.now();
        }

        function scheduleReload() {
            window.clearTimeout(timer);
            timer = window.setTimeout(reload, DEBOUNCE_MS);
        }

        iframe.addEventListener('load', fit);
        reload();
        position();

        // Device width buttons.
        var deviceButtons = panel.querySelectorAll('[data-nl-device]');
        Array.prototype.forEach.call(deviceButtons, function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                device = btn.getAttribute('data-nl-device');
                Array.prototype.forEach.call(deviceButtons, function (b) {
                    b.classList.remove('is-active');
                });
                btn.classList.add('is-active');
                fit();
            });
        });

        var refreshBtn = panel.querySelector('[data-nl-refresh]');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function (e) {
                e.preventDefault();
                reload();
            });
        }

        var toggleBtn = panel.querySelector('[data-nl-toggle]');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                panel.classList.toggle('is-collapsed');
            });
        }

        // Re-render when the Elemental editor changes.
        var area = findArea();
        if (area) {
            new MutationObserver(scheduleReload).observe(area, {
                subtree: true,
                childList: true,
                characterData: true,
                attributes: true
            });
        }

        window.addEventListener('resize', function () {
            position();
            fit();
        });
        window.addEventListener('scroll', position, true);
        window.setInterval(position, 1000);

        // Fallback: catch saves even if the observed node is replaced wholesale.
        window.setInterval(function () {
            var node = findArea();
            var signature = node ? String(node.textContent.length) : '';
            if (signature !== lastSignature) {
                lastSignature = signature;
                scheduleReload();
            }
        }, FALLBACK_MS);

        window.addEventListener('focus', reload);
    }

    function scan() {
        var panels = document.querySelectorAll('[data-newsletter-preview]');
        for (var i = 0; i < panels.length; i++) {
            init(panels[i]);
        }
    }

    // The CMS loads forms via AJAX, so watch for the panel appearing.
    if (document.body) {
        new MutationObserver(scan).observe(document.body, { subtree: true, childList: true });
    }
    document.addEventListener('DOMContentLoaded', scan);
    scan();
})();
