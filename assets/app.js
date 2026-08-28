const api = 'api.php';
let categories = [];
let currentRoot = localStorage.getItem('video_catalog_root') || '';
let currentTree = null;
let expandedDirs = new Set(JSON.parse(localStorage.getItem('video_catalog_expanded_dirs') || '[]'));
let favoriteRoots = JSON.parse(localStorage.getItem('video_catalog_favorite_roots') || '[]');
let selectedItems = new Map();
let searchTimer;
let screenshotGenerationRunning = false;
let screenshotMonitorTimer = null;
let screenshotMonitorRoot = '';
let imageViewerItems = [];
let imageViewerIndex = 0;
let imageViewerWheelLocked = false;
let imageViewerTouchStartX = null;
let metadataViewRows = [];
let screenshotViewRows = [];
let currentFileToolsToken = '';
let fileToolJobTimers = new Map();
let currentViewMode = localStorage.getItem('video_catalog_view_mode') === 'tiles' ? 'tiles' : 'list';
let currentDirectoryPath = localStorage.getItem('video_catalog_selected_dir') || '';
let currentSortMode = localStorage.getItem('video_catalog_sort_mode') || 'name_asc';
let folderSearchQuery = '';
let thumbnailObserver = null;
let mergeItems = [];
let mergePollTimers = new Map();
let mergeMonitorTimer = null;
let mergeMonitorRoot = '';
let currentMergeInfoToken = '';
let currentTranscriptData = null;
let translationTargetTranscript = null;
let translationJobTimers = new Map();
let translationImportFile = null;
let currentTranscriptVersion = 'original';


const $ = (id) => document.getElementById(id);

function showMessage(text, isError = true) {
    const box = $('message');
    box.textContent = text;
    box.classList.toggle('hidden', !text);
    box.style.background = isError ? '#fff7ed' : '#ecfdf5';
    box.style.borderColor = isError ? '#fed7aa' : '#bbf7d0';
    box.style.color = isError ? '#9a3412' : '#166534';
}

async function fetchJson(url, options = {}) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const headers = new Headers(options.headers || {});
    if (csrf) headers.set('X-CSRF-Token', csrf);
    options = { ...options, headers };
    const response = await fetch(url, options);
    const raw = await response.text();
    let data;
    try {
        data = JSON.parse(raw.replace(/^\uFEFF/, '').trim());
    } catch {
        console.error('Invalid API response:', raw);
        throw new Error('Сервер вернул некорректный ответ. Подробности записаны в консоль браузера.');
    }
    if (!data.ok) {
        if (data.auth_required) {
            window.location.href = 'index.php';
            throw new Error('Требуется повторный вход в приложение.');
        }
        throw new Error(data.error || 'Ошибка запроса');
    }
    return data;
}

function postForm(action, values = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value));
    return fetchJson(api, { method: 'POST', body: form });
}

function fileToolsPost(action, values = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value ?? ''));
    return fetchJson('utilities/file_tools.php', { method: 'POST', body: form });
}

function videoMergePost(action, values = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value ?? ''));
    return fetchJson('utilities/video_merge.php', { method: 'POST', body: form });
}

function transcriptionPost(action, values = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value ?? ''));
    return fetchJson('utilities/transcription.php', { method: 'POST', body: form });
}

function translationPost(action, values = {}) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value ?? ''));
    return fetchJson('utilities/translation.php', { method: 'POST', body: form });
}

async function loadCategories(selectedId = '', rootPath = '') {
    const root = String(rootPath || currentRoot || $('rootPath')?.value || '').trim();
    if (!root) {
        categories = [];
        fillCategorySelect($('categoryFilter'), '', 'Все категории');
        fillCategorySelect($('cardCategory'), '', 'Без категории');
        fillBulkCategorySelect($('bulkCategorySelect'));
        fillBulkCategorySelect($('searchBulkCategorySelect'));
        return;
    }
    const params = new URLSearchParams({ action: 'categories', root });
    const data = await fetchJson(`${api}?${params.toString()}`);
    categories = data.categories;
    fillCategorySelect($('categoryFilter'), selectedId, 'Все категории');
    fillCategorySelect($('cardCategory'), selectedId, 'Без категории');
    fillBulkCategorySelect($('bulkCategorySelect'));
    fillBulkCategorySelect($('searchBulkCategorySelect'));
}

function fillCategorySelect(select, selectedId = '', emptyText = 'Без категории') {
    const previous = selectedId || select.value || '';
    select.innerHTML = `<option value="">${emptyText}</option>`;
    for (const category of categories) {
        const option = document.createElement('option');
        option.value = category.id;
        option.textContent = category.name;
        select.appendChild(option);
    }
    select.value = previous;
}

function fillBulkCategorySelect(select) {
    if (!select) return;
    select.innerHTML = '<option value="">В категорию…</option><option value="__none__">Без категории</option>';
    for (const category of categories) {
        const option = document.createElement('option');
        option.value = category.id;
        option.textContent = category.name;
        select.appendChild(option);
    }
    select.value = '';
}

function cacheStatsText(stats) {
    if (!stats) return '';
    const directoryPart = (stats.dirs_added || stats.dirs_removed)
        ? ` Папки: добавлено ${stats.dirs_added || 0}, удалено ${stats.dirs_removed || 0}.`
        : '';
    const screenshotPart = Number(stats.screenshots_pending || 0) > 0
        ? ` Для ${stats.screenshots_pending} видео запланировано создание кадров.`
        : '';
    return `Файлы: добавлено ${stats.added}; изменено ${stats.changed}; перемещено/переименовано ${stats.moved}; удалено ${stats.removed}; без изменений ${stats.unchanged}.${directoryPart}${screenshotPart}`;
}

function updateCacheInfo(cache) {
    if (!cache) return;
    $('cacheInfo').textContent = cache.last_refresh_at
        ? `Последнее обновление кэша: ${cache.last_refresh_at}.`
        : 'Кэш еще не обновлялся.';
}

async function loadTree({ announceInitialCache = true, processScreenshots = true } = {}) {
    const requestedRoot = $('rootPath').value.trim();
    if (!requestedRoot) return showMessage('Укажите папку. Например: D:\\Video');

    localStorage.setItem('video_catalog_root', requestedRoot);
    currentRoot = requestedRoot;
    hideContextMenu();
    $('tree').innerHTML = '';
    $('tree').classList.remove('empty');
    if ($('fileBrowser')) { $('fileBrowser').innerHTML = ''; $('fileBrowser').classList.remove('empty'); }

    try {
        const params = new URLSearchParams({ action: 'tree', root: requestedRoot });
        const categoryId = $('categoryFilter').value;
        if (categoryId) params.set('category_id', categoryId);

        const data = await fetchJson(`${api}?${params.toString()}`);
        applyRootRelocation(data.relocated_from, data.root);
        currentRoot = data.root;
        currentTree = data.tree;
        $('rootPath').value = data.root;
        localStorage.setItem('video_catalog_root', data.root);
        const previousCategoryId = $('categoryFilter').value;
        await loadCategories(previousCategoryId, data.root);
        selectedItems.clear();
        renderTree(data.tree);
        renderFavorites();
        updateCacheInfo(data.cache);
        monitorMergeJobs(currentRoot, true).catch(() => {});

        if (data.cache?.initialized_now && announceInitialCache) {
            showMessage(`Новая папка добавлена в кэш. ${cacheStatsText(data.cache.stats)}`, false);
        } else {
            showMessage('', false);
        }

        if (data.cache?.screenshot_worker_error) {
            setScreenshotProgress(`Фоновое создание кадров не запущено: ${data.cache.screenshot_worker_error}`);
        } else if (data.cache?.screenshot_worker) {
            updateScreenshotWorkerProgress(data.cache.screenshot_worker);
        }

        if (processScreenshots) {
            const workerStatus = data.cache?.screenshot_worker?.status || '';
            const workerPaused = ['paused', 'stopping'].includes(workerStatus);
            if (Number(data.cache?.screenshots_pending || 0) > 0 && !workerPaused) {
                processPendingScreenshots(currentRoot).catch((error) => setScreenshotProgress(error.message));
            } else {
                monitorScreenshotWorker(currentRoot, true).catch(() => {});
            }
        }
    } catch (error) {
        $('tree').textContent = 'Не удалось загрузить дерево файлов.';
        $('tree').classList.add('empty');
        if ($('fileBrowser')) { $('fileBrowser').textContent = 'Не удалось загрузить файлы.'; $('fileBrowser').classList.add('empty'); }
        currentTree = null;
        selectedItems.clear();
        updateSelectionToolbar();
        showMessage(error.message);
    }
}

async function refreshCache() {
    const root = $('rootPath').value.trim();
    if (!root) return showMessage('Сначала укажите папку.');

    const button = $('refreshCacheBtn');
    button.disabled = true;
    showMessage('Обновляю кэш: проверяю папки и файлы на диске...', false);
    try {
        const data = await postForm('refresh_cache', { root });
        applyRootRelocation(data.relocated_from, data.root);
        currentRoot = data.root;
        $('rootPath').value = data.root;
        localStorage.setItem('video_catalog_root', data.root);
        await loadTree({ announceInitialCache: false, processScreenshots: false });

        const workerError = data.cache?.screenshot_worker_error;
        const pending = Number(data.cache?.stats?.screenshots_pending || 0);
        if (workerError) {
            setScreenshotProgress(`Фоновое создание кадров не запущено: ${workerError}`);
        } else if (data.cache?.screenshot_worker) {
            updateScreenshotWorkerProgress(data.cache.screenshot_worker);
            monitorScreenshotWorker(currentRoot, true).catch(() => {});
        } else if (pending > 0) {
            processPendingScreenshots(currentRoot).catch((error) => setScreenshotProgress(error.message));
        }

        const screenshotText = pending > 0
            ? ' Создание кадров запущено отдельным фоновым процессом и продолжится при свернутой или закрытой вкладке.'
            : '';
        showMessage(`Кэш обновлен. ${cacheStatsText(data.cache.stats)}${screenshotText}`, Boolean(workerError));
        if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
    } catch (error) {
        showMessage(error.message);
    } finally {
        button.disabled = false;
    }
}

async function deleteCurrentCache() {
    const root = $('rootPath').value.trim();
    if (!root) return showMessage('Сначала укажите папку, кэш которой нужно удалить.');

    const confirmed = window.confirm(
        'Удалить кэш выбранной библиотеки?\n\n' +
        'Будут удалены записи кэша, категории этой библиотеки, кадры, миниатюры, аудио, вырезанные фрагменты и транскрипты.\n' +
        'Исходные видеофайлы удалены НЕ будут.\n\n' +
        'Продолжить?'
    );
    if (!confirmed) return;

    const button = $('deleteCacheBtn');
    button.disabled = true;
    showMessage('Удаляю кэш выбранной библиотеки...', false);
    try {
        const data = await postForm('delete_cache', { root });
        if (screenshotMonitorTimer) clearTimeout(screenshotMonitorTimer);
        screenshotMonitorTimer = null;
        screenshotMonitorRoot = '';
        currentRoot = '';
        currentTree = null;
        categories = [];
        selectedItems.clear();
        localStorage.removeItem('video_catalog_root');
        $('tree').innerHTML = 'Кэш библиотеки удален.';
        $('tree').classList.add('empty');
        $('searchInput').value = '';
        $('searchResults').classList.add('hidden');
        $('resultsList').innerHTML = '';
        fillCategorySelect($('categoryFilter'), '', 'Все категории');
        fillCategorySelect($('cardCategory'), '', 'Без категории');
        $('cacheInfo').textContent = 'Кэш выбранной библиотеки удален.';
        setScreenshotProgress('');
        updateSelectionToolbar();
        const suffix = data.physical_cache_deleted
            ? ' Служебная папка с производными файлами также удалена.'
            : ' Папка на диске недоступна или служебных файлов уже не было.';
        showMessage('Кэш удален. Исходные видео не затронуты.' + suffix, false);
    } catch (error) {
        showMessage(error.message);
    } finally {
        button.disabled = false;
    }
}

function clientPathKey(type, path) {
    return `${type}|${String(path).replace(/\//g, '\\').replace(/[\\/]+$/, '').toLocaleLowerCase()}`;
}

function selectedPayload() {
    return Array.from(selectedItems.values()).map(({ type, path }) => ({ type, path }));
}

function isSelected(type, path) {
    return selectedItems.has(clientPathKey(type, path));
}

function setSelected(type, path, selected) {
    const key = clientPathKey(type, path);
    if (selected) selectedItems.set(key, { type, path });
    else selectedItems.delete(key);
    syncSelectionUi();
}

function clearSelection() {
    selectedItems.clear();
    syncSelectionUi();
}

function syncSelectionUi() {
    document.querySelectorAll('[data-item-type][data-path]').forEach((row) => {
        const selected = isSelected(row.dataset.itemType, row.dataset.path);
        row.classList.toggle('selected', selected);
        const checkbox = row.querySelector('.node-checkbox');
        if (checkbox) checkbox.checked = selected;
    });
    updateSelectionToolbar();
}

function updateSelectionToolbar() {
    const count = selectedItems.size;
    $('selectionToolbar').classList.toggle('hidden', count === 0);
    $('selectionCount').textContent = `Выбрано: ${count}`;
    const selected = Array.from(selectedItems.values());
    const filesOnly = selected.length > 0 && selected.every((item) => item.type === 'file');
    const canMerge = selected.length >= 2 && filesOnly;
    $('mergeSelectedBtn').classList.toggle('hidden', !canMerge);
    if ($('bulkCategorySelect')) $('bulkCategorySelect').classList.toggle('hidden', !filesOnly);

    const searchToolbar = $('searchSelectionToolbar');
    const searchVisible = !$('searchResults').classList.contains('hidden');
    if (searchToolbar) searchToolbar.classList.toggle('hidden', count === 0 || !searchVisible);
    if ($('searchSelectionCount')) $('searchSelectionCount').textContent = `Выбрано: ${count}`;
    if ($('searchMergeSelectedBtn')) $('searchMergeSelectedBtn').classList.toggle('hidden', !canMerge);
    if ($('searchBulkCategorySelect')) $('searchBulkCategorySelect').classList.toggle('hidden', !filesOnly);
}

function countTreeFiles(node) {
    if (!node) return 0;
    if (node.type === 'file') return 1;
    return (node.children || []).reduce((sum, child) => sum + countTreeFiles(child), 0);
}

function countTreeDirectories(node, includeRoot = false) {
    if (!node || node.type !== 'dir') return 0;
    let total = includeRoot ? 1 : 0;
    for (const child of node.children || []) {
        if (child.type === 'dir') total += countTreeDirectories(child, true);
    }
    return total;
}

function normalizeNodePath(path) {
    return String(path || '').replace(/\\/g, '/').replace(/\/+$/g, '').toLocaleLowerCase();
}

function findDirectoryNode(node, path) {
    if (!node || node.type !== 'dir') return null;
    if (normalizeNodePath(node.path || node.name) === normalizeNodePath(path)) return node;
    for (const child of node.children || []) {
        if (child.type !== 'dir') continue;
        const found = findDirectoryNode(child, path);
        if (found) return found;
    }
    return null;
}

function findDirectoryChain(node, path, chain = []) {
    if (!node || node.type !== 'dir') return null;
    const nextChain = [...chain, node];
    if (normalizeNodePath(node.path || node.name) === normalizeNodePath(path)) return nextChain;
    for (const child of node.children || []) {
        if (child.type !== 'dir') continue;
        const found = findDirectoryChain(child, path, nextChain);
        if (found) return found;
    }
    return null;
}

function directoryMatchesFolderSearch(node) {
    if (!node || node.type !== 'dir') return false;
    const query = folderSearchQuery.trim().toLocaleLowerCase();
    if (!query) return true;
    if (String(node.name || '').toLocaleLowerCase().includes(query)) return true;
    return (node.children || []).some((child) => child.type === 'dir' && directoryMatchesFolderSearch(child));
}

function getCurrentDirectoryNode(tree) {
    if (!tree || tree.type !== 'dir') return null;
    const found = currentDirectoryPath ? findDirectoryNode(tree, currentDirectoryPath) : null;
    if (found) return found;
    currentDirectoryPath = tree.path || tree.name || '';
    localStorage.setItem('video_catalog_selected_dir', currentDirectoryPath);
    return tree;
}

function setCurrentDirectory(path) {
    currentDirectoryPath = String(path || '').trim();
    localStorage.setItem('video_catalog_selected_dir', currentDirectoryPath);
    if (currentTree) renderTree(currentTree);
}

function fileDisplayName(node) {
    return String(node?.title || node?.name || '').trim();
}

function sortFilesForBrowser(items) {
    const files = [...(items || [])];
    const byName = (a, b) => fileDisplayName(a).localeCompare(fileDisplayName(b), undefined, { sensitivity: 'base', numeric: true });
    const byDuration = (a, b) => (Number(a.duration_seconds) || 0) - (Number(b.duration_seconds) || 0);
    switch (currentSortMode) {
        case 'name_desc':
            return files.sort((a, b) => byName(b, a));
        case 'duration_asc':
            return files.sort((a, b) => byDuration(a, b) || byName(a, b));
        case 'duration_desc':
            return files.sort((a, b) => byDuration(b, a) || byName(a, b));
        case 'name_asc':
        default:
            return files.sort((a, b) => byName(a, b));
    }
}

function updateSortModeControl() {
    const select = $('sortMode');
    if (select) select.value = currentSortMode;
}

function setSortMode(mode) {
    const allowed = new Set(['name_asc', 'name_desc', 'duration_asc', 'duration_desc']);
    currentSortMode = allowed.has(mode) ? mode : 'name_asc';
    localStorage.setItem('video_catalog_sort_mode', currentSortMode);
    updateSortModeControl();
    if (currentTree) renderTree(currentTree);
}

function updateViewModeButtons() {
    const isTiles = currentViewMode === 'tiles';
    $('listViewBtn').classList.toggle('active', !isTiles);
    $('tileViewBtn').classList.toggle('active', isTiles);
    $('listViewBtn').setAttribute('aria-pressed', String(!isTiles));
    $('tileViewBtn').setAttribute('aria-pressed', String(isTiles));
}

function setViewMode(mode) {
    const next = mode === 'tiles' ? 'tiles' : 'list';
    if (currentViewMode === next) return;
    currentViewMode = next;
    localStorage.setItem('video_catalog_view_mode', currentViewMode);
    updateViewModeButtons();
    if (currentTree) renderTree(currentTree);
}

function ensureThumbnailObserver() {
    if (thumbnailObserver || !('IntersectionObserver' in window)) return thumbnailObserver;
    thumbnailObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const image = entry.target;
            const src = image.dataset.src || '';
            if (src) {
                image.src = src;
                image.removeAttribute('data-src');
            }
            thumbnailObserver.unobserve(image);
        }
    }, { root: null, rootMargin: '280px 0px', threshold: 0.01 });
    return thumbnailObserver;
}

function observeLazyThumbnails(scope = document) {
    const images = scope.querySelectorAll ? scope.querySelectorAll('img.tile-thumbnail[data-src]') : [];
    const observer = ensureThumbnailObserver();
    if (observer) {
        images.forEach((image) => observer.observe(image));
        return;
    }
    // Fallback for very old browsers: only load thumbnails that are currently visible.
    images.forEach((image) => {
        if (image.offsetParent === null) return;
        const src = image.dataset.src || '';
        if (src) image.src = src;
        image.removeAttribute('data-src');
    });
}

function formatVideoDuration(seconds) {
    const value = Number(seconds);
    if (!Number.isFinite(value) || value <= 0) return '—';
    const total = Math.max(0, Math.round(value));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;
    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
        : `${minutes}:${String(secs).padStart(2, '0')}`;
}

function makePinButton(node, extraClass = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `pin-video-button${extraClass ? ' ' + extraClass : ''}`;
    button.textContent = node.is_pinned ? '★' : '☆';
    button.title = node.is_pinned ? 'Открепить видео' : 'Закрепить видео';
    button.setAttribute('aria-label', button.title);
    button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        await toggleVideoPinned(node.token, !Boolean(node.is_pinned));
    });
    return button;
}

function updatePinnedStateInTree(node, token, pinned) {
    if (!node) return false;
    if (node.type === 'file' && node.token === token) {
        node.is_pinned = Boolean(pinned);
        return true;
    }
    let changed = false;
    for (const child of node.children || []) {
        if (updatePinnedStateInTree(child, token, pinned)) changed = true;
    }
    return changed;
}

async function toggleVideoPinned(token, pinned) {
    if (!token) return;
    try {
        const data = await postForm('set_video_pinned', { token, pinned: pinned ? '1' : '0' });
        if (currentTree) {
            updatePinnedStateInTree(currentTree, token, Boolean(data.pinned));
            renderTree(currentTree);
        }
        const cardToken = $('cardToken')?.value || '';
        if (cardToken === token) updateCardPinButton(Boolean(data.pinned));
        if (!$('searchResults').classList.contains('hidden')) await doSearch();
    } catch (error) {
        showMessage(error.message);
    }
}

function updateCardPinButton(pinned) {
    const button = $('pinFromModal');
    if (!button) return;
    button.dataset.pinned = pinned ? '1' : '0';
    button.textContent = pinned ? '★' : '☆';
    button.title = pinned ? 'Открепить видео' : 'Закрепить видео';
    button.setAttribute('aria-label', button.title);
    button.classList.toggle('selected', Boolean(pinned));
}

function collectPinnedVideoNodes(node, result = []) {
    if (!node) return result;
    if (node.type === 'file') {
        if (node.is_pinned) result.push(node);
        return result;
    }
    for (const child of node.children || []) collectPinnedVideoNodes(child, result);
    return result;
}

function renderPinnedVideos(tree) {
    const section = $('pinnedVideosSection');
    const box = $('pinnedVideos');
    const count = $('pinnedVideosCount');
    if (!section || !box || !count) return;

    const items = collectPinnedVideoNodes(tree, []);
    section.classList.toggle('hidden', items.length === 0);
    count.textContent = items.length ? `(${items.length})` : '';
    box.innerHTML = '';
    if (!items.length) return;

    if (currentViewMode === 'tiles') {
        const grid = document.createElement('div');
        grid.className = 'video-tile-grid pinned-video-grid';
        items.forEach((item) => grid.appendChild(renderVideoTile(item)));
        box.appendChild(grid);
        if (section.open) window.requestAnimationFrame(() => observeLazyThumbnails(box));
        return;
    }

    const list = document.createElement('ul');
    list.className = 'pinned-video-list';
    items.forEach((item) => list.appendChild(renderListNode(item)));
    box.appendChild(list);
}

function renderDirectoryTreeNode(node, isRoot = false) {
    if (!directoryMatchesFolderSearch(node)) return null;

    const listItem = document.createElement('li');
    listItem.className = 'dir-node dir-only-node';

    const childDirs = Array.isArray(node.children)
        ? node.children.filter((child) => child.type === 'dir' && directoryMatchesFolderSearch(child))
        : [];
    const hasChildren = childDirs.length > 0;
    const dirPath = node.path || node.name;
    const searchActive = folderSearchQuery.trim() !== '';
    const isExpanded = searchActive || isRoot || expandedDirs.has(dirPath);
    const isActive = normalizeNodePath(dirPath) === normalizeNodePath(currentDirectoryPath);

    const row = document.createElement('div');
    row.className = `node-row dir-row dir-tree-row${isRoot ? ' root-row' : ''}${isActive ? ' active-dir' : ''}`;
    row.dataset.path = dirPath;

    if (!isRoot) {
        row.appendChild(makeSelectionCheckbox('dir', dirPath));
        attachItemInteractions(row, { type: 'dir', path: dirPath });
    }

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'dir-toggle';
    toggle.textContent = hasChildren ? (isExpanded ? '▾' : '▸') : '•';
    toggle.disabled = !hasChildren;
    toggle.title = hasChildren ? (isExpanded ? 'Свернуть' : 'Развернуть') : 'Пустая папка';

    const icon = document.createElement('span');
    icon.className = 'dir-icon';
    icon.textContent = isExpanded ? '📂' : '📁';

    const name = document.createElement('button');
    name.type = 'button';
    name.draggable = false;
    name.className = 'dir-name';
    name.textContent = node.name;
    name.title = dirPath;

    const children = document.createElement('ul');
    children.className = 'dir-children';
    children.classList.toggle('hidden', !isExpanded);
    childDirs.forEach((child) => {
        const rendered = renderDirectoryTreeNode(child);
        if (rendered) children.appendChild(rendered);
    });

    const toggleDirectory = () => {
        if (!hasChildren) return;
        const willExpand = children.classList.contains('hidden');
        children.classList.toggle('hidden', !willExpand);
        toggle.textContent = willExpand ? '▾' : '▸';
        icon.textContent = willExpand ? '📂' : '📁';
        if (willExpand) expandedDirs.add(dirPath);
        else expandedDirs.delete(dirPath);
        saveExpandedDirs();
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleDirectory();
    });

    name.addEventListener('click', (event) => {
        event.stopPropagation();
        setCurrentDirectory(dirPath);
    });

    row.addEventListener('click', (event) => {
        if (event.target.closest('.node-checkbox') || event.target.closest('.dir-toggle')) return;
        setCurrentDirectory(dirPath);
    });

    row.append(toggle, icon, name);
    attachDropTarget(row, dirPath);
    row.addEventListener('contextmenu', (event) => {
        if (!isRoot) return;
        event.preventDefault();
        event.stopPropagation();
        showContextMenu(event.clientX, event.clientY, { type: 'root', path: dirPath });
    });

    listItem.appendChild(row);
    listItem.appendChild(children);
    return listItem;
}

function renderCurrentDirectoryView(tree) {
    const panel = $('fileBrowser');
    const title = $('currentDirTitle');
    const meta = $('currentDirMeta');
    const subfoldersBox = $('currentDirSubfolders');
    panel.innerHTML = '';
    subfoldersBox.innerHTML = '';

    const currentDir = getCurrentDirectoryNode(tree);
    if (!currentDir) {
        title.textContent = 'Файлы в папке';
        meta.textContent = 'Выберите папку слева, чтобы увидеть файлы.';
        panel.classList.add('empty');
        panel.textContent = 'Видео не найдены';
        subfoldersBox.classList.add('hidden');
        return;
    }

    const childDirs = (currentDir.children || []).filter((child) => child.type === 'dir');
    const childFiles = sortFilesForBrowser((currentDir.children || []).filter((child) => child.type === 'file'));

    title.textContent = currentDir.name || 'Файлы в папке';
    meta.innerHTML = '';
    const chain = findDirectoryChain(tree, currentDir.path || currentDir.name) || [currentDir];
    const breadcrumb = document.createElement('nav');
    breadcrumb.className = 'path-breadcrumb';
    breadcrumb.setAttribute('aria-label', 'Путь к папке');
    chain.forEach((dir, index) => {
        if (index > 0) {
            const separator = document.createElement('span');
            separator.className = 'path-breadcrumb-separator';
            separator.textContent = '›';
            breadcrumb.appendChild(separator);
        }
        const isLast = index === chain.length - 1;
        if (isLast) {
            const current = document.createElement('span');
            current.className = 'path-breadcrumb-current';
            current.textContent = dir.name || dir.path || '';
            breadcrumb.appendChild(current);
        } else {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'path-breadcrumb-link';
            button.textContent = dir.name || dir.path || '';
            button.title = dir.path || dir.name || '';
            button.addEventListener('click', () => setCurrentDirectory(dir.path || dir.name));
            breadcrumb.appendChild(button);
        }
    });
    const stats = document.createElement('span');
    stats.className = 'current-dir-stats';
    stats.textContent = `Файлов: ${childFiles.length} • Подпапок: ${childDirs.length}`;
    meta.append(breadcrumb, stats);

    if (childDirs.length) {
        const label = document.createElement('div');
        label.className = 'subfolder-caption';
        label.textContent = 'Подпапки';
        subfoldersBox.appendChild(label);
        childDirs.forEach((dir) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'subfolder-pill';
            button.textContent = dir.name;
            button.title = dir.path || dir.name;
            button.addEventListener('click', () => setCurrentDirectory(dir.path || dir.name));
            subfoldersBox.appendChild(button);
        });
        subfoldersBox.classList.remove('hidden');
    } else {
        subfoldersBox.classList.add('hidden');
    }

    panel.classList.toggle('empty', childFiles.length === 0);
    if (!childFiles.length) {
        const empty = document.createElement('div');
        empty.className = 'file-browser-empty';
        empty.textContent = 'В этой папке пока нет видеофайлов.';
        panel.appendChild(empty);
        return;
    }

    if (currentViewMode === 'tiles') {
        const grid = document.createElement('div');
        grid.className = 'video-tile-grid file-browser-grid';
        childFiles.forEach((file) => grid.appendChild(renderVideoTile(file)));
        panel.appendChild(grid);
        window.requestAnimationFrame(() => observeLazyThumbnails(panel));
        return;
    }

    const list = document.createElement('ul');
    list.className = 'file-browser-list';
    childFiles.forEach((file) => list.appendChild(renderListNode(file)));
    panel.appendChild(list);
}

function renderTree(tree) {
    const container = $('tree');
    const browser = $('fileBrowser');
    container.innerHTML = '';
    browser.innerHTML = '';

    const list = document.createElement('ul');
    list.className = 'directory-tree-list';
    const rootNode = renderDirectoryTreeNode(tree, true);
    if (rootNode) {
        list.appendChild(rootNode);
        container.appendChild(list);
    } else {
        const empty = document.createElement('div');
        empty.className = 'tree-search-empty';
        empty.textContent = 'Папки не найдены.';
        container.appendChild(empty);
    }

    renderPinnedVideos(tree);
    renderCurrentDirectoryView(tree);

    const fileCount = countTreeFiles(tree);
    const folderCount = countTreeDirectories(tree, false);
    $('fileCounter').textContent = fileCount ? `${fileCount} видео` : 'Видео не найдены';
    if ($('folderCounter')) $('folderCounter').textContent = `${folderCount} папок`;
    updateViewModeButtons();
    updateSortModeControl();
    updateSelectionToolbar();
}

function saveExpandedDirs() {
    localStorage.setItem('video_catalog_expanded_dirs', JSON.stringify([...expandedDirs]));
}

function makeSelectionCheckbox(type, path) {
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'node-checkbox';
    checkbox.checked = isSelected(type, path);
    checkbox.title = 'Выбрать для групповой операции';
    checkbox.addEventListener('click', (event) => event.stopPropagation());
    checkbox.addEventListener('change', () => setSelected(type, path, checkbox.checked));
    return checkbox;
}

function attachItemInteractions(row, item) {
    row.dataset.itemType = item.type;
    row.dataset.path = item.path;
    row.draggable = true;
    row.classList.toggle('selected', isSelected(item.type, item.path));

    row.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (!isSelected(item.type, item.path)) {
            clearSelection();
            setSelected(item.type, item.path, true);
        }
        showContextMenu(event.clientX, event.clientY, item);
    });

    row.addEventListener('dragstart', (event) => {
        if (!isSelected(item.type, item.path)) {
            clearSelection();
            setSelected(item.type, item.path, true);
        }
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', JSON.stringify(selectedPayload()));
        row.classList.add('dragging');
    });
    row.addEventListener('dragend', () => row.classList.remove('dragging'));
}

function attachDropTarget(row, path) {
    row.addEventListener('dragover', (event) => {
        if (!selectedItems.size || isInvalidDestination(path)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        row.classList.add('drop-target');
    });
    row.addEventListener('dragleave', () => row.classList.remove('drop-target'));
    row.addEventListener('drop', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        row.classList.remove('drop-target');
        await moveSelectedTo(path);
    });
}

function renderListNode(node, isRoot = false) {
    const listItem = document.createElement('li');

    if (node.type === 'dir') {
        const hasChildren = Array.isArray(node.children) && node.children.length > 0;
        const dirPath = node.path || node.name;
        const isExpanded = isRoot || expandedDirs.has(dirPath);
        listItem.className = 'dir-node';

        const row = document.createElement('div');
        row.className = `node-row dir-row${isRoot ? ' root-row' : ''}`;
        row.dataset.path = dirPath;

        if (!isRoot) {
            row.appendChild(makeSelectionCheckbox('dir', dirPath));
            attachItemInteractions(row, { type: 'dir', path: dirPath });
        }

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'dir-toggle';
        toggle.textContent = hasChildren ? (isExpanded ? '▾' : '▸') : '•';
        toggle.disabled = !hasChildren;
        toggle.title = hasChildren ? 'Свернуть/развернуть' : 'Пустая папка';

        const icon = document.createElement('span');
        icon.className = 'dir-icon';
        icon.textContent = isExpanded ? '📂' : '📁';

        const name = document.createElement('button');
        name.type = 'button';
        name.draggable = false;
        name.className = 'dir-name';
        name.textContent = node.name;
        name.title = dirPath;

        const toggleDirectory = () => {
            if (!hasChildren) return;
            const children = listItem.querySelector(':scope > .dir-children');
            const willExpand = children.classList.contains('hidden');
            children.classList.toggle('hidden', !willExpand);
            toggle.textContent = willExpand ? '▾' : '▸';
            icon.textContent = willExpand ? '📂' : '📁';
            if (willExpand) expandedDirs.add(dirPath);
            else expandedDirs.delete(dirPath);
            saveExpandedDirs();
        };
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            toggleDirectory();
        });
        name.addEventListener('click', (event) => {
            event.stopPropagation();
            toggleDirectory();
        });

        row.append(toggle, icon, name);
        attachDropTarget(row, dirPath);
        row.addEventListener('contextmenu', (event) => {
            if (!isRoot) return;
            event.preventDefault();
            event.stopPropagation();
            showContextMenu(event.clientX, event.clientY, { type: 'root', path: dirPath });
        });
        listItem.appendChild(row);

        const children = document.createElement('ul');
        children.className = 'dir-children';
        children.classList.toggle('hidden', !isExpanded);
        for (const child of node.children) children.appendChild(renderListNode(child));
        listItem.appendChild(children);
        return listItem;
    }

    const row = document.createElement('div');
    row.className = 'node-row file-row';
    row.appendChild(makeSelectionCheckbox('file', node.path));
    attachItemInteractions(row, { type: 'file', path: node.path, token: node.token });

    const name = document.createElement('button');
    name.type = 'button';
    name.draggable = false;
    name.className = 'file-name';
    name.textContent = node.title ? `${node.title} (${node.name})` : node.name;
    name.title = node.path;
    name.onclick = () => openCard(node.token);
    row.appendChild(name);

    if (node.category_name) {
        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = node.category_name;
        row.appendChild(badge);
    }

    const controls = document.createElement('div');
    controls.className = 'file-row-actions';

    const duration = document.createElement('span');
    duration.className = 'video-duration';
    duration.textContent = formatVideoDuration(node.duration_seconds);
    duration.title = 'Длительность видео';

    const view = document.createElement('button');
    view.type = 'button';
    view.className = 'button view-link';
    view.draggable = false;
    view.textContent = 'Просмотр';
    view.addEventListener('click', (event) => {
        event.stopPropagation();
        openVideo(node.token, node.title || node.name);
    });

    controls.append(duration, view, makePinButton(node));
    row.appendChild(controls);
    listItem.appendChild(row);
    return listItem;
}

function renderVideoTile(node) {
    const tile = document.createElement('div');
    tile.className = 'video-tile';
    attachItemInteractions(tile, { type: 'file', path: node.path, token: node.token });

    const checkbox = makeSelectionCheckbox('file', node.path);
    checkbox.classList.add('tile-checkbox');
    tile.appendChild(checkbox);

    const preview = document.createElement('button');
    preview.type = 'button';
    preview.className = 'tile-preview';
    preview.draggable = false;
    preview.title = node.title || node.name;
    preview.addEventListener('click', (event) => {
        event.stopPropagation();
        openCard(node.token);
    });

    if (node.thumbnail_url) {
        const image = document.createElement('img');
        image.className = 'tile-thumbnail';
        image.alt = node.title || node.name;
        image.dataset.src = node.thumbnail_url;
        image.decoding = 'async';
        image.addEventListener('error', () => {
            image.remove();
            if (!preview.querySelector('.tile-placeholder')) {
                const placeholder = document.createElement('span');
                placeholder.className = 'tile-placeholder';
                placeholder.textContent = 'Нет миниатюры';
                preview.appendChild(placeholder);
            }
        }, { once: true });
        preview.appendChild(image);
    } else {
        const placeholder = document.createElement('span');
        placeholder.className = 'tile-placeholder';
        placeholder.textContent = 'Нет миниатюры';
        preview.appendChild(placeholder);
    }

    const info = document.createElement('div');
    info.className = 'tile-info-row';
    const title = document.createElement('button');
    title.type = 'button';
    title.className = 'tile-title';
    title.draggable = false;
    title.textContent = node.title || node.name;
    title.title = node.title ? `${node.title} (${node.name})` : node.name;
    title.addEventListener('click', (event) => {
        event.stopPropagation();
        openCard(node.token);
    });
    const duration = document.createElement('span');
    duration.className = 'video-duration tile-duration';
    duration.textContent = formatVideoDuration(node.duration_seconds);
    duration.title = 'Длительность видео';
    info.append(title, duration);

    const actions = document.createElement('div');
    actions.className = 'tile-actions';
    const view = document.createElement('button');
    view.type = 'button';
    view.className = 'button tile-view-button';
    view.textContent = 'Просмотр';
    view.addEventListener('click', (event) => {
        event.stopPropagation();
        openVideo(node.token, node.title || node.name);
    });
    actions.append(view, makePinButton(node));

    tile.append(preview, info, actions);
    if (node.category_name) {
        const badge = document.createElement('span');
        badge.className = 'badge tile-category';
        badge.textContent = node.category_name;
        tile.appendChild(badge);
    }
    return tile;
}

function renderTileDirectory(node, isRoot = false) {
    const listItem = document.createElement('li');
    const childNodes = Array.isArray(node.children) ? node.children : [];
    const childDirs = childNodes.filter((child) => child.type === 'dir');
    const childFiles = childNodes.filter((child) => child.type === 'file');
    const hasChildren = childNodes.length > 0;
    const dirPath = node.path || node.name;
    const isExpanded = isRoot || expandedDirs.has(dirPath);
    listItem.className = 'dir-node tile-dir-node';

    const row = document.createElement('div');
    row.className = `node-row dir-row${isRoot ? ' root-row' : ''}`;
    row.dataset.path = dirPath;

    if (!isRoot) {
        row.appendChild(makeSelectionCheckbox('dir', dirPath));
        attachItemInteractions(row, { type: 'dir', path: dirPath });
    }

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'dir-toggle';
    toggle.textContent = hasChildren ? (isExpanded ? '▾' : '▸') : '•';
    toggle.disabled = !hasChildren;

    const icon = document.createElement('span');
    icon.className = 'dir-icon';
    icon.textContent = isExpanded ? '📂' : '📁';

    const name = document.createElement('button');
    name.type = 'button';
    name.draggable = false;
    name.className = 'dir-name';
    name.textContent = node.name;
    name.title = dirPath;

    const children = document.createElement('div');
    children.className = 'dir-children tile-dir-children';
    children.classList.toggle('hidden', !isExpanded);

    const toggleDirectory = () => {
        if (!hasChildren) return;
        const willExpand = children.classList.contains('hidden');
        children.classList.toggle('hidden', !willExpand);
        toggle.textContent = willExpand ? '▾' : '▸';
        icon.textContent = willExpand ? '📂' : '📁';
        if (willExpand) {
            expandedDirs.add(dirPath);
            window.requestAnimationFrame(() => observeLazyThumbnails(children));
        } else {
            expandedDirs.delete(dirPath);
        }
        saveExpandedDirs();
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleDirectory();
    });
    name.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleDirectory();
    });

    row.append(toggle, icon, name);
    attachDropTarget(row, dirPath);
    row.addEventListener('contextmenu', (event) => {
        if (!isRoot) return;
        event.preventDefault();
        event.stopPropagation();
        showContextMenu(event.clientX, event.clientY, { type: 'root', path: dirPath });
    });
    listItem.appendChild(row);

    if (childDirs.length) {
        const dirList = document.createElement('ul');
        dirList.className = 'tile-subdirs';
        childDirs.forEach((child) => dirList.appendChild(renderTileDirectory(child)));
        children.appendChild(dirList);
    }

    if (childFiles.length) {
        const grid = document.createElement('div');
        grid.className = 'video-tile-grid';
        childFiles.forEach((child) => grid.appendChild(renderVideoTile(child)));
        children.appendChild(grid);
    }

    listItem.appendChild(children);
    return listItem;
}

function flattenDirectories(tree) {
    const result = [];
    const walk = (node, depth = 0) => {
        if (node.type !== 'dir') return;
        result.push({ path: node.path, name: node.name, depth });
        for (const child of node.children || []) if (child.type === 'dir') walk(child, depth + 1);
    };
    if (tree) walk(tree);
    return result;
}

function isInvalidDestination(path) {
    const normalized = String(path).replace(/\//g, '\\').replace(/[\\/]+$/, '').toLocaleLowerCase();
    for (const item of selectedItems.values()) {
        if (item.type !== 'dir') continue;
        const source = String(item.path).replace(/\//g, '\\').replace(/[\\/]+$/, '').toLocaleLowerCase();
        if (normalized === source || normalized.startsWith(`${source}\\`)) return true;
    }
    return false;
}

function formatMergeProgressTime(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0
        ? `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function renderMergeProgressJobs(jobs) {
    const panel = $('mergeProgressPanel');
    const box = $('mergeProgressJobs');
    if (!panel || !box) return;
    const active = Array.isArray(jobs) ? jobs : [];
    box.innerHTML = '';
    if (!active.length) {
        panel.classList.add('hidden');
        return;
    }
    for (const job of active) {
        const percent = Math.max(0, Math.min(100, Number(job.progress_percent) || 0));
        const row = document.createElement('div');
        row.className = 'merge-progress-job';

        const top = document.createElement('div');
        top.className = 'merge-progress-job-top';
        const title = document.createElement('strong');
        title.textContent = job.output_name || `Задание #${job.id}`;
        const pct = document.createElement('span');
        pct.className = 'merge-progress-percent';
        pct.textContent = `${Math.round(percent)}%`;
        top.append(title, pct);

        const track = document.createElement('div');
        track.className = 'merge-progress-track';
        const fill = document.createElement('div');
        fill.className = 'merge-progress-fill';
        fill.style.width = `${percent}%`;
        track.appendChild(fill);

        const detail = document.createElement('div');
        detail.className = 'merge-progress-detail';
        const stage = job.progress_stage || (job.status === 'pending' ? 'Ожидание запуска' : 'FFmpeg');
        const message = job.message || '';
        let time = '';
        const total = Number(job.progress_total_seconds) || 0;
        const done = Number(job.progress_seconds) || 0;
        if (total > 0) time = `${formatMergeProgressTime(done)} / ${formatMergeProgressTime(total)}`;
        const heartbeatAge = Number(job.heartbeat_age_seconds);
        const stalled = job.status === 'running' && Number.isFinite(heartbeatAge) && heartbeatAge > 30
            ? `Нет обновления прогресса ${Math.round(heartbeatAge)} сек.`
            : '';
        detail.textContent = [stage, message, time, stalled].filter(Boolean).join(' · ');
        detail.classList.toggle('merge-progress-stalled', Boolean(stalled));

        row.append(top, track, detail);
        box.appendChild(row);
    }
    panel.classList.remove('hidden');
}

async function monitorMergeJobs(root = currentRoot, immediate = false) {
    const normalizedRoot = String(root || '').trim();
    if (mergeMonitorTimer) {
        clearTimeout(mergeMonitorTimer);
        mergeMonitorTimer = null;
    }
    mergeMonitorRoot = normalizedRoot;
    if (!normalizedRoot) {
        renderMergeProgressJobs([]);
        return;
    }
    try {
        const params = new URLSearchParams({ action: 'active_jobs', root: normalizedRoot });
        const data = await fetchJson(`utilities/video_merge.php?${params.toString()}`);
        if (mergeMonitorRoot !== normalizedRoot) return;
        renderMergeProgressJobs(data.jobs || []);
    } catch (error) {
        console.warn('Merge progress monitor:', error);
    }
    if (mergeMonitorRoot !== normalizedRoot) return;
    mergeMonitorTimer = setTimeout(() => monitorMergeJobs(normalizedRoot), immediate ? 1200 : 2000);
}

function mergeDisplayName(path) {
    const parts = String(path || '').split(/[\\/]+/).filter(Boolean);
    return parts.length ? parts[parts.length - 1] : String(path || '');
}

function defaultMergeName() {
    const now = new Date();
    const p = (v) => String(v).padStart(2, '0');
    return `Склейка_${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}_${p(now.getHours())}-${p(now.getMinutes())}`;
}

function renderMergeItems() {
    const box = $('mergeItems');
    box.innerHTML = '';
    mergeItems.forEach((item, index) => {
        const row = document.createElement('div');
        row.className = 'merge-item';
        row.draggable = true;
        row.dataset.index = String(index);

        const handle = document.createElement('span');
        handle.className = 'merge-drag-handle';
        handle.textContent = '☰';
        handle.title = 'Перетащить';

        const order = document.createElement('span');
        order.className = 'merge-order';
        order.textContent = `${index + 1}.`;

        const name = document.createElement('span');
        name.className = 'merge-item-name';
        name.textContent = mergeDisplayName(item.path);
        name.title = item.path;

        const controls = document.createElement('span');
        controls.className = 'merge-item-controls';
        const up = document.createElement('button');
        up.type = 'button';
        up.textContent = '↑';
        up.title = 'Выше';
        up.disabled = index === 0;
        up.onclick = () => moveMergeItem(index, index - 1);
        const down = document.createElement('button');
        down.type = 'button';
        down.textContent = '↓';
        down.title = 'Ниже';
        down.disabled = index === mergeItems.length - 1;
        down.onclick = () => moveMergeItem(index, index + 1);
        controls.append(up, down);

        row.addEventListener('dragstart', (event) => {
            row.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(index));
        });
        row.addEventListener('dragend', () => row.classList.remove('dragging'));
        row.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            row.classList.add('drag-over');
        });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', (event) => {
            event.preventDefault();
            row.classList.remove('drag-over');
            const from = Number(event.dataTransfer.getData('text/plain'));
            const to = Number(row.dataset.index);
            if (Number.isInteger(from) && Number.isInteger(to)) moveMergeItem(from, to);
        });

        row.append(handle, order, name, controls);
        box.appendChild(row);
    });
}

function moveMergeItem(from, to) {
    if (from === to || from < 0 || to < 0 || from >= mergeItems.length || to >= mergeItems.length) return;
    const [item] = mergeItems.splice(from, 1);
    mergeItems.splice(to, 0, item);
    renderMergeItems();
}

function openMergeModal() {
    const selected = Array.from(selectedItems.values());
    if (selected.length < 2 || !selected.every((item) => item.type === 'file')) {
        return showMessage('Для склейки выберите минимум два видео и не выбирайте папки.');
    }
    mergeItems = selected.map((item) => ({ path: item.path }));
    renderMergeItems();
    $('mergeOutputName').value = defaultMergeName();
    $('mergeMode').value = 'auto';
    $('mergeResolution').value = 'auto';
    $('mergeAspect').value = 'fit';
    $('mergeQuality').value = 'normal';
    $('mergeStatus').textContent = '';
    $('startMergeBtn').disabled = false;
    $('mergeModal').classList.remove('hidden');
    $('mergeModal').setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('mergeOutputName').select(), 0);
}

function closeMergeModal() {
    $('mergeModal').classList.add('hidden');
    $('mergeModal').setAttribute('aria-hidden', 'true');
    mergeItems = [];
}

async function startMerge() {
    if (mergeItems.length < 2) return showMessage('Для склейки нужно минимум два видео.');
    const outputName = $('mergeOutputName').value.trim();
    if (!outputName) {
        $('mergeStatus').textContent = 'Укажите название выходного видео.';
        $('mergeOutputName').focus();
        return;
    }
    const button = $('startMergeBtn');
    button.disabled = true;
    $('mergeStatus').textContent = 'Запускаю FFmpeg…';
    try {
        const data = await videoMergePost('start', {
            root: currentRoot,
            items: JSON.stringify(mergeItems),
            output_name: outputName,
            mode: $('mergeMode').value,
            resolution: $('mergeResolution').value,
            aspect: $('mergeAspect').value,
            quality: $('mergeQuality').value,
        });
        const jobId = Number(data.job?.id || 0);
        const name = data.job?.output_name || outputName;
        closeMergeModal();
        clearSelection();
        showMessage(`Склейка «${name}» запущена в фоне.`, false);
        monitorMergeJobs(currentRoot, true).catch(() => {});
        if (jobId) pollMergeJob(jobId, name);
    } catch (error) {
        $('mergeStatus').textContent = error.message;
        button.disabled = false;
    }
}

async function pollMergeJob(jobId, outputName) {
    if (mergePollTimers.has(jobId)) clearTimeout(mergePollTimers.get(jobId));
    try {
        const data = await fetchJson(`utilities/video_merge.php?action=job_status&id=${encodeURIComponent(jobId)}`);
        const job = data.job || {};
        if (['pending', 'running'].includes(job.status)) renderMergeProgressJobs([job]);
        if (job.status === 'ready') {
            mergePollTimers.delete(jobId);
            if (currentRoot) await loadTree({ announceInitialCache: false, processScreenshots: false });
            if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
            showMessage(`Склейка «${job.output_name || outputName}» завершена. ${job.message || ''}`.trim(), false);
            monitorMergeJobs(currentRoot, true).catch(() => {});
            return;
        }
        if (job.status === 'error') {
            mergePollTimers.delete(jobId);
            showMessage(`Склейка «${job.output_name || outputName}»: ${job.message || 'ошибка FFmpeg'}`);
            monitorMergeJobs(currentRoot, true).catch(() => {});
            return;
        }
    } catch (error) {
        console.warn('Merge job status:', error);
    }
    const timer = setTimeout(() => pollMergeJob(jobId, outputName), 2500);
    mergePollTimers.set(jobId, timer);
}

async function loadMergeInfo(token) {
    const section = $('mergeSourcesSection');
    const list = $('mergeSourcesList');
    currentMergeInfoToken = token || '';
    section.classList.add('hidden');
    list.innerHTML = '';
    if (!token) return;
    try {
        const params = new URLSearchParams({ action: 'card_info', token });
        const data = await fetchJson(`utilities/video_merge.php?${params.toString()}`);
        if (currentMergeInfoToken !== token) return;
        const merge = data.merge || {};
        if (!merge.is_merge || !Array.isArray(merge.sources) || !merge.sources.length) return;
        merge.sources.forEach((source) => {
            const li = document.createElement('li');
            if (source.available && source.token) {
                const link = document.createElement('button');
                link.type = 'button';
                link.className = 'merge-source-link';
                link.textContent = source.name || source.file_name;
                link.title = source.relative_path || source.file_name;
                link.onclick = () => openCard(source.token);
                li.appendChild(link);
            } else {
                const text = document.createElement('span');
                text.textContent = `${source.name || source.file_name} — файл отсутствует в библиотеке`;
                text.className = 'muted';
                li.appendChild(text);
            }
            list.appendChild(li);
        });
        section.classList.remove('hidden');
    } catch (error) {
        console.warn('Merge metadata:', error);
    }
}

async function assignSelectedCategory(value) {
    if (!value || !selectedItems.size) return;
    const selected = Array.from(selectedItems.values());
    if (!selected.every((item) => item.type === 'file')) {
        showMessage('Категорию можно назначить только выбранным видео.');
        return;
    }
    const category = value === '__none__'
        ? null
        : categories.find((item) => String(item.id) === String(value));
    const label = value === '__none__' ? 'Без категории' : (category?.name || 'выбранную категорию');
    try {
        const data = await postForm('set_items_category', {
            root: currentRoot,
            category_id: value,
            items: JSON.stringify(selectedPayload()),
        });
        clearSelection();
        await loadTree({ announceInitialCache: false });
        if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
        showMessage(`Категория «${label}» назначена видео: ${data.updated}.`, false);
    } catch (error) {
        showMessage(error.message);
    } finally {
        if ($('bulkCategorySelect')) $('bulkCategorySelect').value = '';
        if ($('searchBulkCategorySelect')) $('searchBulkCategorySelect').value = '';
    }
}

function openMoveModal(preselectedPath = '') {
    if (!selectedItems.size) return showMessage('Сначала выберите файлы или папки.');
    if (!currentTree) return;

    const select = $('moveDestination');
    select.innerHTML = '';
    for (const directory of flattenDirectories(currentTree)) {
        if (isInvalidDestination(directory.path)) continue;
        const option = document.createElement('option');
        option.value = directory.path;
        option.textContent = `${'— '.repeat(directory.depth)}${directory.depth === 0 ? '[Корень] ' : ''}${directory.name}`;
        select.appendChild(option);
    }
    if (!select.options.length) return showMessage('Нет доступной папки назначения.');
    if (preselectedPath && Array.from(select.options).some((option) => option.value === preselectedPath)) {
        select.value = preselectedPath;
    }
    $('moveSummary').textContent = `Будет перемещено выбранных объектов: ${selectedItems.size}.`;
    $('moveModal').classList.remove('hidden');
    $('moveModal').setAttribute('aria-hidden', 'false');
}

function closeMoveModal() {
    $('moveModal').classList.add('hidden');
    $('moveModal').setAttribute('aria-hidden', 'true');
}

async function moveSelectedTo(destination) {
    if (!selectedItems.size) return showMessage('Сначала выберите файлы или папки.');
    if (isInvalidDestination(destination)) return showMessage('Нельзя переместить папку внутрь самой себя.');

    hideContextMenu();
    showMessage('Перемещаю выбранные файлы и папки...', false);
    try {
        const data = await postForm('move_items', {
            root: currentRoot,
            destination,
            items: JSON.stringify(selectedPayload()),
        });
        closeMoveModal();
        clearSelection();
        expandedDirs.add(destination);
        saveExpandedDirs();
        await loadTree({ announceInitialCache: false });
        if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
        showMessage(data.moved ? `Перемещено объектов: ${data.moved}.` : 'Все выбранные объекты уже находятся в этой папке.', false);
    } catch (error) {
        showMessage(error.message);
    }
}

async function deleteSelected() {
    if (!selectedItems.size) return showMessage('Сначала выберите файлы или папки.');
    const count = selectedItems.size;
    const message = `Физически удалить выбранные объекты (${count})?\n\nПапки удаляются вместе со всем содержимым. Карточки, заметки, категории и прикрепленные изображения удаляемых видео тоже будут удалены. Отменить это действие нельзя.`;
    if (!confirm(message)) return;

    hideContextMenu();
    showMessage('Удаляю выбранные объекты с диска и из базы...', false);
    try {
        const data = await postForm('delete_items', {
            root: currentRoot,
            items: JSON.stringify(selectedPayload()),
        });
        clearSelection();
        await loadTree({ announceInitialCache: false });
        if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
        showMessage(`Удалено выбранных объектов: ${data.deleted}.`, false);
    } catch (error) {
        showMessage(error.message);
    }
}

async function createFolder(parentPath) {
    hideContextMenu();
    const name = prompt('Название новой папки:');
    if (name === null) return;
    try {
        const data = await postForm('create_folder', {
            root: currentRoot,
            parent_path: parentPath,
            name,
        });
        expandedDirs.add(parentPath);
        saveExpandedDirs();
        await loadTree({ announceInitialCache: false });
        showMessage(`Папка создана: ${data.path}`, false);
    } catch (error) {
        showMessage(error.message);
    }
}

function addContextMenuButton(label, handler, className = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    if (className) button.className = className;
    button.addEventListener('click', handler);
    $('contextMenu').appendChild(button);
}

function showContextMenu(x, y, target) {
    const menu = $('contextMenu');
    menu.innerHTML = '';

    if (target.type === 'root' || target.type === 'dir') {
        addContextMenuButton(target.type === 'root' ? 'Создать папку' : 'Создать подпапку', () => createFolder(target.path));
        if (selectedItems.size && !isInvalidDestination(target.path)) {
            addContextMenuButton('Переместить выбранное сюда', () => moveSelectedTo(target.path));
        }
    }
    if (target.type === 'file' && target.token) {
        addContextMenuButton('Открыть карточку', () => openCard(target.token));
    }
    if (selectedItems.size) {
        addContextMenuButton('Переместить…', () => openMoveModal());
        addContextMenuButton('Удалить с диска', deleteSelected, 'danger-menu');
    }

    menu.classList.remove('hidden');
    const maxX = window.innerWidth - menu.offsetWidth - 8;
    const maxY = window.innerHeight - menu.offsetHeight - 8;
    menu.style.left = `${Math.max(8, Math.min(x, maxX))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, maxY))}px`;
}

function hideContextMenu() {
    $('contextMenu').classList.add('hidden');
}

function normalizeFavoritePath(path) {
    return path.trim().replace(/[\\/]+$/, '');
}

function favoriteLabel(path) {
    const parts = normalizeFavoritePath(path).split(/[\\/]+/).filter(Boolean);
    return parts.length ? parts[parts.length - 1] : path;
}

function saveFavorites() {
    localStorage.setItem('video_catalog_favorite_roots', JSON.stringify(favoriteRoots));
}

function clientComparablePath(path) {
    return normalizeFavoritePath(String(path || '')).replace(/\\/g, '/').toLocaleLowerCase();
}

function clientReplacePathPrefix(path, oldRoot, newRoot) {
    const raw = String(path || '');
    const normalized = raw.replace(/\\/g, '/');
    const oldNormalized = normalizeFavoritePath(oldRoot).replace(/\\/g, '/');
    const compare = normalized.toLocaleLowerCase();
    const oldCompare = oldNormalized.toLocaleLowerCase();
    if (compare === oldCompare) return newRoot;
    if (!compare.startsWith(oldCompare + '/')) return raw;
    const suffix = normalized.slice(oldNormalized.length).replace(/^\/+/, '');
    const separator = String(newRoot).includes('\\') ? '\\' : '/';
    return normalizeFavoritePath(newRoot) + (suffix ? separator + suffix.replace(/\//g, separator) : '');
}

function applyRootRelocation(oldRoot, newRoot) {
    if (!oldRoot || !newRoot || clientComparablePath(oldRoot) === clientComparablePath(newRoot)) return;

    let favoritesChanged = false;
    favoriteRoots = favoriteRoots.map((path) => {
        if (clientComparablePath(path) !== clientComparablePath(oldRoot)) return path;
        favoritesChanged = true;
        return newRoot;
    });
    if (favoritesChanged) saveFavorites();

    const updatedExpanded = new Set();
    let expandedChanged = false;
    for (const path of expandedDirs) {
        const replaced = clientReplacePathPrefix(path, oldRoot, newRoot);
        if (replaced !== path) expandedChanged = true;
        updatedExpanded.add(replaced);
    }
    if (expandedChanged) {
        expandedDirs = updatedExpanded;
        saveExpandedDirs();
    }
}

function renderFavorites() {
    const box = $('favoriteFolders');
    box.innerHTML = '';
    if (!favoriteRoots.length) {
        box.classList.add('empty-favorites');
        box.textContent = 'Пока нет избранных папок.';
        return;
    }

    box.classList.remove('empty-favorites');
    const activeRoot = normalizeFavoritePath($('rootPath').value || currentRoot || '').toLocaleLowerCase();
    for (const path of favoriteRoots) {
        const item = document.createElement('div');
        item.className = 'favorite-item';
        if (normalizeFavoritePath(path).toLocaleLowerCase() === activeRoot) item.classList.add('active');
        item.title = path;

        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'favorite-open';
        open.textContent = favoriteLabel(path);
        open.onclick = async () => {
            $('rootPath').value = path;
            currentRoot = path;
            localStorage.setItem('video_catalog_root', path);
            await loadTree();
        };

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'favorite-remove';
        remove.textContent = '×';
        remove.title = 'Убрать из избранного';
        remove.onclick = () => removeFavorite(path);
        item.append(open, remove);
        box.appendChild(item);
    }
}

function addCurrentFavorite() {
    const path = normalizeFavoritePath($('rootPath').value || '');
    if (!path) return showMessage('Сначала укажите папку, которую нужно закрепить.');
    if (favoriteRoots.some((item) => normalizeFavoritePath(item).toLocaleLowerCase() === path.toLocaleLowerCase())) {
        showMessage('Эта папка уже есть в избранном.', false);
        return renderFavorites();
    }
    favoriteRoots.push(path);
    favoriteRoots.sort((a, b) => favoriteLabel(a).localeCompare(favoriteLabel(b), 'ru'));
    saveFavorites();
    renderFavorites();
    showMessage('Папка добавлена в избранное.', false);
}

function removeFavorite(path) {
    favoriteRoots = favoriteRoots.filter((item) => normalizeFavoritePath(item).toLocaleLowerCase() !== normalizeFavoritePath(path).toLocaleLowerCase());
    saveFavorites();
    renderFavorites();
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(doSearch, 250);
}

async function doSearch() {
    const query = $('searchInput').value.trim();
    const categoryId = $('categoryFilter').value;
    if (!query && !categoryId) {
        $('searchResults').classList.add('hidden');
        $('resultsList').innerHTML = '';
        updateSelectionToolbar();
        return;
    }
    if (!currentRoot) return;

    try {
        const params = new URLSearchParams({ action: 'search', q: query, root: currentRoot });
        if (categoryId) params.set('category_id', categoryId);
        const data = await fetchJson(`${api}?${params.toString()}`);
        renderResults(data.results);
    } catch (error) {
        showMessage(error.message);
    }
}

function renderResults(results) {
    const box = $('searchResults');
    const list = $('resultsList');
    list.innerHTML = '';
    box.classList.remove('hidden');
    if (!results.length) {
        list.textContent = 'Ничего не найдено.';
        return;
    }

    for (const item of results) {
        const row = document.createElement('div');
        row.className = 'result-row';
        row.appendChild(makeSelectionCheckbox('file', item.file_path));
        attachItemInteractions(row, { type: 'file', path: item.file_path, token: item.token });

        const main = document.createElement('div');
        main.className = 'result-main';
        const title = document.createElement('button');
        title.type = 'button';
        title.className = 'result-title';
        title.textContent = item.custom_title || item.file_name;
        title.onclick = () => openCard(item.token);
        const path = document.createElement('div');
        path.className = 'muted';
        path.textContent = `${item.category_name ? item.category_name + ' · ' : ''}${item.file_path}`;
        const note = document.createElement('p');
        note.textContent = item.note || '';
        main.append(title, path, note);

        const actions = document.createElement('div');
        actions.className = 'result-actions';
        const duration = document.createElement('span');
        duration.className = 'video-duration';
        duration.textContent = formatVideoDuration(item.duration_seconds);
        duration.title = 'Длительность видео';
        const view = document.createElement('button');
        view.type = 'button';
        view.className = 'button view-link';
        view.textContent = 'Просмотр';
        view.addEventListener('click', (event) => {
            event.stopPropagation();
            openVideo(item.token, item.custom_title || item.file_name);
        });
        actions.append(duration, view, makePinButton(item));
        row.append(main, actions);
        list.appendChild(row);
    }
    updateSelectionToolbar();
}

function findFileParentDirectory(node, token) {
    if (!node || node.type !== 'dir') return null;
    for (const child of node.children || []) {
        if (child.type === 'file' && child.token === token) return node;
    }
    for (const child of node.children || []) {
        if (child.type !== 'dir') continue;
        const found = findFileParentDirectory(child, token);
        if (found) return found;
    }
    return null;
}

function getCardNavigationItems(token) {
    if (!currentTree || !token) return [];
    const parent = findFileParentDirectory(currentTree, token);
    if (!parent) return [];
    return sortFilesForBrowser((parent.children || []).filter((child) => child.type === 'file'));
}

function updateCardNavigation(token) {
    const navigation = $('cardNavigation');
    const previous = $('prevCardBtn');
    const next = $('nextCardBtn');
    const counter = $('cardNavCounter');
    if (!navigation || !previous || !next || !counter) return;

    const items = getCardNavigationItems(token);
    const index = items.findIndex((item) => item.token === token);
    const available = index >= 0 && items.length > 1;
    navigation.classList.toggle('hidden', !available);

    if (index < 0 || !items.length) {
        previous.disabled = true;
        next.disabled = true;
        counter.textContent = '';
        previous.dataset.token = '';
        next.dataset.token = '';
        return;
    }

    previous.disabled = index <= 0;
    next.disabled = index >= items.length - 1;
    previous.dataset.token = index > 0 ? (items[index - 1].token || '') : '';
    next.dataset.token = index < items.length - 1 ? (items[index + 1].token || '') : '';
    counter.textContent = `${index + 1} / ${items.length}`;
}

async function navigateCard(direction) {
    const button = direction < 0 ? $('prevCardBtn') : $('nextCardBtn');
    const token = button?.dataset.token || '';
    if (!token || button.disabled) return;
    button.disabled = true;
    try {
        await openCard(token);
    } finally {
        updateCardNavigation($('cardToken')?.value || '');
    }
}

async function openCard(token) {
    hideContextMenu();
    try {
        await loadCategories();
        const data = await fetchJson(`${api}?action=card&token=${encodeURIComponent(token)}`);
        fillCard(data.card);
        $('cardModal').classList.remove('hidden');
        $('cardModal').setAttribute('aria-hidden', 'false');
    } catch (error) {
        showMessage(error.message);
    }
}

function closeCard() {
    const metadataViewWasOpen = !$('metadataViewModal').classList.contains('hidden');
    const screenshotViewWasOpen = !$('screenshotViewModal').classList.contains('hidden');
    $('cardModal').classList.add('hidden');
    $('cardModal').setAttribute('aria-hidden', 'true');

    if (metadataViewWasOpen) {
        window.setTimeout(() => {
            if (!$('metadataViewModal').classList.contains('hidden')) {
                loadMetadataView({ silent: true }).catch(() => {});
            }
        }, 0);
    }
    if (screenshotViewWasOpen) {
        window.setTimeout(() => {
            if (!$('screenshotViewModal').classList.contains('hidden')) {
                loadScreenshotView({ silent: true }).catch(() => {});
            }
        }, 0);
    }
}

function formatFileSize(bytes) {
    const value = Number(bytes) || 0;
    if (value <= 0) return '—';
    const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    let size = value;
    let index = 0;
    while (size >= 1024 && index < units.length - 1) {
        size /= 1024;
        index += 1;
    }
    const digits = index >= 3 ? 2 : index >= 2 ? 1 : 0;
    return `${size.toFixed(digits)} ${units[index]}`;
}

function formatCardDate(value) {
    if (!value) return '—';
    const normalized = String(value).replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString([], { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function renderCardCover(card) {
    const image = $('cardCoverImage');
    const placeholder = $('cardCoverPlaceholder');
    const screenshots = Array.isArray(card.screenshots) ? card.screenshots : [];
    const selected = screenshots.find((item) => item.is_thumbnail) || screenshots[1] || screenshots[0] || null;
    image.classList.toggle('hidden', !selected);
    placeholder.classList.toggle('hidden', Boolean(selected));
    if (selected) {
        image.src = selected.url;
        image.alt = card.custom_title || card.file_name || 'Миниатюра видео';
    } else {
        image.removeAttribute('src');
    }
    const cover = $('cardCoverButton');
    cover.dataset.token = card.token || '';
    cover.dataset.title = card.custom_title || card.file_name || 'Просмотр видео';
}

function fillCard(card) {
    $('modalFileName').textContent = card.file_name;
    $('modalPath').textContent = card.file_path;
    $('cardToken').value = card.token;
    $('customTitle').value = card.custom_title || '';
    $('note').value = card.note || '';
    fillCategorySelect($('cardCategory'), card.category_id || '', 'Без категории');
    $('viewFromModal').dataset.token = card.token;
    $('viewFromModal').dataset.title = card.custom_title || card.file_name;
    $('cardFileSize').textContent = formatFileSize(card.file_size);
    $('cardDuration').textContent = formatVideoDuration(card.duration_seconds);
    $('cardAddedAt').textContent = formatCardDate(card.first_seen_at);
    updateCardNavigation(card.token);
    updateCardPinButton(Boolean(card.is_pinned));
    $('saveStatus').textContent = '';
    renderCardCover(card);
    renderVideoScreenshots(card.screenshots || []);
    renderImages(card.images || []);
    currentFileToolsToken = card.token;
    resetFileToolsPanel();
    loadFileTools(card.token).catch((error) => {
        if (currentFileToolsToken === card.token) $('fileToolsStatus').textContent = error.message;
    });
    loadMergeInfo(card.token);
}

function formatScreenshotTime(seconds) {
    const value = Math.max(0, Math.round(Number(seconds) || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const secs = value % 60;
    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
        : `${minutes}:${String(secs).padStart(2, '0')}`;
}

function renderVideoScreenshots(screenshots) {
    const section = $('videoScreenshotsSection');
    const grid = $('videoScreenshotsGrid');
    const count = $('videoScreenshotsCount');
    grid.innerHTML = '';
    section.classList.toggle('hidden', !screenshots.length);
    count.textContent = screenshots.length ? `(${screenshots.length})` : '';
    if (!screenshots.length) return;

    const viewerItems = screenshots.map((screenshot, index) => ({
        url: screenshot.url,
        caption: `Кадр ${index + 1} · ${formatScreenshotTime(screenshot.position_seconds)}`,
        screenshotId: Number(screenshot.id) || 0,
        isThumbnail: Boolean(screenshot.is_thumbnail),
    }));

    screenshots.forEach((screenshot, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'video-screenshot-thumb';
        button.title = `Кадр на ${formatScreenshotTime(screenshot.position_seconds)}`;

        const image = document.createElement('img');
        image.src = screenshot.url;
        image.alt = `Кадр ${Number(screenshot.sort_order) + 1}`;
        image.loading = 'lazy';

        const time = document.createElement('span');
        time.className = 'video-screenshot-time';
        time.textContent = formatScreenshotTime(screenshot.position_seconds);

        button.append(image, time);
        button.addEventListener('click', () => openImageGallery(viewerItems, index));
        grid.appendChild(button);
    });
}

function renderImages(images) {
    const grid = $('imagesGrid');
    grid.innerHTML = '';
    if (!images.length) {
        grid.innerHTML = '<p class="muted">Фото пока не прикреплены.</p>';
        return;
    }
    for (const imageData of images) {
        const card = document.createElement('div');
        card.className = 'image-card';
        const image = document.createElement('img');
        image.src = imageData.url;
        image.alt = imageData.original_name || 'Фото';
        image.onclick = () => openImage(imageData.url, imageData.original_name || 'Фото');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Удалить';
        remove.onclick = () => deleteImage(imageData.id);
        card.append(image, remove);
        grid.appendChild(card);
    }
}

async function saveCard(event) {
    event.preventDefault();
    const form = new FormData($('cardForm'));
    form.append('action', 'save_card');
    $('saveStatus').textContent = 'Сохраняю...';
    try {
        const data = await fetchJson(api, { method: 'POST', body: form });
        fillCard(data.card);
        $('saveStatus').textContent = 'Сохранено';
        await loadTree({ announceInitialCache: false });
        await doSearch();
    } catch (error) {
        $('saveStatus').textContent = error.message;
    }
}

async function deleteCard() {
    const token = $('cardToken').value;
    if (!token) return;
    if (!confirm('Удалить карточку, заметку, категорию и прикрепленные фото? Сам видеофайл останется на диске.')) return;
    try {
        await postForm('delete_card', { token });
        closeCard();
        await loadTree({ announceInitialCache: false });
        await doSearch();
        showMessage('Карточка удалена. Сам видеофайл не затронут.', false);
    } catch (error) {
        showMessage(error.message);
    }
}

async function deleteFileFromCard() {
    const token = $('cardToken').value;
    if (!token) return;
    const title = $('customTitle').value.trim() || $('modalFileName').textContent || 'это видео';
    const message = `Полностью удалить «${title}» с диска?

Будут удалены сам видеофайл, карточка, прикрепленные фото, кадры, аудио, фрагменты, транскрипты и другие производные данные. Отменить это действие нельзя.`;
    if (!confirm(message)) return;

    const button = $('deleteFileFromCardBtn');
    button.disabled = true;
    try {
        await postForm('delete_file_from_card', { token });
        currentFileToolsToken = '';
        closeCard();
        clearSelection();
        await loadTree({ announceInitialCache: false });
        await doSearch();
        showMessage('Видео полностью удалено с диска и из каталога.', false);
    } catch (error) {
        showMessage(error.message);
    } finally {
        button.disabled = false;
    }
}

async function addCategory() {
    const name = $('newCategory').value.trim();
    if (!name) return;
    try {
        const root = currentRoot || $('rootPath').value.trim();
        if (!root) return showMessage('Сначала выберите библиотеку.');
        const data = await postForm('add_category', { name, root });
        $('newCategory').value = '';
        await loadCategories(data.category.id);
        $('cardCategory').value = data.category.id;
    } catch (error) {
        showMessage(error.message);
    }
}

async function uploadImages() {
    const files = $('imageInput').files;
    if (!files.length) return;
    const form = new FormData();
    form.append('action', 'upload_image');
    form.append('token', $('cardToken').value);
    for (const file of files) form.append('images[]', file);
    try {
        const data = await fetchJson(api, { method: 'POST', body: form });
        $('imageInput').value = '';
        fillCard(data.card);
    } catch (error) {
        showMessage(error.message);
    }
}

async function deleteImage(id) {
    if (!confirm('Удалить это фото?')) return;
    try {
        await postForm('delete_image', { image_id: id });
        await openCard($('cardToken').value);
    } catch (error) {
        showMessage(error.message);
    }
}


function setScreenshotProgress(text = '') {
    const element = $('screenshotProgress');
    element.textContent = text;
    element.classList.toggle('hidden', !text);
}

function screenshotWorkerStatusText(worker) {
    const total = Number(worker.total_jobs || 0);
    const completed = Number(worker.completed_jobs || 0);
    const failed = Number(worker.failed_jobs || 0);
    const processed = completed + failed;
    const frame = Number(worker.current_frame || 0);
    const frameTotal = Number(worker.current_frame_total || 10);
    const fileName = worker.current_file_name || '';

    switch (worker.status) {
        case 'queued':
            return `Создание кадров поставлено в фоновую очередь: ${total} видео.`;
        case 'running': {
            const videoNumber = Math.min(total, processed + 1);
            const framePart = frame > 0 ? `, кадр ${frame}/${frameTotal}` : '';
            const filePart = fileName ? ` — ${fileName}` : '';
            return `Фоновое создание кадров: видео ${videoNumber}/${total}${framePart}${filePart}`;
        }
        case 'stopping':
            return 'Останавливается текущий FFmpeg…';
        case 'paused': {
            const paused = Number(worker.paused_count || 0);
            const countPart = paused > 0 ? ` Осталось видео: ${paused}.` : '';
            return `${worker.message || 'Создание кадров поставлено на паузу.'}${countPart}`;
        }
        case 'finished':
            return failed > 0
                ? `Создание кадров завершено: готово ${completed}, ошибок ${failed}.`
                : `Создание кадров завершено: готово ${completed}.`;
        case 'error':
            return `Ошибка фонового обработчика: ${worker.message || 'неизвестная ошибка'}`;
        default:
            if (Number(worker.pending_count || 0) > 0) {
                return `Ожидают создания кадров: ${worker.pending_count} видео.`;
            }
            return '';
    }
}

function updateScreenshotWorkerProgress(worker) {
    const state = worker || {};
    const text = screenshotWorkerStatusText(state);
    setScreenshotProgress(text);

    const stopButton = $('stopScreenshotWorkerBtn');
    const resumeButton = $('resumeScreenshotWorkerBtn');
    stopButton.classList.toggle('hidden', !['queued', 'running', 'stopping'].includes(state.status));
    stopButton.disabled = state.status === 'stopping';
    resumeButton.classList.toggle('hidden', state.status !== 'paused');
}

function scheduleScreenshotWorkerPoll(root, delay = null) {
    clearTimeout(screenshotMonitorTimer);
    const timeout = delay ?? (document.hidden ? 10000 : 2000);
    screenshotMonitorTimer = setTimeout(() => {
        monitorScreenshotWorker(root).catch(() => {});
    }, timeout);
}

async function monitorScreenshotWorker(root, immediate = false) {
    if (!root) return null;
    screenshotMonitorRoot = root;
    if (immediate) clearTimeout(screenshotMonitorTimer);

    const params = new URLSearchParams({ action: 'screenshot_worker_status', root });
    const data = await fetchJson(`${api}?${params.toString()}`);
    const worker = data.worker || {};
    updateScreenshotWorkerProgress(worker);

    if (['queued', 'running', 'stopping'].includes(worker.status)) {
        scheduleScreenshotWorkerPoll(root);
    } else {
        clearTimeout(screenshotMonitorTimer);
        screenshotMonitorTimer = null;
    }
    return worker;
}

async function processPendingScreenshots(root) {
    if (screenshotGenerationRunning || !root) return null;
    screenshotGenerationRunning = true;
    try {
        const data = await postForm('start_screenshot_worker', { root });
        const worker = data.worker || {};
        updateScreenshotWorkerProgress(worker);
        monitorScreenshotWorker(root, true).catch(() => {});
        return worker;
    } finally {
        screenshotGenerationRunning = false;
    }
}

async function stopScreenshotWorker() {
    const root = currentRoot || $('rootPath').value.trim();
    if (!root) return;
    $('stopScreenshotWorkerBtn').disabled = true;
    try {
        const data = await postForm('stop_screenshot_worker', { root });
        updateScreenshotWorkerProgress(data.worker || {});
        monitorScreenshotWorker(root, true).catch(() => {});
    } catch (error) {
        showMessage(error.message);
        $('stopScreenshotWorkerBtn').disabled = false;
    }
}

async function resumeScreenshotWorker() {
    const root = currentRoot || $('rootPath').value.trim();
    if (!root) return;
    try {
        await processPendingScreenshots(root);
    } catch (error) {
        showMessage(error.message);
    }
}

function resetFileToolsPanel() {
    $('fileToolsSummaryStatus').textContent = '';
    $('fileToolsStatus').textContent = 'Загрузка…';
    $('fileAudioList').innerHTML = '';
    $('fileClipsList').innerHTML = '';
    $('fileTranscriptsList').innerHTML = '';
    $('filePromotedClipsList').innerHTML = '';
    $('fileSourceClipList').innerHTML = '';
    $('fileAudioSection').classList.add('hidden');
    $('fileClipsSection').classList.add('hidden');
    $('fileTranscriptsSection').classList.add('hidden');
    $('filePromotedClipsSection').classList.add('hidden');
    $('fileSourceClipSection').classList.add('hidden');
    $('convertMp4Btn').classList.add('hidden');
    $('convertMp4Btn').classList.remove('danger');
    $('convertMp4Btn').disabled = false;
    $('mediaToolBtn').disabled = false;
}

function formatToolTime(seconds) {
    if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return '';
    const value = Math.max(0, Number(seconds));
    const whole = Math.floor(value);
    const h = Math.floor(whole / 3600);
    const m = Math.floor((whole % 3600) / 60);
    const sec = whole % 60;
    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
        : `${m}:${String(sec).padStart(2, '0')}`;
}

function derivativeRangeLabel(item) {
    if (item.start_seconds === null && item.end_seconds === null) return '';
    return ` (${formatToolTime(item.start_seconds ?? 0)}–${item.end_seconds === null ? 'конец' : formatToolTime(item.end_seconds)})`;
}

function openDerivedVideo(item) {
    const modal = $('videoModal');
    const player = $('videoPlayer');
    $('videoTitle').textContent = item.download_name || 'Просмотр фрагмента';
    $('videoStatus').textContent = 'Загрузка видео...';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    player.src = item.inline_url;
    player.load();
    const promise = player.play();
    if (promise && typeof promise.catch === 'function') {
        promise.catch(() => { $('videoStatus').textContent = 'Нажмите кнопку воспроизведения.'; });
    }
}

async function promoteClip(item) {
    if (!confirm(`Сделать фрагмент «${item.download_name}» обычным видео?\n\nФайл будет перенесен в корень библиотеки. Связанные аудио, транскрипт и переводы для того же интервала будут перенесены к новому видео.`)) return;
    $('fileToolsStatus').textContent = 'Преобразую фрагмент в обычное видео…';
    try {
        const data = await fileToolsPost('promote_clip', { id: item.id });
        $('fileToolsStatus').textContent = `Создано обычное видео: ${data.file?.file_name || item.download_name}`;
        await loadFileTools(currentFileToolsToken);
        await loadTree({ announceInitialCache: false, processScreenshots: true });
        await doSearch();
    } catch (error) {
        $('fileToolsStatus').textContent = error.message;
    }
}

function makeDerivativeActionMenu(entries) {
    const details = document.createElement('details');
    details.className = 'derivative-action-menu';
    const summary = document.createElement('summary');
    summary.className = 'derivative-gear-button';
    summary.textContent = '⚙';
    summary.title = 'Действия';
    summary.setAttribute('aria-label', 'Действия');
    details.appendChild(summary);

    const menu = document.createElement('div');
    menu.className = 'derivative-action-popover';
    for (const entry of entries) {
        if (entry.type === 'link') {
            const link = document.createElement('a');
            link.href = entry.href;
            link.textContent = entry.label;
            link.className = entry.danger ? 'danger-menu-item' : '';
            if (entry.download) link.setAttribute('download', entry.download === true ? '' : entry.download);
            menu.appendChild(link);
            continue;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = entry.label;
        if (entry.danger) button.className = 'danger-menu-item';
        button.disabled = Boolean(entry.disabled);
        if (entry.title) button.title = entry.title;
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            details.removeAttribute('open');
            if (entry.onClick) await entry.onClick();
        });
        menu.appendChild(button);
    }
    details.appendChild(menu);
    return details;
}

async function deleteDerivativeItem(item) {
    if (!confirm(`Удалить файл «${item.download_name}»?`)) return;
    try {
        await fileToolsPost('delete_derivative', { id: item.id });
        await loadFileTools(currentFileToolsToken);
    } catch (error) {
        $('fileToolsStatus').textContent = error.message;
    }
}

function renderDerivativeList(container, items, options = {}) {
    container.innerHTML = '';
    for (const item of items) {
        const row = document.createElement('div');
        row.className = 'compact-derivative-row';

        const main = document.createElement('div');
        main.className = 'compact-derivative-main';

        if (options.clip) {
            const name = document.createElement('button');
            name.type = 'button';
            name.className = 'file-tool-name-button compact-derivative-name';
            name.textContent = item.download_name;
            name.title = 'Просмотреть фрагмент';
            name.addEventListener('click', () => openDerivedVideo(item));
            main.appendChild(name);
            const range = document.createElement('span');
            range.className = 'muted compact-derivative-meta';
            range.textContent = derivativeRangeLabel(item).replace(/^\s*[()]|[()]$/g, '') || 'Видеофрагмент';
            main.appendChild(range);
        } else {
            const link = document.createElement('a');
            link.href = item.download_url;
            link.className = 'file-tool-download compact-derivative-name';
            link.textContent = item.download_name;
            link.setAttribute('download', item.download_name);
            main.appendChild(link);
        }

        if (options.audio) {
            const player = document.createElement('audio');
            player.controls = true;
            player.preload = 'none';
            player.src = item.inline_url;
            player.className = 'file-tool-audio-player compact-audio-player';
            main.appendChild(player);
        }

        const entries = [];
        if (options.clip) {
            entries.push({ type: 'link', label: 'Скачать', href: item.download_url, download: item.download_name });
            entries.push({
                label: 'Сделать обычным видео',
                disabled: options.canPromote === false,
                title: options.canPromote === false ? 'Дождитесь завершения текущих операций с видео' : '',
                onClick: () => promoteClip(item),
            });
        }
        entries.push({ label: 'Удалить', danger: true, onClick: () => deleteDerivativeItem(item) });

        row.append(main, makeDerivativeActionMenu(entries));
        container.appendChild(row);
    }
}

function renderPromotedClips(container, items) {
    container.innerHTML = '';
    for (const item of items || []) {
        const row = document.createElement('div');
        row.className = 'file-tool-result-row';
        const main = document.createElement('div');
        main.className = 'file-tool-result-main';
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'file-tool-name-button';
        link.textContent = item.title || item.file_name;
        link.title = 'Открыть карточку созданного видео';
        link.addEventListener('click', () => openCard(item.token));
        main.appendChild(link);
        const meta = document.createElement('span');
        meta.className = 'muted';
        meta.textContent = 'Открыть карточку';
        row.append(main, meta);
        container.appendChild(row);
    }
}

function renderSourceClip(container, item) {
    container.innerHTML = '';
    if (!item) return;
    const row = document.createElement('div');
    row.className = 'file-tool-result-row';
    const main = document.createElement('div');
    main.className = 'file-tool-result-main';
    const link = document.createElement('button');
    link.type = 'button';
    link.className = 'file-tool-name-button';
    link.textContent = item.title || item.file_name;
    link.title = 'Открыть карточку исходного видео';
    link.addEventListener('click', () => openCard(item.token));
    main.appendChild(link);
    if (item.original_clip_name) {
        const note = document.createElement('span');
        note.className = 'muted';
        note.textContent = `Создано из фрагмента: ${item.original_clip_name}`;
        main.appendChild(note);
    }
    const meta = document.createElement('span');
    meta.className = 'muted';
    meta.textContent = 'Открыть карточку';
    row.append(main, meta);
    container.appendChild(row);
}

function transcriptLanguageLabel(language) {
    const key = String(language || '').toLowerCase();
    const labels = { ru: 'русский', russian: 'русский', en: 'английский', english: 'английский' };
    return labels[key] || String(language || '').toUpperCase();
}

function renderTranscriptList(container, items) {
    container.innerHTML = '';
    for (const item of items) {
        const row = document.createElement('div');
        row.className = 'compact-derivative-row transcript-result-row';

        const main = document.createElement('div');
        main.className = 'compact-derivative-main';
        const name = document.createElement('button');
        name.type = 'button';
        name.className = 'file-tool-name-button compact-derivative-name';
        name.textContent = item.download_name;
        name.title = 'Открыть транскрипт';
        name.addEventListener('click', () => openTranscript(item.id));

        const meta = document.createElement('div');
        meta.className = 'muted compact-derivative-meta';
        const metaParts = [item.provider || 'сервис'];
        if (item.language) metaParts.push(transcriptLanguageLabel(item.language));
        if (item.segment_count) metaParts.push(`${item.segment_count} фрагм.`);
        if (Array.isArray(item.translations) && item.translations.length) {
            metaParts.push(`переводы: ${item.translations.map(x => x.language_label || x.target_language).join(', ')}`);
        }
        if (item.translation_job) metaParts.push(`перевод ${item.translation_job.progress_percent || 0}%`);
        meta.textContent = metaParts.join(' · ');
        main.append(name, meta);

        if (item.translation_job) monitorTranslationJob(item.translation_job.id);

        const entries = [
            { type: 'link', label: 'Скачать TXT', href: item.download_url, download: item.download_name },
            {
                label: item.translation_job ? `Перевод ${item.translation_job.progress_percent || 0}%` : 'Перевести',
                disabled: Boolean(item.translation_job),
                onClick: () => openTranslationTargetModal(item),
            },
            {
                label: 'Удалить',
                danger: true,
                onClick: async () => {
                    if (!confirm(`Удалить транскрипт «${item.download_name}»?`)) return;
                    try {
                        await transcriptionPost('delete', { id: item.id });
                        await loadFileTools(currentFileToolsToken);
                        await doSearch();
                    } catch (error) {
                        $('fileToolsStatus').textContent = error.message;
                    }
                },
            },
        ];

        row.append(main, makeDerivativeActionMenu(entries));
        container.appendChild(row);
    }
}

function fileToolJobLabel(job) {
    const names = {
        audio: 'Извлечение аудио',
        transcript: 'Транскрибация',
        clip: 'Вырезание фрагмента',
        convert: 'Конвертация в MP4',
    };
    return names[job.action_type] || 'FFmpeg';
}

function renderFileTools(tools) {
    const audio = Array.isArray(tools.audio) ? tools.audio : [];
    const transcripts = Array.isArray(tools.transcripts) ? tools.transcripts : [];
    const clips = Array.isArray(tools.clips) ? tools.clips : [];
    const promotedClips = Array.isArray(tools.promoted_clips) ? tools.promoted_clips : [];
    const sourceClip = tools.source_clip || null;
    const jobs = Array.isArray(tools.jobs) ? tools.jobs : [];
    const conversion = tools.conversion || null;

    $('fileAudioSection').classList.toggle('hidden', audio.length === 0);
    $('fileTranscriptsSection').classList.toggle('hidden', transcripts.length === 0);
    $('fileClipsSection').classList.toggle('hidden', clips.length === 0);
    $('filePromotedClipsSection').classList.toggle('hidden', promotedClips.length === 0);
    $('fileSourceClipSection').classList.toggle('hidden', !sourceClip);
    renderDerivativeList($('fileAudioList'), audio, { audio: true });
    renderTranscriptList($('fileTranscriptsList'), transcripts);
    renderDerivativeList($('fileClipsList'), clips, { clip: true, canPromote: jobs.length === 0 });
    renderPromotedClips($('filePromotedClipsList'), promotedClips);
    renderSourceClip($('fileSourceClipList'), sourceClip);

    const runningAudio = jobs.some((job) => job.action_type === 'audio' || job.action_type === 'transcript');
    const runningClip = jobs.some((job) => job.action_type === 'clip');
    const runningConvert = jobs.some((job) => job.action_type === 'convert');
    $('mediaToolBtn').disabled = runningAudio || runningClip;

    const convertBtn = $('convertMp4Btn');
    convertBtn.classList.remove('danger');
    if (conversion) {
        convertBtn.classList.remove('hidden');
        convertBtn.disabled = false;
        convertBtn.textContent = 'Удалить исходное';
        convertBtn.classList.add('danger');
        convertBtn.dataset.mode = 'finalize';
        convertBtn.dataset.derivativeId = conversion.id;
    } else if (!tools.browser_playable) {
        convertBtn.classList.remove('hidden');
        convertBtn.disabled = runningConvert;
        convertBtn.textContent = runningConvert ? 'Конвертация…' : 'Конвертировать в mp4';
        convertBtn.dataset.mode = 'convert';
        delete convertBtn.dataset.derivativeId;
    } else {
        convertBtn.classList.add('hidden');
        convertBtn.dataset.mode = '';
        delete convertBtn.dataset.derivativeId;
    }

    if (jobs.length) {
        $('fileToolsSummaryStatus').textContent = 'Обработка идет';
        $('fileToolsStatus').textContent = jobs.map((job) => `${fileToolJobLabel(job)}: ${job.status === 'pending' ? 'ожидание' : 'выполняется'}`).join(' · ');
        jobs.forEach((job) => monitorFileToolJob(job.id));
    } else {
        $('fileToolsSummaryStatus').textContent = conversion ? 'MP4 готов' : '';
        $('fileToolsStatus').textContent = tools.last_error ? `Последняя ошибка: ${tools.last_error}` : '';
    }
}

async function loadFileTools(token) {
    if (!token) return;
    const requestToken = token;
    const params = new URLSearchParams({ action: 'status', token });
    const data = await fetchJson(`utilities/file_tools.php?${params.toString()}`);
    if (currentFileToolsToken !== requestToken) return;
    renderFileTools(data.tools || {});
}

function openAudioToolModal() {
    $('audioFrom').value = '';
    $('audioTo').value = '';
    $('toolDoClip').checked = false;
    $('toolDoAudio').checked = false;
    $('toolDoTranscript').checked = false;
    $('audioFormat').value = 'mp3';
    $('audioBitrate').value = '64';
    $('transcriptionLanguage').value = 'auto';
    $('audioToolStatus').textContent = '';
    updateUnifiedToolControls();
    $('audioToolModal').classList.remove('hidden');
    $('audioToolModal').setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('audioFrom').focus(), 0);
}

function closeAudioToolModal() {
    $('audioToolModal').classList.add('hidden');
    $('audioToolModal').setAttribute('aria-hidden', 'true');
    $('audioToolStatus').textContent = '';
}

function updateUnifiedToolControls() {
    const showAudio = $('toolDoAudio').checked || $('toolDoTranscript').checked;
    const showTranscript = $('toolDoTranscript').checked;
    $('toolAudioOptions').classList.toggle('hidden', !showAudio);
    $('toolTranscriptOptions').classList.toggle('hidden', !showTranscript);
    const flac = $('audioFormat').value === 'flac';
    $('audioBitrate').disabled = flac;
    if (showTranscript && $('toolDoAudio').checked) {
        $('audioFormatHint').textContent = 'Транскрипт уже включает создание аудио: отдельная вторая аудиодорожка не создается.';
    } else if (showTranscript) {
        $('audioFormatHint').textContent = 'Для транскрипта сначала создается и сохраняется аудио выбранного формата, затем оно отправляется сервису распознавания.';
    } else if (showAudio) {
        $('audioFormatHint').textContent = flac ? 'FLAC сохраняется без потерь; битрейт не применяется.' : 'MP3 сохраняется в выбранном битрейте.';
    } else {
        $('audioFormatHint').textContent = 'Выберите одну или несколько операций. Все используют один интервал «От / До».';
    }
}

async function startUnifiedFileTool() {
    const doClip = $('toolDoClip').checked;
    const doAudio = $('toolDoAudio').checked;
    const doTranscript = $('toolDoTranscript').checked;
    if (!doClip && !doAudio && !doTranscript) {
        $('audioToolStatus').textContent = 'Выберите хотя бы одну операцию.';
        return;
    }
    const button = $('unifiedToolStartBtn');
    button.disabled = true;
    $('audioToolStatus').textContent = 'Запуск…';
    try {
        const data = await fileToolsPost('start_operations', {
            token: currentFileToolsToken,
            start: $('audioFrom').value.trim(),
            end: $('audioTo').value.trim(),
            do_clip: doClip ? '1' : '0',
            do_audio: doAudio ? '1' : '0',
            do_transcript: doTranscript ? '1' : '0',
            format: $('audioFormat').value,
            bitrate: $('audioBitrate').value,
            language: $('transcriptionLanguage').value,
        });
        closeAudioToolModal();
        $('fileToolsSection').open = true;
        const labels = [];
        if (doClip) labels.push('вырезка');
        if (doTranscript) labels.push('аудио + транскрипт');
        else if (doAudio) labels.push('аудио');
        $('fileToolsStatus').textContent = `Запущено: ${labels.join(', ')}.`;
        for (const job of data.jobs || []) monitorFileToolJob(job.id, true);
        await loadFileTools(currentFileToolsToken);
    } catch (error) {
        $('audioToolStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function startConversion() {
    const button = $('convertMp4Btn');
    button.disabled = true;
    $('fileToolsStatus').textContent = 'Запуск конвертации…';
    try {
        const data = await fileToolsPost('start_convert', { token: currentFileToolsToken });
        monitorFileToolJob(data.job.id, true);
        await loadFileTools(currentFileToolsToken);
    } catch (error) {
        $('fileToolsStatus').textContent = error.message;
        button.disabled = false;
    }
}

async function finalizeConversion() {
    const button = $('convertMp4Btn');
    const derivativeId = Number(button.dataset.derivativeId || 0);
    if (!derivativeId) return;
    if (!confirm('Удалить исходный файл и заменить его готовым MP4? Операция изменит физический файл на диске.')) return;
    button.disabled = true;
    $('fileToolsStatus').textContent = 'Замена исходного файла…';
    try {
        const data = await fileToolsPost('finalize_conversion', { id: derivativeId });
        const newToken = data.file?.token;
        if (!newToken) throw new Error('Сервер не вернул новый путь файла.');
        await loadTree({ announceInitialCache: false });
        await doSearch();
        const cardData = await fetchJson(`${api}?action=card&token=${encodeURIComponent(newToken)}`);
        fillCard(cardData.card);
        showMessage('Исходный файл удален. MP4 занял его место, карточка и служебные данные перепривязаны.', false);
    } catch (error) {
        $('fileToolsStatus').textContent = error.message;
        button.disabled = false;
    }
}

async function handleConvertButton() {
    const mode = $('convertMp4Btn').dataset.mode;
    if (mode === 'finalize') await finalizeConversion();
    else if (mode === 'convert') await startConversion();
}

function monitorFileToolJob(jobId, immediate = false) {
    if (!jobId || fileToolJobTimers.has(jobId)) return;
    const poll = async () => {
        try {
            const data = await fetchJson(`utilities/file_tools.php?action=job_status&id=${encodeURIComponent(jobId)}`);
            const job = data.job || {};
            if (job.status === 'ready' || job.status === 'error') {
                fileToolJobTimers.delete(jobId);
                if (currentFileToolsToken && !$('cardModal').classList.contains('hidden')) {
                    await loadFileTools(currentFileToolsToken);
                }
                if (job.status === 'ready' && job.action_type === 'transcript') {
                    await doSearch().catch(() => {});
                }
                if (job.status === 'error') $('fileToolsStatus').textContent = `${fileToolJobLabel(job)}: ${job.message || 'ошибка'}`;
                return;
            }
        } catch (error) {
            fileToolJobTimers.delete(jobId);
            return;
        }
        const timer = window.setTimeout(poll, 1000);
        fileToolJobTimers.set(jobId, timer);
    };
    if (immediate) poll();
    else {
        const timer = window.setTimeout(poll, 700);
        fileToolJobTimers.set(jobId, timer);
    }
}

async function openVideo(token, title = 'Просмотр видео', startSeconds = 0) {
    hideContextMenu();
    const modal = $('videoModal');
    const player = $('videoPlayer');
    const status = $('videoStatus');

    $('videoTitle').textContent = title || 'Просмотр видео';
    status.textContent = 'Загрузка видео...';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    try {
        const params = new URLSearchParams({ action: 'resolve_view', token });
        const resolved = await fetchJson(`utilities/file_tools.php?${params.toString()}`);
        player.src = resolved.url;
        if (resolved.converted) status.textContent = 'Воспроизводится сконвертированная MP4-копия.';
    } catch (error) {
        player.src = `media.php?token=${encodeURIComponent(token)}`;
        status.textContent = 'Не удалось проверить MP4-копию, открываю исходный файл.';
    }
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    const seekTo = Math.max(0, Number(startSeconds) || 0);
    if (seekTo > 0) {
        player.addEventListener('loadedmetadata', () => {
            try { player.currentTime = Math.min(seekTo, Number.isFinite(player.duration) ? Math.max(0, player.duration - 0.05) : seekTo); } catch (_) {}
        }, { once: true });
    }
    player.load();

    const playPromise = player.play();
    if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(() => {
            status.textContent = 'Нажмите кнопку воспроизведения.';
        });
    }
}

function closeVideo() {
    const modal = $('videoModal');
    const player = $('videoPlayer');
    player.pause();
    player.removeAttribute('src');
    player.load();
    $('videoStatus').textContent = '';
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
}

function openImage(url, caption = '') {
    openImageGallery([{ url, caption }], 0);
}

function closeActionsMenu() {
    const menu = $('actionsMenu');
    const button = $('actionsMenuButton');
    if (!menu || !button) return;
    menu.classList.remove('open');
    button.setAttribute('aria-expanded', 'false');
}

function toggleActionsMenu(event) {
    event?.stopPropagation();
    const menu = $('actionsMenu');
    const button = $('actionsMenuButton');
    if (!menu || !button) return;
    const open = !menu.classList.contains('open');
    menu.classList.toggle('open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function populateProviderModels(providerSelect, modelSelect, providers, selectedProvider, selectedModel) {
    providerSelect.innerHTML = '';
    for (const provider of providers || []) {
        const option = document.createElement('option');
        option.value = provider.id;
        option.textContent = provider.label;
        providerSelect.appendChild(option);
    }
    providerSelect.value = selectedProvider || providers?.[0]?.id || '';
    const refreshModels = () => {
        const provider = (providers || []).find(x => x.id === providerSelect.value) || providers?.[0];
        const desired = modelSelect.dataset.selectedModel || selectedModel || provider?.default_model || '';
        modelSelect.innerHTML = '';
        for (const model of provider?.models || []) {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.label || model.id;
            modelSelect.appendChild(option);
        }
        if ([...modelSelect.options].some(x => x.value === desired)) modelSelect.value = desired;
        else if (provider?.default_model) modelSelect.value = provider.default_model;
        delete modelSelect.dataset.selectedModel;
    };
    modelSelect.dataset.selectedModel = selectedModel || '';
    providerSelect.onchange = refreshModels;
    refreshModels();
}

async function openSettingsModal() {
    closeActionsMenu();
    $('settingsModal').classList.remove('hidden');
    $('settingsModal').setAttribute('aria-hidden', 'false');
    $('transcriptionSettingsStatus').textContent = 'Загрузка…';
    $('translationSettingsStatus').textContent = 'Загрузка…';
    $('authSettingsStatus').textContent = '';
    $('transcriptionApiKey').value = '';
    $('translationApiKey').value = '';
    try {
        const [trData, tlData, authData] = await Promise.all([
            fetchJson('utilities/transcription.php?action=settings'),
            fetchJson('utilities/translation.php?action=settings'),
            fetchJson(`${api}?action=auth_settings`),
        ]);
        const tr = trData.settings || {};
        populateProviderModels($('transcriptionProvider'), $('transcriptionModel'), tr.providers || [], tr.provider, tr.model);
        $('transcriptionKeyHint').textContent = tr.has_api_key
            ? `Ключ сохранен (${tr.api_key_hint || 'скрыт'}). Оставьте поле пустым, чтобы не менять его.`
            : 'API-ключ пока не задан.';
        $('transcriptionApiKey').placeholder = tr.has_api_key ? 'Оставьте пустым, чтобы сохранить текущий' : 'Введите API-ключ';
        $('transcriptionPythonPath').value = tr.python_path || '';
        $('transcriptionSettingsStatus').textContent = '';

        const tl = tlData.settings || {};
        populateProviderModels($('translationProvider'), $('translationModel'), tl.providers || [], tl.provider, tl.model);
        if (tl.has_api_key) {
            $('translationKeyHint').textContent = `Ключ перевода сохранен (${tl.api_key_hint || 'скрыт'}).`;
        } else if (tl.uses_transcription_key) {
            $('translationKeyHint').textContent = 'Используется API-ключ Groq из настроек транскрибации.';
        } else {
            $('translationKeyHint').textContent = 'API-ключ перевода не задан.';
        }
        $('translationApiKey').placeholder = tl.has_api_key ? 'Оставьте пустым, чтобы сохранить текущий' : 'Пусто = использовать ключ транскрибации Groq';
        $('translationPythonPath').value = tl.python_path || '';
        $('translationSettingsStatus').textContent = '';

        $('authNewUsername').value = authData.auth?.username || $('authNewUsername').value;
    } catch (error) {
        $('transcriptionSettingsStatus').textContent = error.message;
        $('translationSettingsStatus').textContent = error.message;
    }
}

function closeSettingsModal() {
    $('settingsModal').classList.add('hidden');
    $('settingsModal').setAttribute('aria-hidden', 'true');
    $('transcriptionApiKey').value = '';
    $('translationApiKey').value = '';
    $('authCurrentPassword').value = '';
    $('authNewPassword').value = '';
    $('authNewPasswordConfirm').value = '';
}

async function saveAuthSettings(event) {
    event.preventDefault();
    const button = $('authSettingsSubmitBtn');
    const currentPassword = $('authCurrentPassword').value;
    const newUsername = $('authNewUsername').value.trim();
    const newPassword = $('authNewPassword').value;
    const confirmPassword = $('authNewPasswordConfirm').value;
    if (newPassword !== confirmPassword) {
        $('authSettingsStatus').textContent = 'Новый пароль и подтверждение не совпадают.';
        return;
    }
    button.disabled = true;
    $('authSettingsStatus').textContent = 'Сохраняю…';
    try {
        const data = await postForm('update_auth', {
            current_password: currentPassword,
            new_username: newUsername,
            new_password: newPassword,
            new_password_confirm: confirmPassword,
        });
        $('authNewUsername').value = data.auth?.username || newUsername;
        $('authCurrentPassword').value = '';
        $('authNewPassword').value = '';
        $('authNewPasswordConfirm').value = '';
        $('authSettingsStatus').textContent = 'Сохранено.';
        showMessage('Логин и пароль обновлены.', false);
    } catch (error) {
        $('authSettingsStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function saveTranscriptionSettings(event) {
    event.preventDefault();
    const button = $('transcriptionSettingsSubmitBtn');
    button.disabled = true;
    $('transcriptionSettingsStatus').textContent = 'Сохраняю…';
    try {
        const data = await transcriptionPost('save_settings', {
            provider: $('transcriptionProvider').value,
            model: $('transcriptionModel').value,
            api_key: $('transcriptionApiKey').value.trim(),
            python_path: $('transcriptionPythonPath').value.trim(),
        });
        const settings = data.settings || {};
        $('transcriptionApiKey').value = '';
        $('transcriptionPythonPath').value = settings.python_path || $('transcriptionPythonPath').value.trim();
        $('transcriptionKeyHint').textContent = settings.has_api_key
            ? `Ключ сохранен (${settings.api_key_hint || 'скрыт'}). Оставьте поле пустым, чтобы не менять его.`
            : 'API-ключ пока не задан.';
        $('transcriptionSettingsStatus').textContent = 'Сохранено.';
    } catch (error) {
        $('transcriptionSettingsStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function saveTranslationSettings(event) {
    event.preventDefault();
    const button = $('translationSettingsSubmitBtn');
    button.disabled = true;
    $('translationSettingsStatus').textContent = 'Сохраняю…';
    try {
        const data = await translationPost('save_settings', {
            provider: $('translationProvider').value,
            model: $('translationModel').value,
            api_key: $('translationApiKey').value.trim(),
            python_path: $('translationPythonPath').value.trim(),
        });
        const settings = data.settings || {};
        $('translationApiKey').value = '';
        $('translationPythonPath').value = settings.python_path || $('translationPythonPath').value.trim();
        $('translationKeyHint').textContent = settings.has_api_key
            ? `Ключ перевода сохранен (${settings.api_key_hint || 'скрыт'}).`
            : settings.uses_transcription_key
                ? 'Используется API-ключ Groq из настроек транскрибации.'
                : 'API-ключ перевода не задан.';
        $('translationSettingsStatus').textContent = 'Сохранено.';
    } catch (error) {
        $('translationSettingsStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function openTranslationTargetModal(item) {
    translationTargetTranscript = item;
    translationImportFile = null;
    const modal = $('translationTargetModal');
    const select = $('translationTargetLanguage');
    $('translationTargetStatus').textContent = 'Загрузка языков…';
    $('translationImportFile').value = '';
    $('translationImportFileName').textContent = 'Файл не выбран';
    $('translationCustomName').value = '';
    $('translationCustomNameWrap').classList.add('hidden');
    select.innerHTML = '<option value="">Выберите язык…</option>';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    try {
        const data = await fetchJson('utilities/translation.php?action=settings');
        const settings = data.settings || {};
        const existing = new Set((item.translations || []).filter(x => x.translation_type !== 'custom').map(x => x.target_language));
        for (const language of settings.languages || []) {
            const option = document.createElement('option');
            option.value = language.id;
            const sourceKey = String(item.language || '').toLowerCase();
            const normalizedSource = sourceKey === 'russian' ? 'ru' : sourceKey === 'english' ? 'en' : sourceKey;
            const same = normalizedSource === language.id;
            const ready = existing.has(language.id);
            option.textContent = language.label + (same ? ' (оригинал)' : ready ? ' (уже есть)' : '');
            option.disabled = same || ready;
            select.appendChild(option);
        }
        const custom = document.createElement('option');
        custom.value = 'custom';
        custom.textContent = 'Пользовательский';
        select.appendChild(custom);
        $('translationTargetStatus').textContent = '';
        select.focus();
    } catch (error) {
        $('translationTargetStatus').textContent = error.message;
    }
}

function closeTranslationTargetModal() {
    $('translationTargetModal').classList.add('hidden');
    $('translationTargetModal').setAttribute('aria-hidden', 'true');
    $('translationTargetStatus').textContent = '';
    $('translationTargetLanguage').value = '';
    $('translationTargetLanguage').disabled = false;
    $('translationCustomNameWrap').classList.add('hidden');
    $('translationCustomName').value = '';
    $('translationImportFile').value = '';
    $('translationImportFileName').textContent = 'Файл не выбран';
    translationImportFile = null;
    translationTargetTranscript = null;
}

async function startSelectedTranslation() {
    const item = translationTargetTranscript;
    const target = $('translationTargetLanguage').value;
    if (!item || !target) return;
    const custom = target === 'custom';
    $('translationCustomNameWrap').classList.toggle('hidden', !custom);
    if (custom) {
        $('translationTargetStatus').textContent = translationImportFile ? 'Введите название и нажмите «Импортировать».' : 'Выберите или перетащите TXT-файл и введите название.';
        window.setTimeout(() => $('translationCustomName').focus(), 0);
        return;
    }
    $('translationTargetLanguage').disabled = true;
    $('translationTargetStatus').textContent = 'Запуск перевода…';
    try {
        const data = await translationPost('start', { transcript_id: item.id, target_language: target });
        const job = data.job || {};
        if (job.status === 'skipped' || job.status === 'ready') {
            showMessage(job.message || 'Перевод не требуется.', false);
            closeTranslationTargetModal();
            if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
            return;
        }
        closeTranslationTargetModal();
        $('fileToolsStatus').textContent = 'Перевод транскрипта запущен в фоне.';
        monitorTranslationJob(job.id, true);
        if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
    } catch (error) {
        $('translationTargetStatus').textContent = error.message;
    } finally {
        $('translationTargetLanguage').disabled = false;
    }
}


function setTranslationImportFile(file) {
    if (!file) {
        translationImportFile = null;
        $('translationImportFile').value = '';
        $('translationImportFileName').textContent = 'Файл не выбран';
        return;
    }
    if (!/\.txt$/i.test(file.name || '')) {
        $('translationTargetStatus').textContent = 'Нужен текстовый файл .txt.';
        return;
    }
    translationImportFile = file;
    $('translationImportFileName').textContent = `${file.name} · ${Math.max(1, Math.round(file.size / 1024))} КБ`;
    if ($('translationTargetLanguage').value !== 'custom') $('translationTargetLanguage').value = 'custom';
    $('translationCustomNameWrap').classList.remove('hidden');
    $('translationTargetStatus').textContent = 'Введите название пользовательского перевода.';
}

async function importCustomTranslation() {
    const item = translationTargetTranscript;
    if (!item) return;
    if ($('translationTargetLanguage').value !== 'custom') {
        $('translationTargetStatus').textContent = 'Выберите «Пользовательский».';
        return;
    }
    const name = $('translationCustomName').value.trim();
    if (!name) {
        $('translationTargetStatus').textContent = 'Введите название пользовательского перевода.';
        $('translationCustomName').focus();
        return;
    }
    if (!translationImportFile) {
        $('translationTargetStatus').textContent = 'Выберите TXT-файл.';
        return;
    }
    const button = $('translationImportStartBtn');
    button.disabled = true;
    $('translationTargetStatus').textContent = 'Импортирую…';
    try {
        const form = new FormData();
        form.append('action', 'import_custom');
        form.append('transcript_id', item.id);
        form.append('custom_name', name);
        form.append('file', translationImportFile, translationImportFile.name || 'translation.txt');
        await fetchJson('utilities/translation.php', { method: 'POST', body: form });
        closeTranslationTargetModal();
        showMessage('Пользовательский перевод импортирован.', false);
        if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
        if (currentTranscriptData && Number(currentTranscriptData.id) === Number(item.id)) await reloadCurrentTranscript();
    } catch (error) {
        $('translationTargetStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

function monitorTranslationJob(jobId, immediate = false) {
    if (!jobId || translationJobTimers.has(jobId)) return;
    const poll = async () => {
        try {
            const data = await fetchJson(`utilities/translation.php?action=job_status&id=${encodeURIComponent(jobId)}`);
            const job = data.job || {};
            if (job.status === 'ready' || job.status === 'error') {
                translationJobTimers.delete(jobId);
                if (currentFileToolsToken && !$('cardModal').classList.contains('hidden')) await loadFileTools(currentFileToolsToken);
                if (job.status === 'ready') showMessage('Перевод транскрипта готов.', false);
                else $('fileToolsStatus').textContent = `Перевод: ${job.message || 'ошибка'}`;
                return;
            }
            if (currentFileToolsToken && !$('cardModal').classList.contains('hidden')) await loadFileTools(currentFileToolsToken);
        } catch (_) {
            translationJobTimers.delete(jobId);
            return;
        }
        const timer = window.setTimeout(poll, 1200);
        translationJobTimers.set(jobId, timer);
    };
    if (immediate) poll();
    else translationJobTimers.set(jobId, window.setTimeout(poll, 800));
}

function transcriptViewStorageKey(id) {
    return `video_catalog_transcript_view_${id}`;
}

function renderTranscriptVersion(version) {
    const transcript = currentTranscriptData;
    if (!transcript) return;
    currentTranscriptVersion = version || 'original';
    const container = $('transcriptSegments');
    container.innerHTML = '';
    let translated = null;
    if (currentTranscriptVersion !== 'original') {
        const id = Number(String(currentTranscriptVersion).replace(/^translation:/, ''));
        translated = (transcript.translations || []).find(x => Number(x.id) === id) || null;
        if (!translated) currentTranscriptVersion = 'original';
    }
    const segments = translated ? (translated.segments || []) : (transcript.segments || []);
    for (const segment of segments) {
        const block = document.createElement('div');
        block.className = 'transcript-segment';
        block.dataset.segmentId = String(segment.id || '');

        const time = document.createElement('button');
        time.type = 'button';
        time.className = 'transcript-time-link';
        time.textContent = formatToolTime(segment.start);
        time.title = 'Открыть видео с этого момента';
        time.addEventListener('click', () => openVideo(transcript.token, transcript.title || 'Просмотр видео', Number(segment.start) || 0));

        const text = document.createElement('div');
        text.className = 'transcript-segment-text';
        text.textContent = segment.text || '';

        const actions = document.createElement('div');
        actions.className = 'transcript-segment-actions';
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'transcript-segment-icon';
        edit.textContent = '✎';
        edit.title = 'Редактировать фрагмент';
        edit.addEventListener('click', () => editTranscriptSegment(segment, text, block));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'transcript-segment-icon danger-icon';
        remove.textContent = '×';
        remove.title = 'Удалить фрагмент';
        remove.addEventListener('click', () => deleteTranscriptSegment(segment));
        actions.append(edit, remove);
        block.append(time, text, actions);
        container.appendChild(block);
    }
    if (!segments.length) container.textContent = translated ? (translated.full_text || 'Перевод пуст.') : (transcript.full_text || 'Транскрипт пуст.');
    if (translated) {
        $('transcriptDownload').href = translated.download_url || '#';
        $('transcriptDownload').setAttribute('download', '');
        $('transcriptMeta').textContent = [transcript.provider, transcript.model, `перевод: ${translated.language_label || translated.target_language}`, translated.model].filter(Boolean).join(' · ');
    } else {
        $('transcriptDownload').href = transcript.download_url || '#';
        $('transcriptDownload').setAttribute('download', transcript.download_name || 'transcript.txt');
        $('transcriptMeta').textContent = [transcript.provider, transcript.model, transcript.language ? transcriptLanguageLabel(transcript.language) : ''].filter(Boolean).join(' · ');
    }
    renderTranscriptVersionPicker(currentTranscriptVersion);
}

async function openTranscript(id) {
    const modal = $('transcriptModal');
    $('transcriptTitle').textContent = 'Транскрипт';
    $('transcriptMeta').textContent = 'Загрузка…';
    $('transcriptSegments').innerHTML = '';
    $('transcriptVersionPicker').classList.add('hidden');
    $('transcriptVersionMenu').classList.add('hidden');
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    try {
        const data = await fetchJson(`utilities/transcription.php?action=get&id=${encodeURIComponent(id)}`);
        currentTranscriptData = data.transcript || {};
        $('transcriptTitle').textContent = currentTranscriptData.title || 'Транскрипт';
        const remembered = localStorage.getItem(transcriptViewStorageKey(currentTranscriptData.id)) || 'original';
        const valid = remembered === 'original' || (currentTranscriptData.translations || []).some(x => `translation:${x.id}` === remembered);
        currentTranscriptVersion = valid ? remembered : 'original';
        renderTranscriptVersion(currentTranscriptVersion);
    } catch (error) {
        $('transcriptMeta').textContent = error.message;
    }
}


function transcriptVersionLabel(version) {
    if (!currentTranscriptData || version === 'original') return 'Показать оригинал';
    const id = Number(String(version).replace(/^translation:/, ''));
    const t = (currentTranscriptData.translations || []).find(x => Number(x.id) === id);
    return t ? (t.language_label || t.target_language || 'Перевод') : 'Показать оригинал';
}

function renderTranscriptVersionPicker(version) {
    const picker = $('transcriptVersionPicker');
    const menu = $('transcriptVersionMenu');
    const translations = currentTranscriptData?.translations || [];
    picker.classList.toggle('hidden', translations.length === 0);
    $('transcriptVersionButton').textContent = `${transcriptVersionLabel(version)} ▾`;
    menu.innerHTML = '';

    const original = document.createElement('button');
    original.type = 'button';
    original.className = 'transcript-version-menu-row' + (version === 'original' ? ' active' : '');
    original.textContent = 'Показать оригинал';
    original.addEventListener('click', () => selectTranscriptVersion('original'));
    menu.appendChild(original);

    for (const translation of translations) {
        const value = `translation:${translation.id}`;
        const row = document.createElement('div');
        row.className = 'transcript-version-menu-item' + (version === value ? ' active' : '');
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'transcript-version-menu-row';
        choose.textContent = translation.language_label || translation.target_language || 'Перевод';
        choose.addEventListener('click', () => selectTranscriptVersion(value));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'transcript-version-delete';
        remove.textContent = '×';
        remove.title = 'Удалить перевод';
        remove.addEventListener('click', (event) => {
            event.stopPropagation();
            deleteTranscriptTranslation(translation);
        });
        row.append(choose, remove);
        menu.appendChild(row);
    }
}

function selectTranscriptVersion(version) {
    if (!currentTranscriptData) return;
    currentTranscriptVersion = version;
    localStorage.setItem(transcriptViewStorageKey(currentTranscriptData.id), version);
    $('transcriptVersionMenu').classList.add('hidden');
    renderTranscriptVersion(version);
}

async function reloadCurrentTranscript(preferredVersion = currentTranscriptVersion) {
    if (!currentTranscriptData?.id) return;
    const id = currentTranscriptData.id;
    const data = await fetchJson(`utilities/transcription.php?action=get&id=${encodeURIComponent(id)}`);
    currentTranscriptData = data.transcript || {};
    const valid = preferredVersion === 'original' || (currentTranscriptData.translations || []).some(x => `translation:${x.id}` === preferredVersion);
    currentTranscriptVersion = valid ? preferredVersion : 'original';
    localStorage.setItem(transcriptViewStorageKey(id), currentTranscriptVersion);
    renderTranscriptVersion(currentTranscriptVersion);
}

async function deleteTranscriptTranslation(translation) {
    if (!translation || !confirm(`Удалить перевод «${translation.language_label || translation.target_language}»?`)) return;
    try {
        await translationPost('delete_translation', { id: translation.id });
        const removedVersion = `translation:${translation.id}`;
        if (currentTranscriptVersion === removedVersion) currentTranscriptVersion = 'original';
        await reloadCurrentTranscript(currentTranscriptVersion);
        if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
    } catch (error) {
        $('transcriptMeta').textContent = error.message;
    }
}

async function editTranscriptSegment(segment, textElement, block) {
    if (!currentTranscriptData || block.classList.contains('editing')) return;
    block.classList.add('editing');
    const textarea = document.createElement('textarea');
    textarea.className = 'transcript-segment-editor';
    textarea.value = segment.text || '';
    textElement.replaceWith(textarea);
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    let done = false;
    const save = async () => {
        if (done) return;
        done = true;
        const value = textarea.value.trim();
        try {
            const data = await transcriptionPost('segment_update', {
                transcript_id: currentTranscriptData.id,
                version: currentTranscriptVersion,
                segment_id: segment.id,
                text: value,
            });
            currentTranscriptData = data.transcript || currentTranscriptData;
            renderTranscriptVersion(currentTranscriptVersion);
            if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
            doSearch();
        } catch (error) {
            done = false;
            $('transcriptMeta').textContent = error.message;
            textarea.focus();
        }
    };
    textarea.addEventListener('blur', save, { once: true });
    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            done = true;
            renderTranscriptVersion(currentTranscriptVersion);
        } else if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            textarea.blur();
        }
    });
}

async function deleteTranscriptSegment(segment) {
    if (!currentTranscriptData || !confirm('Удалить этот фрагмент?')) return;
    try {
        const data = await transcriptionPost('segment_delete', {
            transcript_id: currentTranscriptData.id,
            version: currentTranscriptVersion,
            segment_id: segment.id,
        });
        currentTranscriptData = data.transcript || currentTranscriptData;
        renderTranscriptVersion(currentTranscriptVersion);
        if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
        doSearch();
    } catch (error) {
        $('transcriptMeta').textContent = error.message;
    }
}

function openTranscriptAddModal() {
    if (!currentTranscriptData) return;
    $('transcriptAddInput').value = '';
    $('transcriptAddStatus').textContent = '';
    $('transcriptAddModal').classList.remove('hidden');
    $('transcriptAddModal').setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('transcriptAddInput').focus(), 0);
}

function closeTranscriptAddModal() {
    $('transcriptAddModal').classList.add('hidden');
    $('transcriptAddModal').setAttribute('aria-hidden', 'true');
    $('transcriptAddStatus').textContent = '';
}

async function saveTranscriptAddedSegment() {
    if (!currentTranscriptData) return;
    const input = $('transcriptAddInput').value.trim();
    if (!input) {
        $('transcriptAddStatus').textContent = 'Введите [hh:mm:ss] и текст фрагмента.';
        return;
    }
    const button = $('transcriptAddSaveBtn');
    button.disabled = true;
    $('transcriptAddStatus').textContent = 'Сохраняю…';
    try {
        const data = await transcriptionPost('segment_add', {
            transcript_id: currentTranscriptData.id,
            version: currentTranscriptVersion,
            input,
        });
        currentTranscriptData = data.transcript || currentTranscriptData;
        closeTranscriptAddModal();
        renderTranscriptVersion(currentTranscriptVersion);
        if (currentFileToolsToken) await loadFileTools(currentFileToolsToken);
        doSearch();
    } catch (error) {
        $('transcriptAddStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

function closeTranscript() {
    $('transcriptModal').classList.add('hidden');
    $('transcriptModal').setAttribute('aria-hidden', 'true');
    $('transcriptSegments').innerHTML = '';
    $('transcriptVersionMenu').classList.add('hidden');
    currentTranscriptData = null;
    currentTranscriptVersion = 'original';
}

function logoutApplication() {
    closeActionsMenu();
    window.location.href = 'index.php?logout=1';
}

function openMetadataViewModal() {
    closeActionsMenu();
    const root = ($('rootPath').value || currentRoot || '').trim();
    if (!root) {
        showMessage('Сначала выберите корневую папку каталога.');
        return;
    }

    $('metadataViewRoot').textContent = root;
    $('metadataViewSearch').value = '';
    $('metadataViewCount').textContent = '';
    $('metadataViewStatus').textContent = 'Загрузка…';
    $('metadataViewTableBody').innerHTML = '';
    $('metadataViewTableWrap').classList.add('hidden');
    $('metadataViewModal').classList.remove('hidden');
    $('metadataViewModal').setAttribute('aria-hidden', 'false');

    loadMetadataView().catch(() => {});
    window.setTimeout(() => $('metadataViewSearch').focus(), 0);
}

function closeMetadataViewModal() {
    $('metadataViewModal').classList.add('hidden');
    $('metadataViewModal').setAttribute('aria-hidden', 'true');
    metadataViewRows = [];
    $('metadataViewTableBody').innerHTML = '';
    $('metadataViewCount').textContent = '';
}

async function loadMetadataView({ silent = false } = {}) {
    const root = ($('rootPath').value || currentRoot || '').trim();
    if (!root) {
        if (!silent) $('metadataViewStatus').textContent = 'Сначала выберите корневую папку каталога.';
        return;
    }

    if (!silent) {
        $('metadataViewStatus').textContent = 'Загрузка…';
        $('metadataViewTableWrap').classList.add('hidden');
    }

    try {
        const params = new URLSearchParams({ root });
        const data = await fetchJson(`utilities/view_metadata.php?${params.toString()}`);
        metadataViewRows = Array.isArray(data.rows) ? data.rows : [];
        $('metadataViewRoot').textContent = data.root || root;
        renderMetadataViewRows();
    } catch (error) {
        metadataViewRows = [];
        $('metadataViewTableBody').innerHTML = '';
        $('metadataViewTableWrap').classList.add('hidden');
        $('metadataViewCount').textContent = '';
        $('metadataViewStatus').textContent = error.message;
        if (!silent) showMessage(error.message);
        throw error;
    }
}

function metadataViewSearchValue(row) {
    return [
        row.display_title,
        row.custom_title,
        row.file_name,
        row.relative_path,
        row.note,
        row.category_name,
    ].filter(Boolean).join('\n').toLocaleLowerCase('ru-RU');
}

function renderMetadataViewRows() {
    const query = $('metadataViewSearch').value.trim().toLocaleLowerCase('ru-RU');
    const body = $('metadataViewTableBody');
    const tableWrap = $('metadataViewTableWrap');
    const status = $('metadataViewStatus');
    body.innerHTML = '';

    const filtered = query
        ? metadataViewRows.filter((row) => metadataViewSearchValue(row).includes(query))
        : metadataViewRows;

    $('metadataViewCount').textContent = `Показано ${filtered.length} из ${metadataViewRows.length}`;

    if (!metadataViewRows.length) {
        tableWrap.classList.add('hidden');
        status.textContent = 'В кэше выбранной папки нет видеофайлов.';
        return;
    }

    tableWrap.classList.remove('hidden');
    status.textContent = filtered.length
        ? 'Кликните по строке, чтобы открыть обычную карточку файла.'
        : 'По вашему запросу ничего не найдено.';

    for (const item of filtered) {
        const row = document.createElement('tr');
        row.className = 'metadata-view-row';
        row.tabIndex = 0;
        row.title = 'Открыть карточку файла';

        const titleCell = document.createElement('td');
        const title = document.createElement('strong');
        title.className = 'metadata-view-title';
        title.textContent = item.display_title || item.file_name || 'Без названия';
        titleCell.appendChild(title);

        const original = document.createElement('div');
        original.className = 'metadata-view-file muted';
        original.textContent = item.relative_path || item.file_name || '';
        titleCell.appendChild(original);

        if (item.category_name) {
            const category = document.createElement('span');
            category.className = 'badge metadata-view-category';
            category.textContent = item.category_name;
            titleCell.appendChild(category);
        }

        const noteCell = document.createElement('td');
        noteCell.className = 'metadata-view-note';
        noteCell.textContent = item.note || '—';

        const open = () => openCard(item.token);
        row.addEventListener('click', open);
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });

        row.append(titleCell, noteCell);
        body.appendChild(row);
    }
}

function openScreenshotViewModal() {
    closeActionsMenu();
    const root = ($('rootPath').value || currentRoot || '').trim();
    if (!root) {
        showMessage('Сначала выберите корневую папку каталога.');
        return;
    }

    $('screenshotViewRoot').textContent = root;
    $('screenshotViewSearch').value = '';
    $('screenshotViewCount').textContent = '';
    $('screenshotViewStatus').textContent = 'Загрузка…';
    $('screenshotViewRows').innerHTML = '';
    $('screenshotViewRows').classList.add('hidden');
    $('screenshotViewModal').classList.remove('hidden');
    $('screenshotViewModal').setAttribute('aria-hidden', 'false');

    loadScreenshotView().catch(() => {});
    window.setTimeout(() => $('screenshotViewSearch').focus(), 0);
}

function closeScreenshotViewModal() {
    $('screenshotViewModal').classList.add('hidden');
    $('screenshotViewModal').setAttribute('aria-hidden', 'true');
    screenshotViewRows = [];
    $('screenshotViewRows').innerHTML = '';
    $('screenshotViewCount').textContent = '';
}

async function loadScreenshotView({ silent = false } = {}) {
    const root = ($('rootPath').value || currentRoot || '').trim();
    if (!root) {
        if (!silent) $('screenshotViewStatus').textContent = 'Сначала выберите корневую папку каталога.';
        return;
    }

    if (!silent) {
        $('screenshotViewStatus').textContent = 'Загрузка…';
        $('screenshotViewRows').classList.add('hidden');
    }

    try {
        const params = new URLSearchParams({ root });
        const data = await fetchJson(`utilities/view_screenshots.php?${params.toString()}`);
        screenshotViewRows = Array.isArray(data.rows) ? data.rows : [];
        $('screenshotViewRoot').textContent = data.root || root;
        renderScreenshotViewRows();
    } catch (error) {
        screenshotViewRows = [];
        $('screenshotViewRows').innerHTML = '';
        $('screenshotViewRows').classList.add('hidden');
        $('screenshotViewCount').textContent = '';
        $('screenshotViewStatus').textContent = error.message;
        if (!silent) showMessage(error.message);
        throw error;
    }
}

function screenshotViewSearchValue(row) {
    return [
        row.display_title,
        row.custom_title,
        row.file_name,
        row.relative_path,
        row.category_name,
    ].filter(Boolean).join('\n').toLocaleLowerCase('ru-RU');
}

function screenshotViewStatusLabel(item) {
    if (Array.isArray(item.screenshots) && item.screenshots.length) return '';
    const status = item.screenshot_status || 'missing';
    if (status === 'pending') return 'Кадры запланированы';
    if (status === 'processing') return 'Кадры создаются';
    if (status === 'paused') return 'Создание кадров приостановлено';
    if (status === 'error') return item.last_error ? `Ошибка: ${item.last_error}` : 'Ошибка создания кадров';
    return 'Кадры не созданы';
}

function renderScreenshotViewRows() {
    const query = $('screenshotViewSearch').value.trim().toLocaleLowerCase('ru-RU');
    const box = $('screenshotViewRows');
    const status = $('screenshotViewStatus');
    box.innerHTML = '';

    const filtered = query
        ? screenshotViewRows.filter((row) => screenshotViewSearchValue(row).includes(query))
        : screenshotViewRows;

    $('screenshotViewCount').textContent = `Показано ${filtered.length} из ${screenshotViewRows.length}`;

    if (!screenshotViewRows.length) {
        box.classList.add('hidden');
        status.textContent = 'В кэше выбранной папки нет видеофайлов.';
        return;
    }

    box.classList.remove('hidden');
    status.textContent = filtered.length
        ? 'Клик по кадру открывает просмотр; листание ограничено пятью кадрами этой строки.'
        : 'По вашему запросу ничего не найдено.';

    for (const item of filtered) {
        const row = document.createElement('div');
        row.className = 'screenshot-view-row';

        const info = document.createElement('div');
        info.className = 'screenshot-view-info';
        const title = document.createElement('strong');
        title.className = 'screenshot-view-title';
        title.textContent = item.display_title || item.file_name || 'Без названия';
        const path = document.createElement('div');
        path.className = 'screenshot-view-file muted';
        path.textContent = item.relative_path || item.file_name || '';
        info.append(title, path);
        if (item.category_name) {
            const category = document.createElement('span');
            category.className = 'badge screenshot-view-category';
            category.textContent = item.category_name;
            info.appendChild(category);
        }

        const frames = document.createElement('div');
        frames.className = 'screenshot-view-thumbs';
        const shots = Array.isArray(item.screenshots) ? item.screenshots.slice(0, 5) : [];
        const viewerItems = shots.map((shot, index) => ({
            url: shot.url,
            caption: `${item.display_title || item.file_name} · кадр ${index + 1} · ${formatScreenshotTime(shot.position_seconds)}`,
            screenshotId: Number(shot.id) || 0,
            isThumbnail: Boolean(shot.is_thumbnail),
        }));

        if (shots.length) {
            shots.forEach((shot, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'screenshot-view-thumb';
                button.title = `Открыть кадр ${index + 1} (${formatScreenshotTime(shot.position_seconds)})`;
                const image = document.createElement('img');
                image.src = shot.url;
                image.alt = `Кадр ${index + 1}`;
                image.loading = 'lazy';
                const time = document.createElement('span');
                time.textContent = formatScreenshotTime(shot.position_seconds);
                button.append(image, time);
                button.addEventListener('click', () => openImageGallery(viewerItems, index));
                frames.appendChild(button);
            });
        } else {
            const empty = document.createElement('div');
            empty.className = 'screenshot-view-empty muted';
            empty.textContent = screenshotViewStatusLabel(item);
            frames.appendChild(empty);
        }

        const actions = document.createElement('div');
        actions.className = 'screenshot-view-actions';
        const go = document.createElement('button');
        go.type = 'button';
        go.className = 'primary compact';
        go.textContent = 'Перейти';
        go.addEventListener('click', () => openCard(item.token));
        actions.appendChild(go);

        row.append(info, frames, actions);
        box.appendChild(row);
    }
}

function openMetadataImportModal() {
    closeActionsMenu();
    const root = ($('rootPath').value || currentRoot || '').trim();
    if (!root) {
        showMessage('Сначала выберите корневую папку каталога.');
        return;
    }
    $('metadataImportRoot').textContent = root;
    $('metadataFileInput').value = '';
    $('metadataOverwriteBlanks').checked = false;
    $('metadataImportStatus').textContent = '';
    $('metadataImportResult').innerHTML = '';
    $('metadataImportResult').classList.add('hidden');
    $('metadataImportModal').classList.remove('hidden');
    $('metadataImportModal').setAttribute('aria-hidden', 'false');
}

function closeMetadataImportModal() {
    $('metadataImportModal').classList.add('hidden');
    $('metadataImportModal').setAttribute('aria-hidden', 'true');
    $('metadataImportStatus').textContent = '';
}

function metadataStatusLabel(status) {
    return {
        updated: 'Обновлено',
        unchanged: 'Без изменений',
        not_found: 'Не найдено',
        ambiguous: 'Неоднозначно',
        skipped: 'Пропущено',
        error: 'Ошибка',
    }[status] || status;
}

function renderMetadataImportResult(result) {
    const box = $('metadataImportResult');
    const summary = result.summary || {};
    box.innerHTML = '';
    box.classList.remove('hidden');

    const summaryGrid = document.createElement('div');
    summaryGrid.className = 'metadata-summary-grid';
    const stats = [
        ['Строк обработано', summary.table_rows || 0],
        ['Карточек обновлено', summary.updated || 0],
        ['Новых карточек', summary.created_cards || 0],
        ['Без изменений', summary.unchanged || 0],
        ['Не найдено', summary.not_found || 0],
        ['Неоднозначно', summary.ambiguous || 0],
        ['Пропущено', summary.skipped || 0],
        ['Ошибок', summary.errors || 0],
        ['Создано категорий', summary.categories_created || 0],
    ];
    for (const [label, value] of stats) {
        const item = document.createElement('div');
        item.className = 'metadata-summary-item';
        const number = document.createElement('strong');
        number.textContent = String(value);
        const caption = document.createElement('span');
        caption.textContent = label;
        item.append(number, caption);
        summaryGrid.appendChild(item);
    }
    box.appendChild(summaryGrid);

    if (!Array.isArray(result.details) || !result.details.length) return;

    const details = document.createElement('details');
    details.className = 'metadata-details';
    const detailsSummary = document.createElement('summary');
    detailsSummary.textContent = `Подробный отчет (${result.details.length}${result.details_limited ? '+' : ''})`;
    details.appendChild(detailsSummary);

    const tableWrap = document.createElement('div');
    tableWrap.className = 'metadata-table-wrap';
    const table = document.createElement('table');
    table.className = 'metadata-result-table';
    table.innerHTML = '<thead><tr><th>Строка</th><th>Файл</th><th>Статус</th><th>Результат</th></tr></thead>';
    const body = document.createElement('tbody');

    for (const detail of result.details) {
        const row = document.createElement('tr');
        row.className = `metadata-row-${detail.status || 'unknown'}`;
        const rowNumber = document.createElement('td');
        rowNumber.textContent = detail.row || '';
        const file = document.createElement('td');
        file.textContent = detail.file || '—';
        const status = document.createElement('td');
        status.textContent = metadataStatusLabel(detail.status);
        const message = document.createElement('td');
        message.textContent = detail.message || '';
        if (Array.isArray(detail.matches) && detail.matches.length) {
            const matches = document.createElement('div');
            matches.className = 'metadata-match-list';
            matches.textContent = detail.matches.join(' · ');
            message.appendChild(matches);
        }
        row.append(rowNumber, file, status, message);
        body.appendChild(row);
    }

    table.appendChild(body);
    tableWrap.appendChild(table);
    details.appendChild(tableWrap);
    box.appendChild(details);
}

async function importMetadata(event) {
    event.preventDefault();
    const root = ($('rootPath').value || currentRoot || '').trim();
    const file = $('metadataFileInput').files[0];
    if (!root) return showMessage('Сначала выберите корневую папку каталога.');
    if (!file) {
        $('metadataImportStatus').textContent = 'Выберите Excel-файл.';
        return;
    }

    const button = $('metadataImportSubmitBtn');
    const form = new FormData();
    form.append('root', root);
    form.append('metadata_file', file);
    if ($('metadataOverwriteBlanks').checked) form.append('overwrite_blanks', '1');

    button.disabled = true;
    $('metadataImportStatus').textContent = 'Импортирую…';
    $('metadataImportResult').classList.add('hidden');

    try {
        const data = await fetchJson('utilities/import_metadata.php', { method: 'POST', body: form });
        renderMetadataImportResult(data.result);
        $('metadataImportStatus').textContent = 'Импорт завершен.';
        await loadCategories();
        if (currentRoot) await loadTree({ announceInitialCache: false });
        if ($('searchInput').value.trim() || $('categoryFilter').value) await doSearch();
    } catch (error) {
        $('metadataImportStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

function openImageGallery(items, startIndex = 0) {
    imageViewerItems = Array.isArray(items)
        ? items.filter((item) => item && item.url).map((item) => ({
            url: item.url,
            caption: item.caption || '',
            screenshotId: Number(item.screenshotId) || 0,
            isThumbnail: Boolean(item.isThumbnail),
        }))
        : [];
    if (!imageViewerItems.length) return;

    imageViewerIndex = Math.max(0, Math.min(Number(startIndex) || 0, imageViewerItems.length - 1));
    renderImageViewer();
    $('imageModal').classList.remove('hidden');
    $('imageModal').setAttribute('aria-hidden', 'false');
}

function renderImageViewer() {
    const item = imageViewerItems[imageViewerIndex];
    if (!item) return;

    $('bigImage').src = item.url;
    $('bigImage').alt = item.caption || 'Изображение';

    const hasMultiple = imageViewerItems.length > 1;
    $('imagePrevBtn').classList.toggle('hidden', !hasMultiple);
    $('imageNextBtn').classList.toggle('hidden', !hasMultiple);
    $('imageViewerInfo').classList.toggle('hidden', !hasMultiple && !item.caption);
    $('imageViewerCounter').textContent = hasMultiple
        ? `${imageViewerIndex + 1} / ${imageViewerItems.length}`
        : '';
    $('imageViewerCaption').textContent = item.caption || '';

    const star = $('setThumbnailBtn');
    const canSetThumbnail = Number(item.screenshotId) > 0;
    star.classList.toggle('hidden', !canSetThumbnail);
    star.classList.toggle('selected', canSetThumbnail && Boolean(item.isThumbnail));
    star.textContent = item.isThumbnail ? '★' : '☆';
    star.title = item.isThumbnail ? 'Этот кадр используется как миниатюра' : 'Сделать кадр миниатюрой';
    star.setAttribute('aria-label', star.title);
}

async function setCurrentImageAsThumbnail() {
    const item = imageViewerItems[imageViewerIndex];
    if (!item || !Number(item.screenshotId)) return;

    const button = $('setThumbnailBtn');
    button.disabled = true;
    try {
        await postForm('set_video_thumbnail', { screenshot_id: item.screenshotId });
        imageViewerItems.forEach((viewerItem) => { viewerItem.isThumbnail = false; });
        item.isThumbnail = true;

        for (const row of screenshotViewRows) {
            for (const shot of row.screenshots || []) {
                shot.is_thumbnail = Number(shot.id) === Number(item.screenshotId);
            }
        }

        renderImageViewer();
        if (!$('screenshotViewModal').classList.contains('hidden')) renderScreenshotViewRows();

        const cardToken = $('cardToken').value;
        if (cardToken && !$('cardModal').classList.contains('hidden')) {
            const cardData = await fetchJson(`${api}?action=card&token=${encodeURIComponent(cardToken)}`);
            fillCard(cardData.card);
        }
        if (currentRoot && currentTree) {
            await loadTree({ announceInitialCache: false, processScreenshots: false });
        }
        showMessage('Миниатюра видео изменена.', false);
    } catch (error) {
        showMessage(error.message);
    } finally {
        button.disabled = false;
    }
}

function showImageAt(index) {
    if (imageViewerItems.length < 2) return;
    imageViewerIndex = (index + imageViewerItems.length) % imageViewerItems.length;
    renderImageViewer();
}

function showPreviousImage() {
    showImageAt(imageViewerIndex - 1);
}

function showNextImage() {
    showImageAt(imageViewerIndex + 1);
}


function libraryTransferPost(action, values = {}, file = null) {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => form.append(key, value ?? ''));
    if (file) form.append('archive', file, file.name || 'solanace_export.zip');
    return fetchJson('utilities/library_transfer.php', { method: 'POST', body: form });
}

function formatTransferSize(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return `${value} Б`;
    const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    let n = value / 1024;
    let unit = units[0];
    for (let i = 1; i < units.length && n >= 1024; i++) { n /= 1024; unit = units[i]; }
    return `${n >= 100 ? n.toFixed(0) : n >= 10 ? n.toFixed(1) : n.toFixed(2)} ${unit}`;
}

async function exportCurrentLibrary() {
    closeActionsMenu();
    const root = String($('rootPath')?.value || currentRoot || '').trim();
    if (!root) return showMessage('Сначала выберите корневую папку библиотеки.');
    if (!confirm('Создать ZIP-экспорт текущей библиотеки? Архив с базой и служебным кэшем будет сохранен в корне выбранной папки. Исходные видео в архив не включаются.')) return;
    const button = $('exportLibraryBtn');
    button.disabled = true;
    showMessage('Создаю экспорт библиотеки. Для большого кэша это может занять некоторое время…', false);
    try {
        const data = await libraryTransferPost('export', { root });
        const result = data.export || {};
        showMessage(`Экспорт готов: ${result.file_name || 'ZIP'} · ${formatTransferSize(result.size)} · файлов библиотеки: ${result.files || 0}. Архив сохранен в корне библиотеки.`, false);
    } catch (error) {
        showMessage(error.message);
    } finally {
        button.disabled = false;
    }
}

async function refreshLibraryImportList() {
    const root = String($('rootPath')?.value || currentRoot || '').trim();
    const select = $('libraryImportServerZip');
    if (!root || !select) return;
    select.innerHTML = '<option value="">Загрузка списка…</option>';
    try {
        const data = await libraryTransferPost('list_exports', { root });
        const items = Array.isArray(data.exports) ? data.exports : [];
        select.innerHTML = '<option value="">Выберите архив…</option>';
        for (const item of items) {
            const option = document.createElement('option');
            option.value = item.name;
            option.textContent = `${item.name} · ${formatTransferSize(item.size)}`;
            select.appendChild(option);
        }
        if (!items.length) {
            const option = document.createElement('option');
            option.value = '';
            option.disabled = true;
            option.textContent = 'В корне нет экспортов Solanace';
            select.appendChild(option);
        }
    } catch (error) {
        select.innerHTML = '<option value="">Не удалось получить список</option>';
        $('libraryImportStatus').textContent = error.message;
    }
}

async function openLibraryImportModal() {
    closeActionsMenu();
    const root = String($('rootPath')?.value || currentRoot || '').trim();
    if (!root) return showMessage('Сначала выберите корневую папку библиотеки.');
    $('libraryImportRoot').textContent = root;
    $('libraryImportFile').value = '';
    if ($('libraryImportSubdir')) $('libraryImportSubdir').value = '';
    $('libraryImportStatus').textContent = '';
    $('libraryImportModal').classList.remove('hidden');
    $('libraryImportModal').setAttribute('aria-hidden', 'false');
    await refreshLibraryImportList();
}

function closeLibraryImportModal() {
    $('libraryImportModal').classList.add('hidden');
    $('libraryImportModal').setAttribute('aria-hidden', 'true');
    $('libraryImportStatus').textContent = '';
}

async function importCurrentLibrary() {
    const root = String($('rootPath')?.value || currentRoot || '').trim();
    const localFile = $('libraryImportFile').files?.[0] || null;
    const serverZip = String($('libraryImportServerZip').value || '').trim();
    const pathPrefix = String($('libraryImportSubdir')?.value || '').trim();
    if (!localFile && !serverZip) {
        $('libraryImportStatus').textContent = 'Выберите ZIP из корня библиотеки или загрузите архив с компьютера.';
        return;
    }
    if (!confirm('Импортировать метаданные и служебный кэш в текущую библиотеку? Существующие исходные видео не будут перемещены или удалены.')) return;
    const button = $('libraryImportStartBtn');
    button.disabled = true;
    $('libraryImportStatus').textContent = 'Импортирую архив и сопоставляю файлы…';
    try {
        const values = { root, path_prefix: pathPrefix };
        if (!localFile) values.server_zip = serverZip;
        const data = await libraryTransferPost('import', values, localFile);
        const result = data.import || {};
        const missing = Array.isArray(result.missing_files) ? result.missing_files : [];
        $('libraryImportStatus').textContent = `Готово. Сопоставлено видео: ${result.mapped_files || 0}; карточек: ${result.cards || 0}; аудио/фрагментов: ${result.derivatives || 0}; транскриптов: ${result.transcripts || 0}; файлов кэша: ${result.cache_files || 0}.${result.path_prefix ? ` Подпапка: ${result.path_prefix}.` : ''}${missing.length ? ` Не найдено/изменено: ${missing.length}.` : ''}`;
        await loadTree({ announceInitialCache: false, processScreenshots: false });
        if (missing.length) showMessage(`Импорт завершен, но ${missing.length} файлов не удалось сопоставить. Первые: ${missing.slice(0, 5).join('; ')}`, true);
        else showMessage('Импорт библиотеки успешно завершен.', false);
        await refreshLibraryImportList();
    } catch (error) {
        $('libraryImportStatus').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

function closeImage() {
    $('imageModal').classList.add('hidden');
    $('imageModal').setAttribute('aria-hidden', 'true');
    $('bigImage').src = '';
    $('setThumbnailBtn').classList.add('hidden');
    $('setThumbnailBtn').classList.remove('selected');
    $('setThumbnailBtn').disabled = false;
    imageViewerItems = [];
    imageViewerIndex = 0;
    imageViewerTouchStartX = null;
}

document.addEventListener('click', (event) => {
    hideContextMenu();
    if (!event.target.closest('.actions-dropdown')) closeActionsMenu();
    if (event.target.dataset.close) closeCard();
    if (event.target.dataset.closeImage) closeImage();
    if (event.target.dataset.closeMove) closeMoveModal();
    if (event.target.dataset.closeMerge) closeMergeModal();
    if (event.target.dataset.closeVideo) closeVideo();
    if (event.target.dataset.closeAudioTool) closeAudioToolModal();
    if (event.target.dataset.closeMetadataImport) closeMetadataImportModal();
    if (event.target.dataset.closeMetadataView) closeMetadataViewModal();
    if (event.target.dataset.closeScreenshotView) closeScreenshotViewModal();
    if (event.target.dataset.closeSettings) closeSettingsModal();
    if (event.target.dataset.closeLibraryImport) closeLibraryImportModal();
    if (event.target.dataset.closeTranslationTarget) closeTranslationTargetModal();
    if (event.target.dataset.closeTranscriptAdd) closeTranscriptAddModal();
    if (event.target.dataset.closeTranscript) closeTranscript();
    if (!event.target.closest('#transcriptVersionPicker')) $('transcriptVersionMenu')?.classList.add('hidden');
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        hideContextMenu();
        closeActionsMenu();

        if (!$('imageModal').classList.contains('hidden')) {
            closeImage();
            return;
        }
        if (!$('videoModal').classList.contains('hidden')) {
            closeVideo();
            return;
        }
        if (!$('transcriptAddModal').classList.contains('hidden')) {
            closeTranscriptAddModal();
            return;
        }
        if (!$('transcriptModal').classList.contains('hidden')) {
            closeTranscript();
            return;
        }
        if (!$('audioToolModal').classList.contains('hidden')) {
            closeAudioToolModal();
            return;
        }
        if (!$('cardModal').classList.contains('hidden')) {
            closeCard();
            return;
        }
        if (!$('mergeModal').classList.contains('hidden')) {
            closeMergeModal();
            return;
        }
        if (!$('moveModal').classList.contains('hidden')) {
            closeMoveModal();
            return;
        }
        if (!$('metadataImportModal').classList.contains('hidden')) {
            closeMetadataImportModal();
            return;
        }
        if (!$('metadataViewModal').classList.contains('hidden')) {
            closeMetadataViewModal();
            return;
        }
        if (!$('screenshotViewModal').classList.contains('hidden')) {
            closeScreenshotViewModal();
            return;
        }
        if (!$('translationTargetModal').classList.contains('hidden')) {
            closeTranslationTargetModal();
            return;
        }
        if (!$('libraryImportModal').classList.contains('hidden')) {
            closeLibraryImportModal();
            return;
        }
        if (!$('settingsModal').classList.contains('hidden')) {
            closeSettingsModal();
            return;
        }
    }
    if (!$('imageModal').classList.contains('hidden')) {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            showPreviousImage();
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            showNextImage();
        } else if (event.key === 'Home') {
            event.preventDefault();
            showImageAt(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            showImageAt(imageViewerItems.length - 1);
        }
    }
    if (event.key === 'Delete' && selectedItems.size
        && $('cardModal').classList.contains('hidden')
        && $('moveModal').classList.contains('hidden')
        && $('mergeModal').classList.contains('hidden')
        && $('imageModal').classList.contains('hidden')
        && $('videoModal').classList.contains('hidden')
        && $('audioToolModal').classList.contains('hidden')
        && $('metadataImportModal').classList.contains('hidden')
        && $('metadataViewModal').classList.contains('hidden')
        && $('screenshotViewModal').classList.contains('hidden')
        && $('settingsModal').classList.contains('hidden')
        && $('libraryImportModal').classList.contains('hidden')
        && $('translationTargetModal').classList.contains('hidden')
        && $('transcriptModal').classList.contains('hidden')) {
        deleteSelected();
    }
});

$('setThumbnailBtn').addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    setCurrentImageAsThumbnail();
});

$('imagePrevBtn').addEventListener('click', (event) => {
    event.stopPropagation();
    showPreviousImage();
});
$('imageNextBtn').addEventListener('click', (event) => {
    event.stopPropagation();
    showNextImage();
});
$('imageStage').addEventListener('click', (event) => {
    if (imageViewerItems.length < 2) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const relativeX = event.clientX - rect.left;
    if (relativeX < rect.width * 0.35) showPreviousImage();
    else if (relativeX > rect.width * 0.65) showNextImage();
});
$('imageModal').addEventListener('wheel', (event) => {
    if (imageViewerItems.length < 2 || imageViewerWheelLocked) return;
    if (Math.abs(event.deltaY) < 8 && Math.abs(event.deltaX) < 8) return;
    event.preventDefault();
    imageViewerWheelLocked = true;
    if (event.deltaY > 0 || event.deltaX > 0) showNextImage();
    else showPreviousImage();
    window.setTimeout(() => { imageViewerWheelLocked = false; }, 180);
}, { passive: false });
$('imageStage').addEventListener('touchstart', (event) => {
    imageViewerTouchStartX = event.touches[0]?.clientX ?? null;
}, { passive: true });
$('imageStage').addEventListener('touchend', (event) => {
    if (imageViewerTouchStartX === null || imageViewerItems.length < 2) return;
    const endX = event.changedTouches[0]?.clientX ?? imageViewerTouchStartX;
    const delta = endX - imageViewerTouchStartX;
    imageViewerTouchStartX = null;
    if (Math.abs(delta) < 45) return;
    if (delta < 0) showNextImage();
    else showPreviousImage();
}, { passive: true });

$('listViewBtn').addEventListener('click', () => setViewMode('list'));
$('tileViewBtn').addEventListener('click', () => setViewMode('tiles'));
$('sortMode')?.addEventListener('change', (event) => setSortMode(event.target.value));
$('folderSearchInput')?.addEventListener('input', (event) => {
    folderSearchQuery = String(event.target.value || '');
    if (currentTree) renderTree(currentTree);
});
$('pinnedVideosSection').addEventListener('toggle', () => {
    if ($('pinnedVideosSection').open && currentViewMode === 'tiles') observeLazyThumbnails($('pinnedVideos'));
});
updateViewModeButtons();

$('actionsMenuButton').addEventListener('click', toggleActionsMenu);
$('openMetadataImportBtn').addEventListener('click', openMetadataImportModal);
$('openMetadataViewBtn').addEventListener('click', openMetadataViewModal);
$('openScreenshotViewBtn').addEventListener('click', openScreenshotViewModal);
$('exportLibraryBtn').addEventListener('click', exportCurrentLibrary);
$('importLibraryBtn').addEventListener('click', openLibraryImportModal);
$('libraryImportRefreshBtn').addEventListener('click', refreshLibraryImportList);
$('libraryImportStartBtn').addEventListener('click', importCurrentLibrary);
$('openSettingsBtn').addEventListener('click', openSettingsModal);
$('logoutBtn').addEventListener('click', logoutApplication);
$('authSettingsForm').addEventListener('submit', saveAuthSettings);
$('transcriptionSettingsForm').addEventListener('submit', saveTranscriptionSettings);
$('translationSettingsForm').addEventListener('submit', saveTranslationSettings);
$('translationTargetLanguage').addEventListener('change', startSelectedTranslation);
$('translationImportChooseBtn').addEventListener('click', () => $('translationImportFile').click());
$('translationImportFile').addEventListener('change', () => setTranslationImportFile($('translationImportFile').files?.[0] || null));
$('translationImportDrop').addEventListener('dragover', (event) => {
    event.preventDefault();
    $('translationImportDrop').classList.add('dragover');
});
$('translationImportDrop').addEventListener('dragleave', () => $('translationImportDrop').classList.remove('dragover'));
$('translationImportDrop').addEventListener('drop', (event) => {
    event.preventDefault();
    $('translationImportDrop').classList.remove('dragover');
    setTranslationImportFile(event.dataTransfer?.files?.[0] || null);
});
$('translationImportStartBtn').addEventListener('click', importCustomTranslation);
$('transcriptVersionButton').addEventListener('click', (event) => {
    event.stopPropagation();
    $('transcriptVersionMenu').classList.toggle('hidden');
});
$('transcriptTranslateBtn')?.addEventListener('click', () => {
    if (currentTranscriptData) openTranslationTargetModal(currentTranscriptData);
});
$('transcriptAddSegmentBtn').addEventListener('click', openTranscriptAddModal);
$('transcriptAddSaveBtn').addEventListener('click', saveTranscriptAddedSegment);
$('metadataViewSearch').addEventListener('input', renderMetadataViewRows);
$('screenshotViewSearch').addEventListener('input', renderScreenshotViewRows);
$('metadataImportForm').addEventListener('submit', importMetadata);

document.addEventListener('click', (event) => {
    document.querySelectorAll('.derivative-action-menu[open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});

$('tree').addEventListener('contextmenu', (event) => {
    if (event.target.closest('.node-row, .video-tile')) return;
    event.preventDefault();
    if (currentRoot) showContextMenu(event.clientX, event.clientY, { type: 'root', path: currentRoot });
});
$('scanBtn').addEventListener('click', () => loadTree());
$('refreshCacheBtn').addEventListener('click', refreshCache);
$('deleteCacheBtn').addEventListener('click', deleteCurrentCache);
$('stopScreenshotWorkerBtn').addEventListener('click', stopScreenshotWorker);
$('resumeScreenshotWorkerBtn').addEventListener('click', resumeScreenshotWorker);
$('addFavoriteBtn').addEventListener('click', addCurrentFavorite);
$('moveSelectedBtn').addEventListener('click', () => openMoveModal());
$('bulkCategorySelect').addEventListener('change', (event) => assignSelectedCategory(event.target.value));
$('mergeSelectedBtn').addEventListener('click', openMergeModal);
$('startMergeBtn').addEventListener('click', startMerge);
$('deleteSelectedBtn').addEventListener('click', deleteSelected);
$('clearSelectionBtn').addEventListener('click', clearSelection);
$('searchMoveSelectedBtn').addEventListener('click', () => openMoveModal());
$('searchBulkCategorySelect').addEventListener('change', (event) => assignSelectedCategory(event.target.value));
$('searchMergeSelectedBtn').addEventListener('click', openMergeModal);
$('searchDeleteSelectedBtn').addEventListener('click', deleteSelected);
$('searchClearSelectionBtn').addEventListener('click', clearSelection);
$('confirmMoveBtn').addEventListener('click', () => moveSelectedTo($('moveDestination').value));
$('resetBtn').addEventListener('click', async () => {
    $('searchInput').value = '';
    $('categoryFilter').value = '';
    $('searchResults').classList.add('hidden');
    $('resultsList').innerHTML = '';
    updateSelectionToolbar();
    if (currentRoot) await loadTree({ announceInitialCache: false });
});
$('searchInput').addEventListener('input', scheduleSearch);
$('categoryFilter').addEventListener('change', async () => {
    await doSearch();
    if (currentRoot) await loadTree({ announceInitialCache: false });
});
$('cardForm').addEventListener('submit', saveCard);
$('deleteCardBtn').addEventListener('click', deleteCard);
$('deleteFileFromCardBtn').addEventListener('click', deleteFileFromCard);
$('mediaToolBtn').addEventListener('click', openAudioToolModal);
$('convertMp4Btn').addEventListener('click', handleConvertButton);
$('unifiedToolStartBtn').addEventListener('click', startUnifiedFileTool);
$('toolDoClip').addEventListener('change', updateUnifiedToolControls);
$('toolDoAudio').addEventListener('change', updateUnifiedToolControls);
$('toolDoTranscript').addEventListener('change', updateUnifiedToolControls);
$('audioFormat').addEventListener('change', updateUnifiedToolControls);
$('viewFromModal').addEventListener('click', () => {
    const button = $('viewFromModal');
    if (button.dataset.token) openVideo(button.dataset.token, button.dataset.title || $('modalFileName').textContent);
});
$('cardCoverButton')?.addEventListener('click', () => {
    const button = $('cardCoverButton');
    if (button.dataset.token) openVideo(button.dataset.token, button.dataset.title || $('modalFileName').textContent);
});
$('pinFromModal').addEventListener('click', () => {
    const token = $('cardToken').value;
    const pinned = $('pinFromModal').dataset.pinned === '1';
    if (token) toggleVideoPinned(token, !pinned);
});
$('videoPlayer').addEventListener('loadedmetadata', () => {
    $('videoStatus').textContent = '';
});
$('videoPlayer').addEventListener('playing', () => {
    $('videoStatus').textContent = '';
});
$('videoPlayer').addEventListener('error', () => {
    $('videoStatus').textContent = 'Браузер не смог воспроизвести этот формат или файл недоступен.';
});
$('addCategoryBtn').addEventListener('click', addCategory);
$('uploadBtn').addEventListener('click', uploadImages);



document.addEventListener('visibilitychange', () => {
    if (!document.hidden && screenshotMonitorRoot) {
        monitorScreenshotWorker(screenshotMonitorRoot, true).catch(() => {});
    }
});

window.addEventListener('focus', () => {
    if (screenshotMonitorRoot) monitorScreenshotWorker(screenshotMonitorRoot, true).catch(() => {});
    if (currentRoot) monitorMergeJobs(currentRoot, true).catch(() => {});
});

(async function init() {
    $('rootPath').value = currentRoot;
    renderFavorites();
    updateSelectionToolbar();
    try {
        if (currentRoot) await loadTree();
        else await loadCategories();
    } catch (error) {
        showMessage('Проверьте подключение к БД: ' + error.message);
    }
})();

$('prevCardBtn')?.addEventListener('click', () => navigateCard(-1));
$('nextCardBtn')?.addEventListener('click', () => navigateCard(1));

document.addEventListener('keydown', (event) => {
    if ($('cardModal')?.classList.contains('hidden')) return;
    if (!event.altKey || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
    const target = event.target;
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) return;
    event.preventDefault();
    navigateCard(event.key === 'ArrowLeft' ? -1 : 1);
});
