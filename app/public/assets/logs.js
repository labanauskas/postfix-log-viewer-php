/* Mail log page: server-side filtering/paging + detail drawer */
(function () {
  const page = document.getElementById('page');
  let TODAY = page.dataset.today; // refreshed from the server on every load (page may stay open past midnight)
  const state = {
    date: page.dataset.date,
    status: page.dataset.status || '',
    q: '',
    page: 1,
    per: 50,
    dir: 'desc',
  };

  const LABEL = { sent: 'Delivered', deferred: 'Deferred', bounced: 'Bounced', rejected: 'Rejected', error: 'Error', queued: 'Queued', other: 'Other' };
  const BADGE = { sent: 'success', deferred: 'warning', bounced: 'danger', rejected: 'danger', error: 'danger', queued: 'info', other: 'muted' };

  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const badge = (s) => `<span class="badge badge-${BADGE[s] || 'muted'}">${esc(LABEL[s] || s)}</span>`;

  function fmtSize(n) {
    if (n == null) return '<span class="muted">—</span>';
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /* ── data loading ── */
  let reqId = 0;
  async function load(opts = {}) {
    const my = ++reqId;
    const params = new URLSearchParams({
      action: 'logs', date: state.date, status: state.status, q: state.q,
      page: state.page, per: state.per, dir: state.dir,
    });
    if (opts.refresh) params.set('refresh', '1');
    if (!opts.quiet) $('tableSection').classList.add('table-loading');
    try {
      const res = await fetch('api.php?' + params.toString(), { credentials: 'same-origin' });
      if (res.status === 401) { location.href = 'login.php'; return; }
      const data = await res.json();
      if (my !== reqId) return;
      const err = $('loadError');
      if (data.error) {
        err.textContent = data.error;
        err.hidden = false;
        $('tableBody').innerHTML = '';
        $('tableInfo').textContent = '';
        $('pagination').innerHTML = '';
        return;
      }
      err.hidden = true;
      if (data.today) {
        TODAY = data.today;
        $('dateInput').max = data.today;
      }
      if (data.date && data.date !== state.date) { // "today" resolved by the server
        state.date = data.date;
        $('dateInput').value = data.date;
        syncUrl();
      }
      render(data);
    } catch (e) {
      if (my !== reqId) return;
      const err = $('loadError');
      err.textContent = 'Failed to load log: ' + e.message;
      err.hidden = false;
    } finally {
      if (my === reqId) $('tableSection').classList.remove('table-loading');
    }
  }

  function render(data) {
    const st = data.stats;
    document.querySelectorAll('[data-stat]').forEach((el) => {
      const k = el.dataset.stat;
      const v = k === 'queued' ? (st.queued || 0) + (st.other || 0) : st[k] || 0;
      el.textContent = v.toLocaleString();
    });
    document.querySelectorAll('.stat-card').forEach((c) => c.classList.toggle('selected', c.dataset.filter === state.status));

    $('subtitle').textContent = `${state.date}${state.date === TODAY ? ' (today)' : ''} · ${data.lines.toLocaleString()} Postfix lines` +
      (data.parsed_at ? ` · indexed ${data.parsed_at}` : '');

    const list = data.list;
    const tbody = $('tableBody');
    if (!list.rows.length) {
      tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="ti ti-mood-empty"></i><p>No log entries match</p></div></td></tr>`;
    } else {
      tbody.innerHTML = list.rows.map(rowHtml).join('');
    }

    const total = list.total;
    const start = (list.page - 1) * list.per;
    $('tableInfo').textContent = total ? `Showing ${start + 1}–${Math.min(start + list.per, total)} of ${total.toLocaleString()} entries` : '0 entries';
    renderPagination(list.page, list.pages);
  }

  function rowHtml(r) {
    const isEvent = r.kind === 'event';
    const sender = r.sender === '' ? (isEvent ? '<span class="muted">—</span>' : '<span class="muted">&lt;&gt; null sender</span>') : esc(r.sender);
    let to = r.rcpt ? esc(r.rcpt) : '<span class="muted">—</span>';
    if (r.rcpt_count > 1) to += ` <span class="muted">+${r.rcpt_count - 1}</span>`;
    const detail = r.status === 'sent' ? (r.relay || '') : (r.reason || r.relay || '');
    const full = [r.relay, r.reason].filter(Boolean).join(' — ');
    return `<tr class="clickable" tabindex="0" data-id="${esc(r.id)}">
      <td class="mono">${esc(r.ts)}</td>
      <td class="mono">${isEvent ? '<span class="muted">—</span>' : esc(r.id)}</td>
      <td class="cell-trunc" title="${esc(r.sender)}">${sender}</td>
      <td class="cell-trunc" title="${esc(r.rcpt)}">${to}</td>
      <td>${badge(r.status)}</td>
      <td class="cell-trunc muted" title="${esc(full)}">${esc(detail)}</td>
      <td class="mono nowrap">${fmtSize(r.size)}</td>
    </tr>`;
  }

  function renderPagination(cur, pages) {
    let html = `<button class="page-btn" data-page="${cur - 1}" ${cur === 1 ? 'disabled' : ''} aria-label="Previous page"><i class="ti ti-chevron-left"></i></button>`;
    for (let i = 1; i <= pages; i++) {
      if (pages > 7 && i > 2 && i < pages - 1 && Math.abs(i - cur) > 1) {
        if (i === 3 || i === pages - 2) html += `<span class="page-btn" style="cursor:default;color:var(--text-3)">…</span>`;
        continue;
      }
      html += `<button class="page-btn${i === cur ? ' active' : ''}" data-page="${i}" aria-label="Page ${i}">${i}</button>`;
    }
    html += `<button class="page-btn" data-page="${cur + 1}" ${cur >= pages ? 'disabled' : ''} aria-label="Next page"><i class="ti ti-chevron-right"></i></button>`;
    $('pagination').innerHTML = html;
  }

  /* ── URL + controls ── */
  function syncUrl() {
    const p = new URLSearchParams();
    p.set('date', state.date);
    if (state.status) p.set('status', state.status);
    history.replaceState(null, '', '?' + p.toString());
  }

  function setStatus(s) {
    state.status = s;
    state.page = 1;
    $('statusFilter').value = s;
    syncUrl();
    load();
  }

  function setDate(d) {
    if (!d) return;
    state.date = d;
    state.page = 1;
    if (d !== 'today') $('dateInput').value = d;
    closeDrawer();
    syncUrl();
    load();
  }

  function shiftDate(n) {
    const d = new Date(state.date + 'T12:00:00');
    d.setDate(d.getDate() + n);
    const p = (x) => String(x).padStart(2, '0');
    setDate(`${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`);
  }

  $('prevDay').addEventListener('click', () => shiftDate(-1));
  $('nextDay').addEventListener('click', () => shiftDate(1));
  $('todayBtn').addEventListener('click', () => setDate('today')); // server resolves the real current date
  $('dateInput').addEventListener('change', (e) => setDate(e.target.value));
  $('refreshBtn').addEventListener('click', () => load({ refresh: true }));
  $('statusFilter').addEventListener('change', (e) => setStatus(e.target.value));
  $('perFilter').addEventListener('change', (e) => { state.per = parseInt(e.target.value, 10); state.page = 1; load(); });

  let timer = null;
  $('searchInput').addEventListener('input', (e) => {
    clearTimeout(timer);
    timer = setTimeout(() => { state.q = e.target.value; state.page = 1; load(); }, 300);
  });

  $('sortTime').addEventListener('click', () => {
    state.dir = state.dir === 'desc' ? 'asc' : 'desc';
    $('sortIcon').className = 'ti ' + (state.dir === 'desc' ? 'ti-arrow-down' : 'ti-arrow-up');
    state.page = 1;
    load();
  });

  document.querySelectorAll('.stat-card').forEach((c) => {
    c.addEventListener('click', () => setStatus(c.dataset.filter));
    c.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setStatus(c.dataset.filter); } });
  });

  $('pagination').addEventListener('click', (e) => {
    const b = e.target.closest('[data-page]');
    if (!b || b.disabled) return;
    state.page = parseInt(b.dataset.page, 10);
    load();
    $('tableSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  /* ── detail drawer ── */
  const drawer = $('drawer');
  let selectedRow = null;

  function openRow(tr) {
    if (selectedRow) selectedRow.classList.remove('selected');
    selectedRow = tr;
    tr.classList.add('selected');
    openMessage(tr.dataset.id);
  }

  $('tableBody').addEventListener('click', (e) => {
    const tr = e.target.closest('tr.clickable');
    if (tr) openRow(tr);
  });
  $('tableBody').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const tr = e.target.closest('tr.clickable');
    if (tr) openRow(tr);
  });

  function closeDrawer() {
    drawer.classList.remove('open');
    $('drawerOverlay').classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    if (selectedRow) { selectedRow.classList.remove('selected'); selectedRow = null; }
  }
  $('drawerClose').addEventListener('click', closeDrawer);
  $('drawerOverlay').addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

  async function openMessage(id) {
    drawer.classList.add('open');
    $('drawerOverlay').classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    $('drawerTitle').textContent = id.startsWith('evt-') ? 'Log event' : id;
    $('drawerSub').textContent = '';
    $('drawerBadge').innerHTML = '';
    $('drawerBody').innerHTML = '<div class="empty-state"><i class="ti ti-loader-2"></i><p>Loading…</p></div>';
    try {
      const res = await fetch('api.php?' + new URLSearchParams({ action: 'message', date: state.date, id }), { credentials: 'same-origin' });
      if (res.status === 401) { location.href = 'login.php'; return; }
      const data = await res.json();
      if (data.error) {
        $('drawerBody').innerHTML = `<div class="alert alert-err">${esc(data.error)}</div>`;
        return;
      }
      renderMessage(data.message);
    } catch (e) {
      $('drawerBody').innerHTML = `<div class="alert alert-err">${esc(e.message)}</div>`;
    }
  }

  function kv(rows) {
    const out = rows.filter((r) => r[1] !== '' && r[1] != null).map((r) => `<dt>${esc(r[0])}</dt><dd>${r[2] ? r[1] : esc(r[1])}</dd>`).join('');
    return `<dl class="kv">${out}</dl>`;
  }

  function lineClass(l) {
    if (/status=(bounced|expired)|reject|fatal|panic|error/i.test(l)) return 'l-bad';
    if (/status=deferred|warning/i.test(l)) return 'l-warn';
    if (/status=sent/i.test(l)) return 'l-ok';
    return '';
  }

  function renderMessage(m) {
    const isEvent = m.kind === 'event';
    $('drawerTitle').textContent = isEvent ? 'Log event' : m.id;
    $('drawerSub').textContent = isEvent ? `${state.date} ${m.first_ts}` : `${state.date} ${m.first_ts}${m.last_ts !== m.first_ts ? ' → ' + m.last_ts : ''}`;
    $('drawerBadge').innerHTML = badge(m.status);

    let html = '<section class="drawer-sec"><h3>Summary</h3>';
    if (isEvent) {
      html += kv([['Status', LABEL[m.status] || m.status], ['Time', m.first_ts], ['Process', m.process], ['From', m.sender],
        ['Recipient', m.recipient], ['Client', m.client], ['Reason', m.reason]]);
    } else {
      const bounce = m.bounce_id ? `<button class="link-btn mono" data-open="${esc(m.bounce_id)}" type="button">${esc(m.bounce_id)}</button> (bounce notification)` : '';
      html += kv([
        ['Status', LABEL[m.status] || m.status],
        ['From', m.sender === '' ? '<> (null sender)' : m.sender],
        ['Recipients', m.recipients.length ? String(m.recipients.length) : (m.nrcpt != null ? String(m.nrcpt) : '')],
        ['Size', m.size != null ? m.size.toLocaleString() + ' bytes' : ''],
        ['Client', m.client], ['SASL user', m.sasl], ['Message-ID', m.message_id], ['Subject', m.subject],
        ['Expired', m.expired ? 'Yes, returned to sender' : ''],
        ['Note', m.note],
        ['Related', bounce, true],
        ['Removed from queue', m.removed ? 'Yes' : ''],
      ]);
    }
    html += '</section>';

    if (!isEvent) {
      if (m.recipients.length) {
        html += '<section class="drawer-sec"><h3>Recipients</h3><div class="rcpt-list">' + m.recipients.map((r) => `
          <div class="rcpt">
            <div class="rcpt-top"><span class="mono">${esc(r.addr)}</span>${badge(r.status === 'sent' || r.status === 'deferred' || r.status === 'bounced' || r.status === 'rejected' ? r.status : 'other')}</div>
            ${r.orig_to ? `<div class="muted">original: ${esc(r.orig_to)}</div>` : ''}
            <div class="muted">${esc([r.relay && r.relay !== 'none' ? 'relay ' + r.relay : '', r.dsn ? 'dsn ' + r.dsn : '', r.delay ? 'delay ' + r.delay + 's' : '', r.attempts > 1 ? r.attempts + ' attempts' : '', r.time].filter(Boolean).join(' · '))}</div>
            ${r.response ? `<div class="rcpt-resp">${esc(r.response)}</div>` : ''}
          </div>`).join('') + '</div></section>';
      }
      if (m.warnings && m.warnings.length) {
        html += '<section class="drawer-sec"><h3>Warnings</h3><ul class="warn-list">' + m.warnings.map((w) => `<li>${esc(w)}</li>`).join('') + '</ul></section>';
      }
    }

    html += `<section class="drawer-sec"><h3>Raw log lines (${m.lines.length})</h3><pre class="raw-log">${
      m.lines.map((l) => `<span class="${lineClass(l)}">${esc(l)}</span>`).join('\n')}</pre></section>`;

    $('drawerBody').innerHTML = html;
    $('drawerBody').scrollTop = 0;
  }

  $('drawerBody').addEventListener('click', (e) => {
    const b = e.target.closest('[data-open]');
    if (b) openMessage(b.dataset.open);
  });

  /* ── auto refresh for today ── */
  setInterval(() => {
    if (state.date === TODAY && document.visibilityState === 'visible' && !drawer.classList.contains('open')) {
      load({ quiet: true });
    }
  }, 30000);

  /* init */
  $('statusFilter').value = state.status;
  load();
})();
