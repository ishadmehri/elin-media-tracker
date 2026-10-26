(() => {
  const cfg = window.mtrk;
  if (!cfg) return;
  const t = cfg.i18n;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const post = async (action, data = {}) => {
    const body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', cfg.nonce);
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(cfg.ajax, { method: 'POST', body, credentials: 'same-origin' });
    const json = await res.json().catch(() => null);
    if (!json || !json.success) throw new Error((json && json.data) || t.error);
    return json.data;
  };

  // Usage modal: a native <dialog>, so focus trap, Esc and the backdrop come from the browser.
  let dialog;
  let seq = 0;
  const openUsage = async (id) => {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'mtrk-dialog';
      dialog.setAttribute('aria-labelledby', 'mtrk-dialog-title');
      const bar = document.createElement('form');
      bar.method = 'dialog';
      bar.className = 'mtrk-dialog-bar';
      const h = document.createElement('h2');
      h.id = 'mtrk-dialog-title';
      h.textContent = t.title;
      const close = document.createElement('button');
      close.className = 'mtrk-close';
      close.setAttribute('aria-label', t.close);
      close.textContent = '×';
      bar.append(h, close);
      const content = document.createElement('div');
      content.className = 'mtrk-body';
      dialog.append(bar, content);
      dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
      // The media modal underneath listens for Esc/arrows too; while ours is open, keys are ours only.
      // Default actions (Esc closes the dialog, Tab moves focus) still happen.
      window.addEventListener('keydown', (e) => { if (dialog.open) e.stopPropagation(); }, true);
      document.body.append(dialog);
    }
    const content = dialog.querySelector('.mtrk-body');
    const mine = ++seq;
    content.textContent = t.loading;
    if (!dialog.open) dialog.showModal();
    try {
      const data = await post('mtrk_usage', { id });
      if (mine === seq) content.innerHTML = data.html;
    } catch (e) {
      if (mine === seq) content.textContent = e.message;
    }
  };

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.mtrk-open');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation(); // keep the media grid from treating it as a selection click
    openUsage(btn.dataset.id);
  }, true);

  // Scan page: start, then step until done. Steps are idempotent, so failures are retried.
  const form = document.getElementById('mtrk-form');
  const box = document.getElementById('mtrk-progress');
  if (!form || !box) return;
  const bar = box.querySelector('progress');
  const label = box.querySelector('.mtrk-label');
  const cancel = box.querySelector('.mtrk-cancel');
  const retry = box.querySelector('.mtrk-retry');
  let stop = false;

  const show = (p) => {
    form.hidden = true;
    box.hidden = false;
    bar.value = p.percent;
    label.textContent = `${p.percent}% — ${p.label}`;
  };

  const run = async () => {
    retry.hidden = true;
    let fails = 0;
    while (!stop) {
      let p;
      try {
        p = await post('mtrk_scan', { do: 'step' });
        fails = 0;
      } catch (e) {
        if (++fails > 3) {
          label.textContent = e.message;
          retry.hidden = false;
          return;
        }
        await sleep(2000);
        continue;
      }
      if (p.done) {
        bar.value = 100;
        label.textContent = t.done;
        window.location.reload();
        return;
      }
      show(p);
      if (p.busy) await sleep(2000);
    }
    await post('mtrk_scan', { do: 'cancel' }).catch(() => {});
    window.location.reload();
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    try {
      show(await post('mtrk_scan', { do: 'start', code: fd.get('code'), tables: fd.get('tables') ? 1 : '' }));
      run();
    } catch (err) {
      window.alert(err.message);
    }
  });

  cancel.addEventListener('click', () => {
    stop = true;
    cancel.disabled = true;
    label.textContent = t.stopping;
  });
  retry.addEventListener('click', run);

  const running = JSON.parse(box.dataset.running || 'null');
  if (running) {
    show(running);
    run();
  }
})();
