/**
 * Keeps Silverstripe's native CMS preview pane in sync with Elemental edits.
 *
 * Newsletter previews are rendered server-side, so the iframe can only reflect
 * saved block state. Elemental block edits are saved through nested Ajax flows,
 * which do not always look like a submit of the main DataObject form.
 */
(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    var DEBOUNCE_MS = 700;
    var SCAN_MS = 2000;
    var timer = null;
    var observer = null;

    function hasPreviewableForm() {
        return $('.cms-edit-form.cms-previewable, .cms-previewable').length > 0;
    }

    function cacheBust(url) {
        if (!url || url === 'about:blank') {
            return url;
        }

        var hash = '';
        var hashIndex = url.indexOf('#');
        if (hashIndex >= 0) {
            hash = url.substring(hashIndex);
            url = url.substring(0, hashIndex);
        }

        url = url.replace(/([?&])_nl_ts=[^&#]*(&?)/, function (match, prefix, suffix) {
            return suffix ? prefix : '';
        }).replace(/[?&]$/, '');

        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_nl_ts=' + Date.now() + hash;
    }

    function cacheBustNavigatorStates() {
        $('.cms-preview-states .state-name').each(function () {
            var href = $(this).attr('href');
            if (href) {
                $(this).attr('href', cacheBust(href));
            }
        });
    }

    function reloadIframeFallback() {
        var iframe = document.querySelector('.cms-preview iframe');
        if (iframe && iframe.getAttribute('src')) {
            iframe.setAttribute('src', cacheBust(iframe.getAttribute('src')));
        }
    }

    function refreshPreview() {
        if (!hasPreviewableForm()) {
            return;
        }

        var preview = $('.cms-preview');
        if (!preview.length) {
            return;
        }

        cacheBustNavigatorStates();

        try {
            if (typeof preview.entwine === 'function') {
                var previewEntwine = preview.entwine('ss.preview');
                if (previewEntwine && typeof previewEntwine._loadCurrentState === 'function') {
                    previewEntwine._loadCurrentState();
                    if (typeof previewEntwine.redraw === 'function') {
                        previewEntwine.redraw();
                    }
                    return;
                }
            }
        } catch (e) {
            reloadIframeFallback();
            return;
        }

        reloadIframeFallback();
    }

    function scheduleRefresh(delay) {
        window.clearTimeout(timer);
        timer = window.setTimeout(refreshPreview, delay || DEBOUNCE_MS);
    }

    function findElementalRoots() {
        return document.querySelectorAll(
            '.element-editor, .elemental-editor, .ElementEditor, [data-elemental-area], ' +
            '.elemental-area, .elementalarea, .element-list, .elemental-editor__elements'
        );
    }

    function observeElementalRoots() {
        if (!document.body || !hasPreviewableForm()) {
            return;
        }

        var roots = findElementalRoots();
        if (!roots.length) {
            return;
        }

        if (!observer) {
            observer = new MutationObserver(function () {
                scheduleRefresh();
            });
        }

        Array.prototype.forEach.call(roots, function (root) {
            if (root.dataset.newsletterPreviewObserved) {
                return;
            }
            root.dataset.newsletterPreviewObserved = '1';
            observer.observe(root, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['class', 'data-id', 'data-record-id', 'data-state', 'aria-busy']
            });
        });
    }

    function isRelevantAjax(settings) {
        if (!hasPreviewableForm()) {
            return false;
        }

        var data = settings && settings.data ? String(settings.data) : '';
        var url = settings && settings.url ? String(settings.url) : '';
        var haystack = url + ' ' + data;

        return /element|elemental|gridfield|field|item|versioned|newsletter/i.test(haystack);
    }

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (isRelevantAjax(settings)) {
            observeElementalRoots();
            scheduleRefresh(250);
        }
    });

    $(document).on('aftersubmitform change', '.cms-edit-form.cms-previewable', function () {
        scheduleRefresh();
    });

    $(document).on('click', '.element-editor button, .elemental-editor button, .ElementEditor button', function () {
        scheduleRefresh();
    });

    $(window).on('focus', function () {
        scheduleRefresh(250);
    });

    $(function () {
        observeElementalRoots();
        window.setInterval(observeElementalRoots, SCAN_MS);
    });
})(window.jQuery);
