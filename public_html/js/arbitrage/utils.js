'use strict';

function arbQs(sel, root = document) {
  return root.querySelector(sel);
}
function arbQsa(sel, root = document) {
  return Array.from(root.querySelectorAll(sel));
}

function arbGetCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function arbDebounce(fn, waitMs) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), waitMs);
  };
}

async function arbPostJson(url, data) {
  const resp = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': arbGetCsrf(),
    },
    body: JSON.stringify(data),
  });
  const json = await resp.json().catch(() => ({}));
  if (!resp.ok || json?.ok === false || json?.success === false) {
    const message = json?.error || json?.message || `HTTP ${resp.status}`;
    const err = new Error(message);
    err.response = json;
    throw err;
  }
  return json;
}

function arbFmtMoney(n) {
  const v = Number(n || 0);
  return v.toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' €';
}

function arbFmtPct(n) {
  const v = Number(n || 0);
  return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
}

function arbSetSaveState(el, text, isError = false) {
  if (!el) return;
  el.textContent = text;
  el.style.color = isError ? '#cc5c58' : '';
}

function arbCopyText(text) {
  if (!text) return Promise.resolve(false);
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text).then(() => true).catch(() => false);
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  const ok = document.execCommand('copy');
  document.body.removeChild(ta);
  return Promise.resolve(ok);
}

function arbInitModeToggle(root = document) {
  const btn = root.querySelector('[data-mode-toggle]');
  if (!btn) return;
  const KEY = 'arb-mode';
  const apply = (mode) => {
    document.documentElement.classList.toggle('arb-client', mode === 'client');
    btn.textContent = mode === 'client' ? 'Mode expert' : 'Mode client';
  };
  const saved = localStorage.getItem(KEY) || 'expert';
  apply(saved);
  btn.addEventListener('click', () => {
    const now = document.documentElement.classList.contains('arb-client') ? 'expert' : 'client';
    localStorage.setItem(KEY, now);
    apply(now);
  });
}

function arbInitPrint(root = document) {
  root.querySelectorAll('[data-print]').forEach((b) => {
    b.addEventListener('click', () => window.print());
  });
}

