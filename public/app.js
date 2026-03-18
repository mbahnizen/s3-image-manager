let currentWorkspaceId = 1;
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
let csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
let workspaces = [];
let pendingQueue = [];
let pendingSeq = 0;
let activeUploads = 0;
let activeJobMap = new Map();
const thresholdMeta = document.querySelector('meta[name="workspace-sync-threshold"]');
const workspaceSyncThreshold = thresholdMeta ? parseInt(thresholdMeta.getAttribute('content'), 10) : 20;
const lastWorkspaceKey = 'nizen_last_workspace_id';
const seenTipKey = 'nizen_seen_workspace_tip';
const loggedInKey = 'nizen_has_logged_in';
const themeKey = 'nizen_theme';
const tabKey = 'nizen_active_tab';

function setHidden(el, hidden) {
    if (!el) return;
    el.classList.toggle('is-hidden', hidden);
}

function setProgressFill(percent) {
    const fill = document.getElementById('progressFill');
    if (!fill) return;
    const clamped = Math.max(0, Math.min(100, Math.round(percent / 5) * 5));
    const nextClass = `progress-p${clamped}`;
    const prevClass = fill.dataset.progressClass;
    if (prevClass) fill.classList.remove(prevClass);
    fill.classList.add(nextClass);
    fill.dataset.progressClass = nextClass;
}

function showLogin() {
    if (!document.getElementById('loginOverlay')) {
        location.reload();
    }
}

function normalizeBaseName(name) {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function toTitleCase(name) {
    return name.replace(/\b\w/g, (c) => c.toUpperCase());
}

function generateAlt(baseName) {
    return baseName.replace(/-/g, ' ').toLowerCase();
}

function generateCaption(baseName, workspaceTitle) {
    const cleanName = toTitleCase(baseName.replace(/-/g, ' '));
    if (workspaceTitle) {
        return `Tampilan ${cleanName} pada artikel ${workspaceTitle}.`;
    }
    return `${cleanName}.`;
}

function showToast(message, type = 'info', duration = 2200) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    pulseIsland();
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 200);
    }, duration);
}

function setIslandStatus(text, expand = false) {
    const status = document.getElementById('islandStatusLabel');
    const island = document.querySelector('.dynamic-island');
    if (status) status.textContent = text;
    if (island) island.classList.toggle('expanded', !!expand);
}

function pulseIsland() {
    const island = document.querySelector('.dynamic-island');
    if (!island) return;
    island.classList.remove('pulse');
    void island.offsetWidth;
    island.classList.add('pulse');
}

function updateIslandActivity() {
    const pending = pendingQueue.length;
    const inProgress = activeUploads;
    const islandProgress = document.getElementById('islandProgressLabel');
    const islandQueue = document.getElementById('islandQueueCount');
    if (islandQueue) islandQueue.textContent = `Queue: ${pending}`;
    if (islandProgress) islandProgress.textContent = `In Progress: ${inProgress}`;
    if (inProgress > 0) {
        setIslandStatus(`Uploading ${inProgress}/${inProgress + pending}`, true);
    } else if (pending > 0) {
        setIslandStatus('Ready', true);
    } else {
        setIslandStatus('Idle', false);
    }
}

function showProgress(title, desc, percent = 10) {
    const overlay = document.getElementById('progressOverlay');
    const titleEl = document.getElementById('progressTitle');
    const descEl = document.getElementById('progressDesc');
    if (!overlay || !titleEl || !descEl) return;
    titleEl.textContent = title;
    descEl.textContent = desc;
    setProgressFill(percent);
    overlay.classList.add('is-visible-flex');
}

function updateProgress(desc, percent) {
    const descEl = document.getElementById('progressDesc');
    if (descEl) descEl.textContent = desc;
    setProgressFill(percent);
}

function hideProgress() {
    const overlay = document.getElementById('progressOverlay');
    if (overlay) overlay.classList.remove('is-visible-flex');
}

async function safeFetch(url, options = {}) {
    const defaultHeaders = {
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json'
    };
    options.headers = { ...defaultHeaders, ...options.headers };

    try {
        const res = await fetch(url, options);
        if (res.status === 401) {
            showLogin();
            throw new Error('Session expired');
        }
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            const msg = data.error || `Server error: ${res.status}`;
            if (res.status === 403) showToast('Forbidden: ' + msg, 'error');
            else if (res.status >= 500) showToast('Server Error: ' + msg, 'error');
            throw new Error(msg);
        }
        return await res.json();
    } catch (e) {
        console.error('Fetch error:', e);
        throw e;
    }
}

async function waitForWorkspaceJob(jobId, title, onDone) {
    showProgress(title, 'Menunggu proses...', 10);
    let done = false;

    const poll = async () => {
        if (done) return;
        try {
            const data = await safeFetch(`../api/workspace_job_status.php?id=${jobId}`);
            if (data.status === 'completed') {
                updateProgress('Selesai', 100);
                done = true;
                setTimeout(() => hideProgress(), 300);
                showToast('Proses workspace selesai', 'success');
                if (onDone) onDone();
                return;
            }
            if (data.status === 'failed') {
                done = true;
                hideProgress();
                showToast('Proses workspace gagal: ' + (data.error || 'Unknown error'), 'error');
                return;
            }
            const total = data.total || 0;
            const progress = data.progress || 0;
            const percent = total > 0 ? Math.min(95, Math.round((progress / total) * 90) + 5) : 20;
            updateProgress(`Progress: ${progress}/${total}`, percent);
        } catch (e) {
            done = true;
            hideProgress();
            showToast('Gagal memantau proses: ' + e.message, 'error');
        }
    };

    await poll();
    const interval = setInterval(() => {
        if (done) {
            clearInterval(interval);
            return;
        }
        poll();
    }, 2000);
}

async function performLogin() {
    const password = document.getElementById('adminPassword').value;
    const res = await fetch('../api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ password })
    });
    let data = {};
    const text = await res.text();
    try {
        data = JSON.parse(text);
    } catch (e) {
        data = { success: false, error: 'Invalid server response: ' + text };
    }
    if (data.success) {
        csrfToken = data.csrf_token;
        document.getElementById('loginOverlay').remove();
        localStorage.setItem(loggedInKey, '1');
        loadWorkspaces();
    } else {
        document.getElementById('loginError').textContent = data.error || 'Login failed';
    }
}

function switchTab(name) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.tab[data-tab="${name}"]`).classList.add('active');
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('is-hidden'));
    const targetView = document.getElementById(`${name}View`);
    if (targetView) targetView.classList.remove('is-hidden');
    localStorage.setItem(tabKey, name);
    const islandMode = document.getElementById('islandModeLabel');
    if (islandMode) islandMode.textContent = `Mode: ${name.charAt(0).toUpperCase()}${name.slice(1)}`;
    pulseIsland();
    if (name === 'gallery') loadGallery();
    if (name === 'workspaces') loadWorkspaces();
}

function toggleTheme() {
    document.body.classList.toggle('dark');
    localStorage.setItem(themeKey, document.body.classList.contains('dark') ? 'dark' : 'light');
}

async function loadWorkspaces() {
    try {
        const data = await safeFetch('../api/workspaces.php');
        const activeJobsData = await safeFetch('../api/workspace_jobs_active.php');
        activeJobMap = new Map();
        (activeJobsData.jobs || []).forEach(job => {
            activeJobMap.set(String(job.workspace_id), job);
        });
        const list = document.getElementById('workspaceList');
        const empty = document.getElementById('workspaceEmpty');
        const select = document.getElementById('workspaceSelect');
        const uploadSelect = document.getElementById('uploadWorkspaceSelect');
        const gallerySelect = document.getElementById('galleryWorkspaceSelect');
        workspaces = data.workspaces || [];
        list.innerHTML = '';
        if (select) select.innerHTML = '';
        if (uploadSelect) uploadSelect.innerHTML = '';
        if (gallerySelect) gallerySelect.innerHTML = '';
        const savedId = localStorage.getItem(lastWorkspaceKey);
        if (savedId && workspaces.find(w => String(w.id) === String(savedId))) {
            currentWorkspaceId = savedId;
        } else {
            const generalWs = workspaces.find(w => (w.title || '').toLowerCase() === 'general' || (w.slug || '').toLowerCase() === 'general');
            if (generalWs) currentWorkspaceId = generalWs.id;
            else if (workspaces[0]) currentWorkspaceId = workspaces[0].id;
        }

        const filtered = applyWorkspaceSearchSort(workspaces);
        setHidden(empty, filtered.length !== 0);

        filtered.forEach(ws => {
            const card = document.createElement('div');
            card.className = 'workspace-card';

            const titleRow = document.createElement('div');
            titleRow.className = 'title';
            const titleText = document.createElement('span');
            titleText.textContent = ws.title;
            titleRow.appendChild(titleText);
            if (String(ws.id) === String(currentWorkspaceId)) {
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = 'Active';
                titleRow.appendChild(badge);
            }
            const countForQueue = ws.asset_count ?? 0;
            const activeJob = activeJobMap.get(String(ws.id));
            if (activeJob) {
                const badge = document.createElement('span');
                if (activeJob.status === 'running') {
                    badge.className = 'badge running';
                    badge.textContent = 'Running';
                } else if (activeJob.status === 'failed') {
                    badge.className = 'badge failed';
                    badge.textContent = 'Failed';
                } else {
                    badge.className = 'badge queue';
                    badge.textContent = 'Queued';
                }
                badge.title = `Workspace job: ${activeJob.status}`;
                titleRow.appendChild(badge);
            } else if (countForQueue > workspaceSyncThreshold) {
                const badge = document.createElement('span');
                badge.className = 'badge queue';
                badge.textContent = 'Queue Mode';
                badge.title = 'Operasi rename/delete akan diproses oleh worker';
                titleRow.appendChild(badge);
            }

            const meta = document.createElement('div');
            meta.className = 'meta';
            const count = ws.asset_count ?? 0;
            const size = formatBytes(ws.total_bytes ?? 0);
            const last = ws.last_asset_at ? `Last: ${ws.last_asset_at}` : 'Last: -';
            meta.textContent = '';
            const metaFiles = document.createElement('span');
            metaFiles.textContent = `Files: ${count}`;
            const metaSize = document.createElement('span');
            metaSize.textContent = `Size: ${size}`;
            const metaLast = document.createElement('span');
            metaLast.textContent = `${last}`;
            meta.appendChild(metaFiles);
            meta.appendChild(metaSize);
            meta.appendChild(metaLast);

            const actions = document.createElement('div');
            actions.className = 'actions';

            const openBtn = document.createElement('button');
            openBtn.className = 'btn btn-primary';
            openBtn.textContent = 'Open';
            openBtn.onclick = () => {
                setWorkspace(ws.id);
                switchTab('gallery');
            };

            const renameBtn = document.createElement('button');
            renameBtn.className = 'btn btn-secondary';
            renameBtn.textContent = 'Rename';
            renameBtn.onclick = () => openRenameModal(ws);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger';
            delBtn.textContent = 'Delete';
            delBtn.onclick = () => openDeleteModal(ws);

            actions.appendChild(openBtn);
            actions.appendChild(renameBtn);
            actions.appendChild(delBtn);

            card.appendChild(titleRow);
            card.appendChild(meta);
            card.appendChild(actions);
            list.appendChild(card);
        });

        workspaces.forEach(ws => {
            const opt = document.createElement('option');
            opt.value = ws.id;
            opt.textContent = ws.title;
            opt.selected = ws.id == currentWorkspaceId;
            if (select) select.appendChild(opt);

            if (uploadSelect) {
                const opt2 = document.createElement('option');
                opt2.value = ws.id;
                opt2.textContent = ws.title;
                opt2.selected = ws.id == currentWorkspaceId;
                uploadSelect.appendChild(opt2);
            }
            if (gallerySelect) {
                const opt3 = document.createElement('option');
                opt3.value = ws.id;
                opt3.textContent = ws.title;
                opt3.selected = ws.id == currentWorkspaceId;
                gallerySelect.appendChild(opt3);
            }
        });
        localStorage.setItem(lastWorkspaceKey, String(currentWorkspaceId));
        updateUploadWorkspaceLabel();
        const galleryView = document.getElementById('galleryView');
        if (galleryView && !galleryView.classList.contains('is-hidden')) {
            loadGallery();
        }
        const tip = document.getElementById('firstLoginTip');
        if (localStorage.getItem(loggedInKey) === '1' && !localStorage.getItem(seenTipKey)) {
            setHidden(tip, false);
            localStorage.setItem(seenTipKey, '1');
        } else {
            setHidden(tip, true);
        }
        loadWorkspaceJobs();
    } catch (e) {
        showToast('Failed to load workspaces: ' + e.message, 'error');
    }
}

async function loadWorkspaceJobs() {
    const list = document.getElementById('workspaceJobsList');
    const empty = document.getElementById('workspaceJobsEmpty');
    const filter = document.getElementById('workspaceJobsFilter');
    const hideOldToggle = document.getElementById('workspaceJobsHideOld');
    if (!list || !empty) return;

    try {
        const hideOld = hideOldToggle ? (hideOldToggle.checked ? 1 : 0) : 1;
        let url = `../api/workspace_jobs.php?limit=20&hide_old=${hideOld}`;
        const filterValue = filter ? filter.value : '';
        if (filterValue === 'failed') {
            url += '&status=failed';
        } else if (filterValue === 'active') {
            url += '&status=queued,running';
        }
        const data = await safeFetch(url);
        const jobs = data.jobs || [];
        list.innerHTML = '';
        setHidden(empty, jobs.length !== 0);

        jobs.forEach(job => {
            const card = document.createElement('div');
            card.className = 'workspace-card';

            let payload = {};
            try {
                payload = job.payload ? JSON.parse(job.payload) : {};
            } catch (e) {
                payload = {};
            }

            const title = document.createElement('div');
            title.className = 'title';
            const titleText = document.createElement('span');
            titleText.textContent = `#${job.id} ${String(job.type || '').toUpperCase()}`;
            title.appendChild(titleText);

            const statusBadge = document.createElement('span');
            if (job.status === 'running') {
                statusBadge.className = 'badge running';
                statusBadge.textContent = 'Running';
            } else if (job.status === 'failed') {
                statusBadge.className = 'badge failed';
                statusBadge.textContent = 'Failed';
            } else if (job.status === 'completed') {
                statusBadge.className = 'badge';
                statusBadge.textContent = 'Done';
            } else {
                statusBadge.className = 'badge queue';
                statusBadge.textContent = 'Queued';
            }
            title.appendChild(statusBadge);

            const meta = document.createElement('div');
            meta.className = 'meta';
            const total = Number(job.total || 0);
            const progress = Number(job.progress || 0);
            const progressText = total > 0 ? `${progress}/${total}` : '-';
            const wsTitle = job.workspace_title || `Workspace ${job.workspace_id}`;
            const assetCount = (job.asset_count ?? 0);
            meta.textContent = '';
            const metaStatus = document.createElement('span');
            metaStatus.textContent = `Status: ${job.status}`;
            const metaWorkspace = document.createElement('span');
            metaWorkspace.textContent = `Workspace: ${wsTitle}`;
            const metaAssets = document.createElement('span');
            metaAssets.textContent = `Assets: ${assetCount}`;
            meta.appendChild(metaStatus);
            meta.appendChild(metaWorkspace);
            meta.appendChild(metaAssets);

            const details = document.createElement('div');
            details.className = 'meta';
            const detailParts = [];
            if (job.type === 'rename' && payload.new_title) {
                detailParts.push(`New title: ${payload.new_title}`);
            }
            detailParts.push(`Progress: ${progressText}`);
            detailParts.push(`Updated: ${job.updated_at}`);
            details.textContent = '';
            detailParts.forEach(part => {
                const span = document.createElement('span');
                span.textContent = part;
                details.appendChild(span);
            });

            const actions = document.createElement('div');
            actions.className = 'actions';
            const copyBtn = document.createElement('button');
            copyBtn.className = 'btn btn-secondary';
            copyBtn.textContent = 'Copy Job ID';
            copyBtn.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(String(job.id));
                    showToast('Job ID disalin', 'success');
                } catch (e) {
                    showToast('Gagal copy Job ID', 'error');
                }
            };
            actions.appendChild(copyBtn);
            const openBtn = document.createElement('button');
            openBtn.className = 'btn btn-primary';
            openBtn.textContent = 'Open Workspace';
            openBtn.onclick = () => {
                setWorkspace(job.workspace_id);
                switchTab('gallery');
            };
            actions.appendChild(openBtn);

            if (job.status === 'failed') {
                const retryBtn = document.createElement('button');
                retryBtn.className = 'btn btn-primary';
                retryBtn.textContent = 'Retry';
                retryBtn.onclick = async () => {
                    try {
                        await safeFetch('../api/workspace_job_retry.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: job.id })
                        });
                        showToast('Job di-queue ulang', 'success');
                        loadWorkspaces();
                    } catch (e) {
                        showToast('Retry gagal: ' + e.message, 'error');
                    }
                };
                actions.appendChild(retryBtn);
            }
            if (job.status === 'queued') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'btn btn-danger';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = async () => {
                    try {
                        await safeFetch('../api/workspace_job_cancel.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: job.id })
                        });
                        showToast('Job dibatalkan', 'success');
                        loadWorkspaces();
                    } catch (e) {
                        showToast('Cancel gagal: ' + e.message, 'error');
                    }
                };
                actions.appendChild(cancelBtn);
            }
            if (job.status === 'running') {
                const forceBtn = document.createElement('button');
                forceBtn.className = 'btn btn-danger';
                forceBtn.textContent = 'Mark Stale';
                forceBtn.onclick = async () => {
                    try {
                        await safeFetch('../api/workspace_job_force_reset.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: job.id })
                        });
                        showToast('Job ditandai stale', 'success');
                        loadWorkspaces();
                    } catch (e) {
                        showToast('Mark stale gagal: ' + e.message, 'error');
                    }
                };
                actions.appendChild(forceBtn);
            }

            card.appendChild(title);
            card.appendChild(meta);
            card.appendChild(details);
            if (job.last_error) {
                const errorNote = document.createElement('div');
                errorNote.className = 'hint';
                errorNote.textContent = `Error: ${job.last_error}`;
                card.appendChild(errorNote);
            }
            card.appendChild(actions);
            list.appendChild(card);
        });
    } catch (e) {
        console.error('Failed to load workspace jobs:', e);
        setHidden(empty, false);
    }
}

function updateUploadWorkspaceLabel() {
    const ws = workspaces.find(w => String(w.id) === String(currentWorkspaceId));
    const islandLabel = document.getElementById('islandWorkspaceLabel');
    if (islandLabel) islandLabel.textContent = ws ? ws.title : '-';
    const uploadSelect = document.getElementById('uploadWorkspaceSelect');
    if (uploadSelect) {
        uploadSelect.value = ws ? ws.id : '';
    }
    const gallerySelect = document.getElementById('galleryWorkspaceSelect');
    if (gallerySelect) {
        gallerySelect.value = ws ? ws.id : '';
    }
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const val = bytes / Math.pow(1024, i);
    return `${val.toFixed(val >= 10 || i === 0 ? 0 : 1)} ${sizes[i]}`;
}

function applyWorkspaceSearchSort(list) {
    const search = (document.getElementById('workspaceSearch')?.value || '').toLowerCase().trim();
    const sort = document.getElementById('workspaceSort')?.value || 'recent';
    let filtered = list.filter(ws => ws.title.toLowerCase().includes(search));
    if (sort === 'az') {
        filtered.sort((a, b) => a.title.localeCompare(b.title));
    } else if (sort === 'count') {
        filtered.sort((a, b) => (b.asset_count || 0) - (a.asset_count || 0));
    } else {
        filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    }
    return filtered;
}

function setWorkspace(id) {
    currentWorkspaceId = id;
    localStorage.setItem(lastWorkspaceKey, String(currentWorkspaceId));
    refreshPendingMetaForWorkspace();
    updateUploadWorkspaceLabel();
    const galleryView = document.getElementById('galleryView');
    if (galleryView && !galleryView.classList.contains('is-hidden')) {
        loadGallery();
    }
}

document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
});
document.getElementById('createWorkspaceBtn')?.addEventListener('click', createWorkspace);
document.getElementById('refreshWorkspaceJobsBtn')?.addEventListener('click', loadWorkspaceJobs);
document.getElementById('loginBtn')?.addEventListener('click', performLogin);
document.getElementById('workspaceModalCancelBtn')?.addEventListener('click', closeWorkspaceModal);

const legacySelect = document.getElementById('workspaceSelect');
if (legacySelect) {
    legacySelect.addEventListener('change', (e) => {
        setWorkspace(e.target.value);
    });
}

const uploadWorkspaceSelect = document.getElementById('uploadWorkspaceSelect');
if (uploadWorkspaceSelect) {
    uploadWorkspaceSelect.addEventListener('change', (e) => {
        setWorkspace(e.target.value);
    });
}
const galleryWorkspaceSelect = document.getElementById('galleryWorkspaceSelect');
if (galleryWorkspaceSelect) {
    galleryWorkspaceSelect.addEventListener('change', (e) => {
        setWorkspace(e.target.value);
    });
}

async function createWorkspace() {
    const title = document.getElementById('wsTitle').value;
    if (!title) return;
    try {
        await safeFetch('../api/workspaces.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title })
        });
        document.getElementById('wsTitle').value = '';
        loadWorkspaces();
    } catch (e) {
        showToast('Error creating workspace: ' + e.message, 'error');
    }
}

document.getElementById('workspaceSearch')?.addEventListener('input', () => loadWorkspaces());
document.getElementById('workspaceSort')?.addEventListener('change', () => loadWorkspaces());
document.getElementById('workspaceJobsFilter')?.addEventListener('change', () => loadWorkspaceJobs());
document.getElementById('workspaceJobsHideOld')?.addEventListener('change', () => loadWorkspaceJobs());
document.getElementById('wsTitle')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        createWorkspace();
    }
});

function openRenameModal(ws) {
    const modal = document.getElementById('workspaceModal');
    const title = document.getElementById('workspaceModalTitle');
    const desc = document.getElementById('workspaceModalDesc');
    const meta = document.getElementById('workspaceModalMeta');
    const input = document.getElementById('workspaceModalInput');
    const confirmBtn = document.getElementById('workspaceModalConfirm');
    if (!modal || !title || !desc || !input || !confirmBtn || !meta) return;
    title.textContent = 'Rename Workspace';
    desc.textContent = 'Mengganti nama akan mengubah URL gambar di workspace ini.';
    const count = ws.asset_count ?? 0;
    const mode = count > workspaceSyncThreshold ? 'queue' : 'sync';
    meta.textContent = `Asset: ${count} | Mode: ${mode.toUpperCase()} (threshold ${workspaceSyncThreshold})`;
    const activeJob = activeJobMap.get(String(ws.id));
    if (activeJob) {
        meta.textContent += ` | Job: ${String(activeJob.status || '').toUpperCase()}`;
    }
    input.classList.remove('is-hidden');
    input.value = ws.title;
    input.placeholder = 'Masukkan nama baru';
    confirmBtn.className = 'btn btn-primary';
    confirmBtn.textContent = 'Rename';
    confirmBtn.disabled = true;
    confirmBtn.classList.add('btn-disabled');
    const originalTitle = (ws.title || '').trim();
    const syncButtonState = () => {
        const newTitle = (input.value || '').trim();
        const canRename = newTitle.length > 0 && newTitle !== originalTitle;
        confirmBtn.disabled = !canRename;
        if (canRename) {
            confirmBtn.classList.remove('btn-disabled');
        } else if (!confirmBtn.classList.contains('btn-disabled')) {
            confirmBtn.classList.add('btn-disabled');
        }
    };
    input.oninput = syncButtonState;
    syncButtonState();
    confirmBtn.onclick = () => {
        if (confirmBtn.disabled) return;
        closeWorkspaceModal();
        renameWorkspace(ws, input.value);
    };
    modal.classList.add('is-visible-flex');
    input.focus();
}

function openDeleteModal(ws) {
    const modal = document.getElementById('workspaceModal');
    const title = document.getElementById('workspaceModalTitle');
    const desc = document.getElementById('workspaceModalDesc');
    const meta = document.getElementById('workspaceModalMeta');
    const input = document.getElementById('workspaceModalInput');
    const confirmBtn = document.getElementById('workspaceModalConfirm');
    if (!modal || !title || !desc || !input || !confirmBtn || !meta) return;
    title.textContent = 'Delete Workspace';
    const count = ws.asset_count ?? 0;
    desc.textContent = `Workspace "${ws.title}" berisi ${count} file. Semua file akan dihapus permanen.`;
    const mode = count > workspaceSyncThreshold ? 'queue' : 'sync';
    meta.textContent = `Asset: ${count} | Mode: ${mode.toUpperCase()} (threshold ${workspaceSyncThreshold})`;
    const activeJob = activeJobMap.get(String(ws.id));
    if (activeJob) {
        meta.textContent += ` | Job: ${String(activeJob.status || '').toUpperCase()}`;
    }
    input.classList.remove('is-hidden');
    input.value = '';
    input.placeholder = 'Ketik DELETE untuk konfirmasi';
    confirmBtn.className = 'btn btn-danger';
    confirmBtn.textContent = 'Delete';
    confirmBtn.onclick = () => {
        if (input.value !== 'DELETE') {
            showToast('Ketik DELETE untuk konfirmasi', 'error');
            return;
        }
        closeWorkspaceModal();
        deleteWorkspace(ws);
    };
    modal.classList.add('is-visible-flex');
}

function closeWorkspaceModal() {
    const modal = document.getElementById('workspaceModal');
    if (modal) modal.classList.remove('is-visible-flex');
    const meta = document.getElementById('workspaceModalMeta');
    if (meta) meta.textContent = '';
    const input = document.getElementById('workspaceModalInput');
    if (input) input.classList.add('is-hidden');
}

async function deleteWorkspace(ws) {
    try {
        const shouldQueue = (ws.asset_count ?? 0) > workspaceSyncThreshold;
        const data = await safeFetch('../api/delete_workspace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: ws.id })
        });
        if (data.job_id) {
            showToast(`Masuk queue: Job #${data.job_id}`, 'info');
            await loadWorkspaces();
        } else {
            if (String(currentWorkspaceId) === String(ws.id)) {
                currentWorkspaceId = 1;
            }
            await loadWorkspaces();
            showToast('Workspace berhasil dihapus', 'success');
        }
    } catch (e) {
        showToast('Delete workspace gagal: ' + e.message, 'error');
    }
}

async function renameWorkspace(ws, newTitle) {
    if (!newTitle) return;
    if (newTitle.trim() === ws.title.trim()) return;
    try {
        const shouldQueue = (ws.asset_count ?? 0) > workspaceSyncThreshold;
        if (!shouldQueue) {
            showProgress('Renaming Workspace', 'Menyiapkan proses...', 15);
        }
        const data = await safeFetch('../api/rename_workspace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: ws.id, title: newTitle })
        });
        if (data.job_id) {
            if (!shouldQueue) {
                hideProgress();
            }
            showToast(`Masuk queue: Job #${data.job_id}`, 'info');
            await loadWorkspaces();
        } else {
            updateProgress('Menyelesaikan...', 100);
            setTimeout(() => hideProgress(), 300);
            await loadWorkspaces();
            if (String(currentWorkspaceId) === String(ws.id)) {
                loadGallery();
            }
            showToast('Workspace berhasil di-rename', 'success');
        }
    } catch (e) {
        hideProgress();
        showToast('Rename workspace gagal: ' + e.message, 'error');
    }
}

async function loadGallery() {
    try {
        const data = await safeFetch(`../api/list_assets.php?workspace_id=${currentWorkspaceId}`);
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '';
        data.assets.forEach((asset, idx) => {
            const card = document.createElement('div');
            card.className = 'asset-card';
            
            const imgLink = document.createElement('a');
            imgLink.className = 'thumb';
            imgLink.href = asset.public_url;
            imgLink.dataset.url = asset.public_url;
            imgLink.target = '_blank';
            imgLink.rel = 'noopener';
            imgLink.title = 'Klik untuk membuka gambar di tab baru';

            const img = document.createElement('img');
            img.src = asset.public_url;
            img.dataset.url = asset.public_url;
            img.alt = asset.alt_text || '';
            imgLink.appendChild(img);

            const info = document.createElement('div');
            info.className = 'asset-info';

            const storedName = asset.stored_name || asset.original_name || '';
            const dotIndex = storedName.lastIndexOf('.');
            const baseName = dotIndex > 0 ? storedName.slice(0, dotIndex) : storedName;
            const ext = dotIndex > 0 ? storedName.slice(dotIndex + 1) : '';

            const nameRow = document.createElement('div');
            nameRow.className = 'row';

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.value = baseName;
            nameInput.placeholder = 'File name';
            nameInput.dataset.original = baseName;
            nameInput.className = 'grow';
            nameInput.onblur = () => {
                nameInput.value = normalizeBaseName(nameInput.value);
            };

            const extLabel = document.createElement('span');
            extLabel.textContent = ext ? `.${ext}` : '';
            extLabel.className = 'hint';

            const renameBtn = document.createElement('button');
            renameBtn.className = 'btn btn-primary';
            renameBtn.textContent = 'Save Name';
            renameBtn.title = 'Simpan perubahan nama file dan URL';
            renameBtn.onclick = () => renameAsset(asset.id, nameInput.value, ext, img, imgLink, renameBtn, nameInput);
            renameBtn.disabled = true;
            renameBtn.classList.add('btn-disabled');

            const syncRenameState = () => {
                const normalized = normalizeBaseName(nameInput.value);
                const original = nameInput.dataset.original || '';
                const canRename = normalized.length > 0 && normalized !== original;
                renameBtn.disabled = !canRename;
                if (canRename) {
                    renameBtn.classList.remove('btn-disabled');
                    if (!renameBtn.classList.contains('btn-primary')) {
                        renameBtn.className = 'btn btn-primary';
                    }
                } else if (!renameBtn.classList.contains('btn-disabled')) {
                    renameBtn.classList.add('btn-disabled');
                }
            };
            nameInput.addEventListener('input', syncRenameState);
            syncRenameState();

            nameRow.appendChild(nameInput);
            nameRow.appendChild(extLabel);
            nameRow.appendChild(renameBtn);

            const altInput = document.createElement('input');
            altInput.type = 'text';
            altInput.value = asset.alt_text || '';
            altInput.placeholder = 'Alt Text';
            altInput.classList.add('input-full');
            altInput.dataset.initial = altInput.value;

            const capInput = document.createElement('input');
            capInput.type = 'text';
            capInput.value = asset.caption || '';
            capInput.placeholder = 'Caption';
            capInput.classList.add('input-full', 'mb-12');
            capInput.dataset.initial = capInput.value;

            const metaActions = document.createElement('div');
            metaActions.className = 'asset-actions';

            const saveMetaBtn = document.createElement('button');
            saveMetaBtn.className = 'btn btn-disabled';
            saveMetaBtn.textContent = 'Save Meta';
            saveMetaBtn.title = 'Simpan Alt Text dan Caption';
            saveMetaBtn.onclick = () => updateMetadata(asset.id, capInput, altInput, saveMetaBtn);
            setSaveMetaState(saveMetaBtn, false);

            const onMetaChange = () => {
                const dirty = altInput.value !== altInput.dataset.initial || capInput.value !== capInput.dataset.initial;
                setSaveMetaState(saveMetaBtn, dirty);
            };
            altInput.addEventListener('input', onMetaChange);
            capInput.addEventListener('input', onMetaChange);

            const autoMetaBtn = document.createElement('button');
            autoMetaBtn.className = 'btn btn-secondary btn-auto';
            autoMetaBtn.innerHTML = '<span class="sparkle"></span>Auto Meta';
            autoMetaBtn.title = 'Buat Alt Text & Caption otomatis dengan cerdas';
            autoMetaBtn.onclick = () => {
                const wsTitle = (workspaces.find(w => String(w.id) === String(currentWorkspaceId)) || {}).title || '';
                const normalizedBase = normalizeBaseName(nameInput.value);
                nameInput.value = normalizedBase;
                altInput.value = generateAlt(normalizedBase);
                capInput.value = generateCaption(normalizedBase, wsTitle);
                onMetaChange();
                flashButton(autoMetaBtn, 'Applied', 'btn btn-info btn-pulse');
            };

            const actions = document.createElement('div');
            actions.className = 'asset-actions align';
            const leftCluster = document.createElement('div');
            leftCluster.className = 'cluster';
            const rightCluster = document.createElement('div');
            rightCluster.className = 'cluster';

            const copyBtn = document.createElement('button');
            copyBtn.className = 'btn btn-primary';
            copyBtn.textContent = 'Copy MD';
            copyBtn.title = 'Salin snippet Markdown siap tempel';
            copyBtn.onclick = () => {
                const url = imgLink.dataset.url || img.dataset.url || img.src;
                copySnippet(url, altInput.value, capInput.value, idx + 1);
                flashButton(copyBtn, 'Copied', 'btn btn-info btn-pulse', 800);
            };

            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-danger';
            delBtn.textContent = 'Del';
            delBtn.title = 'Hapus gambar dari storage';
            delBtn.onclick = () => deleteAsset(asset.id, delBtn);

            metaActions.appendChild(autoMetaBtn);
            metaActions.appendChild(saveMetaBtn);
            leftCluster.appendChild(copyBtn);
            rightCluster.appendChild(delBtn);
            actions.appendChild(leftCluster);
            actions.appendChild(rightCluster);
            
            const spacer1 = document.createElement('div');
            spacer1.className = 'spacer';
            const spacer2 = document.createElement('div');
            spacer2.className = 'spacer';

            info.appendChild(nameRow);
            info.appendChild(spacer1);
            info.appendChild(altInput);
            info.appendChild(spacer2);
            info.appendChild(capInput);
            info.appendChild(metaActions);
            info.appendChild(actions);
            
            card.appendChild(imgLink);
            card.appendChild(info);
            grid.appendChild(card);
        });
    } catch (e) {
        console.error('Failed to load gallery:', e);
    }
}

async function updateMetadata(id, captionInput, altInput, button) {
    const caption = captionInput.value;
    const altText = altInput.value;
    const asset = { id: id, caption: caption, alt_text: altText };
    setButtonLoading(button, 'Saving...');
    const fallback = setTimeout(() => {
        if (button && button.textContent === 'Saving...') {
            resetButton(button);
        }
    }, 5000);

    try {
        await safeFetch('../api/update_asset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(asset)
        });
        clearTimeout(fallback);
        captionInput.dataset.initial = caption;
        altInput.dataset.initial = altText;
        flashButton(button, 'Saved', 'btn btn-success btn-pulse');
        setTimeout(() => setSaveMetaState(button, false), 1300);
    } catch (e) {
        clearTimeout(fallback);
        flashButton(button, 'Error', 'btn btn-danger btn-pulse');
        console.error('Update failed:', e.message);
    }
}

async function renameAsset(id, baseName, ext, imgElement, linkElement, button, nameInput) {
    const normalized = normalizeBaseName(baseName);
    if (!normalized) {
        showToast('Nama file tidak boleh kosong.', 'error');
        return;
    }
    const fullName = ext ? `${normalized}.${ext}` : normalized;
    try {
        const data = await safeFetch('../api/rename_asset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, new_name: fullName })
        });
        if (data.url) {
            imgElement.src = data.url;
            if (linkElement) {
                linkElement.href = data.url;
                linkElement.dataset.url = data.url;
            }
            imgElement.dataset.url = data.url;
        }
        flashButton(button, 'Saved', 'btn btn-success btn-pulse');
        if (nameInput) {
            nameInput.value = normalized;
            nameInput.dataset.original = normalized;
            button.disabled = true;
            button.className = 'btn btn-disabled';
        }
    } catch (e) {
        showToast('Rename gagal: ' + e.message, 'error');
    }
}

async function deleteAsset(id, button) {
    if (!confirm('Are you sure?')) return;
    const formData = new FormData();
    formData.append('id', id);
    try {
        await safeFetch('../api/delete.php', { method: 'POST', body: formData });
        flashButton(button, 'Deleted', 'btn btn-danger btn-pulse');
        setTimeout(() => loadGallery(), 350);
    } catch (e) {
        showToast('Delete failed: ' + e.message, 'error');
    }
}

function copySnippet(url, alt, caption, num) {
    let md = `![${alt || 'image'}](${url})`;
    if (caption) {
        md += `\n*Gambar ${num}: ${caption}*`;
    }
    navigator.clipboard.writeText(md);
    showToast('Snippet copied!', 'success');
}

function flashButton(button, text, className, duration = 1200) {
    if (!button) return;
    const originalHtml = button.dataset.originalHtml || button.innerHTML;
    const originalClass = button.dataset.originalClass || button.className;
    button.textContent = text;
    button.className = className;
    button.disabled = true;
    setTimeout(() => {
        button.innerHTML = originalHtml;
        button.className = originalClass;
        button.disabled = false;
        delete button.dataset.originalHtml;
        delete button.dataset.originalClass;
    }, duration);
}

function setButtonLoading(button, text) {
    if (!button) return;
    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
        button.dataset.originalClass = button.className;
    }
    button.textContent = text;
    button.disabled = true;
}

function resetButton(button) {
    if (!button) return;
    const originalHtml = button.dataset.originalHtml || button.innerHTML;
    const originalClass = button.dataset.originalClass || button.className;
    button.innerHTML = originalHtml;
    button.className = originalClass;
    button.disabled = false;
    delete button.dataset.originalHtml;
    delete button.dataset.originalClass;
}

function setSaveMetaState(button, dirty) {
    if (!button) return;
    button.disabled = !dirty;
    if (dirty) {
        button.className = button.className.replace('btn-disabled', '').trim();
        if (!button.className.includes('btn-primary')) {
            button.className = 'btn btn-primary';
        }
    } else {
        button.className = 'btn btn-disabled';
    }
}

// Upload Logic
const dropZone = document.getElementById('dropZone');
dropZone.onclick = () => document.getElementById('fileInput').click();

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
    dropZone.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); });
});

dropZone.addEventListener('dragover', () => dropZone.classList.add('dragover'));
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', (e) => {
    dropZone.classList.remove('dragover');
    const files = e.dataTransfer.files;
    Array.from(files).forEach(queueFile);
});

document.getElementById('fileInput').addEventListener('change', (e) => {
    Array.from(e.target.files).forEach(queueFile);
    e.target.value = '';
});

const pendingList = document.getElementById('pendingList');
const uploadAllBtn = document.getElementById('uploadAllBtn');

function queueFile(file) {
    const parts = file.name.split('.');
    const ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
    const base = parts.join('.');
    const normalizedBase = normalizeBaseName(base) || 'file';
    const wsTitle = (workspaces.find(w => String(w.id) === String(currentWorkspaceId)) || {}).title || '';
    const item = {
        id: ++pendingSeq,
        file,
        baseName: normalizedBase,
        ext,
        alt: generateAlt(normalizedBase),
        caption: generateCaption(normalizedBase, wsTitle),
        metaTouched: false
    };
    pendingQueue.push(item);
    renderPendingList();
}

function refreshPendingMetaForWorkspace() {
    if (pendingQueue.length === 0) return;
    const wsTitle = (workspaces.find(w => String(w.id) === String(currentWorkspaceId)) || {}).title || '';
    pendingQueue.forEach(item => {
        if (!item.metaTouched) {
            item.alt = generateAlt(item.baseName);
            item.caption = generateCaption(item.baseName, wsTitle);
        }
    });
    renderPendingList();
}

function renderPendingList() {
    pendingList.innerHTML = '';
    pendingQueue.forEach(item => {
        const wrapper = document.createElement('div');
        wrapper.className = 'pending-item';

        const row = document.createElement('div');
        row.className = 'pending-row';

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.value = item.baseName;
        nameInput.placeholder = 'Nama file';
        nameInput.oninput = () => {
            item.baseName = nameInput.value;
        };
        nameInput.onblur = () => {
            const normalized = normalizeBaseName(nameInput.value);
            nameInput.value = normalized;
            item.baseName = normalized;
            if (!item.metaTouched) {
                const wsTitle = (workspaces.find(w => String(w.id) === String(currentWorkspaceId)) || {}).title || '';
                item.alt = generateAlt(normalized);
                item.caption = generateCaption(normalized, wsTitle);
                altInput.value = item.alt;
                capInput.value = item.caption;
            }
            updateUploadAllButton();
        };

        const extLabel = document.createElement('span');
        extLabel.className = 'hint';
        extLabel.textContent = item.ext ? `.${item.ext}` : '';

        row.appendChild(nameInput);
        row.appendChild(extLabel);

        const metaRow = document.createElement('div');
        metaRow.className = 'pending-meta';

        const capInput = document.createElement('input');
        capInput.type = 'text';
        capInput.value = item.caption;
        capInput.placeholder = 'Caption';
        capInput.oninput = () => {
            item.metaTouched = true;
            item.caption = capInput.value;
        };

        const altInput = document.createElement('input');
        altInput.type = 'text';
        altInput.value = item.alt;
        altInput.placeholder = 'Alt Text';
        altInput.oninput = () => {
            item.metaTouched = true;
            item.alt = altInput.value;
        };

        metaRow.appendChild(altInput);
        metaRow.appendChild(capInput);

        const actionRow = document.createElement('div');
        actionRow.className = 'pending-actions';

        const uploadBtn = document.createElement('button');
        uploadBtn.className = 'btn btn-primary';
        uploadBtn.textContent = 'Upload';
        uploadBtn.onclick = () => uploadPendingItem(item, nameInput, altInput, capInput, uploadBtn);

        const removeBtn = document.createElement('button');
        removeBtn.className = 'btn btn-secondary';
        removeBtn.textContent = 'Remove';
        removeBtn.onclick = () => {
            pendingQueue = pendingQueue.filter(p => p.id !== item.id);
            renderPendingList();
        };

        actionRow.appendChild(uploadBtn);
        actionRow.appendChild(removeBtn);

        wrapper.appendChild(row);
        wrapper.appendChild(metaRow);
        wrapper.appendChild(actionRow);
        pendingList.appendChild(wrapper);
    });

    updateUploadAllButton();
    updateIslandActivity();
}

function updateUploadAllButton() {
    if (pendingQueue.length === 0) {
        uploadAllBtn.classList.add('is-hidden');
        return;
    }
    uploadAllBtn.classList.remove('is-hidden');
    const hasInvalid = pendingQueue.some(item => !item.baseName);
    uploadAllBtn.disabled = hasInvalid;
}

uploadAllBtn.onclick = async () => {
    for (const item of [...pendingQueue]) {
        const base = normalizeBaseName(item.baseName);
        if (!base) continue;
        await uploadPendingItem(item);
    }
};

async function uploadPendingItem(item, nameInput, altInput, capInput, button) {
    const base = normalizeBaseName((nameInput && nameInput.value) || item.baseName);
    if (!base) {
        showToast('Nama file harus diisi.', 'error');
        return;
    }
    item.baseName = base;
    const altText = (altInput && altInput.value) || item.alt;
    const caption = (capInput && capInput.value) || item.caption;
    const filename = item.ext ? `${base}.${item.ext}` : base;

    const status = document.getElementById('uploadStatus');
    const div = document.createElement('div');
    div.textContent = `Uploading ${filename}...`;
    status.appendChild(div);

    const formData = new FormData();
    formData.append('file', item.file);
    formData.append('workspace_id', currentWorkspaceId);
    formData.append('filename', filename);
    formData.append('alt_text', altText);
    formData.append('caption', caption);

    try {
        if (button) {
            button.disabled = true;
            button.textContent = 'Uploading...';
        }
        activeUploads += 1;
        updateIslandActivity();
        pulseIsland();
        const data = await safeFetch('../api/upload.php', {
            method: 'POST',
            body: formData
        });
        div.textContent = `Uploaded: ${data.name}`;
        const copyBtn = document.createElement('button');
        copyBtn.textContent = 'Copy MD';
        copyBtn.className = 'btn btn-mini';
        copyBtn.onclick = () => copySnippet(data.url, data.alt_text, data.caption);
        div.appendChild(copyBtn);
        pendingQueue = pendingQueue.filter(p => p.id !== item.id);
        renderPendingList();
        showToast('Upload selesai', 'success');
    } catch (e) {
        div.textContent = `${filename} failed: ${e.message}`;
        div.classList.add('text-error');
        if (button) {
            button.disabled = false;
            button.textContent = 'Upload';
        }
        showToast('Upload gagal: ' + e.message, 'error');
    } finally {
        if (activeUploads > 0) activeUploads -= 1;
        updateIslandActivity();
    }
}

// Clipboard Paste
document.addEventListener('paste', (e) => {
    const items = e.clipboardData.items;
    for (let item of items) {
        if (item.kind === 'file') {
            queueFile(item.getAsFile());
        }
    }
});

const savedTheme = localStorage.getItem(themeKey);
if (savedTheme === 'dark') {
    document.body.classList.add('dark');
}
const savedTab = localStorage.getItem(tabKey);
const hasLoginOverlay = !!document.getElementById('loginOverlay');
if (!hasLoginOverlay) {
    if (savedTab) {
        switchTab(savedTab);
    }
    loadWorkspaces();
}
