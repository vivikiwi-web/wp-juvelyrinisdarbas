(() => {
  'use strict';
  const init = () => {
  const cfg = window.JD_INQUIRY;
  const holder = document.querySelector('.jd-inquiry-holder');
  if (!cfg || !holder) return;
  const panel = holder.querySelector('#nm-cart-panel');
  const list = holder.querySelector('.jd-inquiry-items');
  const message = holder.querySelector('.jd-inquiry-message');
  const contact = holder.querySelector('.jd-inquiry-contact');
  const success = holder.querySelector('.jd-inquiry-success');
  let submitted = false;
  const storageKey = 'jd-inquiry-v1';
  const uuid = () => typeof window.crypto.randomUUID === 'function'
    ? window.crypto.randomUUID()
    : Array.from(window.crypto.getRandomValues(new Uint8Array(24)), value => value.toString(16).padStart(2, '0')).join('');
  let state = { token: uuid(), items: [] };
  let trigger = null;
  let busy = false;
  let previousOverflow = '';
  let previousBodyPad = '';
  let previousHeaderPad = '';
  let scrollLocked = false;
  let closeTimer = 0;
  const header = document.getElementById('nm-header');
  const slideMs = 250;
  const motionReduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const lockScroll = () => {
    if (scrollLocked) return;
    scrollLocked = true;
    previousOverflow = document.body.style.overflow;
    previousBodyPad = document.body.style.paddingRight;
    previousHeaderPad = header ? header.style.paddingRight : '';
    const gap = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.overflow = 'hidden';
    if (gap > 0) {
      const pad = `${gap}px`;
      document.body.style.paddingRight = pad;
      if (header) header.style.paddingRight = pad;
    }
  };
  const unlockScroll = () => {
    if (!scrollLocked) return;
    scrollLocked = false;
    document.body.style.overflow = previousOverflow;
    document.body.style.paddingRight = previousBodyPad;
    if (header) header.style.paddingRight = previousHeaderPad;
  };
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey));
    if (saved && typeof saved.token === 'string' && Array.isArray(saved.items) && saved.items.length <= 10) state = saved;
  } catch (_) { /* Storage can be disabled; the current tab still works. */ }
  const persist = () => {
    try { sessionStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) { /* Optional recovery. */ }
  };
  const say = text => { message.textContent = text; };
  const valueLabel = value => ({ gold: 'Auksas', silver: 'Sidabras', yellow: 'Geltonas', white: 'Baltas', red: 'Raudonas' }[value] || value);
  const render = () => {
    list.replaceChildren();
    success.hidden = !submitted;
    list.hidden = submitted;
    message.hidden = submitted;
    if (!state.items.length && !submitted) {
      const empty = document.createElement('p'); empty.textContent = 'Užklausos sąrašas tuščias.'; list.append(empty);
    }
    state.items.forEach((item, index) => {
      const row = document.createElement('article'); row.className = 'jd-inquiry-item';
      const img = document.createElement('img'); img.src = item.data.image; img.alt = ''; img.width = 72; img.height = 90;
      const text = document.createElement('div');
      const name = document.createElement('h4'); name.textContent = item.data.title;
      const code = document.createElement('p'); code.textContent = item.data.sku;
      const type = document.createElement('p'); type.textContent = item.mode === 'custom' ? 'Pageidavimas kitokiam variantui' : 'Užklausa apie šį modelį';
      text.append(name, code, type);
      Object.entries(item.preferences || {}).forEach(([key, value]) => {
        const field = item.data.fields?.[key];
        const label = field?.label || cfg.labels[key];
        if (!value || !label) return;
        const line = document.createElement('p'); line.textContent = `${label}: ${field?.options?.[value] || valueLabel(value)}`; text.append(line);
      });
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'jd-inquiry-remove';
      remove.textContent = 'Pašalinti'; remove.disabled = busy;
      remove.addEventListener('click', () => {
        if (busy) return;
        state.items.splice(index, 1); state.token = uuid(); persist(); render(); say('');
      });
      text.append(remove); row.append(img, text); list.append(row);
    });
    contact.hidden = !state.items.length;
  };
  const finishClose = () => {
    document.body.classList.remove('cart-panel-closing');
    holder.classList.remove('jd-inquiry-ready');
    panel.setAttribute('aria-hidden', 'true');
    holder.inert = true;
    unlockScroll();
    if (trigger && trigger.isConnected) trigger.focus({ preventScroll: true });
  };
  const close = () => {
    if (!document.body.classList.contains('cart-panel-open')) return;
    document.body.classList.add('cart-panel-closing');
    document.body.classList.remove('cart-panel-open');
    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(finishClose, motionReduced() ? 0 : slideMs);
  };
  const open = origin => {
    window.clearTimeout(closeTimer);
    trigger = origin || document.activeElement;
    document.body.classList.remove('cart-panel-closing');
    holder.inert = false;
    panel.setAttribute('aria-hidden', 'false');
    // Size the shell first. Changing its width in the same frame as the
    // transform makes the browser drop Savoy's slide transition.
    lockScroll();
    holder.classList.add('jd-inquiry-ready');
    panel.getBoundingClientRect();
    document.body.classList.add('cart-panel-open');
    holder.querySelector('.jd-inquiry-close').focus({ preventScroll: true });
  };
  holder.querySelector('.jd-inquiry-close').addEventListener('click', close);
  holder.querySelector('.jd-inquiry-success-close').addEventListener('click', close);
  holder.querySelector('.jd-inquiry-overlay').addEventListener('click', close);
  document.addEventListener('keydown', event => {
    if (!document.body.classList.contains('cart-panel-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(); }
    if (event.key !== 'Tab') return;
    const focusable = [...panel.querySelectorAll('a[href],button,input,select,textarea,[tabindex="0"]')].filter(e => !e.disabled && e.getClientRects().length);
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }, true);
  document.addEventListener('click', event => {
    if (event.target.closest('.nm-menu-cart a,#nm-menu-cart-btn')) {
      event.preventDefault(); event.stopImmediatePropagation(); render(); open(event.target.closest('a'));
    }
  }, true);
  const preferencesDialog = document.getElementById('jd-preferences-dialog');
  const preferencesTrigger = document.querySelector('.jd-preferences-open');
  let preferencesOrigin = null;
  const closePreferences = () => {
    if (preferencesDialog?.open) preferencesDialog.close();
    unlockScroll();
    preferencesTrigger?.focus({ preventScroll: true });
  };
  preferencesTrigger?.addEventListener('click', () => {
    preferencesOrigin = preferencesTrigger;
    lockScroll();
    preferencesDialog.showModal();
  });
  preferencesDialog?.querySelector('.jd-preferences-close').addEventListener('click', closePreferences);
  preferencesDialog?.addEventListener('cancel', event => { event.preventDefault(); closePreferences(); });
  preferencesDialog?.addEventListener('click', event => {
    if (event.target !== preferencesDialog) return;
    const box = preferencesDialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) closePreferences();
  });
  const add = async (productId, mode, preferences, origin, status) => {
    if (busy) return;
    if (state.items.length >= 10) { status.textContent = 'Vienoje užklausoje galima pateikti iki dešimties gaminių.'; return; }
    origin.disabled = true; status.textContent = 'Pridedama…';
    try {
      const body = new URLSearchParams({ action: 'jd_catalog_item', nonce: cfg.nonce, product_id: productId });
      const response = await fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.data?.message || 'Nepavyko pridėti gaminio. Atnaujinkite puslapį.');
      const duplicate = state.items.some(i => i.product_id === result.data.product_id && i.mode === mode && JSON.stringify(i.preferences) === JSON.stringify(preferences));
      if (!duplicate) state.items.push({ product_id: result.data.product_id, mode, preferences, data: result.data });
      submitted = false;
      state.token = uuid(); persist(); render(); status.textContent = ''; say('');
      const fromPreferences = preferencesDialog?.contains(origin);
      if (fromPreferences) closePreferences();
      open(fromPreferences ? preferencesOrigin : origin);
    } catch (error) { status.textContent = error.message || 'Ryšio klaida. Bandykite dar kartą.'; }
    finally { origin.disabled = false; }
  };
  document.querySelectorAll('.jd-inquiry-add').forEach(button => button.addEventListener('click', () => add(button.dataset.productId, 'model', {}, button, button.parentElement.querySelector('.jd-inquiry-inline-status'))));
  document.querySelectorAll('.jd-preferences').forEach(form => {
    const fields = new Map([...form.querySelectorAll('[data-jd-field]')].map(label => [label.dataset.jdField, {
      input: label.querySelector('input,select,textarea'), condition: JSON.parse(label.dataset.jdCondition || 'null')
    }]));
    const updateConditions = () => {
      const active = (key, trail = new Set()) => {
        const field = fields.get(key);
        if (!field || trail.has(key)) return false;
        if (!field.condition) return true;
        const next = new Set(trail); next.add(key);
        if (!active(field.condition.field, next)) return false;
        const equal = fields.get(field.condition.field).input.value === field.condition.value;
        return field.condition.operator === 'equals' ? equal : !equal;
      };
      const statuses = new Map([...fields.keys()].map(key => [key, active(key)]));
      fields.forEach((field, key) => {
        field.input.disabled = !statuses.get(key);
        if (field.input.disabled) field.input.value = '';
      });
    };
    form.addEventListener('change', updateConditions);
    updateConditions();
    form.addEventListener('submit', event => {
      event.preventDefault(); const preferences = {};
      new FormData(form).forEach((value, key) => { if (String(value).trim()) preferences[key] = String(value).trim(); });
      add(form.dataset.productId, 'custom', preferences, form.querySelector('button[type="submit"]'), form.querySelector('[role="status"]'));
    });
  });
  const cf7 = holder.querySelector('.wpcf7 form');
  if (cf7) {
    // Preserve authenticated admin preview access when the CF7 REST endpoint runs.
    const rest = document.createElement('input'); rest.type = 'hidden'; rest.name = '_wpnonce'; rest.value = cfg.restNonce; cf7.append(rest);
    cf7.addEventListener('submit', event => {
      if (!state.items.length) { event.preventDefault(); event.stopImmediatePropagation(); say('Pirmiausia pridėkite gaminį.'); return; }
      cf7.elements['jd-nonce'].value = cfg.nonce;
      cf7.elements['jd-request'].value = JSON.stringify({ token: state.token, items: state.items.map(({ product_id, mode, preferences }) => ({ product_id, mode, preferences })) });
    }, true);
  }
  document.addEventListener('wpcf7beforesubmit', event => { if (Number(event.detail.contactFormId) === Number(cfg.formId)) { busy = true; render(); } });
  document.addEventListener('wpcf7submit', event => { if (Number(event.detail.contactFormId) === Number(cfg.formId)) { busy = false; render(); } });
  document.addEventListener('wpcf7mailsent', event => {
    if (Number(event.detail.contactFormId) !== Number(cfg.formId)) return;
    busy = false; submitted = true; state = { token: uuid(), items: [] }; persist(); say(''); render();
    success.querySelector('h2').focus({ preventScroll: true });
  });
  render();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
