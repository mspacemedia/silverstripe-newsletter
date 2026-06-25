/**
 * Visual builder + live preview for newsletter merge-field expressions.
 *
 * Enhances any <textarea class="js-mergefield-input"> rendered by
 * MergeFieldBuilderField: it adds relation/field pickers that insert canonical
 * expression fragments (e.g. "Order.Sum(Amount)") and a live preview that
 * evaluates the current expression against a sample anchor record via the
 * NewsletterAdmin mergePreview endpoint. All evaluation is server-side; this
 * script only orchestrates the UI. Degrades to a plain textarea without JS.
 */
(function () {
  'use strict';

  var AGGREGATES = ['Count', 'Sum', 'Avg', 'Min', 'Max'];
  var FIELD_OPS = { Sum: 1, Avg: 1, Min: 1, Max: 1 };

  function dash(className) {
    return (className || '').replace(/\\/g, '-');
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (key) {
      if (key === 'text') {
        node.textContent = attrs[key];
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    (children || []).forEach(function (child) {
      node.appendChild(child);
    });
    return node;
  }

  function option(value, label) {
    var opt = document.createElement('option');
    opt.value = value;
    opt.textContent = label;
    return opt;
  }

  function insertAtCursor(textarea, snippet) {
    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var value = textarea.value;
    textarea.value = value.slice(0, start) + snippet + value.slice(end);
    var caret = start + snippet.length;
    textarea.selectionStart = textarea.selectionEnd = caret;
    textarea.focus();
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      var self = this;
      clearTimeout(timer);
      timer = setTimeout(function () {
        fn.apply(self, args);
      }, wait);
    };
  }

  function Builder(textarea) {
    this.textarea = textarea;
    this.config = JSON.parse(textarea.getAttribute('data-mergefield') || '{}');
    this.schema = null;
    this.recordID = null;
    this.build();
    this.loadSchema();
  }

  Builder.prototype.build = function () {
    var self = this;

    this.relationSelect = el('select', { class: 'mergefield-control' });
    this.opSelect = el('select', { class: 'mergefield-control' });
    this.fieldSelect = el('select', { class: 'mergefield-control' });
    this.insertBtn = el('button', { type: 'button', class: 'mergefield-insert btn btn-secondary btn-sm', text: 'Insert' });

    this.relationSelect.addEventListener('change', function () { self.syncControls(); });
    this.opSelect.addEventListener('change', function () { self.syncControls(); });
    this.insertBtn.addEventListener('click', function () { self.insert(); });

    this.pickerRow = el('div', { class: 'mergefield-row' }, [
      el('label', { class: 'mergefield-label', text: 'Build:' }),
      this.relationSelect,
      this.opSelect,
      this.fieldSelect,
      this.insertBtn,
    ]);

    this.previewValue = el('span', { class: 'mergefield-preview-value', text: '—' });
    this.previewRecord = el('span', { class: 'mergefield-preview-record' });
    this.randomBtn = el('button', { type: 'button', class: 'mergefield-random btn btn-outline-secondary btn-sm', text: 'Randomise sample' });
    this.randomBtn.addEventListener('click', function () { self.recordID = null; self.preview(); });

    this.previewRow = el('div', { class: 'mergefield-row mergefield-preview' }, [
      el('label', { class: 'mergefield-label', text: 'Preview:' }),
      this.previewValue,
      this.previewRecord,
      this.randomBtn,
    ]);

    this.panel = el('div', { class: 'mergefield-builder' }, [this.pickerRow, this.previewRow]);
    this.textarea.parentNode.insertBefore(this.panel, this.textarea.nextSibling);

    this.textarea.addEventListener('input', debounce(function () { self.preview(); }, 450));
  };

  Builder.prototype.loadSchema = function () {
    var self = this;
    if (!this.config.schemaUrl) {
      return;
    }
    fetch(this.config.schemaUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (schema) {
        self.schema = schema;
        self.populateRelations();
        self.syncControls();
        self.preview();
      })
      .catch(function () { self.setStatus('Could not load field list.', true); });
  };

  Builder.prototype.anchor = function () {
    if (!this.schema) { return null; }
    return this.schema.classes[this.schema.anchorClass] || null;
  };

  Builder.prototype.populateRelations = function () {
    var anchor = this.anchor();
    this.relationSelect.innerHTML = '';
    this.relationSelect.appendChild(option('', '— this record —'));
    if (!anchor) { return; }
    (anchor.relations || []).forEach(function (rel) {
      this.relationSelect.appendChild(option(dash(rel.class) + '::' + rel.name, rel.name + ' (' + rel.type + ')'));
    }, this);
  };

  Builder.prototype.syncControls = function () {
    var relValue = this.relationSelect.value;

    // Direct field of the anchor: hide operation, list anchor fields.
    if (!relValue) {
      this.opSelect.style.display = 'none';
      this.fillFields(this.anchor());
      this.fieldSelect.style.display = '';
      return;
    }

    this.opSelect.style.display = '';
    if (!this.opSelect.options.length) {
      AGGREGATES.forEach(function (op) { this.opSelect.appendChild(option(op, op)); }, this);
    }

    if (FIELD_OPS[this.opSelect.value]) {
      var relClass = relValue.split('::')[0];
      this.fillFields(this.schema.classes[relClass]);
      this.fieldSelect.style.display = '';
    } else {
      this.fieldSelect.style.display = 'none';
    }
  };

  Builder.prototype.fillFields = function (classInfo) {
    this.fieldSelect.innerHTML = '';
    var fields = (classInfo && classInfo.fields) || [];
    fields.forEach(function (field) {
      this.fieldSelect.appendChild(option(field.name, field.name + ' (' + field.type + ')'));
    }, this);
  };

  Builder.prototype.insert = function () {
    var relValue = this.relationSelect.value;
    var snippet;

    if (!relValue) {
      snippet = this.fieldSelect.value;
    } else {
      var relName = relValue.split('::')[1];
      var op = this.opSelect.value;
      if (FIELD_OPS[op]) {
        snippet = relName + '.' + op + '(' + this.fieldSelect.value + ')';
      } else {
        snippet = relName + '.' + op;
      }
    }

    if (snippet) {
      insertAtCursor(this.textarea, snippet);
    }
  };

  Builder.prototype.preview = function () {
    var self = this;
    if (!this.config.previewUrl) { return; }
    var expression = this.textarea.value.trim();
    if (!expression) {
      this.setStatus('—', false);
      this.previewRecord.textContent = '';
      return;
    }

    var url = this.config.previewUrl + '?expression=' + encodeURIComponent(expression) +
      (this.recordID ? '&recordID=' + encodeURIComponent(this.recordID) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.record) { self.recordID = res.recordID || self.recordID; }
        if (res.ok) {
          self.setStatus(res.value === '' ? '(empty)' : res.value, false);
        } else {
          self.setStatus(res.error || 'Invalid expression', true);
        }
        self.previewRecord.textContent = res.record ? 'sample: ' + res.record : '';
      })
      .catch(function () { self.setStatus('Preview unavailable', true); });
  };

  Builder.prototype.setStatus = function (text, isError) {
    this.previewValue.textContent = text;
    this.previewValue.classList.toggle('is-error', !!isError);
  };

  function init(root) {
    var nodes = (root || document).querySelectorAll('textarea.js-mergefield-input');
    Array.prototype.forEach.call(nodes, function (node) {
      if (node.dataset.mergefieldReady) { return; }
      node.dataset.mergefieldReady = '1';
      new Builder(node);
    });
  }

  if (document.readyState !== 'loading') {
    init(document);
  } else {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  }

  // Re-scan when the CMS swaps panels via its PJAX navigation.
  document.addEventListener('DOMNodesInserted', function (e) { init(e.target || document); });
})();
