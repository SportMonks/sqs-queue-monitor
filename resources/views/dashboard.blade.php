<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Queue Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
</head>
<body class="bg-gray-950 text-gray-100 font-mono text-sm">

<div id="app" class="min-h-screen p-6">
    <div class="max-w-7xl mx-auto">

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-bold text-white">Queue Monitor</h1>
            <div id="connection-switcher" class="flex gap-2"></div>
        </div>

        <div id="status-bar" class="mb-4 text-xs text-gray-400"></div>

        <div id="breadcrumb" class="mb-4 text-xs text-gray-500"></div>

        <div id="table-container" class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="w-full min-w-[640px]">
                <thead id="table-head" class="bg-gray-900 text-gray-400 uppercase text-xs"></thead>
                <tbody id="table-body" class="divide-y divide-gray-800"></tbody>
            </table>
        </div>

        <div id="empty-state" class="hidden text-center py-12 text-gray-500">
            Waiting for jobs…
        </div>

    </div>
</div>

<script>
(function () {
    const CONNECTIONS_CONFIG = @json($connections);
    const SWEEP_INTERVAL_MS = 60_000;
    const PROCESSED_TTL_MS = 30 * 60_000;
    const STALE_PENDING_TTL_MS = 120 * 60_000;
    const STALE_DROP_TTL_MS = STALE_PENDING_TTL_MS + 60_000;
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const state = {
        connections: {},
        active: null,
    };

    CONNECTIONS_CONFIG.forEach(conn => {
        const pusher = new Pusher(conn.key, {
            wsHost: conn.host,
            wsPort: conn.port,
            wssPort: conn.port,
            forceTLS: conn.port === 443,
            enabledTransports: ['ws', 'wss'],
            cluster: 'mt1',
            authEndpoint: conn.authEndpoint + '?key=' + encodeURIComponent(conn.key),
            auth: {
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    ...(conn.authHeaders ?? {}),
                },
            },
        });

        const connection = { pusher, jobs: new Map(), status: 'connecting' };
        state.connections[conn.name] = connection;

        pusher.connection.bind('connected',    () => { connection.status = 'connected';    render(); });
        pusher.connection.bind('disconnected', () => { connection.status = 'disconnected'; render(); });
        pusher.connection.bind('failed',       () => { connection.status = 'failed';       render(); });

        const channel = pusher.subscribe('private-' + conn.channel);
        channel.bind('pusher:subscription_error', () => { connection.status = 'auth-error'; render(); });
        channel.bind('job.dispatched', data => handleDispatched(conn.name, data));
        channel.bind('job.updated',    data => handleUpdated(conn.name, data));
    });

    if (CONNECTIONS_CONFIG.length > 0) {
        state.active = CONNECTIONS_CONFIG[0].name;
    }

    function handleDispatched(connName, data) {
        const jobs = state.connections[connName]?.jobs;
        if (! jobs) { return; }
        const existing = jobs.get(data.tracker_id);
        if (existing) {
            jobs.set(data.tracker_id, { ...existing,
                queue_name: data.queue_name, group_name: data.group_name,
                display_name: data.display_name, dispatched_at: data.dispatched_at,
            });
        } else {
            jobs.set(data.tracker_id, {
                tracker_id: data.tracker_id,
                queue_name: data.queue_name,
                group_name: data.group_name,
                display_name: data.display_name,
                dispatched_at: data.dispatched_at,
                delayed_until: data.delayed_until ?? null,
                status: 'pending',
                processed_at: null,
                processing_time_ms: null,
                memory_bytes: null,
                cpu_percent: null,
            });
        }
        if (connName === state.active) { render(); }
    }

    function handleUpdated(connName, data) {
        const jobs = state.connections[connName]?.jobs;
        if (! jobs) { return; }
        const existing = jobs.get(data.tracker_id);
        if (existing) {
            jobs.set(data.tracker_id, { ...existing,
                status: data.status,
                processed_at: data.processed_at,
                processing_time_ms: data.processing_time_ms,
                memory_bytes: data.memory_bytes ?? null,
                cpu_percent: data.cpu_percent ?? null,
            });
        } else {
            jobs.set(data.tracker_id, {
                tracker_id: data.tracker_id,
                queue_name: data.queue_name,
                group_name: data.group_name,
                display_name: data.display_name,
                dispatched_at: data.dispatched_at,
                delayed_until: null,
                status: data.status,
                processed_at: data.processed_at,
                processing_time_ms: data.processing_time_ms,
                memory_bytes: data.memory_bytes ?? null,
                cpu_percent: data.cpu_percent ?? null,
            });
        }
        if (connName === state.active) { render(); }
    }

    function sweep() {
        const now = Date.now();
        Object.values(state.connections).forEach(conn => {
            conn.jobs.forEach((job, id) => {
                const dispatched = job.dispatched_at ? new Date(job.dispatched_at).getTime() : null;
                const processed  = job.processed_at  ? new Date(job.processed_at).getTime()  : null;
                if ((job.status === 'processed' || job.status === 'failed') && processed && now - processed > PROCESSED_TTL_MS) {
                    conn.jobs.delete(id); return;
                }
                if (job.status === 'stale' && dispatched && now - dispatched > STALE_DROP_TTL_MS) {
                    conn.jobs.delete(id); return;
                }
                if (job.status === 'pending' && dispatched && now - dispatched > STALE_PENDING_TTL_MS) {
                    conn.jobs.set(id, { ...job, status: 'stale' });
                }
                if (! dispatched && processed && now - processed > PROCESSED_TTL_MS) {
                    conn.jobs.delete(id);
                }
            });
        });
        render();
    }
    setInterval(sweep, SWEEP_INTERVAL_MS);

    function parseHash() {
        const params = {};
        location.hash.slice(1).split('&').filter(Boolean).forEach(p => {
            const [k, v] = p.split('=');
            params[decodeURIComponent(k)] = decodeURIComponent(v ?? '');
        });
        return params;
    }

    window.addEventListener('hashchange', render);

    const sort = { col: null, dir: null };
    let lastViewKey = null;

    const SORT_ICON = {
        none: '<svg class="inline-block ml-1 align-middle opacity-30" width="8" height="11" viewBox="0 0 8 11" fill="currentColor"><path d="M4 0L8 5H0Z"/><path d="M4 11L0 6H8Z"/></svg>',
        desc: '<svg class="inline-block ml-1 align-middle text-blue-400" width="8" height="11" viewBox="0 0 8 11" fill="currentColor"><path d="M4 11L0 5H8Z"/></svg>',
        asc:  '<svg class="inline-block ml-1 align-middle text-blue-400" width="8" height="11" viewBox="0 0 8 11" fill="currentColor"><path d="M4 0L8 6H0Z"/></svg>',
    };

    window.cycleSort = function (col) {
        if (sort.col !== col)       { sort.col = col; sort.dir = 'desc'; }
        else if (sort.dir === 'desc') { sort.dir = 'asc'; }
        else                          { sort.col = null; sort.dir = null; }
        render();
    };

    function jobs() {
        return Array.from(state.connections[state.active]?.jobs.values() ?? []);
    }

    function fmtMs(ms) {
        if (ms === null || ms === undefined) { return '—'; }
        return ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : ms + 'ms';
    }

    function fmtDt(iso) {
        if (! iso) { return '—'; }
        return new Date(iso).toLocaleString();
    }

    function avgMs(arr) {
        const vals = arr.map(j => j.processing_time_ms).filter(v => v !== null);
        if (! vals.length) { return null; }
        return Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);
    }

    function avgWaitMs(arr) {
        const vals = arr
            .filter(j => j.status === 'processed' && ! j.delayed_until && j.dispatched_at && j.processed_at && j.processing_time_ms !== null)
            .map(j => new Date(j.processed_at).getTime() - j.processing_time_ms - new Date(j.dispatched_at).getTime());
        if (! vals.length) { return null; }
        return Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);
    }

    function fmtBytes(bytes) {
        if (bytes === null || bytes === undefined) { return '—'; }
        const abs = Math.abs(bytes);
        const sign = bytes < 0 ? '-' : '';
        if (abs < 1024) { return sign + abs + ' B'; }
        if (abs < 1024 * 1024) { return sign + (abs / 1024).toFixed(1) + ' KB'; }
        if (abs < 1024 * 1024 * 1024) { return sign + (abs / (1024 * 1024)).toFixed(1) + ' MB'; }
        return sign + (abs / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    function avgMemoryBytes(arr) {
        const vals = arr.map(j => j.memory_bytes).filter(v => v !== null);
        if (! vals.length) { return null; }
        return Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);
    }

    function fmtPercent(pct) {
        if (pct === null || pct === undefined) { return '—'; }
        return pct + '%';
    }

    function avgCpuPercent(arr) {
        const vals = arr.map(j => j.cpu_percent).filter(v => v !== null);
        if (! vals.length) { return null; }
        return Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);
    }

    function isDelayed(j) {
        return j.delayed_until !== null && new Date(j.delayed_until).getTime() > Date.now();
    }

    function unprocessedCount(arr) {
        return arr.filter(j => (j.status === 'pending' || j.status === 'stale') && ! isDelayed(j)).length;
    }

    function delayedCount(arr) {
        return arr.filter(j => j.status === 'pending' && isDelayed(j)).length;
    }

    function failedCount(arr) {
        return arr.filter(j => j.status === 'failed').length;
    }

    function shortName(name) {
        if (! name) { return name; }
        return name.split(/[\\\/]/).pop() ?? name;
    }

    const COPY_ICON = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`;

    window.copyJobName = function (btn, e) {
        e.stopPropagation();
        const text = btn.dataset.copy;

        const showTip = () => {
            const r   = btn.getBoundingClientRect();
            const tip = document.createElement('div');
            tip.textContent = 'Copied to clipboard!';
            tip.style.cssText = [
                'position:fixed',
                'left:' + (r.left + r.width / 2) + 'px',
                'top:' + (r.top - 8) + 'px',
                'transform:translate(-50%,-100%)',
                'background:#111827',
                'color:#4ade80',
                'border:1px solid #374151',
                'padding:3px 10px',
                'border-radius:6px',
                'font-size:11px',
                'font-family:monospace',
                'white-space:nowrap',
                'pointer-events:none',
                'z-index:9999',
                'transition:opacity 0.3s',
            ].join(';');
            document.body.appendChild(tip);
            setTimeout(() => { tip.style.opacity = '0'; }, 900);
            setTimeout(() => { tip.remove(); }, 1200);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(showTip);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            showTip();
        }
    };

    function copyIcon(name) {
        return `<button class="inline-flex items-center ml-1.5 align-middle text-gray-600 hover:text-gray-200" style="cursor:pointer" data-copy="${name}" onclick="copyJobName(this,event)" title="Copy class name">${COPY_ICON}</button>`;
    }

    function statusBadge(status) {
        const map = {
            pending: 'text-yellow-400', processed: 'text-green-400',
            failed: 'text-red-400', stale: 'text-gray-500',
        };
        return `<span class="${map[status] ?? ''}">${status}</span>`;
    }

    function buildHash(params) {
        return Object.entries(params)
            .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
            .join('&');
    }

    function navLink(label, params) {
        return `<a href="#${buildHash(params)}" class="text-blue-400 hover:underline">${label}</a>`;
    }

    function setThead(cols) {
        document.getElementById('table-head').innerHTML =
            '<tr>' + cols.map((c, i) =>
                `<th class="px-2 py-2 sm:px-4 sm:py-3 text-left cursor-pointer select-none hover:text-gray-200" onclick="cycleSort(${i})">${c}${sort.col === i ? SORT_ICON[sort.dir] : SORT_ICON.none}</th>`
            ).join('') + '</tr>';
    }

    function setTbody(rows) {
        let sorted = rows;
        if (sort.col !== null) {
            sorted = [...rows].sort((a, b) => {
                let va = a.values?.[sort.col] ?? null;
                let vb = b.values?.[sort.col] ?? null;
                if (va === null) { va = typeof vb === 'number' ? -Infinity : ''; }
                if (vb === null) { vb = typeof va === 'number' ? -Infinity : ''; }
                const cmp = (typeof va === 'number' && typeof vb === 'number')
                    ? va - vb
                    : String(va).localeCompare(String(vb));
                return sort.dir === 'desc' ? -cmp : cmp;
            });
        }
        const tbody = document.getElementById('table-body');
        tbody.innerHTML = sorted.map(row => {
            const cells = row.cells;
            const href  = row.href ?? null;
            const tr    = href
                ? `<tr class="hover:bg-gray-900 cursor-pointer" onclick="location.hash='${href}'">`
                : `<tr class="hover:bg-gray-900">`;
            return tr + cells.map(c => `<td class="px-2 py-2 sm:px-4 sm:py-3 whitespace-nowrap">${c}</td>`).join('') + '</tr>';
        }).join('');
        const empty = document.getElementById('empty-state');
        const table = document.getElementById('table-container');
        if (rows.length === 0) { table.classList.add('hidden'); empty.classList.remove('hidden'); }
        else { table.classList.remove('hidden'); empty.classList.add('hidden'); }
    }

    let renderPending = false;

    document.addEventListener('selectionchange', () => {
        if (renderPending && ! window.getSelection()?.toString()) {
            renderPending = false;
            render();
        }
    });

    function render() {
        if (window.getSelection()?.toString()) { renderPending = true; return; }
        renderPending = false;
        const hash = parseHash();
        if (hash.connection && state.connections[hash.connection]) {
            state.active = hash.connection;
        }

        const viewKey = hash.job ? 'detail' : hash.group !== undefined ? 'group' : hash.queue ? 'queue' : 'all';
        if (viewKey !== lastViewKey) { sort.col = null; sort.dir = null; lastViewKey = viewKey; }

        renderSwitcher();
        renderStatusBar();
        renderBreadcrumb(hash);

        if (hash.job && hash.queue && hash.group !== undefined) {
            renderJobDetail(hash);
        } else if (hash.group !== undefined && hash.queue) {
            renderGroupView(hash);
        } else if (hash.queue) {
            renderQueueView(hash);
        } else {
            renderAllQueues();
        }
    }

    function renderSwitcher() {
        const el = document.getElementById('connection-switcher');
        el.innerHTML = Object.entries(state.connections).map(([name, conn]) => {
            const dot = conn.status === 'connected'
                ? 'bg-green-500' : conn.status === 'disconnected' || conn.status === 'failed' || conn.status === 'auth-error'
                ? 'bg-red-500' : 'bg-yellow-500';
            const active = name === state.active ? 'ring-1 ring-blue-400' : '';
            const hash = parseHash();
            return `<a href="#${buildHash({ ...hash, connection: name })}"
                        class="flex items-center gap-1 px-3 py-1 rounded bg-gray-800 ${active} hover:bg-gray-700">
                        <span class="w-2 h-2 rounded-full ${dot}"></span>${name}
                    </a>`;
        }).join('');
    }

    function renderStatusBar() {
        const conn = state.connections[state.active];
        if (! conn) { return; }
        document.getElementById('status-bar').textContent =
            `${state.active} · ${conn.status} · ${conn.jobs.size} jobs in memory`;
    }

    function renderBreadcrumb(hash) {
        const parts = [];
        const base = { connection: state.active };
        parts.push(navLink('All Queues', base));
        if (hash.queue) {
            parts.push(navLink(hash.queue, { ...base, queue: hash.queue }));
        }
        if (hash.group !== undefined) {
            parts.push(navLink(hash.group || '(none)', { ...base, queue: hash.queue, group: hash.group }));
        }
        if (hash.job) {
            parts.push(`<span class="text-white" title="${hash.job}">${shortName(hash.job)}</span>`);
        }
        document.getElementById('breadcrumb').innerHTML = parts.join(' <span class="text-gray-600">›</span> ');
    }

    function renderAllQueues() {
        const all = jobs();
        const queueMap = {};
        all.forEach(j => {
            const q = j.queue_name ?? '(unknown)';
            if (! queueMap[q]) { queueMap[q] = []; }
            queueMap[q].push(j);
        });
        setThead(['Queue', 'Tracked', 'Failed', 'Pending', 'Delayed', 'Avg processing time', 'Avg wait time', 'Avg memory', 'Avg CPU']);
        setTbody(Object.entries(queueMap).map(([q, qjobs]) => {
            const processed = qjobs.filter(j => j.status === 'processed');
            return {
                href:   buildHash({ connection: state.active, queue: q }),
                values: [q, qjobs.length, failedCount(qjobs), unprocessedCount(qjobs), delayedCount(qjobs), avgMs(processed), avgWaitMs(qjobs), avgMemoryBytes(processed), avgCpuPercent(processed)],
                cells:  [q, qjobs.length, failedCount(qjobs), unprocessedCount(qjobs), delayedCount(qjobs), fmtMs(avgMs(processed)), fmtMs(avgWaitMs(qjobs)), fmtBytes(avgMemoryBytes(processed)), fmtPercent(avgCpuPercent(processed))],
            };
        }));
    }

    function renderQueueView(hash) {
        const all = jobs().filter(j => j.queue_name === hash.queue);
        const groupMap = {};
        all.forEach(j => {
            const g = j.group_name ?? '(none)';
            if (! groupMap[g]) { groupMap[g] = []; }
            groupMap[g].push(j);
        });
        setThead(['Group', 'Tracked', 'Failed', 'Pending', 'Delayed', 'Avg processing time', 'Avg wait time', 'Avg memory', 'Avg CPU']);
        setTbody(Object.entries(groupMap).map(([g, gjobs]) => {
            const processed = gjobs.filter(j => j.status === 'processed');
            return {
                href:   buildHash({ connection: state.active, queue: hash.queue, group: g === '(none)' ? '' : g }),
                values: [g, gjobs.length, failedCount(gjobs), unprocessedCount(gjobs), delayedCount(gjobs), avgMs(processed), avgWaitMs(gjobs), avgMemoryBytes(processed), avgCpuPercent(processed)],
                cells:  [`<span title="${g}">${g}</span>${copyIcon(g)}`, gjobs.length, failedCount(gjobs), unprocessedCount(gjobs), delayedCount(gjobs), fmtMs(avgMs(processed)), fmtMs(avgWaitMs(gjobs)), fmtBytes(avgMemoryBytes(processed)), fmtPercent(avgCpuPercent(processed))],
            };
        }));
    }

    function renderGroupView(hash) {
        const groupFilter = hash.group === '' ? null : hash.group;
        const all = jobs().filter(j =>
            j.queue_name === hash.queue && (j.group_name ?? null) === groupFilter
        );
        const jobMap = {};
        all.forEach(j => {
            const n = j.display_name ?? '(unknown)';
            if (! jobMap[n]) { jobMap[n] = []; }
            jobMap[n].push(j);
        });
        setThead(['Job', 'Tracked', 'Failed', 'Pending', 'Delayed', 'Avg processing time', 'Avg wait time', 'Avg memory', 'Avg CPU']);
        setTbody(Object.entries(jobMap).map(([n, njobs]) => {
            const processed = njobs.filter(j => j.status === 'processed');
            return {
                href:   buildHash({ connection: state.active, queue: hash.queue, group: hash.group, job: n }),
                values: [n, njobs.length, failedCount(njobs), unprocessedCount(njobs), delayedCount(njobs), avgMs(processed), avgWaitMs(njobs), avgMemoryBytes(processed), avgCpuPercent(processed)],
                cells:  [`<span title="${n}">${shortName(n)}</span>${copyIcon(n)}`, njobs.length, failedCount(njobs), unprocessedCount(njobs), delayedCount(njobs), fmtMs(avgMs(processed)), fmtMs(avgWaitMs(njobs)), fmtBytes(avgMemoryBytes(processed)), fmtPercent(avgCpuPercent(processed))],
            };
        }));
    }

    function renderJobDetail(hash) {
        const groupFilter = hash.group === '' ? null : hash.group;
        const all = jobs()
            .filter(j =>
                j.queue_name === hash.queue &&
                (j.group_name ?? null) === groupFilter &&
                j.display_name === hash.job
            )
            .sort((a, b) => (b.dispatched_at ?? '').localeCompare(a.dispatched_at ?? ''));
        setThead(['Job', 'Dispatched at', 'Processed at', 'Processing time', 'Wait time', 'Memory', 'CPU', 'Status']);
        setTbody(all.map(j => {
            const waitMs = j.dispatched_at && j.processed_at && j.processing_time_ms !== null
                ? new Date(j.processed_at).getTime() - j.processing_time_ms - new Date(j.dispatched_at).getTime()
                : null;
            return {
                values: [j.display_name ?? '', new Date(j.dispatched_at ?? 0).getTime(), new Date(j.processed_at ?? 0).getTime(), j.processing_time_ms, waitMs, j.memory_bytes, j.cpu_percent, j.status],
                cells:  [`<span title="${j.display_name ?? ''}">${shortName(j.display_name) ?? '—'}</span>${copyIcon(j.display_name ?? '')}`, fmtDt(j.dispatched_at), fmtDt(j.processed_at), fmtMs(j.processing_time_ms), fmtMs(waitMs), fmtBytes(j.memory_bytes), fmtPercent(j.cpu_percent), statusBadge(j.status)],
            };
        }));
    }

    render();
})();
</script>
</body>
</html>
