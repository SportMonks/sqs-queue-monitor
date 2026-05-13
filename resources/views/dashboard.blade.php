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
            <table class="w-full">
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
    const CHANNEL = @json($channel);
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
            authEndpoint: conn.authEndpoint,
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

        const channel = pusher.subscribe('private-' + CHANNEL);
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
                status: 'pending',
                processed_at: null,
                processing_time_ms: null,
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
            });
        } else {
            jobs.set(data.tracker_id, {
                tracker_id: data.tracker_id,
                queue_name: data.queue_name,
                group_name: data.group_name,
                display_name: data.display_name,
                dispatched_at: data.dispatched_at,
                status: data.status,
                processed_at: data.processed_at,
                processing_time_ms: data.processing_time_ms,
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

    function oldestPending(arr) {
        const pending = arr.filter(j => j.status === 'pending' && j.dispatched_at);
        if (! pending.length) { return null; }
        return pending.reduce((a, b) => a.dispatched_at < b.dispatched_at ? a : b).dispatched_at;
    }

    function latestDispatched(arr) {
        const withDate = arr.filter(j => j.dispatched_at);
        if (! withDate.length) { return null; }
        return withDate.reduce((a, b) => a.dispatched_at > b.dispatched_at ? a : b).dispatched_at;
    }

    function statusBadge(status) {
        const map = {
            pending: 'text-yellow-400', processed: 'text-green-400',
            failed: 'text-red-400', stale: 'text-gray-500',
        };
        return `<span class="${map[status] ?? ''}">${status}</span>`;
    }

    function navLink(label, params) {
        const href = '#' + Object.entries(params)
            .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
            .join('&');
        return `<a href="${href}" class="text-blue-400 hover:underline">${label}</a>`;
    }

    function setThead(cols) {
        document.getElementById('table-head').innerHTML =
            '<tr>' + cols.map(c => `<th class="px-4 py-3 text-left">${c}</th>`).join('') + '</tr>';
    }

    function setTbody(rows) {
        const tbody = document.getElementById('table-body');
        tbody.innerHTML = rows.map(cells =>
            '<tr class="hover:bg-gray-900">' + cells.map(c => `<td class="px-4 py-3">${c}</td>`).join('') + '</tr>'
        ).join('');
        const empty = document.getElementById('empty-state');
        const table = document.getElementById('table-container');
        if (rows.length === 0) { table.classList.add('hidden'); empty.classList.remove('hidden'); }
        else { table.classList.remove('hidden'); empty.classList.add('hidden'); }
    }

    function render() {
        renderSwitcher();
        renderStatusBar();

        const hash = parseHash();
        if (hash.connection && state.connections[hash.connection]) {
            state.active = hash.connection;
        }

        renderBreadcrumb(hash);

        if (hash.job && hash.queue && hash.group !== undefined) {
            renderJobDetail(hash);
        } else if (hash.group !== undefined && hash.queue) {
            renderGroupView(hash);
        } else if (hash.queue) {
            renderQueueView(hash);
        } else {
            renderAllQueues(hash);
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
            return `<a href="#${new URLSearchParams({ ...hash, connection: name }).toString()}"
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
            parts.push(`<span class="text-white">${hash.job}</span>`);
        }
        document.getElementById('breadcrumb').innerHTML = parts.join(' <span class="text-gray-600">›</span> ');
    }

    function renderAllQueues(hash) {
        const all = jobs();
        const queueMap = {};
        all.forEach(j => {
            const q = j.queue_name ?? '(unknown)';
            if (! queueMap[q]) { queueMap[q] = []; }
            queueMap[q].push(j);
        });
        setThead(['Queue', 'Jobs tracked', 'Oldest unprocessed', 'Latest dispatched', 'Avg processing time', '']);
        setTbody(Object.entries(queueMap).map(([q, qjobs]) => [
            q,
            qjobs.length,
            fmtDt(oldestPending(qjobs)),
            fmtDt(latestDispatched(qjobs)),
            fmtMs(avgMs(qjobs.filter(j => j.status === 'processed'))),
            navLink('View →', { connection: state.active, queue: q }),
        ]));
    }

    function renderQueueView(hash) {
        const all = jobs().filter(j => j.queue_name === hash.queue);
        const groupMap = {};
        all.forEach(j => {
            const g = j.group_name ?? '(none)';
            if (! groupMap[g]) { groupMap[g] = []; }
            groupMap[g].push(j);
        });
        setThead(['Group', 'Jobs tracked', 'Oldest unprocessed', 'Latest dispatched', 'Avg processing time', '']);
        setTbody(Object.entries(groupMap).map(([g, gjobs]) => [
            g,
            gjobs.length,
            fmtDt(oldestPending(gjobs)),
            fmtDt(latestDispatched(gjobs)),
            fmtMs(avgMs(gjobs.filter(j => j.status === 'processed'))),
            navLink('View →', { connection: state.active, queue: hash.queue, group: g === '(none)' ? '' : g }),
        ]));
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
        setThead(['Job', 'Amount tracked', 'Oldest unprocessed', 'Latest dispatched', 'Avg processing time', '']);
        setTbody(Object.entries(jobMap).map(([n, njobs]) => [
            n,
            njobs.length,
            fmtDt(oldestPending(njobs)),
            fmtDt(latestDispatched(njobs)),
            fmtMs(avgMs(njobs.filter(j => j.status === 'processed'))),
            navLink('View →', { connection: state.active, queue: hash.queue, group: hash.group, job: n }),
        ]));
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
        setThead(['Job', 'Dispatched at', 'Processed at', 'Processing time', 'Status']);
        setTbody(all.map(j => [
            j.display_name ?? '—',
            fmtDt(j.dispatched_at),
            fmtDt(j.processed_at),
            fmtMs(j.processing_time_ms),
            statusBadge(j.status),
        ]));
    }

    render();
})();
</script>
</body>
</html>
