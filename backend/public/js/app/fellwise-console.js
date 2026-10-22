/* Fellwise Mission Control.
 * Renders the pipeline topology and animates every real hop recorded in
 * ops.delivery_attempts (polled through the Livewire page's state()).
 * Queue depths come from the broker; nothing here is simulated. */
(() => {
  const COL = i => 36 + i * 150, ROW = [36, 196, 356], NW = 134, NH = 66, W = 1234, H = 440;
  const NODES = [
    { id: 'cms', title: 'CMS · Filament', sub: 'Catalogue context', kind: 'app', c: 0, r: 0 },
    { id: 'outbox', title: 'Outbox', sub: 'outbox_messages · same tx', kind: 'table', c: 1, r: 0 },
    { id: 'relay', title: 'Outbox relay', sub: 'publisher confirms', kind: 'worker', c: 2, r: 0 },
    { id: 'exchange', title: 'fellwise.events', sub: 'topic · durable', kind: 'exchange', c: 3, r: 0, mono: true },
    { id: 'catalogue', title: 'core.catalogue', full: 'core.catalogue', sub: 'catalogue.# legacy.#', kind: 'queue', c: 4, r: 0, mono: true },
    { id: 'consumer', title: 'Core consumer', sub: 'messaging:consume', kind: 'worker', c: 5, r: 0 },
    { id: 'inbox', title: 'Inbox', sub: '(event_id, consumer) PK', kind: 'table', c: 6, r: 0 },
    { id: 'readmodel', title: 'Read model', sub: 'catalogue_experiences', kind: 'table', c: 7, r: 0 },
    { id: 'traildesk', title: 'TrailDesk', sub: 'legacy backfill · ACL', kind: 'app', c: 0, r: 1 },
    { id: 'coreapi', title: 'Core API', sub: 'Bookings context', kind: 'app', c: 2, r: 1 },
    { id: 'requeue', title: 'fellwise.requeue', sub: 'dead-letter · direct', kind: 'exchange', c: 3, r: 1, mono: true },
    { id: 'retry5', title: 'retry.5s', full: 'core.catalogue.retry.5s', sub: 'ttl 5 s → requeue', kind: 'queue', c: 4, r: 1, mono: true, ttl: 5000 },
    { id: 'retry15', title: 'retry.15s', full: 'core.catalogue.retry.15s', sub: 'ttl 15 s → requeue', kind: 'queue', c: 5, r: 1, mono: true, ttl: 15000 },
    { id: 'retry45', title: 'retry.45s', full: 'core.catalogue.retry.45s', sub: 'ttl 45 s → requeue', kind: 'queue', c: 6, r: 1, mono: true, ttl: 45000 },
    { id: 'parking', title: 'parking-lot', full: 'core.catalogue.parking-lot', sub: 'no TTL · x-last-error', kind: 'queue', c: 7, r: 1, mono: true },
    { id: 'cmsconsumer', title: 'CMS consumer', sub: 'messaging:consume', kind: 'worker', c: 0, r: 2 },
    { id: 'cmsbookings', title: 'cms.bookings', full: 'cms.bookings', sub: 'bookings.#', kind: 'queue', c: 1, r: 2, mono: true },
  ];
  const N = Object.fromEntries(NODES.map(n => [n.id, n]));
  for (const n of NODES) { n.x = COL(n.c); n.y = ROW[n.r]; n.cx = n.x + NW / 2; n.cy = n.y + NH / 2; n.right = n.x + NW; n.bottom = n.y + NH; }
  const P = (x, y) => `${x} ${y}`;
  const hline = (a, b) => `M${P(a.right, a.cy)} L${P(b.x, b.cy)}`;
  const vcurve = (x1, y1, x2, y2) => { const ym = (y1 + y2) / 2; return `M${P(x1, y1)} C${P(x1, ym)} ${P(x2, ym)} ${P(x2, y2)}`; };
  const ortho = (pts, rad = 10) => {
    let d = `M${P(pts[0][0], pts[0][1])}`;
    for (let i = 1; i < pts.length - 1; i++) {
      const [px, py] = pts[i - 1], [x, y] = pts[i], [nx, ny] = pts[i + 1];
      const dx1 = Math.sign(x - px), dy1 = Math.sign(y - py), dx2 = Math.sign(nx - x), dy2 = Math.sign(ny - y);
      d += ` L${P(x - dx1 * rad, y - dy1 * rad)} Q${P(x, y)} ${P(x + dx2 * rad, y + dy2 * rad)}`;
    }
    const l = pts[pts.length - 1];
    return d + ` L${P(l[0], l[1])}`;
  };
  const EDGES = [
    { id: 'cms-outbox', d: hline(N.cms, N.outbox) }, { id: 'outbox-relay', d: hline(N.outbox, N.relay) }, { id: 'relay-exchange', d: hline(N.relay, N.exchange) },
    { id: 'exchange-catalogue', d: hline(N.exchange, N.catalogue) }, { id: 'catalogue-consumer', d: hline(N.catalogue, N.consumer) },
    { id: 'consumer-inbox', d: hline(N.consumer, N.inbox) }, { id: 'inbox-readmodel', d: hline(N.inbox, N.readmodel) },
    { id: 'consumer-retry5', cls: 'retry', d: vcurve(N.consumer.cx - 30, N.consumer.bottom, N.retry5.cx, N.retry5.y) },
    { id: 'consumer-retry15', cls: 'retry', d: vcurve(N.consumer.cx, N.consumer.bottom, N.retry15.cx, N.retry15.y) },
    { id: 'consumer-retry45', cls: 'retry', d: vcurve(N.consumer.cx + 30, N.consumer.bottom, N.retry45.cx, N.retry45.y) },
    { id: 'consumer-parking', cls: 'retry', d: vcurve(N.consumer.cx + 50, N.consumer.bottom, N.parking.cx, N.parking.y) },
    { id: 'retry5-requeue', cls: 'retry', d: ortho([[N.retry5.cx, N.retry5.bottom], [N.retry5.cx, 300], [N.requeue.cx, 300], [N.requeue.cx, N.requeue.bottom]]) },
    { id: 'retry15-requeue', cls: 'retry', d: ortho([[N.retry15.cx, N.retry15.bottom], [N.retry15.cx, 300], [N.requeue.cx, 300], [N.requeue.cx, N.requeue.bottom]]) },
    { id: 'retry45-requeue', cls: 'retry', d: ortho([[N.retry45.cx, N.retry45.bottom], [N.retry45.cx, 300], [N.requeue.cx, 300], [N.requeue.cx, N.requeue.bottom]]) },
    { id: 'requeue-catalogue', cls: 'retry', d: vcurve(N.requeue.right - 28, N.requeue.y, N.catalogue.x + 36, N.catalogue.bottom) },
    { id: 'exchange-cmsbookings', d: ortho([[N.exchange.x + 8, N.exchange.bottom], [N.exchange.x + 8, 130], [N.cmsbookings.cx, 130], [N.cmsbookings.cx, N.cmsbookings.y]]) },
    { id: 'cmsbookings-cmsconsumer', d: `M${P(N.cmsbookings.x, N.cmsbookings.cy)} L${P(N.cmsconsumer.right, N.cmsconsumer.cy)}` },
    { id: 'cmsconsumer-cms', d: ortho([[N.cmsconsumer.x, N.cmsconsumer.cy], [20, N.cmsconsumer.cy], [20, N.cms.cy], [N.cms.x, N.cms.cy]]) },
    { id: 'coreapi-exchange', d: ortho([[N.coreapi.cx, N.coreapi.y], [N.coreapi.cx, 150], [N.exchange.right - 8, 150], [N.exchange.right - 8, N.exchange.bottom]]) },
    { id: 'traildesk-outbox', d: vcurve(N.traildesk.right - 24, N.traildesk.y, N.outbox.x + 20, N.outbox.bottom) },
    { id: 'parking-catalogue', cls: 'retry', d: ortho([[N.parking.cx + 40, N.parking.y], [N.parking.cx + 40, 160], [N.catalogue.right - 12, 160], [N.catalogue.right - 12, N.catalogue.bottom]]) },
  ];
  const E = Object.fromEntries(EDGES.map(e => [e.id, e]));
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const HOP = reduced ? 1 : 330, HOLD_MAX = 30;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = n => Number(n || 0).toLocaleString('en-GB');
  const ease = t => t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;

  /** Map one telemetry row to a route through the topology and a dot colour. */
  function routeFor(row) {
    const ev = row.event || '', cms = row.consumer === 'cms.bookings', legacy = ev.startsWith('legacy.');
    switch (row.outcome) {
      case 'published':
        if (ev.startsWith('bookings.')) return { path: ['coreapi', 'exchange', 'cmsbookings'], state: 'booking' };
        return { path: [legacy ? 'traildesk' : 'cms', 'outbox', 'relay', 'exchange', 'catalogue'], state: legacy ? 'legacy' : 'ok' };
      case 'replayed': return { path: ['outbox', 'relay', 'exchange', 'catalogue'], state: 'replay' };
      case 'processed':
        if (cms) return { path: ['cmsbookings', 'cmsconsumer', 'cms'], state: 'booking', done: true };
        return { path: ['catalogue', 'consumer', 'inbox', 'readmodel'], state: row.replay ? 'replay' : legacy ? 'legacy' : 'ok', done: true };
      case 'duplicate':
        return cms ? { path: ['cmsbookings', 'cmsconsumer'], state: 'dup', done: true } : { path: ['catalogue', 'consumer', 'inbox'], state: 'dup', done: true };
      case 'retry_scheduled': {
        const tier = { '5s': 'retry5', '15s': 'retry15', '45s': 'retry45' }[row.tier] || 'retry5';
        return { path: ['catalogue', 'consumer', tier], state: 'retry', hold: tier };
      }
      case 'parked': return { path: ['catalogue', 'consumer', 'parking'], state: 'parked', hold: 'parking' };
      case 'unparked': return { path: ['parking', 'catalogue'], state: 'replay', fromParking: true };
      default: return null;
    }
  }

  function boot(root, wire) {
    if (root.__fw) return;
    root.__fw = true;
    const $ = sel => root.querySelector(sel);
    const urls = { event: root.dataset.eventUrl, timeline: root.dataset.timelineUrl };
    const S = { cursor: 0, fault: false, state: null, dots: [], rows: [], arrivals: {}, history: { lag: [], thr: [], retry: [], parked: [], drift: [], dup: [] }, selected: null, busy: false };
    for (const n of NODES) S.arrivals[n.id] = [];

    // ---- topology DOM ----
    const topo = $('[data-topo]');
    const edges = EDGES.map(e => `<path class="fw-edge-halo" d="${e.d}"/><path class="fw-edge ${e.cls || ''}" data-edge="${e.id}" d="${e.d}"/>`).join('');
    const labels = `<text class="fw-edge-label" x="${(N.retry5.cx + N.requeue.cx) / 2}" y="294" text-anchor="middle">ttl expiry → fellwise.requeue → core.catalogue</text>
      <text class="fw-edge-label" x="${N.cmsbookings.cx + 8}" y="124">bookings.#</text>
      <text class="fw-edge-label" x="${N.consumer.cx + 56}" y="${N.consumer.bottom + 40}">x-retry-count</text>
      <text class="fw-edge-label" x="${N.parking.cx + 34}" y="152" text-anchor="end">unpark</text>
      <text class="fw-edge-label" x="${N.coreapi.cx + 6}" y="146">bookings.booking.confirmed</text>
      <text class="fw-edge-label" x="26" y="${N.cmsconsumer.cy - 8}" transform="rotate(-90 26 ${N.cmsconsumer.cy - 8})">projected into CMS</text>`;
    const nodes = NODES.map(n => `<button type="button" class="fw-node k-${n.kind}" data-node="${n.id}" style="left:${n.x}px;top:${n.y}px" title="${esc(n.full || n.title)}">
        <div class="t"><b class="${n.mono ? 'mono' : ''}">${esc(n.title)}</b><span class="d"></span></div>
        <div class="s">${esc(n.sub)}</div><div class="m">—</div></button>`).join('');
    topo.innerHTML = `<svg class="edges" viewBox="0 0 ${W} ${H}">${edges}${labels}</svg>${nodes}<svg class="dots" viewBox="0 0 ${W} ${H}"><g data-dots></g><g data-more></g></svg>`;
    const dotLayer = topo.querySelector('[data-dots]'), moreLayer = topo.querySelector('[data-more]');
    const pathEl = {};
    for (const e of EDGES) { pathEl[e.id] = topo.querySelector(`[data-edge="${e.id}"]`); e.len = pathEl[e.id].getTotalLength(); }
    const wrap = $('[data-topo-wrap]');
    const fit = () => {
      const s = wrap.clientWidth / W;
      if (s < .82) { topo.style.transform = 'none'; wrap.style.height = H + 'px'; wrap.style.overflowX = 'auto'; return; }
      const k = Math.min(s, 1.3); topo.style.transform = `scale(${k})`; wrap.style.height = Math.round(H * k) + 'px'; wrap.style.overflowX = 'hidden';
    };
    new ResizeObserver(fit).observe(wrap); fit();
    topo.addEventListener('click', e => { const b = e.target.closest('[data-node]'); if (b) openPanel(b.dataset.node); });

    // ---- dots ----
    function spawn(row) {
      const r = routeFor(row);
      if (!r) return;
      // Continue an in-flight or held dot for the same event so a message reads as one journey.
      let dot = S.dots.find(d => d.eventId === row.event_id && !d.done && d.consumerKey === (row.consumer === 'cms.bookings' ? 'cms' : 'core')
        && (d.queue.length ? d.queue[d.queue.length - 1] : d.node) === r.path[0] && d.phase !== 'parked');
      if (!dot && (row.outcome === 'processed' || row.outcome === 'duplicate' || row.outcome === 'retry_scheduled' || row.outcome === 'parked')) {
        // Back from a retry tier: animate tier → requeue → queue first.
        const held = S.dots.find(d => d.eventId === row.event_id && d.phase === 'held' && d.node.startsWith('retry'));
        if (held) { held.phase = 'dwell'; held.until = 0; held.queue.push('requeue', 'catalogue'); dot = held; }
      }
      if (r.fromParking) {
        const parked = S.dots.find(d => d.eventId === row.event_id && d.phase === 'parked') || S.dots.find(d => d.phase === 'parked');
        if (parked) { parked.phase = 'dwell'; parked.until = 0; parked.state = 'replay'; parked.queue.push('catalogue'); parked.hold = null; return; }
      }
      if (dot) {
        dot.queue.push(...r.path.slice(1)); dot.state = r.state; dot.hold = r.hold || null; dot.finishAtEnd = !!r.done; dot.row = row;
        return;
      }
      if (S.dots.length > 260) return; // keep the canvas readable under bulk load
      const start = N[r.path[0]];
      S.dots.push({ eventId: row.event_id, consumerKey: row.consumer === 'cms.bookings' ? 'cms' : 'core', row, state: r.state, node: r.path[0], queue: r.path.slice(1),
        hold: r.hold || null, finishAtEnd: !!r.done, phase: 'dwell', until: performance.now() + 60, x: start.cx, y: start.cy, el: null, born: performance.now() });
    }
    function step(now) {
      for (const d of S.dots) {
        if (d.phase === 'travel') {
          if (now - d.t0 >= d.dur) {
            d.node = d.to; d.phase = 'dwell'; d.until = now + (N[d.node].kind === 'queue' ? 120 : 70);
            (S.arrivals[d.node] ||= []).push(now);
            if (!d.queue.length) {
              if (d.hold && d.node === d.hold) { d.phase = d.hold === 'parking' ? 'parked' : 'held'; d.heldAt = now; }
              else if (d.finishAtEnd || d.node === 'catalogue' || d.node === 'cmsbookings') { d.phase = d.finishAtEnd ? 'finishing' : 'waiting'; d.until = now + (d.finishAtEnd ? 250 : 2500); }
            }
          }
        } else if (d.phase === 'dwell' && now >= d.until && d.queue.length) {
          const to = d.queue.shift(), edge = E[`${d.node}-${to}`];
          if (!edge) { d.node = to; continue; }
          d.edge = edge.id; d.to = to; d.phase = 'travel'; d.t0 = now; d.dur = HOP * Math.max(.75, Math.min(2.4, edge.len / 150));
        } else if ((d.phase === 'finishing' || d.phase === 'waiting') && now >= d.until) {
          d.done = true;
        } else if (d.phase === 'held' && now - d.heldAt > (N[d.node].ttl || 5000) + 8000) {
          // TTL expired and no further attempt arrived (consumer down?): show it going back to the queue.
          d.phase = 'dwell'; d.until = 0; d.queue.push('requeue', 'catalogue'); d.finishAtEnd = true; d.hold = null;
        }
      }
      for (const d of S.dots) if (d.done && d.el) { const el = d.el; el.classList.add('fade'); setTimeout(() => el.remove(), 480); d.el = null; }
      S.dots = S.dots.filter(d => !d.done);
    }
    function draw(now) {
      const stacks = {}, overflow = {};
      for (const d of S.dots) {
        if (!d.el) { d.el = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); dotLayer.appendChild(d.el); }
        d.el.setAttribute('class', 'fw-dot ' + d.state);
        let x = d.x, y = d.y;
        if (d.phase === 'travel') {
          const t = Math.min(1, (now - d.t0) / d.dur), pt = pathEl[d.edge].getPointAtLength(ease(t) * E[d.edge].len); x = pt.x; y = pt.y;
        } else {
          const n = N[d.node], idx = (stacks[d.node] = (stacks[d.node] ?? -1) + 1);
          if (idx >= HOLD_MAX) { overflow[d.node] = (overflow[d.node] || 0) + 1; d.el.style.display = 'none'; continue; }
          d.el.style.display = '';
          if (n.kind === 'queue') { x = n.x + 8 + (idx % 10) * 12.5; y = n.bottom + 9 + Math.floor(idx / 10) * 10; }
          else { x = n.right - 10 - (idx % 6) * 11; y = n.bottom + 9; }
        }
        d.x = x; d.y = y; d.el.setAttribute('cx', x); d.el.setAttribute('cy', y);
      }
      moreLayer.innerHTML = Object.entries(overflow).map(([id, c]) => `<text class="fw-more" x="${N[id].x + 8}" y="${N[id].bottom + 44}">+${c}</text>`).join('');
      for (const e of EDGES) pathEl[e.id].classList.toggle('hot', S.dots.some(d => d.edge === e.id && d.phase === 'travel'));
    }
    const loop = now => { step(now); draw(now); requestAnimationFrame(loop); };
    requestAnimationFrame(loop);

    // Keep held/parked dots honest against the broker's real depths.
    function reconcile(nodes) {
      for (const [node, depth] of [['retry5', nodes.retry5], ['retry15', nodes.retry15], ['retry45', nodes.retry45], ['parking', nodes.parking]]) {
        const held = S.dots.filter(d => d.node === node && (d.phase === 'held' || d.phase === 'parked'));
        for (const d of held.slice(depth)) {
          if (node === 'parking') { d.done = true; continue; }
          if (performance.now() - d.heldAt > 1500) { d.phase = 'dwell'; d.until = 0; d.queue.push('requeue', 'catalogue'); d.finishAtEnd = true; d.hold = null; }
        }
        // Parked messages from before the page loaded.
        if (node === 'parking') {
          for (let i = held.length; i < Math.min(depth, 60); i++) {
            S.dots.push({ eventId: 'parked-' + i, consumerKey: 'core', state: 'parked', node: 'parking', queue: [], phase: 'parked', heldAt: performance.now(), x: N.parking.cx, y: N.parking.cy, el: null, born: 0 });
          }
        }
      }
    }

    // ---- polling ----
    async function poll() {
      if (S.busy) return;
      S.busy = true;
      try {
        const st = await wire.state(S.cursor);
        const first = S.state === null;
        S.state = st; S.fault = st.fault;
        const rows = st.attempts || [];
        if (rows.length) {
          S.cursor = rows[rows.length - 1].id;
          S.rows = [...rows.slice().reverse(), ...S.rows].slice(0, 60);
          if (!first) {
            const sample = rows.length > 160 ? rows.filter((_, i) => i % Math.ceil(rows.length / 160) === 0) : rows;
            sample.forEach((row, i) => setTimeout(() => spawn(row), reduced ? 0 : Math.min(900, i * Math.max(12, 900 / sample.length))));
          }
        } else if (first) { S.cursor = st.cursor; }
        if (first && st.cursor > S.cursor) S.cursor = st.cursor;
        reconcile(st.nodes);
        sample();
        render();
      } catch (e) {
        root.querySelector('[data-broker]').className = 'fw-pill bad';
        root.querySelector('[data-broker]').innerHTML = '<i></i> console offline';
      } finally { S.busy = false; }
    }
    setInterval(poll, 1000); poll();

    function rate(id) { const now = performance.now(); S.arrivals[id] = S.arrivals[id].filter(t => now - t < 5000); return (S.arrivals[id].length / 5).toFixed(1); }
    function sample() {
      const st = S.state, n = st.nodes, h = S.history, push = (k, v) => { h[k].push(v); if (h[k].length > 40) h[k].shift(); };
      push('lag', st.kpi.lag_p95 || 0); push('thr', st.kpi.throughput); push('retry', n.retry5 + n.retry15 + n.retry45);
      push('parked', n.parking + n.cmsParking); push('drift', st.consistency.drifted); push('dup', st.kpi.duplicates);
    }

    // ---- rendering ----
    const spark = vals => {
      const w = 120, h = 22, max = Math.max(1, ...vals), step = w / Math.max(1, vals.length - 1);
      const pts = vals.map((v, i) => [i * step, h - 2 - (v / max) * (h - 4)]);
      const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
      return `<svg class="fw-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><path class="fill" d="${line} L${w} ${h} L0 ${h} Z"/><path d="${line}"/></svg>`;
    };
    const kpi = (cls, label, value, unit, detail, series) => `<div class="fw-kpi ${cls}"><div class="l">${label}</div><div class="v">${value}${unit ? `<small>${unit}</small>` : ''}</div><div class="d">${detail}</div>${spark(series)}</div>`;
    function render() {
      const st = S.state, n = st.nodes, k = st.kpi, h = S.history, inRetry = n.retry5 + n.retry15 + n.retry45, parked = n.parking + n.cmsParking;
      $('[data-kpis]').innerHTML = [
        kpi('', 'End-to-end lag · p95', k.lag_p95 === null ? '—' : fmt(k.lag_p95), k.lag_p95 === null ? '' : 'ms', 'CMS commit → Core projection', h.lag),
        kpi('', 'Throughput', fmt(k.throughput), '/min', `${fmt(k.processed)} projected today`, h.thr),
        kpi(inRetry ? 'warn' : '', 'In retry', fmt(inRetry), '', inRetry ? `5s ${n.retry5} · 15s ${n.retry15} · 45s ${n.retry45}` : 'retry tiers empty', h.retry),
        kpi(parked ? 'bad' : '', 'Parked', fmt(parked), '', parked ? 'waiting for unpark or replay' : 'parking lots empty', h.parked),
        kpi(st.consistency.drifted ? 'bad' : 'ok', 'Drift', fmt(st.consistency.drifted), '', st.consistency.drifted ? `of ${fmt(st.consistency.checked)} experiences` : `${fmt(st.consistency.checked)} experiences match`, h.drift),
        kpi(k.duplicates ? 'info' : '', 'Duplicates skipped', fmt(k.duplicates), '', 'inbox · last 24 h', h.dup),
      ].join('');

      const sw = $('[data-fault]'); sw.setAttribute('aria-checked', String(S.fault));
      const br = $('[data-broker]'); br.className = 'fw-pill ' + (st.broker ? 'ok' : 'bad'); br.innerHTML = `<i></i> ${st.broker ? 'broker connected' : 'broker unreachable'}`;

      const depth = { outbox: n.outbox, catalogue: n.catalogue, retry5: n.retry5, retry15: n.retry15, retry45: n.retry45, parking: n.parking, cmsbookings: n.cmsbookings, inbox: n.inbox, readmodel: '' };
      const metrics = {
        cms: `${fmt(n.bookingsIn)} bookings in`, outbox: `${rate('outbox')}/s · ${fmt(n.outboxTotal)} rows`, relay: `${rate('relay')}/s · confirmed`,
        exchange: `${rate('exchange')}/s · 2 queues`, catalogue: `${rate('catalogue')}/s · ${n.catalogueConsumers} cons`,
        consumer: S.fault ? 'throwing · 0 acks' : `${rate('consumer')}/s acked`, inbox: `${fmt(n.inbox)} ids · ${fmt(k.duplicates)} dup`,
        readmodel: `${fmt(n.readmodel)} rows · ${fmt(n.legacyInCore)} legacy`, traildesk: `${fmt(st.backfill.imported)} of ${fmt(st.backfill.total)}`,
        coreapi: `${rate('coreapi')}/s · ${n.coreOutbox} pending`, requeue: `${rate('requeue')}/s · 2 bind`,
        retry5: `${rate('retry5')}/s · ttl 5 s`, retry15: `${rate('retry15')}/s · ttl 15 s`, retry45: `${rate('retry45')}/s · ttl 45 s`,
        parking: n.parking ? 'awaiting operator' : 'empty', cmsconsumer: `${rate('cmsconsumer')}/s acked`, cmsbookings: `${rate('cmsbookings')}/s · ${n.cmsbookingsConsumers} cons`,
      };
      for (const node of NODES) {
        const el = topo.querySelector(`[data-node="${node.id}"]`), d = depth[node.id];
        let cls = '';
        if (node.id === 'consumer') cls = S.fault ? 'bad' : rate('consumer') > 0 ? 'ok' : '';
        else if (node.id === 'parking') cls = n.parking ? 'bad' : '';
        else if (node.id.startsWith('retry')) cls = n[node.id] ? 'warn' : '';
        else if (node.id === 'catalogue' || node.id === 'cmsbookings') cls = (node.id === 'catalogue' ? n.catalogueConsumers : n.cmsbookingsConsumers) === 0 ? 'warn' : rate(node.id) > 0 ? 'ok' : '';
        else if (node.id === 'outbox') cls = n.outbox > 50 ? 'warn' : rate('outbox') > 0 ? 'ok' : '';
        else cls = rate(node.id) > 0 ? 'ok' : '';
        el.className = `fw-node k-${node.kind}${cls ? ' st-' + cls : ''}${S.selected === node.id ? ' selected' : ''}`;
        el.querySelector('.d').textContent = d === undefined || d === '' ? '' : fmt(d);
        el.querySelector('.m').textContent = metrics[node.id] || '';
      }

      // consistency
      const c = st.consistency;
      $('[data-check-meta]').textContent = c.ran_at ? `last run ${c.ran_at} · ${c.duration_ms} ms` : 'not run yet';
      $('[data-check-big]').className = 'fw-big ' + (c.drifted ? 'bad' : 'ok');
      $('[data-check-big]').innerHTML = c.drifted
        ? `<b>${fmt(c.drifted)}</b><div><div class="t">experiences diverged</div><div class="s">sha1(id|version|status|price) differs between CMS and Core</div></div>`
        : `<b>${fmt(c.checked)}</b><div><div class="t">experiences in sync</div><div class="s">indexed fingerprints match on both sides</div></div>`;
      $('[data-check-list]').innerHTML = c.items.length ? c.items.map(i => `<a class="fw-row" href="${urls.timeline.replace('__ID__', i.id)}">
          <span class="bullet bad"></span><span class="grow">${esc(i.title)}</span>
          <span class="meta">${i.kind === 'missing' ? 'missing in Core' : i.kind === 'orphan' ? 'orphan' : `v${i.cms} ≠ v${i.core}`}</span></a>`).join('')
        : '<div class="fw-empty">Nothing to repair. Every published experience matches Core.</div>';

      // backfill
      const b = st.backfill, pct = b.total ? Math.round((b.imported + b.skipped) / b.total * 100) : 0;
      $('[data-backfill-stat]').innerHTML = `<span>${fmt(b.imported)} imported · ${fmt(b.skipped)} skipped</span><span class="mono">${pct}% of ${fmt(b.total)}</span>`;
      $('[data-backfill-bar]').style.width = pct + '%';

      // stream
      $('[data-stream]').innerHTML = S.rows.length ? S.rows.slice(0, 40).map(r => {
        const meta = { published: ['info', 'published'], processed: [r.replay ? 'info' : 'ok', r.replay ? 'replayed ok' : 'processed'], duplicate: ['dup', 'duplicate'], retry_scheduled: ['warn', 'retry ' + (r.tier || '')], parked: ['bad', 'parked'], unparked: ['info', 'unparked'], replayed: ['info', 'replay'] }[r.outcome] || ['', r.outcome];
        const cls = r.event.startsWith('legacy.') && r.outcome !== 'retry_scheduled' && r.outcome !== 'parked' ? 'legacy' : r.event.startsWith('bookings.') && r.outcome !== 'parked' ? 'booking' : meta[0];
        return `<a class="fw-row" href="${urls.event.replace('__ID__', r.event_id)}" title="${esc(r.error || r.consumer)}"><span class="t">${esc(r.at)}</span><span class="bullet ${cls}"></span><span class="grow ev">${esc(r.event)}</span><span class="meta">${esc(meta[1])}${r.duration_ms !== null && r.outcome === 'processed' ? ' · ' + r.duration_ms + ' ms' : ''}</span></a>`;
      }).join('') : '<div class="fw-empty">Waiting for the first message…</div>';

      if (S.selected) renderPanel();
    }

    // ---- slide-over ----
    const ROLE = {
      cms: 'Filament panel. Saves an aggregate and its outbox row in one MySQL transaction, and consumes bookings.* back into its own stats projection.',
      outbox: 'Append-only event log in the CMS schema. Never deleted: replays read from here.',
      relay: 'Claims unpublished rows with FOR UPDATE SKIP LOCKED, publishes persistent + mandatory, stamps published_at only after the broker confirms.',
      exchange: 'Topic exchange. Routing key = versioned event name, e.g. catalogue.experience.published.v2.',
      catalogue: 'Quorum queue bound to catalogue.# and legacy.#. DLX → fellwise.parking, x-delivery-limit 10.',
      consumer: 'Manual acks, prefetch 20. Inbox claim + projector in one transaction; on failure republishes to the next retry tier with a confirm, then acks.',
      inbox: 'INSERT IGNORE on (event_id, consumer). A second delivery of the same event is acked without side effects.',
      readmodel: 'Core\'s projection of the catalogue. Upserts are version-guarded so older or replayed events never overwrite newer state.',
      traildesk: 'Legacy PHP 5 platform. The backfill reads tbl_listing in id order through an anti-corruption layer and emits legacy.experience.imported.v1 through the same outbox.',
      coreapi: 'Bookings context. Publishes bookings.booking.confirmed.v1 and availability.changed.v1 from its own outbox.',
      requeue: 'Direct exchange. Expired retry messages come back only to the queue that owns them (x-dead-letter-routing-key).',
      retry5: 'TTL queue: messages wait 5 s, then dead-letter to fellwise.requeue → core.catalogue.',
      retry15: 'TTL queue: second attempt waits 15 s.', retry45: 'TTL queue: last attempt waits 45 s before parking.',
      parking: 'No TTL, no DLX: evidence is kept with x-last-error and x-retry-count until an operator unparks or replays.',
      cmsconsumer: 'Projects booking facts into the CMS (bookings count, places left).', cmsbookings: 'Quorum queue bound to bookings.#.',
    };
    function openPanel(id) { S.selected = id; root.querySelector('[data-panel]').classList.add('on'); renderPanel(); render(); }
    root.querySelector('[data-panel-close]').addEventListener('click', () => { S.selected = null; root.querySelector('[data-panel]').classList.remove('on'); render(); });
    function renderPanel() {
      const id = S.selected, node = N[id], st = S.state; if (!st) return;
      $('[data-panel-title]').innerHTML = `<span class="${node.mono ? 'mono' : ''}">${esc(node.full || node.title)}</span>`;
      const recent = S.rows.filter(r => routeFor(r)?.path.includes(id)).slice(0, 12);
      const parked = id === 'parking' ? S.rows.filter(r => r.outcome === 'parked').slice(0, 20) : [];
      $('[data-panel-body]').innerHTML = `
        <p style="margin:0;color:var(--ink-2)">${esc(ROLE[id] || '')}</p>
        <dl class="fw-kv">
          <dt>Rate (5 s)</dt><dd>${rate(id)}/s</dd>
          ${node.kind === 'queue' ? `<dt>Depth</dt><dd>${fmt(st.nodes[id] ?? 0)}</dd>` : ''}
          ${node.ttl ? `<dt>x-message-ttl</dt><dd>${node.ttl} ms</dd><dt>DLX</dt><dd>fellwise.requeue → core.catalogue</dd>` : ''}
          ${id === 'consumer' ? `<dt>Fault injection</dt><dd>${S.fault ? 'ON: handlers throw' : 'off'}</dd>` : ''}
          ${id === 'outbox' ? `<dt>Unpublished</dt><dd>${fmt(st.nodes.outbox)}</dd><dt>Total rows</dt><dd>${fmt(st.nodes.outboxTotal)}</dd>` : ''}
        </dl>
        ${id === 'parking' ? `<div><h4>Parked messages</h4><div class="fw-list">${parked.length ? parked.map(r => `<div class="fw-row" style="flex-wrap:wrap">
            <a class="grow ev" href="${urls.timeline.replace('__ID__', r.aggregate_id)}">${esc(r.event)}</a>
            <button class="fw-btn" type="button" data-unpark="${esc(r.aggregate_id)}">Unpark</button>
            <span class="fw-err" title="${esc(r.error)}">${esc(r.error || '')}</span></div>`).join('') : '<div class="fw-empty">Nothing parked in this session.</div>'}</div></div>` : ''}
        <div><h4>Recent traffic</h4><div class="fw-list">${recent.length ? recent.map(r => `<a class="fw-row" href="${urls.event.replace('__ID__', r.event_id)}"><span class="t">${esc(r.at)}</span><span class="grow ev">${esc(r.event)}</span><span class="meta">${esc(r.outcome.replace('_', ' '))}</span></a>`).join('') : '<div class="fw-empty">No traffic through this node yet.</div>'}</div></div>`;
      $('[data-panel-body]').querySelectorAll('[data-unpark]').forEach(b => b.addEventListener('click', () => wire.unparkAggregate(b.dataset.unpark)));
    }

    // ---- controls ----
    $('[data-fault]').addEventListener('click', async () => { S.fault = await wire.toggleFault(); render(); });
    $('[data-run-check]').addEventListener('click', () => wire.runConsistencyCheck());
    $('[data-backfill]').addEventListener('click', () => wire.mountAction('backfill'));
  }

  window.fwMissionControl = boot;
})();
