<?php
require_once __DIR__ . '/auth.php';

auth_ensure_schema();
auth_start_session();

if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: index.php');
    exit;
}

$authError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['auth_action'] ?? '') === 'login') {
    if (!auth_verify_slider_captcha((string)($_POST['slider_captcha'] ?? ''))) {
        $authError = 'Подтвердите, что вы человек: протяните ползунок вправо.';
    } elseif (auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $authError = 'Неверный логин или пароль.';
    }
}
$sliderCaptchaToken = auth_slider_captcha_token();

if (!auth_is_logged_in()):
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — Solanace</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="login-page">
<div class="login-shell">
    <div class="login-language language-switcher" aria-label="Language">
        <button type="button" class="language-button" data-language="ru">RU</button>
        <button type="button" class="language-button" data-language="en">EN</button>
    </div>
    <div class="login-card">
        <img class="login-logo" src="assets/solanace-logo.png?v=<?= (int)@filemtime(__DIR__ . '/assets/solanace-logo.png') ?>" alt="Solanace">
        <p class="login-subtitle">Локальный умный медиаархив</p>
        <p class="muted login-prompt">Введите логин и пароль.</p>
    <?php if ($authError !== ''): ?>
        <div class="message auth-login-error"><?= htmlspecialchars($authError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" class="login-form" autocomplete="on">
        <input type="hidden" name="auth_action" value="login">
        <label>
            <span>Логин</span>
            <input name="username" type="text" autocomplete="username" autofocus required>
        </label>
        <label>
            <span>Пароль</span>
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <div class="slider-captcha" id="sliderCaptcha" data-token="<?= htmlspecialchars($sliderCaptchaToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="slider-captcha-head">
                <span>Проверка</span>
                <strong id="sliderCaptchaState">Протяните ползунок вправо</strong>
            </div>
            <div class="slider-captcha-track">
                <div id="sliderCaptchaFill" class="slider-captcha-fill"></div>
                <input id="sliderCaptchaRange" type="range" min="0" max="100" value="0" aria-label="Протяните ползунок вправо">
                <span class="slider-captcha-arrow" aria-hidden="true">→</span>
            </div>
            <input id="sliderCaptchaToken" name="slider_captcha" type="hidden" value="">
        </div>
        <button id="loginSubmitBtn" type="submit" class="primary login-submit" disabled>Войти</button>
    </form>
    </div>
</div>
<script src="assets/i18n.js?v=<?= (int)@filemtime(__DIR__ . '/assets/i18n.js') ?>"></script>
<script src="assets/login.js?v=<?= (int)@filemtime(__DIR__ . '/assets/login.js') ?>"></script>
</body>
</html>
<?php
exit;
endif;
$currentAuthUsername = auth_current_username();
$csrfToken = auth_csrf_token();
$defaultPasswordActive = auth_default_password_active();
$defaultDatabaseCredentialsActive = defined('DB_USER') && defined('DB_PASS') && DB_USER === 'admin' && DB_PASS === 'admin';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solanace</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div class="app">
    <?php if ($defaultPasswordActive || $defaultDatabaseCredentialsActive): ?>
    <div class="default-password-warning" role="alert">
        <strong>Внимание:</strong>
        <?php if ($defaultPasswordActive): ?>
            <span>используется пароль приложения по умолчанию <code>admin</code>. Откройте «Действия → Настройки» и смените его.</span>
        <?php endif; ?>
        <?php if ($defaultDatabaseCredentialsActive): ?>
            <span>Стандартные данные БД <code>admin/admin</code> указаны в <code>config.php</code>. Смените пароль пользователя БД и обновите <code>DB_PASS</code>.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <header class="topbar brand-topbar">
        <div class="brand-block">
            <img class="brand-logo" src="assets/solanace-logo.png?v=<?= (int)@filemtime(__DIR__ . '/assets/solanace-logo.png') ?>" alt="Solanace">
        </div>
        <div class="topbar-actions">
            <div class="language-switcher" aria-label="Language">
                <button type="button" class="language-button" data-language="ru">RU</button>
                <button type="button" class="language-button" data-language="en">EN</button>
            </div>
            <div class="actions-dropdown">
            <button id="actionsMenuButton" class="actions-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
                Действия <span aria-hidden="true">▾</span>
            </button>
            <div id="actionsMenu" class="actions-menu" role="menu">
                <button id="openMetadataImportBtn" type="button" role="menuitem">Групповое добавление метаданных</button>
                <button id="openMetadataViewBtn" type="button" role="menuitem">Групповой просмотр метаданных</button>
                <button id="openScreenshotViewBtn" type="button" role="menuitem">Групповой просмотр кадров</button>
                <button id="openSettingsBtn" type="button" role="menuitem">Настройки</button>
                <button id="logoutBtn" type="button" role="menuitem" class="actions-menu-danger">Выйти</button>
            </div>
            </div>
        </div>
    </header>

    <section class="panel controls">
        <div class="path-controls">
            <label class="path-label">
                <span>Папка с видео</span>
                <input id="rootPath" type="text" placeholder="Например: D:\\Video или C:\\Users\\User\\Movies">
            </label>
            <button id="scanBtn" class="primary" type="button">Показать файлы</button>
            <button id="refreshCacheBtn" type="button" title="Проверить изменения на диске и обновить кэш">↻ Обновить кэш</button>
            <button id="deleteCacheBtn" class="danger" type="button" title="Удалить кэш выбранной библиотеки и все производные файлы, не затрагивая исходные видео">Удалить кэш</button>
            <button id="addFavoriteBtn" type="button">★ В избранное</button>
        </div>
        <div class="cache-line">
            <span id="cacheInfo" class="muted">Кэш еще не загружен.</span>
            <div class="screenshot-worker-line">
                <span id="screenshotProgress" class="muted screenshot-progress hidden"></span>
                <div class="screenshot-worker-actions">
                    <button id="stopScreenshotWorkerBtn" type="button" class="danger compact hidden">Остановить</button>
                    <button id="resumeScreenshotWorkerBtn" type="button" class="compact hidden">Продолжить</button>
                </div>
            </div>
        </div>
        <div class="favorites-block">
            <div class="favorites-title">Закрепленные папки</div>
            <div id="favoriteFolders" class="favorites-list empty-favorites">Пока нет избранных папок.</div>
        </div>
    </section>

    <section class="panel filters">
        <label>
            <span>Поиск</span>
            <input id="searchInput" type="search" placeholder="Имя файла, заголовок, заметка, путь, категория...">
        </label>
        <label>
            <span>Категория</span>
            <select id="categoryFilter"><option value="">Все категории</option></select>
        </label>
        <button id="resetBtn" type="button">Сброс</button>
    </section>

    <section id="message" class="message hidden"></section>

    <section id="mergeProgressPanel" class="panel merge-progress-panel hidden" aria-live="polite">
        <div class="merge-progress-panel-head">
            <strong>Склейка видео</strong>
            <span class="muted">FFmpeg работает в фоне</span>
        </div>
        <div id="mergeProgressJobs" class="merge-progress-jobs"></div>
    </section>

    <section id="searchResults" class="panel hidden">
        <h2>Результаты поиска</h2>
        <div id="searchSelectionToolbar" class="selection-toolbar search-selection-toolbar hidden">
            <strong id="searchSelectionCount">Выбрано: 0</strong>
            <button id="searchMoveSelectedBtn" type="button">Переместить…</button>
            <select id="searchBulkCategorySelect" class="bulk-category-select hidden" aria-label="Назначить категорию">
                <option value="">В категорию…</option>
            </select>
            <button id="searchMergeSelectedBtn" type="button" class="hidden">Склеить</button>
            <button id="searchDeleteSelectedBtn" type="button" class="danger">Удалить с диска</button>
            <button id="searchClearSelectionBtn" type="button">Снять выделение</button>
        </div>
        <div id="resultsList"></div>
    </section>

    <main class="panel tree-panel">
        <details id="pinnedVideosSection" class="pinned-videos-section hidden" open>
            <summary class="pinned-videos-summary">
                <span><strong>Закрепленные видео</strong> <span id="pinnedVideosCount" class="muted"></span></span>
                <span class="muted">Текущая библиотека</span>
            </summary>
            <div id="pinnedVideos" class="pinned-videos-body"></div>
        </details>

        <div class="tree-head">
            <div>
                <h2>Файловая структура</h2>
                <p class="muted">Правая кнопка мыши открывает операции. Файлы и папки можно выделять и перетаскивать.</p>
            </div>
            <div class="tree-head-tools">
                <div class="view-mode-toggle" role="group" aria-label="Режим отображения">
                    <button id="listViewBtn" type="button" class="view-mode-button active" aria-pressed="true" title="Список">☰ <span>Список</span></button>
                    <button id="tileViewBtn" type="button" class="view-mode-button" aria-pressed="false" title="Миниатюры">▦ <span>Миниатюры</span></button>
                </div>
                <span id="fileCounter"></span>
            </div>
        </div>

        <div id="selectionToolbar" class="selection-toolbar hidden">
            <strong id="selectionCount">Выбрано: 0</strong>
            <button id="moveSelectedBtn" type="button">Переместить…</button>
            <select id="bulkCategorySelect" class="bulk-category-select hidden" aria-label="Назначить категорию">
                <option value="">В категорию…</option>
            </select>
            <button id="mergeSelectedBtn" type="button" class="hidden">Склеить</button>
            <button id="deleteSelectedBtn" type="button" class="danger">Удалить с диска</button>
            <button id="clearSelectionBtn" type="button">Снять выделение</button>
        </div>

        <div id="tree" class="tree empty" tabindex="0">Укажите папку и нажмите «Показать файлы».</div>
    </main>
</div>

<div id="contextMenu" class="context-menu hidden" role="menu"></div>

<div id="moveModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-move="1"></div>
    <div class="modal-window move-window">
        <button class="modal-close" type="button" data-close-move="1">×</button>
        <h2>Переместить выбранное</h2>
        <p id="moveSummary" class="muted"></p>
        <label>
            <span>Папка назначения</span>
            <select id="moveDestination"></select>
        </label>
        <div class="actions">
            <button id="confirmMoveBtn" type="button" class="primary">Переместить</button>
            <button type="button" data-close-move="1">Отмена</button>
        </div>
    </div>
</div>

<div id="mergeModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-merge="1"></div>
    <div class="modal-window merge-window">
        <button class="modal-close" type="button" data-close-merge="1">×</button>
        <h2>Склеить видео</h2>
        <p class="muted">Перетащите строки, чтобы установить последовательность. Результат сохраняется в корень текущей библиотеки.</p>
        <div id="mergeItems" class="merge-items"></div>
        <label>
            <span>Название выходного видео *</span>
            <input id="mergeOutputName" type="text" maxlength="180" placeholder="Например: Поездка 2026">
        </label>
        <div class="merge-options-grid">
            <label>
                <span>Режим</span>
                <select id="mergeMode">
                    <option value="auto">Авто — без перекодирования, если возможно</option>
                    <option value="reencode">Всегда перекодировать</option>
                </select>
            </label>
            <label>
                <span>Итоговое разрешение</span>
                <select id="mergeResolution">
                    <option value="auto">Авто</option>
                    <option value="1920x1080">1920×1080</option>
                    <option value="1280x720">1280×720</option>
                </select>
            </label>
            <label>
                <span>Разные пропорции</span>
                <select id="mergeAspect">
                    <option value="fit">Вписать целиком (поля)</option>
                    <option value="crop">Заполнить кадр (обрезка)</option>
                </select>
            </label>
            <label>
                <span>Качество</span>
                <select id="mergeQuality">
                    <option value="high">Высокое</option>
                    <option value="normal" selected>Обычное</option>
                    <option value="compact">Компактное</option>
                </select>
            </label>
        </div>
        <div id="mergeStatus" class="muted"></div>
        <div class="actions">
            <button id="startMergeBtn" type="button" class="primary">Склеить</button>
            <button type="button" data-close-merge="1">Отмена</button>
        </div>
    </div>
</div>

<div id="metadataViewModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-metadata-view="1"></div>
    <div class="modal-window metadata-view-window">
        <button class="modal-close" type="button" data-close-metadata-view="1">×</button>
        <h2>Групповой просмотр метаданных</h2>
        <p class="muted">Таблица формируется по файлам из текущей выбранной корневой папки.</p>
        <p id="metadataViewRoot" class="metadata-import-root"></p>

        <div class="metadata-view-toolbar">
            <label>
                <span>Поиск по названию и заметке</span>
                <input id="metadataViewSearch" type="search" placeholder="Начните вводить название или текст заметки…" autocomplete="off">
            </label>
            <span id="metadataViewCount" class="muted"></span>
        </div>

        <div id="metadataViewStatus" class="metadata-view-status muted">Загрузка…</div>
        <div id="metadataViewTableWrap" class="metadata-view-table-wrap hidden">
            <table class="metadata-view-table">
                <thead>
                <tr>
                    <th>Название</th>
                    <th>Заметка</th>
                </tr>
                </thead>
                <tbody id="metadataViewTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="screenshotViewModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-screenshot-view="1"></div>
    <div class="modal-window screenshot-view-window">
        <button class="modal-close" type="button" data-close-screenshot-view="1">×</button>
        <h2>Групповой просмотр кадров</h2>
        <p class="muted">Первые пять автоматически созданных кадров для каждого видео из текущей корневой папки.</p>
        <p id="screenshotViewRoot" class="metadata-import-root"></p>

        <div class="screenshot-view-toolbar">
            <label>
                <span>Поиск по названию файла</span>
                <input id="screenshotViewSearch" type="search" placeholder="Название, имя файла, путь или категория…" autocomplete="off">
            </label>
            <span id="screenshotViewCount" class="muted"></span>
        </div>

        <div id="screenshotViewStatus" class="screenshot-view-status muted">Загрузка…</div>
        <div id="screenshotViewRows" class="screenshot-view-rows hidden"></div>
    </div>
</div>

<div id="metadataImportModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-metadata-import="1"></div>
    <div class="modal-window metadata-import-window">
        <button class="modal-close" type="button" data-close-metadata-import="1">×</button>
        <h2>Групповое добавление метаданных</h2>
        <p class="muted">Импорт выполняется для файлов из текущей выбранной корневой папки.</p>
        <p id="metadataImportRoot" class="metadata-import-root"></p>

        <form id="metadataImportForm">
            <label>
                <span>Excel-файл</span>
                <input id="metadataFileInput" name="metadata_file" type="file" accept=".xlsx,.csv" required>
            </label>
            <div class="metadata-columns-help">
                <strong>Ожидаемые колонки:</strong>
                <code>Название файла</code>, <code>Кастомный заголовок</code>, <code>Заметка</code>, <code>Категория</code>.
                Название можно указывать с расширением или без него. Для одинаковых имен в разных подпапках укажите относительный путь.
            </div>
            <label class="check-label">
                <input id="metadataOverwriteBlanks" name="overwrite_blanks" type="checkbox" value="1">
                <span>Пустые ячейки очищают существующие значения</span>
            </label>
            <div class="actions">
                <button id="metadataImportSubmitBtn" type="submit" class="primary">Импортировать</button>
                <button type="button" data-close-metadata-import="1">Закрыть</button>
                <span id="metadataImportStatus" class="muted"></span>
            </div>
        </form>

        <div id="metadataImportResult" class="metadata-import-result hidden"></div>
    </div>
</div>

<div id="settingsModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-settings="1"></div>
    <div class="modal-window settings-window">
        <button class="modal-close" type="button" data-close-settings="1">×</button>
        <h2>Настройки</h2>

        <details class="settings-section" open>
            <summary><strong>Транскрибация</strong></summary>
            <form id="transcriptionSettingsForm" class="settings-form">
                <div class="settings-grid-two">
                    <label>
                        <span>Сервис</span>
                        <select id="transcriptionProvider" name="provider"></select>
                    </label>
                    <label>
                        <span>Модель</span>
                        <select id="transcriptionModel" name="model"></select>
                    </label>
                </div>
                <label>
                    <span>API-ключ</span>
                    <input id="transcriptionApiKey" name="api_key" type="password" autocomplete="off" placeholder="Введите API-ключ">
                </label>
                <p id="transcriptionKeyHint" class="muted"></p>
                <label>
                    <span>Python для провайдера</span>
                    <input id="transcriptionPythonPath" name="python_path" type="text" autocomplete="off" placeholder="Например: C:\laragon\bin\python\python-3.13\python.exe">
                </label>
                <div class="actions">
                    <button id="transcriptionSettingsSubmitBtn" type="submit" class="primary">Сохранить транскрибацию</button>
                    <span id="transcriptionSettingsStatus" class="muted"></span>
                </div>
            </form>
        </details>

        <details class="settings-section" open>
            <summary><strong>Перевод</strong></summary>
            <form id="translationSettingsForm" class="settings-form">
                <div class="settings-grid-two">
                    <label>
                        <span>Сервис</span>
                        <select id="translationProvider" name="provider"></select>
                    </label>
                    <label>
                        <span>Модель</span>
                        <select id="translationModel" name="model"></select>
                    </label>
                </div>
                <label>
                    <span>API-ключ</span>
                    <input id="translationApiKey" name="api_key" type="password" autocomplete="off" placeholder="Можно использовать ключ транскрибации Groq">
                </label>
                <p id="translationKeyHint" class="muted"></p>
                <label>
                    <span>Python для провайдера</span>
                    <input id="translationPythonPath" name="python_path" type="text" autocomplete="off" placeholder="Если пусто — используется Python из транскрибации">
                </label>
                <p class="muted">Для Groq при пустых полях API-ключа и Python используются соответствующие настройки транскрибации.</p>
                <div class="actions">
                    <button id="translationSettingsSubmitBtn" type="submit" class="primary">Сохранить перевод</button>
                    <span id="translationSettingsStatus" class="muted"></span>
                </div>
            </form>
        </details>

        <details class="settings-section">
            <summary><strong>Логин и пароль</strong></summary>
            <form id="authSettingsForm" class="settings-form">
                <label>
                    <span>Логин</span>
                    <input id="authNewUsername" name="new_username" type="text" maxlength="190" autocomplete="username" value="<?= htmlspecialchars($currentAuthUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
                <label>
                    <span>Текущий пароль</span>
                    <input id="authCurrentPassword" name="current_password" type="password" autocomplete="current-password" required>
                </label>
                <label>
                    <span>Новый пароль</span>
                    <input id="authNewPassword" name="new_password" type="password" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять">
                </label>
                <label>
                    <span>Повторите новый пароль</span>
                    <input id="authNewPasswordConfirm" name="new_password_confirm" type="password" autocomplete="new-password">
                </label>
                <div class="actions">
                    <button id="authSettingsSubmitBtn" type="submit" class="primary">Сохранить логин/пароль</button>
                    <span id="authSettingsStatus" class="muted"></span>
                </div>
            </form>
        </details>
    </div>
</div>

<div id="cardModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-window wide">
        <button class="modal-close" type="button" data-close="1">×</button>
        <h2 id="modalFileName">Карточка файла</h2>

        <details id="videoScreenshotsSection" class="video-screenshots-section hidden">
            <summary class="video-screenshots-summary">
                <span>
                    <strong>Кадры из видео</strong>
                    <span id="videoScreenshotsCount" class="video-screenshots-count"></span>
                </span>
                <span class="muted">Автоматически создаются при обновлении кэша</span>
            </summary>
            <div id="videoScreenshotsGrid" class="video-screenshots-grid"></div>
        </details>

        <details id="fileToolsSection" class="file-tools-section">
            <summary class="file-tools-summary">
                <span><strong>Работа с файлом</strong></span>
                <span id="fileToolsSummaryStatus" class="muted"></span>
            </summary>
            <div class="file-tools-body">
                <div class="file-tools-buttons">
                    <button id="mediaToolBtn" type="button">Аудио/Вырезка/Транскрипт</button>
                    <button id="convertMp4Btn" type="button" class="hidden">Конвертировать в mp4</button>
                </div>
                <div id="fileToolsStatus" class="muted file-tools-status"></div>
                <div id="fileAudioSection" class="file-tool-results hidden">
                    <strong>Аудио</strong>
                    <div id="fileAudioList" class="file-tool-list"></div>
                </div>
                <div id="fileTranscriptsSection" class="file-tool-results hidden">
                    <strong>Транскрипты</strong>
                    <div id="fileTranscriptsList" class="file-tool-list"></div>
                </div>
                <div id="fileClipsSection" class="file-tool-results hidden">
                    <strong>Фрагменты</strong>
                    <div id="fileClipsList" class="file-tool-list"></div>
                </div>
                <div id="filePromotedClipsSection" class="file-tool-results hidden">
                    <strong>Созданные из фрагментов видео</strong>
                    <div id="filePromotedClipsList" class="file-tool-list"></div>
                </div>
                <div id="fileSourceClipSection" class="file-tool-results hidden">
                    <strong>Исходное видео для этого фрагмента</strong>
                    <div id="fileSourceClipList" class="file-tool-list"></div>
                </div>
            </div>
        </details>

        <details id="mergeSourcesSection" class="merge-sources-section hidden" open>
            <summary><strong>Склейка из видео</strong></summary>
            <ol id="mergeSourcesList" class="merge-sources-list"></ol>
        </details>

        <p id="modalPath" class="muted"></p>

        <form id="cardForm">
            <input type="hidden" id="cardToken" name="token">
            <label>
                <span>Кастомный заголовок</span>
                <input id="customTitle" name="custom_title" type="text" maxlength="255">
            </label>
            <label>
                <span>Заметка</span>
                <textarea id="note" name="note" rows="8"></textarea>
            </label>
            <div class="category-row">
                <label>
                    <span>Категория</span>
                    <select id="cardCategory" name="category_id"><option value="">Без категории</option></select>
                </label>
                <label>
                    <span>Новая категория</span>
                    <input id="newCategory" type="text" placeholder="Название">
                </label>
                <button id="addCategoryBtn" type="button">Добавить</button>
            </div>
            <div class="actions">
                <button type="submit" class="primary">Сохранить</button>
                <button id="viewFromModal" class="button" type="button">Просмотр</button>
                <button id="pinFromModal" class="pin-video-button" type="button" title="Закрепить видео" aria-label="Закрепить видео">☆</button>
                <button id="deleteCardBtn" type="button" class="danger">Удалить карточку</button>
                <button id="deleteFileFromCardBtn" type="button" class="danger">Удалить с диска</button>
                <span id="saveStatus" class="muted"></span>
            </div>
        </form>

        <hr>
        <div class="upload-row">
            <label>
                <span>Прикрепить фото</span>
                <input id="imageInput" type="file" accept="image/*" multiple>
            </label>
            <button id="uploadBtn" type="button">Загрузить</button>
        </div>
        <div id="imagesGrid" class="images-grid"></div>
    </div>
</div>

<div id="audioToolModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-audio-tool="1"></div>
    <div class="modal-window compact-tool-window">
        <button class="modal-close" type="button" data-close-audio-tool="1">×</button>
        <h2>Аудио / Вырезка / Транскрипт</h2>
        <p class="muted tool-help">Укажите общий интервал. Для аудио и транскрипта пустые поля означают весь файл. Для вырезки нужно указать хотя бы одну границу.</p>
        <div class="time-range-row">
            <label><span>От</span><input id="audioFrom" type="text" placeholder="00:00:00"></label>
            <label><span>До</span><input id="audioTo" type="text" placeholder="00:00:00"></label>
        </div>

        <div class="unified-tool-options">
            <div class="tool-card">
                <label class="tool-choice-row">
                    <input id="toolDoClip" type="checkbox">
                    <span class="tool-choice-copy">
                        <strong>Вырезать фрагмент видео</strong>
                        <small>Создать отдельный видеофрагмент по указанному интервалу.</small>
                    </span>
                </label>
            </div>

            <div class="tool-card">
                <label class="tool-choice-row">
                    <input id="toolDoAudio" type="checkbox">
                    <span class="tool-choice-copy">
                        <strong>Получить аудио</strong>
                        <small>Извлечь звук из видео в MP3 или FLAC.</small>
                    </span>
                </label>
                <div id="toolAudioOptions" class="tool-nested-options hidden">
                    <label>
                        <span>Формат</span>
                        <select id="audioFormat">
                            <option value="mp3">MP3</option>
                            <option value="flac">FLAC</option>
                        </select>
                    </label>
                    <label>
                        <span>Битрейт MP3, кбит/с</span>
                        <select id="audioBitrate">
                            <option value="64">64</option>
                            <option value="96">96</option>
                            <option value="192">192</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="tool-card">
                <label class="tool-choice-row">
                    <input id="toolDoTranscript" type="checkbox">
                    <span class="tool-choice-copy">
                        <strong>Транскрипт</strong>
                        <small>Создать аудио и распознать речь с выбранным языком.</small>
                    </span>
                </label>
                <div id="toolTranscriptOptions" class="tool-nested-options hidden">
                    <label>
                        <span>Язык</span>
                        <select id="transcriptionLanguage">
                            <option value="auto">Автоматически</option>
                            <option value="ru">Русский</option>
                            <option value="en">Английский</option>
                        </select>
                    </label>
                </div>
            </div>
        </div>
        <p id="audioFormatHint" class="muted tool-help">Транскрипт всегда создает и сохраняет соответствующее аудио. Если одновременно отмечены «Получить аудио» и «Транскрипт», отдельная вторая аудиодорожка не создается.</p>
        <div class="actions audio-tool-actions">
            <button id="unifiedToolStartBtn" type="button" class="primary">Гоу</button>
            <span id="audioToolStatus" class="muted"></span>
        </div>
    </div>
</div>

<div id="imageModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-image="1"></div>
    <div class="modal-window image-window">
        <button class="modal-close" type="button" data-close-image="1">×</button>
        <button id="imagePrevBtn" class="image-nav image-nav-prev hidden" type="button" aria-label="Предыдущий кадр">‹</button>
        <div id="imageStage" class="image-stage">
            <img id="bigImage" alt="Фото">
            <button id="setThumbnailBtn" class="image-thumbnail-star hidden" type="button" title="Сделать миниатюрой" aria-label="Сделать кадр миниатюрой">☆</button>
        </div>
        <button id="imageNextBtn" class="image-nav image-nav-next hidden" type="button" aria-label="Следующий кадр">›</button>
        <div id="imageViewerInfo" class="image-viewer-info hidden">
            <span id="imageViewerCounter"></span>
            <span id="imageViewerCaption"></span>
        </div>
    </div>
</div>

<div id="translationTargetModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-translation-target="1"></div>
    <div class="modal-window compact-tool-window translation-target-window">
        <button class="modal-close" type="button" data-close-translation-target="1">×</button>
        <h2>Перевод транскрипта</h2>

        <div class="manual-translation-import">
            <div class="settings-section-title">Импорт готового перевода</div>
            <div id="translationImportDrop" class="translation-import-drop" tabindex="0">
                <strong>Перетащите TXT сюда</strong>
                <span class="muted">или</span>
                <button id="translationImportChooseBtn" type="button">Выбрать файл</button>
                <span id="translationImportFileName" class="muted">Файл не выбран</span>
                <input id="translationImportFile" type="file" accept=".txt,text/plain" hidden>
            </div>
        </div>

        <label>
            <span>Язык перевода</span>
            <select id="translationTargetLanguage">
                <option value="">Выберите язык…</option>
            </select>
        </label>
        <div id="translationCustomNameWrap" class="hidden">
            <label>
                <span>Название пользовательского перевода</span>
                <input id="translationCustomName" type="text" maxlength="190" placeholder="Например: Перевод Иванова">
            </label>
            <button id="translationImportStartBtn" type="button" class="primary">Импортировать</button>
        </div>
        <p id="translationTargetStatus" class="muted"></p>
    </div>
</div>

<div id="transcriptModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-transcript="1"></div>
    <div class="modal-window transcript-window">
        <button class="modal-close" type="button" data-close-transcript="1">×</button>
        <div class="transcript-head">
            <div>
                <h2 id="transcriptTitle">Транскрипт</h2>
                <p id="transcriptMeta" class="muted"></p>
            </div>
            <div class="transcript-head-actions">
                <div id="transcriptVersionPicker" class="transcript-version-picker hidden">
                    <button id="transcriptVersionButton" type="button" class="transcript-version-button">Показать оригинал ▾</button>
                    <div id="transcriptVersionMenu" class="transcript-version-menu hidden"></div>
                </div>
                <a id="transcriptDownload" class="button-link" href="#" download>Скачать TXT</a>
                <button id="transcriptAddSegmentBtn" type="button">Добавить фрагмент</button>
            </div>
        </div>
        <div id="transcriptSegments" class="transcript-segments"></div>
    </div>
</div>

<div id="transcriptAddModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-transcript-add="1"></div>
    <div class="modal-window compact-tool-window">
        <button class="modal-close" type="button" data-close-transcript-add="1">×</button>
        <h2>Добавить фрагмент</h2>
        <p class="muted">Введите тайм-код и текст. Например: <code>[00:12:35] Текст нового фрагмента</code></p>
        <textarea id="transcriptAddInput" rows="6" placeholder="[hh:mm:ss] Текст фрагмента"></textarea>
        <div class="modal-actions">
            <button id="transcriptAddSaveBtn" type="button" class="primary">Сохранить</button>
            <button type="button" data-close-transcript-add="1">Отмена</button>
        </div>
        <p id="transcriptAddStatus" class="muted"></p>
    </div>
</div>

<div id="videoModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-video="1"></div>
    <div class="modal-window video-window">
        <button class="modal-close" type="button" data-close-video="1">×</button>
        <h2 id="videoTitle">Просмотр видео</h2>
        <video id="videoPlayer" controls playsinline preload="metadata"></video>
        <p id="videoStatus" class="muted video-status"></p>
    </div>
</div>

<script src="assets/i18n.js?v=<?= (int)@filemtime(__DIR__ . '/assets/i18n.js') ?>"></script>
<script src="assets/app.js?v=<?= (int)@filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
