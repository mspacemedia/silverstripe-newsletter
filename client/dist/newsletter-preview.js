/**
 * Keeps Silverstripe's native CMS preview pane in sync with Elemental edits.
 *
 * Saved changes use the native preview reload. Dirty block changes are posted to
 * a preview-only endpoint so authors can see unsaved content before Save draft
 * or Publish writes it.
 */
(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    var DEBOUNCE_MS = 700;
    var UNSAVED_DEBOUNCE_MS = 650;
    var SCAN_MS = 2000;

    var refreshTimer = null;
    var unsavedTimer = null;
    var observer = null;
    var activeUnsavedXHR = null;
    var activeUnsavedPayload = null;
    var hadDirtyBlocks = false;
    var lastDirtyForm = null;
    var lastUnsavedPayload = null;
    var tinyMCEBound = {};

    var DIRTY_SELECTOR = '.element-form-dirty-state';
    var STATUS_SELECTOR = '.status-addedtodraft, .status-modified';
    var ELEMENTAL_FIELD_PATTERN = /^PageElements_(\d+)_/;

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

    function getCurrentPreviewUrl() {
        var activeState = $('.cms-preview-states .state-name.active, .cms-preview-states .active .state-name')
            .filter('[href]')
            .first();

        if (!activeState.length) {
            activeState = $('.cms-preview-states .state-name[href]').first();
        }

        if (activeState.length) {
            return activeState.attr('href');
        }

        var iframe = document.querySelector('.cms-preview iframe');
        return iframe ? iframe.getAttribute('src') : null;
    }

    function getUnsavedPreviewUrl() {
        var url = getCurrentPreviewUrl();
        if (!url) {
            return null;
        }

        var unsavedUrl = url.replace(/\/cmsPreview\/(\d+)(?=\/|$|\?|#)/, '/cmsPreviewUnsaved/$1');
        return unsavedUrl === url ? null : cacheBust(unsavedUrl);
    }

    function clearUnsavedIframeSource() {
        var iframe = document.querySelector('.cms-preview iframe');
        if (iframe) {
            iframe.removeAttribute('srcdoc');
        }
    }

    function reloadIframeFallback() {
        var iframe = document.querySelector('.cms-preview iframe');
        if (!iframe) {
            return;
        }

        clearUnsavedIframeSource();

        var src = iframe.getAttribute('src') || getCurrentPreviewUrl();
        if (src) {
            iframe.setAttribute('src', cacheBust(src));
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

        clearUnsavedIframeSource();
        cacheBustNavigatorStates();

        try {
            if (typeof preview._loadCurrentState === 'function') {
                preview._loadCurrentState();
                if (typeof preview.redraw === 'function') {
                    preview.redraw();
                }
                return;
            }

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
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(refreshPreview, delay || DEBOUNCE_MS);
    }

    function clearPendingUnsavedPreview() {
        window.clearTimeout(unsavedTimer);
        if (activeUnsavedXHR && activeUnsavedXHR.readyState !== 4) {
            activeUnsavedXHR.abort();
        }
        activeUnsavedXHR = null;
        activeUnsavedPayload = null;
        lastDirtyForm = null;
        lastUnsavedPayload = null;
    }

    function hasDirtyBlocks() {
        return $(DIRTY_SELECTOR).length > 0;
    }

    function syncRichTextEditors() {
        var tiny = window.tinymce || window.tinyMCE;
        if (tiny && typeof tiny.triggerSave === 'function') {
            tiny.triggerSave();
        }
    }

    function addSerialisedValue(target, name, value) {
        if (target[name] === undefined) {
            target[name] = value;
            return;
        }

        if (!Array.isArray(target[name])) {
            target[name] = [target[name]];
        }

        target[name].push(value);
    }

    function addFormBlockData(form, blocks) {
        var serialised = $(form).serializeArray();

        serialised.forEach(function (item) {
            var match = ELEMENTAL_FIELD_PATTERN.exec(item.name);
            if (!match) {
                return;
            }

            var blockID = match[1];
            blocks[blockID] = blocks[blockID] || {};
            addSerialisedValue(blocks[blockID], item.name, item.value);
        });
    }

    function addUniqueForm(forms, form) {
        if (form && forms.indexOf(form) === -1) {
            forms.push(form);
        }
    }

    function findElementFormFromNode(node) {
        var form = $(node).closest('form')[0];
        if (form) {
            return form;
        }

        return $(node)
            .closest('.element-editor, .elemental-editor, .ElementEditor, .element-editor__element, .elemental-editor__element')
            .find('form')
            .first()[0] || null;
    }

    function getUnsavedBlockData() {
        var forms = [];
        var blocks = {};

        syncRichTextEditors();

        $(DIRTY_SELECTOR).each(function () {
            addUniqueForm(forms, findElementFormFromNode(this));
        });

        addUniqueForm(forms, lastDirtyForm);

        forms.forEach(function (form) {
            addFormBlockData(form, blocks);
        });

        return blocks;
    }

    function writePreviewHTML(html) {
        var iframe = document.querySelector('.cms-preview iframe');
        if (!iframe) {
            return;
        }

        iframe.removeAttribute('src');

        if ('srcdoc' in iframe) {
            iframe.srcdoc = html;
        } else if (iframe.contentWindow && iframe.contentWindow.document) {
            var doc = iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
        }

        $(iframe).removeClass('loading');
    }

    function refreshUnsavedPreview() {
        if (!hasPreviewableForm()) {
            return;
        }

        var blocks = getUnsavedBlockData();
        if (!Object.keys(blocks).length) {
            return;
        }

        var payload = JSON.stringify({ blocks: blocks });
        if (payload === lastUnsavedPayload || payload === activeUnsavedPayload) {
            return;
        }

        var url = getUnsavedPreviewUrl();
        if (!url) {
            return;
        }

        if (activeUnsavedXHR && activeUnsavedXHR.readyState !== 4) {
            activeUnsavedXHR.abort();
        }

        activeUnsavedPayload = payload;
        activeUnsavedXHR = $.ajax({
            type: 'POST',
            url: url,
            data: payload,
            contentType: 'application/json; charset=utf-8',
            dataType: 'html',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (html) {
            lastUnsavedPayload = payload;
            writePreviewHTML(html);
        }).always(function () {
            activeUnsavedXHR = null;
            activeUnsavedPayload = null;
        });
    }

    function scheduleUnsavedPreview(delay) {
        window.clearTimeout(unsavedTimer);
        unsavedTimer = window.setTimeout(refreshUnsavedPreview, delay || UNSAVED_DEBOUNCE_MS);
    }

    function fieldBelongsToElementalForm(field) {
        var name = field && field.getAttribute ? field.getAttribute('name') : null;
        if (name && ELEMENTAL_FIELD_PATTERN.test(name)) {
            return true;
        }

        var form = findElementFormFromNode(field);
        if (!form) {
            return false;
        }

        return $(form).find('[name^="PageElements_"]').length > 0;
    }

    function handlePotentialDirtyField(field) {
        if (!fieldBelongsToElementalForm(field)) {
            return;
        }

        lastDirtyForm = findElementFormFromNode(field);
        hadDirtyBlocks = true;
        scheduleUnsavedPreview();
    }

    function handleDirtyStatusChange() {
        if (hasDirtyBlocks()) {
            hadDirtyBlocks = true;
            scheduleUnsavedPreview(250);
            return;
        }

        if (hadDirtyBlocks) {
            hadDirtyBlocks = false;
            clearPendingUnsavedPreview();
            scheduleRefresh(250);
        }
    }

    function nodeMatches(node, selector) {
        if (!node || node.nodeType !== 1) {
            return false;
        }

        return node.matches(selector) || Boolean(node.querySelector(selector));
    }

    function mutationTouches(mutation, selector) {
        if (nodeMatches(mutation.target, selector)) {
            return true;
        }

        var nodes = Array.prototype.slice.call(mutation.addedNodes)
            .concat(Array.prototype.slice.call(mutation.removedNodes));

        return nodes.some(function (node) {
            return nodeMatches(node, selector);
        });
    }

    function handleElementalMutation(mutations) {
        var dirtyTouched = false;
        var statusTouched = false;
        var structuralTouched = false;

        mutations.forEach(function (mutation) {
            dirtyTouched = dirtyTouched || mutationTouches(mutation, DIRTY_SELECTOR);
            statusTouched = statusTouched || mutationTouches(mutation, STATUS_SELECTOR);

            if (mutation.type === 'childList') {
                structuralTouched = structuralTouched || mutation.addedNodes.length || mutation.removedNodes.length;
            }
        });

        if (dirtyTouched) {
            handleDirtyStatusChange();
        }

        if (statusTouched || structuralTouched) {
            if (hasDirtyBlocks()) {
                scheduleUnsavedPreview(250);
            } else {
                scheduleRefresh(250);
            }
        }

        bindTinyMCEEditors();
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
            observer = new MutationObserver(handleElementalMutation);
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

    function bindTinyMCEEditors() {
        var tiny = window.tinymce || window.tinyMCE;
        if (!tiny || !tiny.editors) {
            return;
        }

        var editors = Array.isArray(tiny.editors)
            ? tiny.editors
            : Object.keys(tiny.editors).map(function (key) {
                return tiny.editors[key];
            });

        editors.forEach(function (editor) {
            if (!editor || !editor.id || tinyMCEBound[editor.id] || typeof editor.on !== 'function') {
                return;
            }

            tinyMCEBound[editor.id] = true;
            editor.on('input change keyup undo redo SetContent', function () {
                var field = document.getElementById(editor.id);
                if (field) {
                    handlePotentialDirtyField(field);
                }
            });
        });
    }

    function isRelevantAjax(settings) {
        if (!hasPreviewableForm()) {
            return false;
        }

        var data = settings && settings.data ? String(settings.data) : '';
        var url = settings && settings.url ? String(settings.url) : '';
        if (/cmsPreview(?:Unsaved)?\//i.test(url)) {
            return false;
        }

        var haystack = url + ' ' + data;

        return /element|elemental|gridfield|field|item|versioned|newsletter/i.test(haystack);
    }

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!isRelevantAjax(settings)) {
            return;
        }

        observeElementalRoots();
        bindTinyMCEEditors();

        if (hasDirtyBlocks()) {
            scheduleUnsavedPreview(250);
        } else {
            hadDirtyBlocks = false;
            clearPendingUnsavedPreview();
            scheduleRefresh(250);
        }
    });

    $(document).on('aftersubmitform', '.cms-edit-form.cms-previewable', function () {
        hadDirtyBlocks = false;
        clearPendingUnsavedPreview();
        scheduleRefresh();
    });

    $(document).on('input change keyup', 'input, textarea, select', function () {
        handlePotentialDirtyField(this);
    });

    $(document).on('change', '.cms-edit-form.cms-previewable', function () {
        handleDirtyStatusChange();
    });

    $(document).on('click', '.element-editor button, .elemental-editor button, .ElementEditor button', function () {
        window.setTimeout(function () {
            observeElementalRoots();
            bindTinyMCEEditors();
            handleDirtyStatusChange();
        }, 0);
    });

    $(window).on('focus', function () {
        if (hasDirtyBlocks()) {
            scheduleUnsavedPreview(250);
        } else {
            scheduleRefresh(250);
        }
    });

    $(function () {
        observeElementalRoots();
        bindTinyMCEEditors();
        handleDirtyStatusChange();

        window.setInterval(function () {
            observeElementalRoots();
            bindTinyMCEEditors();
        }, SCAN_MS);
    });
})(window.jQuery);
