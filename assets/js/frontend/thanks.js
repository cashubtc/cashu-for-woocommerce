(function () {
  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function mintHost(m) {
    try {
      return new URL(String(m || '')).hostname.replace(/^www\./i, '');
    } catch {
      return String(m || '');
    }
  }

  // i18n helpers
  function t(key) {
    var dict = (window.cashu_wc_thanks && window.cashu_wc_thanks.i18n) || {};
    return dict[key] || key;
  }
  function sym() {
    return (window.cashu_wc_thanks && window.cashu_wc_thanks.symbol) || '₿';
  }
  function sprintf(fmt /*, ...args */) {
    // Prefer WP sprintf if available
    try {
      if (window.wp && wp.i18n && typeof wp.i18n.sprintf === 'function') {
        return wp.i18n.sprintf.apply(null, arguments);
      }
    } catch {}
    // Fallback: very small %s/%d replacement (good enough for our usage)
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(fmt).replace(/%(\d+\$)?[sd]/g, function () {
      var v = args[i++];
      return v === undefined || v === null ? '' : String(v);
    });
  }
  async function copyText(text) {
    if (!text) return Promise.resolve(false);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard
        .writeText(text)
        .then(() => true)
        .catch(() => false);
    }
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      // Avoid scrolling to bottom
      ta.style.top = '0';
      ta.style.left = '0';
      ta.style.position = 'fixed';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(!!ok);
    } catch {
      return Promise.resolve(false);
    }
  }

  // Bootstrap
  const root = document.getElementById('cashu-change-root');
  if (!root) return;
  const storageKey = 'cashu_wc_change';

  // Get change from local storage
  let payload = null;
  try {
    const raw = localStorage.getItem(storageKey);
    payload = raw ? JSON.parse(raw) : null;
  } catch {
    payload = null;
  }
  if (!payload || !Array.isArray(payload.items) || payload.items.length === 0) {
    root.remove();
    return;
  }

  // TTL 60 mins
  if (payload.created && Date.now() - payload.created > 60 * 60 * 1000) {
    try {
      localStorage.removeItem(storageKey);
    } catch {}
    root.remove();
    return;
  }

  // cashu.me link injected via sprintf so translators see %s in the .po
  // file and can move the visible text wherever the target grammar wants.
  // The anchor literal is hard-coded — no user input flows in.
  const cashuMeAnchor =
    '<a href="https://cashu.me" target="_blank" rel="noopener noreferrer">cashu.me</a>';

  // Add change container
  root.classList.add('cashu-change');
  root.innerHTML = `
		<div class="cashu-change-head">
			<div class="cashu-change-title">${t('title')}</div>
			<button type="button" class="cashu-change-dismiss">${t('dismiss')}</button>
		</div>
		<div class="cashu-change-lead">
			${t('lead')}
		</div>
		<div class="cashu-change-no-wallet">
			${sprintf(t('no_wallet'), cashuMeAnchor)}
		</div>
		<div class="cashu-change-list"></div>
		<div class="cashu-change-tip">
			<strong>${t('important')}</strong> ${t('tip')}
		</div>
	`;

  // Add change items
  const list = $('.cashu-change-list', root);
  payload.items.forEach((it, idx) => {
    const item = document.createElement('div');
    item.className = 'cashu-change-item';
    item.setAttribute('data-idx', String(idx));
    const dustNote =
      it && it.dust ? `<div class="cashu-change-note">${t('dust_note')}</div>` : '';
    item.innerHTML = `
			<div class="cashu-change-row">
				<div class="cashu-change-left">
					<div class="cashu-change-label"></div>
					<div class="cashu-change-meta"></div>
					${dustNote}
				</div>
				<div class="cashu-change-btns">
					<button type="button" class="cashu-change-btn cashu-change-copy" data-action="copy" data-idx="${idx}">${t('copy')}</button>
					<button type="button" class="cashu-change-btn cashu-change-toggle" data-action="toggle" data-idx="${idx}">${t('show')}</button>
				</div>
			</div>
			<pre class="cashu-change-token" hidden></pre>
		`;
    const labelEl = $('.cashu-change-label', item);
    labelEl.textContent = it && it.kind ? String(it.kind) : t('change');
    if (it && it.dust) {
      const badge = document.createElement('span');
      badge.className = 'cashu-change-badge';
      badge.textContent = t('dust_badge');
      labelEl.appendChild(badge);
    }
    const metaEl = $('.cashu-change-meta', item);
    const amount = Number.isFinite(it && it.amount) ? it.amount : 0;
    const host = mintHost(it && it.mint);
    metaEl.textContent = sprintf(t('meta_amount'), sym(), amount, host);
    const tokenEl = $('.cashu-change-token', item);
    tokenEl.textContent = String(it && it.token ? it.token : '');
    list.appendChild(item);
  });

  // Global click listener
  root.addEventListener('click', async (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('button') : null;
    if (!btn) return;
    // Dismiss
    if (btn.classList.contains('cashu-change-dismiss')) {
      try {
        localStorage.removeItem(storageKey);
      } catch {}
      root.remove();
      return;
    }
    const action = btn.getAttribute('data-action');
    const idxRaw = btn.getAttribute('data-idx');
    const idx = idxRaw ? parseInt(idxRaw, 10) : NaN;
    if (!Number.isFinite(idx)) return;
    const item = payload.items[idx];
    if (!item) return;
    // Item toggle
    if (action === 'toggle') {
      const card = btn.closest('.cashu-change-item');
      const pre = card ? $('.cashu-change-token', card) : null;
      if (!pre) return;
      const isHidden = pre.hasAttribute('hidden');
      if (isHidden) {
        pre.removeAttribute('hidden');
        btn.textContent = t('hide');
      } else {
        pre.setAttribute('hidden', '');
        btn.textContent = t('show');
      }
      return;
    }
    // Copy token
    if (action === 'copy') {
      const token = String(item.token || '');
      if (!token) return;
      const old = btn.textContent;
      btn.disabled = true;
      const ok = await copyText(token);
      btn.textContent = ok ? t('copied') : t('copy_failed');
      setTimeout(() => {
        btn.textContent = old;
        btn.disabled = false;
      }, 1200);
    }
  });
})();
