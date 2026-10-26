/* ==========================================================================
   dialog.js — the application's own ask, confirm and tell.

   The browser's confirm() and prompt() cannot be styled, cannot be read by
   the page, and on some campus machines are suppressed entirely, which turns
   "are you sure?" into a silent yes. Everything here is built from the
   classes already in style.css, so a dialog looks like the rest of the site.

   PriDialog.ask     -> Promise<string|null>
   PriDialog.confirm -> Promise<boolean>
   PriDialog.notify  -> Promise<void>

   A form that changes or removes data carries data-confirm and nothing else:
   the submit handler below asks the question, adds confirmed=1 and submits.
   With JavaScript off the form posts straight through, and the handler on the
   server answers with its own confirm page, so the question is never skipped.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var open = null;   // the dialog on screen, if any

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  /* Every dialog is this shell. The caller decides what goes in the body and
     what the main action says. */
  function build(options) {
    var titleId = 'dialog-title-' + Math.random().toString(36).slice(2, 10);

    var backdrop = el('div', 'modal-backdrop');
    var modal    = el('div', 'modal');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', titleId);

    var head  = el('div', 'modal-head');
    var title = el('h2', null, options.title || '');
    title.id = titleId;
    var close = el('button', 'modal-close');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close');
    close.innerHTML = '&times;';
    head.appendChild(title);
    head.appendChild(close);
    modal.appendChild(head);

    if (options.message) {
      modal.appendChild(el('p', null, options.message));
    }

    var field = null;
    if (options.kind === 'ask') {
      var wrap  = el('div', 'field');
      var label = el('label', null, options.label || '');
      var input = el('input');
      input.type = 'text';
      input.id = titleId + '-input';
      input.value = options.value || '';
      if (options.placeholder) { input.placeholder = options.placeholder; }
      label.setAttribute('for', input.id);
      wrap.appendChild(label);
      wrap.appendChild(input);
      modal.appendChild(wrap);
      field = input;
    }

    var actions = el('div', 'form-actions');
    var cancel  = null;
    if (options.kind !== 'notify') {
      cancel = el('button', 'button button-secondary', options.cancelLabel || 'Cancel');
      cancel.type = 'button';
      actions.appendChild(cancel);
    }
    var ok = el('button', 'button' + (options.danger ? ' button-danger' : ''), options.okLabel || 'OK');
    ok.type = 'button';
    actions.appendChild(ok);
    modal.appendChild(actions);

    backdrop.appendChild(modal);
    return { backdrop: backdrop, modal: modal, field: field, ok: ok, cancel: cancel, close: close };
  }

  function show(options) {
    /* One at a time. A second ask while one is open would leave the first
       waiting for a click that can no longer happen. */
    if (open) { open.settle(null); }

    var parts   = build(options);
    var trigger = document.activeElement;

    return new Promise(function (resolve) {
      function settle(value) {
        if (!open) { return; }
        open = null;
        document.removeEventListener('keydown', onKey, true);
        if (parts.backdrop.parentNode) { parts.backdrop.parentNode.removeChild(parts.backdrop); }
        if (trigger && typeof trigger.focus === 'function') { trigger.focus(); }
        resolve(value);
      }

      function accept() {
        if (options.kind === 'ask') {
          var text = parts.field.value;
          if (options.required && text.trim() === '') { parts.field.focus(); return; }
          settle(text);
        } else if (options.kind === 'confirm') {
          settle(true);
        } else {
          settle(undefined);
        }
      }

      function reject() {
        settle(options.kind === 'confirm' ? false : null);
      }

      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); reject(); return; }
        if (e.key === 'Enter' && options.kind === 'ask' && e.target === parts.field) {
          e.preventDefault();
          accept();
          return;
        }
        if (e.key !== 'Tab') { return; }
        /* Keep the keyboard inside the dialog while it is open. */
        var focusable = parts.modal.querySelectorAll('button, input, select, textarea, a[href]');
        if (!focusable.length) { return; }
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }

      open = { settle: settle };

      parts.ok.addEventListener('click', accept);
      if (parts.cancel) { parts.cancel.addEventListener('click', reject); }
      parts.close.addEventListener('click', reject);
      parts.backdrop.addEventListener('mousedown', function (e) {
        if (e.target === parts.backdrop) { reject(); }
      });

      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(parts.backdrop);
      (parts.field || parts.ok).focus();
    });
  }

  var PriDialog = {
    ask: function (o) {
      o = o || {};
      return show({
        kind: 'ask', title: o.title, message: o.message, label: o.label,
        value: o.value || '', placeholder: o.placeholder || '',
        okLabel: o.okLabel || 'OK', cancelLabel: o.cancelLabel || 'Cancel',
        required: !!o.required
      });
    },
    confirm: function (o) {
      o = o || {};
      return show({
        kind: 'confirm', title: o.title, message: o.message,
        okLabel: o.okLabel || 'OK', cancelLabel: o.cancelLabel || 'Cancel',
        danger: !!o.danger
      });
    },
    notify: function (o) {
      o = o || {};
      return show({ kind: 'notify', title: o.title, message: o.message, okLabel: o.okLabel || 'OK' });
    }
  };

  /* Forms that change or remove data. The question is the data-confirm text;
     the button label and the danger tone are optional. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) { return; }
    var question = form.getAttribute('data-confirm');
    if (!question || form.hasAttribute('data-confirmed')) { return; }

    /* The button that was pressed carries a name and value on some forms, and
       a plain submit() would drop it, so it is put back by hand below. */
    var submitter = e.submitter || null;

    e.preventDefault();
    PriDialog.confirm({
      title:   form.getAttribute('data-confirm-title') || 'Please confirm',
      message: question,
      okLabel: form.getAttribute('data-confirm-ok') || 'OK',
      danger:  form.hasAttribute('data-confirm-danger')
    }).then(function (yes) {
      if (!yes) { return; }
      if (!form.querySelector('input[name=confirmed]')) {
        var flag = document.createElement('input');
        flag.type = 'hidden';
        flag.name = 'confirmed';
        flag.value = '1';
        form.appendChild(flag);
      }
      /* Marked so this handler does not ask the question a second time. */
      form.setAttribute('data-confirmed', '1');
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitter || undefined);
        return;
      }
      if (submitter && submitter.name) {
        var pressed = document.createElement('input');
        pressed.type = 'hidden';
        pressed.name = submitter.name;
        pressed.value = submitter.value;
        form.appendChild(pressed);
      }
      form.submit();
    });
  }, true);

  window.PriDialog = PriDialog;
})(window, document);
