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

  const fmt = (s, ...a) => s.replace(/%(\d)\$s/g, (_, i) => a[i - 1]).replace('%s', a[0]);
  const mb = (b) => (b / 1048576).toFixed(1);

  // One modal for "where is it used" (media library) and "preview" (plugin page): a native <dialog>, so focus
  // trap, Esc and the backdrop come from the browser.
  let dialog;
  let seq = 0;
  const openDialog = async (action, id) => {
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
    const title = dialog.querySelector('#mtrk-dialog-title');
    const mine = ++seq;
    title.textContent = action === 'mtrk_preview' ? t.preview : t.title;
    dialog.classList.toggle('mtrk-dialog-wide', action === 'mtrk_preview');
    content.textContent = t.loading;
    if (!dialog.open) dialog.showModal();
    try {
      const data = await post(action, { id });
      if (mine !== seq) return;
      content.innerHTML = data.html;
      if (data.title) title.textContent = data.title;
    } catch (e) {
      if (mine === seq) content.textContent = e.message;
    }
  };

  // Actions on media (plugin page): quarantine, restore, keep, unkeep, delete. The page reloads with a notice.
  const act = async (name, ids, btn) => {
    if (!ids.length) return;
    if (name === 'delete' && !window.confirm(fmt(t.confirmDelete, ids.length))) return;
    if (btn) btn.disabled = true;
    try {
      const r = await post('mtrk_act', { act: name, ids });
      const url = new URL(window.location.href);
      url.searchParams.delete('paged'); // the current page may be gone now
      url.searchParams.set('done', name);
      url.searchParams.set('n', r.done.length);
      url.searchParams.set('b', r.bytes);
      window.location.href = url.toString();
    } catch (e) {
      window.alert(e.message);
      if (btn) btn.disabled = false;
    }
  };

  const picked = () => [...document.querySelectorAll('.mtrk-pick:checked')].map((b) => b.value);
  const syncBulk = () => {
    const ids = picked();
    document.querySelectorAll('.mtrk-bulk[data-scope]').forEach((bar) => {
      bar.querySelectorAll('[data-act]').forEach((b) => { b.disabled = !ids.length; });
      bar.querySelector('.mtrk-count').textContent = ids.length ? fmt(t.selected, ids.length) : '';
    });
  };

  document.addEventListener('click', (e) => {
    const usage = e.target.closest('.mtrk-open');
    const preview = e.target.closest('.mtrk-preview');
    const action = e.target.closest('[data-act]');
    if (usage || preview) {
      e.preventDefault();
      e.stopPropagation(); // keep the media grid from treating it as a selection click
      openDialog(usage ? 'mtrk_usage' : 'mtrk_preview', (usage || preview).dataset.id);
    } else if (action && (action.dataset.id || action.closest('.mtrk-bulk[data-scope]'))) {
      e.preventDefault();
      act(action.dataset.act, action.dataset.id ? [action.dataset.id] : picked(), action);
    }
  }, true);

  document.addEventListener('change', (e) => {
    if (e.target.matches('.mtrk-bulk[data-scope] .mtrk-all')) {
      document.querySelectorAll('.mtrk-pick').forEach((b) => { b.checked = e.target.checked; });
    }
    if (e.target.matches('.mtrk-pick, .mtrk-all')) syncBulk();
  });

  // Quarantine tab: do requests for missing upload files reach WordPress here? (nginx rules or a CDN can stop them)
  const probe = document.querySelector('.mtrk-probe[data-url]');
  if (probe) {
    fetch(probe.dataset.url, { cache: 'no-store', credentials: 'omit' })
      .then((r) => r.headers.get('X-Mtrk') === 'ok')
      .catch(() => false)
      .then((ok) => {
        probe.textContent = ok ? t.probeOk : t.probeFail;
        probe.classList.add(ok ? 'notice-success' : 'notice-warning');
      });
  }

  // Orphan files: walk the media folders in batches, list what no attachment owns, delete the checked ones.
  const orphans = document.getElementById('mtrk-orphans');
  if (orphans) {
    const status = orphans.querySelector('.mtrk-orphans-status');
    const result = orphans.querySelector('.mtrk-orphans-result');
    const tbody = orphans.querySelector('tbody');
    const checkBtn = orphans.querySelector('.mtrk-orphans-check');
    const delBtn = orphans.querySelector('.mtrk-orphans-delete');
    const all = orphans.querySelector('.mtrk-orphans-all');
    const more = orphans.querySelector('.mtrk-orphans-more');
    const count = orphans.querySelector('.mtrk-count');
    const isImage = /\.(jpe?g|png|gif|webp|avif|svg)$/i;
    const PAGE = 300;
    let files = [];
    let shown = 0;

    const cell = (child) => {
      const td = document.createElement('td');
      td.append(child);
      return td;
    };
    const text = (s, tag = 'span') => {
      const el = document.createElement(tag);
      el.textContent = s;
      return el;
    };
    const row = (f) => {
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.className = 'mtrk-orphan';
      box.value = f.path;
      box.disabled = f.kept;
      box.setAttribute('aria-label', f.path);
      const th = document.createElement('th');
      th.className = 'check-column';
      th.scope = 'row';
      th.append(box);
      const name = document.createElement('div');
      name.className = 'mtrk-qmedia';
      if (isImage.test(f.path)) {
        const img = document.createElement('img');
        img.src = `${orphans.dataset.base}/${f.path.split('/').map(encodeURIComponent).join('/')}`;
        img.alt = '';
        img.loading = 'lazy';
        name.append(img);
      }
      const path = text(f.path, 'bdi');
      path.dir = 'ltr';
      name.append(path);
      const kind = f.kept ? t.orphanKept : (f.type === 'size' ? fmt(t.orphanSize, f.owner) : t.orphanFile);
      const tr = document.createElement('tr');
      tr.append(th, cell(name), cell(text(kind)), cell(text(`${mb(f.bytes)} ${t.mb}`)));
      return tr;
    };
    const renderMore = () => {
      files.slice(shown, shown + PAGE).forEach((f) => tbody.append(row(f)));
      shown = Math.min(files.length, shown + PAGE);
      more.hidden = shown >= files.length;
      more.textContent = `${t.more} (${files.length - shown})`;
    };
    const selected = () => [...tbody.querySelectorAll('.mtrk-orphan:checked')].map((b) => b.value);
    const sync = () => {
      const n = selected().length;
      delBtn.disabled = !n;
      count.textContent = n ? fmt(t.selected, n) : '';
    };

    const check = async () => {
      checkBtn.disabled = true;
      files = [];
      try {
        let cursor = 0;
        let folders = 0;
        do {
          const r = await post('mtrk_orphans', { do: 'step', cursor });
          files = files.concat(r.files);
          folders = Math.min(r.total, folders + 20);
          status.textContent = fmt(t.orphansChecking, folders);
          cursor = r.next;
        } while (cursor !== null);
        files.sort((a, b) => (a.kept - b.kept) || (b.bytes - a.bytes)); // deletable and biggest first
        tbody.replaceChildren();
        shown = 0;
        renderMore();
        all.checked = false;
        sync();
        result.hidden = !files.length;
        const free = files.filter((f) => !f.kept);
        status.textContent = files.length ? fmt(t.orphansFound, free.length, mb(free.reduce((n, f) => n + f.bytes, 0))) : t.orphansNone;
      } catch (e) {
        status.textContent = e.message;
      }
      checkBtn.disabled = false;
    };

    checkBtn.addEventListener('click', check);
    more.addEventListener('click', renderMore);
    orphans.addEventListener('change', (e) => {
      if (e.target === all) {
        while (all.checked && shown < files.length) renderMore(); // "all" means all, not only the rows drawn
        tbody.querySelectorAll('.mtrk-orphan:not(:disabled)').forEach((b) => { b.checked = all.checked; });
      }
      sync();
    });
    delBtn.addEventListener('click', async () => {
      const paths = selected();
      if (!window.confirm(fmt(t.confirmDelete, paths.length))) return;
      delBtn.disabled = true;
      let removed = 0;
      let bytes = 0;
      try {
        for (let i = 0; i < paths.length; i += 200) {
          const r = await post('mtrk_orphans', { do: 'delete', paths: paths.slice(i, i + 200) });
          removed += r.files;
          bytes += r.bytes;
        }
        await check();
        status.textContent = fmt(t.sizesDeleted, removed, mb(bytes));
      } catch (e) {
        status.textContent = e.message;
      }
    });
  }

  // Unregistered sizes: walk every attachment in batches to report, then again to delete the checked sizes.
  const sizes = document.getElementById('mtrk-sizes');
  if (sizes) {
    const status = sizes.querySelector('.mtrk-sizes-status');
    const result = sizes.querySelector('.mtrk-sizes-result');
    const tbody = sizes.querySelector('tbody');
    const checkBtn = sizes.querySelector('.mtrk-sizes-check');
    const cleanBtn = sizes.querySelector('.mtrk-sizes-clean');
    const all = sizes.querySelector('.mtrk-sizes-all');
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
