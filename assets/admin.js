(() => {
  const cfg = window.mtrk;
  if (!cfg) return;
  const t = cfg.i18n;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const post = async (action, data = {}) => {
    const body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', cfg.nonce);
    Object.entries(data).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => body.append(`${k}[]`, x));
      else body.append(k, v);
    });
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

  // Unregistered sizes: walk every attachment in batches to report, then again to delete the checked sizes.
  const sizes = document.getElementById('mtrk-sizes');
  if (sizes) {
    const status = sizes.querySelector('.mtrk-sizes-status');
    const result = sizes.querySelector('.mtrk-sizes-result');
    const tbody = sizes.querySelector('tbody');
    const checkBtn = sizes.querySelector('.mtrk-sizes-check');
    const cleanBtn = sizes.querySelector('.mtrk-sizes-clean');
    const all = sizes.querySelector('.mtrk-sizes-all');
    const mb = (b) => (b / 1048576).toFixed(1);
    const fmt = (s, ...a) => s.replace(/%(\d)\$s/g, (_, i) => a[i - 1]).replace('%s', a[0]);
    const sum = (list, key) => list.reduce((n, s) => n + s[key], 0);
    let report = {};

    const walk = async (data) => {
      const total = {};
      let cursor = 0;
      let seen = 0;
      do {
        const r = await post('mtrk_sizes', { ...data, cursor });
        Object.entries(r.sizes).forEach(([name, s]) => {
          const tot = total[name] || (total[name] = { files: 0, bytes: 0, kept: 0, dims: s.dims });
          tot.files += s.files;
          tot.bytes += s.bytes;
          tot.kept += s.kept;
        });
        cursor = r.next;
        seen += r.count;
        status.textContent = fmt(t.sizesChecking, seen);
      } while (cursor !== null);
      return total;
    };

    const render = () => {
      tbody.replaceChildren();
      const names = Object.keys(report).sort((a, b) => report[b].bytes - report[a].bytes);
      names.forEach((name) => {
        const s = report[name];
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'mtrk-size';
        box.value = name;
        box.disabled = !s.files;
        box.setAttribute('aria-label', name);
        const th = document.createElement('th');
        th.className = 'check-column';
        th.scope = 'row';
        th.append(box);
        const tr = document.createElement('tr');
        tr.append(th, ...[name, s.dims, s.files, `${mb(s.bytes)} ${t.mb}`, s.kept].map((v) => {
          const td = document.createElement('td');
          const bdi = document.createElement('bdi'); // keep "1170×600" and size names in their own direction in RTL
          bdi.textContent = v;
          td.append(bdi);
          return td;
        }));
        tbody.append(tr);
      });
      all.checked = false;
      cleanBtn.disabled = true;
      result.hidden = !names.length;
      const list = Object.values(report);
      status.textContent = names.length ? fmt(t.sizesFound, sum(list, 'files'), mb(sum(list, 'bytes'))) : t.sizesNone;
    };

    const selected = () => [...tbody.querySelectorAll('.mtrk-size:checked')].map((b) => b.value);
    sizes.addEventListener('change', (e) => {
      if (e.target === all) tbody.querySelectorAll('.mtrk-size:not(:disabled)').forEach((b) => { b.checked = all.checked; });
      cleanBtn.disabled = !selected().length;
    });

    const check = async () => {
      checkBtn.disabled = true;
      cleanBtn.disabled = true;
      try {
        report = await walk({ do: 'report' });
        render();
      } catch (e) {
        status.textContent = e.message;
      }
      checkBtn.disabled = false;
    };
    checkBtn.addEventListener('click', check);

    cleanBtn.addEventListener('click', async () => {
      const names = selected();
      const picked = names.map((n) => report[n]);
      if (!window.confirm(fmt(t.sizesConfirm, sum(picked, 'files'), mb(sum(picked, 'bytes'))))) return;
      checkBtn.disabled = true;
      cleanBtn.disabled = true;
      try {
        const done = Object.values(await walk({ do: 'clean', names }));
        await check();
        status.textContent = fmt(t.sizesDeleted, sum(done, 'files'), mb(sum(done, 'bytes')));
      } catch (e) {
        status.textContent = e.message;
        checkBtn.disabled = false;
      }
    });
  }

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
