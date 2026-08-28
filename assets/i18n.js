(() => {
    'use strict';

    const STORAGE_KEY = 'solanace_language';
    const TEXT = {
        'Локальный умный медиаархив': 'Smart local media archive',
        'Внимание:': 'Warning:',
        'используется пароль приложения по умолчанию': 'the default application password is still in use',
        'Стандартные данные БД': 'Default database credentials',
        'Смените пароль пользователя БД и обновите': 'Change the database user password and update',
        'Откройте «Действия → Настройки» и смените его.': 'Open Actions → Settings and change it.',
        'Введите логин и пароль.': 'Enter your username and password.',
        'Неверный логин или пароль.': 'Incorrect username or password.',
        'Подтвердите, что вы человек: протяните ползунок вправо.': 'Please verify that you are human by dragging the slider to the right.',
        'Логин': 'Username',
        'Пароль': 'Password',
        'Войти': 'Sign in',
        'Проверка': 'Verification',
        'Протяните ползунок вправо': 'Drag the slider to the right',
        'Готово': 'Done',
        'Действия': 'Actions',
        'Групповое добавление метаданных': 'Bulk metadata import',
        'Групповой просмотр метаданных': 'Bulk metadata view',
        'Групповой просмотр кадров': 'Bulk frame view',
        'Экспорт библиотеки': 'Export library',
        'Импорт библиотеки': 'Import library',
        'Подпапка с перенесёнными файлами (необязательно)': 'Subfolder containing transferred files (optional)',
        'Оставьте пустым, если структура файлов начинается прямо от выбранной корневой папки. Путь указывается относительно неё.': 'Leave this empty if the transferred file structure starts directly in the selected root folder. Enter a path relative to that root.',
        'Например: Archive2026 или Перенос/Видео': 'For example: Archive2026 or Transfer/Videos',
        'Импорт выполняется в текущую выбранную корневую папку. Исходные видео в ZIP не входят: они уже должны находиться в этой папке с той же относительной структурой.': 'The import is applied to the currently selected root folder. Source videos are not included in the ZIP and must already exist in that folder with the same relative structure.',
        'Текущая папка': 'Current folder',
        'ZIP из корня библиотеки': 'ZIP from library root',
        'Выберите архив…': 'Choose archive…',
        'Загрузить ZIP с компьютера': 'Upload ZIP from computer',
        'Обновить список': 'Refresh list',
        'Импортировать': 'Import',
        'Настройки': 'Settings',
        'Выйти': 'Sign out',
        'Папка с видео': 'Video folder',
        'Показать файлы': 'Show files',
        '↻ Обновить кэш': '↻ Refresh cache',
        'Обновить кэш': 'Refresh cache',
        'Удалить кэш': 'Delete cache',
        '★ В избранное': '★ Add favorite',
        'В избранное': 'Add favorite',
        'Кэш еще не загружен.': 'Cache has not been loaded yet.',
        'Кэш еще не обновлялся.': 'Cache has not been refreshed yet.',
        'Остановить': 'Stop',
        'Продолжить': 'Resume',
        'Закрепленные папки': 'Favorite folders',
        'Пока нет избранных папок.': 'No favorite folders yet.',
        'Поиск': 'Search',
        'Категория': 'Category',
        'Все категории': 'All categories',
        'Сброс': 'Reset',
        'Результаты поиска': 'Search results',
        'Выбрано: 0': 'Selected: 0',
        'Переместить…': 'Move…',
        'Переместить': 'Move',
        'В категорию…': 'Assign category…',
        'Склеить': 'Merge',
        'Удалить с диска': 'Delete from disk',
        'Снять выделение': 'Clear selection',
        'Закрепленные видео': 'Pinned videos',
        'Текущая библиотека': 'Current library',
        'Файловая структура': 'File structure',
        'Правая кнопка мыши открывает операции. Файлы и папки можно выделять и перетаскивать.': 'Right-click for actions. Files and folders can be selected and dragged.',
        'Список': 'List',
        'Миниатюры': 'Thumbnails',
        'Сортировка': 'Sort',
        'По алфавиту (А-Я)': 'Alphabetical (A-Z)',
        'По алфавиту (Я-А)': 'Alphabetical (Z-A)',
        'По длительности (↑)': 'Duration (↑)',
        'По длительности (↓)': 'Duration (↓)',
        'Папки': 'Folders',
        'Поиск по папкам…': 'Search folders…',
        'Поиск по папкам': 'Search folders',
        'Папки не найдены.': 'No folders found.',
        'Путь к папке': 'Folder path',
        'Файлы в папке': 'Files in folder',
        'Выберите папку слева, чтобы увидеть файлы.': 'Choose a folder on the left to view files.',
        'Подпапки': 'Subfolders',
        'В этой папке пока нет видеофайлов.': 'There are no video files in this folder yet.',
        'Режим отображения': 'View mode',
        'Без категории': 'No category',
        'Просмотр': 'View',
        'Закрепить видео': 'Pin video',
        'Открепить видео': 'Unpin video',
        'Переместить выбранное': 'Move selected',
        'Папка назначения': 'Destination folder',
        'Отмена': 'Cancel',
        'Склеить видео': 'Merge videos',
        'Перетащите строки, чтобы установить последовательность. Результат сохраняется в корень текущей библиотеки.': 'Drag rows to set the order. The result is saved to the root of the current library.',
        'Название выходного видео *': 'Output video name *',
        'Итоговое разрешение': 'Output resolution',
        'Авто': 'Auto',
        'Разные пропорции': 'Aspect ratio handling',
        'Вписать целиком (поля)': 'Fit entire frame (letterbox)',
        'Заполнить кадр (обрезка)': 'Fill frame (crop)',
        'Качество': 'Quality',
        'Высокое': 'High',
        'Обычное': 'Normal',
        'Компактное': 'Compact',
        'Режим': 'Mode',
        'Авто — без перекодирования, если возможно': 'Auto — no re-encoding when possible',
        'Всегда перекодировать': 'Always re-encode',
        'Начать склейку': 'Start merge',
        'Склейка видео': 'Video merge',
        'FFmpeg работает в фоне': 'FFmpeg is running in the background',
        'Карточка файла': 'File card',
        'Перелистывание карточек': 'Card navigation',
        'Предыдущая карточка': 'Previous card',
        'Следующая карточка': 'Next card',
        'Кадры из видео': 'Video frames',
        'Автоматически создаются при обновлении кэша': 'Generated automatically during cache refresh',
        'Работа с файлом': 'File tools',
        'Вырезка/Аудио/Транскрипт': 'Clip / Audio / Transcript',
        'Видеофрагменты': 'Video clips',
        'Размер': 'Size',
        'Длительность': 'Duration',
        'Добавлено': 'Added',
        'Прикрепленные фото': 'Attached photos',
        'Закрепить': 'Pin',
        'Нет миниатюры': 'No thumbnail',
        'Сделать обычным видео': 'Make regular video',
        'Открыть транскрипт': 'Open transcript',
        'Аудио/Вырезка/Транскрипт': 'Audio / Clip / Transcript',
        'Аудио / Вырезка / Транскрипт': 'Audio / Clip / Transcript',
        'Конвертировать в mp4': 'Convert to MP4',
        'Аудио': 'Audio',
        'Транскрипты': 'Transcripts',
        'Фрагменты': 'Clips',
        'Созданные из фрагментов видео': 'Videos created from clips',
        'Исходное видео для этого фрагмента': 'Source video for this clip',
        'Склейка из видео': 'Merged from videos',
        'Кастомный заголовок': 'Custom title',
        'Заметка': 'Note',
        'Новая категория': 'New category',
        'Добавить': 'Add',
        'Сохранить': 'Save',
        'Удалить карточку': 'Delete card',
        'Прикрепить фото': 'Attach photos',
        'Загрузить': 'Upload',
        'От': 'From',
        'До': 'To',
        'Вырезать фрагмент видео': 'Cut video clip',
        'Создать отдельный видеофрагмент по указанному интервалу.': 'Create a separate video clip for the selected interval.',
        'Получить аудио': 'Extract audio',
        'Извлечь звук из видео в MP3 или FLAC.': 'Extract audio from the video as MP3 or FLAC.',
        'Формат': 'Format',
        'Битрейт MP3, кбит/с': 'MP3 bitrate, kbps',
        'Транскрипт': 'Transcript',
        'Создать аудио и распознать речь с выбранным языком.': 'Create audio and transcribe speech using the selected language.',
        'Язык': 'Language',
        'Автоматически': 'Automatic',
        'Русский': 'Russian',
        'Английский': 'English',
        'Гоу': 'Go',
        'Перевод транскрипта': 'Transcript translation',
        'Импорт готового перевода': 'Import existing translation',
        'Перетащите TXT сюда': 'Drop TXT here',
        'или': 'or',
        'Выбрать файл': 'Choose file',
        'Файл не выбран': 'No file selected',
        'Язык перевода': 'Translation language',
        'Выберите язык…': 'Choose language…',
        'Название пользовательского перевода': 'Custom translation name',
        'Импортировать': 'Import',
        'Показать оригинал ▾': 'Show original ▾',
        'Скачать TXT': 'Download TXT',
        'Добавить фрагмент': 'Add segment',
        'Введите тайм-код и текст. Например:': 'Enter a timecode and text. Example:',
        'Закрыть': 'Close',
        'Предыдущий кадр': 'Previous frame',
        'Следующий кадр': 'Next frame',
        'Сделать миниатюрой': 'Set as thumbnail',
        'Настройки транскрибации': 'Transcription settings',
        'Транскрибация': 'Transcription',
        'Сервис': 'Service',
        'Модель': 'Model',
        'API-ключ': 'API key',
        'Python': 'Python',
        'Перевод': 'Translation',
        'Логин и пароль': 'Username and password',
        'Текущий пароль': 'Current password',
        'Новый пароль': 'New password',
        'Повторите новый пароль': 'Repeat new password',
        'Оставьте пустым, чтобы не менять': 'Leave blank to keep unchanged',
        'Сохранить логин/пароль': 'Save username/password',
        'Удалить': 'Delete',
        'Скачать': 'Download',
        'Сделать обычным': 'Make regular video',
        'Посмотреть': 'View',
        'Перевести': 'Translate',
        'Просмотр видео': 'Video player',
        'Загрузка…': 'Loading…',
        'Загрузка видео...': 'Loading video…',
        'Нажмите кнопку воспроизведения.': 'Press play to start playback.',
        'Без названия': 'Untitled',
        'Не найдено': 'Not found',
        'Ничего не найдено.': 'Nothing found.',
        'Видео не найдены': 'No videos found',
        'Кадры не созданы': 'Frames not generated',
        'Кадры создаются': 'Frames are being generated',
        'Кадры запланированы': 'Frames queued',
        'Обработка идет': 'Processing',
        'Ожидание запуска': 'Waiting to start',
        'Запуск…': 'Starting…',
        'Ошибка': 'Error',
        'Ошибка запроса': 'Request error',
        'В категорию…': 'Assign category…',
        'Назначить категорию': 'Assign category',
        'Название': 'Title',
        'Название файла': 'File name',
        'Поиск по названию файла': 'Search by file name',
        'Поиск по названию и заметке': 'Search title and note',
        'Первые пять автоматически созданных кадров для каждого видео из текущей корневой папки.': 'The first five generated frames for each video in the current root folder.',
        'Клик по кадру открывает просмотр; листание ограничено пятью кадрами этой строки.': 'Click a frame to view it; navigation is limited to the five frames in that row.',
        'Групповой просмотр кадров': 'Bulk frame view',
        'Групповой просмотр метаданных': 'Bulk metadata view',
        'Групповое добавление метаданных': 'Bulk metadata import',
        'Имя файла, заголовок, заметка, путь, категория...': 'File name, title, note, path, category…',
        'Название, имя файла, путь или категория…': 'Title, file name, path or category…',
        'Например: D:\\Video или C:\\Users\\User\\Movies': 'For example: D:\\Video or C:\\Users\\User\\Movies',
        'Например: C:\\laragon\\bin\\python\\python-3.13\\python.exe': 'For example: C:\\laragon\\bin\\python\\python-3.13\\python.exe',
        'Проверить изменения на диске и обновить кэш': 'Check disk changes and refresh cache',
        'Удалить кэш выбранной библиотеки и все производные файлы, не затрагивая исходные видео': 'Delete the selected library cache and all derived files without deleting source videos',
        'Выбрать для групповой операции': 'Select for bulk action',
        'Длительность видео': 'Video duration',
        'Открыть карточку': 'Open card',
        'Открыть карточку файла': 'Open file card',
        'Открыть карточку исходного видео': 'Open source video card',
        'Открыть карточку созданного видео': 'Open created video card',
        'Открыть видео с этого момента': 'Open video at this time',
        'Открыть карточку': 'Open card',
        'Свернуть': 'Collapse',
        'Развернуть': 'Expand',
        'Excel-файл': 'Excel file',
        'Python для провайдера': 'Provider Python',
        'Введите API-ключ': 'Enter API key',
        'Для Groq при пустых полях API-ключа и Python используются соответствующие настройки транскрибации.': 'For Groq, empty API key and Python fields use the corresponding transcription settings.',
        'Если пусто — используется Python из транскрибации': 'If empty, transcription Python is used',
        'Импорт выполняется для файлов из текущей выбранной корневой папки.': 'Import is performed for files in the currently selected root folder.',
        'Можно использовать ключ транскрибации Groq': 'You can reuse the Groq transcription key',
        'Начните вводить название или текст заметки…': 'Start typing a title or note text…',
        'Ожидаемые колонки:': 'Expected columns:',
        'Пустые ячейки очищают существующие значения': 'Empty cells clear existing values',
        'Сделать кадр миниатюрой': 'Set frame as thumbnail',
        'Сохранить перевод': 'Save translation',
        'Сохранить транскрибацию': 'Save transcription',
        'Таблица формируется по файлам из текущей выбранной корневой папки.': 'The table is built from files in the currently selected root folder.',
        'Транскрипт всегда создает и сохраняет соответствующее аудио. Если одновременно отмечены «Получить аудио» и «Транскрипт», отдельная вторая аудиодорожка не создается.': 'A transcript always creates and saves the corresponding audio. If both Extract audio and Transcript are selected, no second audio track is created.',
        'Укажите общий интервал. Для аудио и транскрипта пустые поля означают весь файл. Для вырезки нужно указать хотя бы одну границу.': 'Set a shared interval. Empty fields mean the whole file for audio and transcription. For clipping, specify at least one boundary.',
        'Укажите папку и нажмите «Показать файлы».': 'Choose a folder and click Show files.',
        'Название можно указывать с расширением или без него. Для одинаковых имен в разных подпапках укажите относительный путь.': 'The file name may be entered with or without an extension. For duplicate names in different subfolders, specify the relative path.',
        'Например: Перевод Иванова': 'For example: Ivanov translation',
        'Например: Поездка 2026': 'For example: Trip 2026',
        '[00:12:35] Текст нового фрагмента': '[00:12:35] New segment text',
        '[hh:mm:ss] Текст фрагмента': '[hh:mm:ss] Segment text'
    };

    const REGEX = [
        [/^Выбрано:\s*(\d+)$/u, 'Selected: $1'],
        [/^Кадр\s+(\d+)$/u, 'Frame $1'],
        [/^Кадр\s+(\d+)\s+·\s+(.+)$/u, 'Frame $1 · $2'],
        [/^Перевод\s+(\d+)%$/u, 'Translation $1%'],
        [/^Склейка\s+(\d+)%$/u, 'Merge $1%'],
        [/^Осталось видео:\s*(\d+)\.?$/u, 'Videos remaining: $1.'],
        [/^Ожидают создания кадров:\s*(\d+)\s+видео\.?$/u, '$1 videos are waiting for frame generation.'],
        [/^Нет обновления прогресса\s+(\d+)\s+сек\.?$/u, 'No progress update for $1 sec.'],
        [/^Создано обычное видео:\s*(.+)$/u, 'Regular video created: $1'],
        [/^Последнее обновление кэша:\s*(.+)\.$/u, 'Last cache refresh: $1.'],
        [/^Перекодирование\s+(\d+)\/(\d+)\s+·\s+(.+)$/u, 'Re-encoding $1/$2 · $3'],
        [/^Быстрая склейка\s+·\s+(.+)$/u, 'Fast merge · $1'],
        [/^Перевод сегментов\s+(.+)$/u, 'Translating segments $1'],
        [/^Задание\s+#(\d+)$/u, 'Job #$1'],
        [/^(\d+)\s+папок$/u, '$1 folders'],
        [/^Файлов:\s*(\d+)\s+•\s+Подпапок:\s*(\d+)$/u, 'Files: $1 • Subfolders: $2'],
        [/^Кадр на\s+(.+)$/u, 'Frame at $1'],
        [/^Файлы:\s+добавлено\s+(\d+);\s+изменено\s+(\d+);\s+перемещено\/переименовано\s+(\d+);\s+удалено\s+(\d+);\s+без изменений\s+(\d+)\.(.*)$/u,
            'Files: added $1; changed $2; moved/renamed $3; removed $4; unchanged $5.$6']
    ];

    const PHRASES = [
        ['Название можно указывать с расширением или без него. Для одинаковых имен в разных подпапках укажите относительный путь.', 'The file name may be entered with or without an extension. For duplicate names in different subfolders, specify the relative path.'],
        ['используется пароль приложения по умолчанию admin. Откройте «Действия → Настройки» и смените его.', 'the default application password admin is still in use. Open Actions → Settings and change it.'],
        ['Стандартные данные БД admin/admin указаны в config.php. Смените пароль пользователя БД и обновите DB_PASS.', 'Default database credentials admin/admin are configured in config.php. Change the database user password and update DB_PASS.'],
        ['Ошибка фонового обработчика:', 'Background worker error:'],
        ['Последняя ошибка:', 'Last error:'],
        ['Ошибка создания кадров', 'Frame generation error'],
        ['Извлечение аудио', 'Audio extraction'],
        ['Вырезание фрагмента', 'Clip extraction'],
        ['Конвертация в MP4', 'MP4 conversion'],
        ['Конвертация…', 'Converting…'],
        ['Запуск конвертации…', 'Starting conversion…'],
        ['Запуск перевода…', 'Starting translation…'],
        ['Запускаю FFmpeg…', 'Starting FFmpeg…'],
        ['Останавливается текущий FFmpeg…', 'Stopping current FFmpeg…'],
        ['Кэш библиотеки удален.', 'Library cache deleted.'],
        ['Кэш выбранной библиотеки удален.', 'Selected library cache deleted.'],
        ['Кэш удален. Исходные видео не затронуты.', 'Cache deleted. Source videos were not affected.'],
        ['Карточка удалена. Сам видеофайл не затронут.', 'Card deleted. The video file was not affected.'],
        ['Видео полностью удалено с диска и из каталога.', 'Video fully deleted from disk and catalog.'],
        ['Логин и пароль обновлены.', 'Username and password updated.'],
        ['Миниатюра видео изменена.', 'Video thumbnail updated.'],
        ['Импорт завершен.', 'Import completed.'],
        ['Импортирую…', 'Importing…'],
        ['Не удалось загрузить дерево файлов.', 'Could not load the file tree.'],
        ['Сервер вернул некорректный ответ. Подробности записаны в консоль браузера.', 'The server returned an invalid response. Details were written to the browser console.'],
        ['Требуется повторный вход в приложение.', 'Please sign in again.'],
        ['Требуется вход в приложение.', 'Sign-in is required.'],
        ['Новый пароль и подтверждение не совпадают.', 'New password and confirmation do not match.'],
        ['Название новой папки:', 'New folder name:'],
        ['Для склейки нужно минимум два видео.', 'At least two videos are required to merge.'],
        ['Для склейки выберите минимум два видео и не выбирайте папки.', 'Select at least two videos and no folders to merge.'],
        ['Категорию можно назначить только выбранным видео.', 'A category can only be assigned to selected videos.'],
        ['Выберите хотя бы одну операцию.', 'Select at least one operation.'],
        ['Выберите одну или несколько операций. Все используют один интервал «От / До».', 'Select one or more operations. All use the same From / To interval.'],
        ['Транскрипт уже включает создание аудио: отдельная вторая аудиодорожка не создается.', 'A transcript already includes audio creation; a second separate audio track is not created.'],
        ['Для транскрипта сначала создается и сохраняется аудио выбранного формата, затем оно отправляется сервису распознавания.', 'For transcription, audio in the selected format is created and saved first, then sent to the speech recognition service.'],
        ['FLAC сохраняется без потерь; битрейт не применяется.', 'FLAC is lossless; bitrate does not apply.'],
        ['MP3 сохраняется в выбранном битрейте.', 'MP3 is saved using the selected bitrate.'],
        ['Дождитесь завершения текущих операций с видео', 'Wait for the current video operations to finish'],
        ['Нажмите кнопку воспроизведения.', 'Press play to start playback.'],
        ['Браузер не смог воспроизвести этот формат или файл недоступен.', 'The browser could not play this format or the file is unavailable.'],
        ['Воспроизводится сконвертированная MP4-копия.', 'Playing the converted MP4 copy.'],
        ['Не удалось проверить MP4-копию, открываю исходный файл.', 'Could not verify the MP4 copy; opening the source file.']
    ];

    let language = localStorage.getItem(STORAGE_KEY) === 'en' ? 'en' : 'ru';
    const originalText = new WeakMap();
    const originalAttrs = new WeakMap();
    let applying = false;

    function shouldSkip(node) {
        const el = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
        if (!el) return false;
        return Boolean(el.closest([
            '[data-i18n-skip]',
            '.file-name', '.dir-name', '.result-title', '.favorite-open', '.tile-title',
            '.transcript-segment-text', '.transcript-segment-editor', '.transcript-result-name',
            '.file-tool-name-button', '.metadata-view-title-cell', '.metadata-view-note-cell',
            '.screenshot-view-name', '.image-viewer-caption'
        ].join(',')));
    }

    function translateString(value) {
        if (language !== 'en' || typeof value !== 'string' || value === '') return value;
        const leading = value.match(/^\s*/)?.[0] || '';
        const trailing = value.match(/\s*$/)?.[0] || '';
        const core = value.slice(leading.length, value.length - trailing.length || undefined);
        if (!core) return value;
        if (TEXT[core]) return leading + TEXT[core] + trailing;
        for (const [rx, replacement] of REGEX) {
            if (rx.test(core)) return leading + core.replace(rx, replacement) + trailing;
        }
        let out = core;
        for (const [ru, en] of PHRASES) out = out.replaceAll(ru, en);
        return leading + out + trailing;
    }

    function applyTextNode(node) {
        if (shouldSkip(node)) return;
        if (!originalText.has(node)) originalText.set(node, node.nodeValue || '');
        const source = originalText.get(node);
        const target = language === 'en' ? translateString(source) : source;
        if (node.nodeValue !== target) node.nodeValue = target;
    }

    const ATTRS = ['placeholder', 'title', 'aria-label'];
    function applyElementAttrs(el) {
        if (!(el instanceof Element) || shouldSkip(el)) return;
        let saved = originalAttrs.get(el);
        if (!saved) { saved = {}; originalAttrs.set(el, saved); }
        for (const attr of ATTRS) {
            if (!el.hasAttribute(attr)) continue;
            if (!(attr in saved)) saved[attr] = el.getAttribute(attr) || '';
            const source = saved[attr];
            const target = language === 'en' ? translateString(source) : source;
            if (el.getAttribute(attr) !== target) el.setAttribute(attr, target);
        }
    }

    function applyNode(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            applyTextNode(node);
            return;
        }
        if (!(node instanceof Element)) return;
        applyElementAttrs(node);
        for (const child of node.childNodes) applyNode(child);
    }

    function refreshButtons() {
        document.querySelectorAll('[data-language]').forEach(btn => {
            const active = btn.getAttribute('data-language') === language;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function applyAll() {
        if (!document.body) return;
        applying = true;
        try {
            applyNode(document.body);
            document.documentElement.lang = language === 'en' ? 'en' : 'ru';
            if (document.title === 'Вход — Solanace' || document.title === 'Sign in — Solanace') {
                document.title = language === 'en' ? 'Sign in — Solanace' : 'Вход — Solanace';
            }
            refreshButtons();
        } finally {
            applying = false;
        }
    }

    function setLanguage(next) {
        next = next === 'en' ? 'en' : 'ru';
        if (next === language) { refreshButtons(); return; }
        language = next;
        localStorage.setItem(STORAGE_KEY, language);
        applyAll();
        window.dispatchEvent(new CustomEvent('solanace:languagechange', { detail: { language } }));
    }

    document.addEventListener('click', event => {
        const btn = event.target.closest?.('[data-language]');
        if (btn) setLanguage(btn.getAttribute('data-language'));
    });

    const observer = new MutationObserver(records => {
        if (applying) return;
        applying = true;
        try {
            for (const record of records) {
                if (record.type === 'childList') {
                    record.addedNodes.forEach(applyNode);
                } else if (record.type === 'attributes' && record.target instanceof Element) {
                    const attr = record.attributeName;
                    if (ATTRS.includes(attr)) {
                        let saved = originalAttrs.get(record.target) || {};
                        const current = record.target.getAttribute(attr) || '';
                        // If application code writes a new Russian UI value, use it as the new source.
                        if (/[А-Яа-яЁё]/u.test(current)) saved[attr] = current;
                        originalAttrs.set(record.target, saved);
                        applyElementAttrs(record.target);
                    }
                }
            }
        } finally {
            applying = false;
        }
    });

    const nativeConfirm = window.confirm.bind(window);
    const nativeAlert = window.alert.bind(window);
    const nativePrompt = window.prompt.bind(window);
    window.confirm = message => nativeConfirm(language === 'en' ? translateString(String(message)) : message);
    window.alert = message => nativeAlert(language === 'en' ? translateString(String(message)) : message);
    window.prompt = (message, value) => nativePrompt(language === 'en' ? translateString(String(message)) : message, value);

    window.SolanaceI18n = {
        get language() { return language; },
        setLanguage,
        translate: translateString,
        refresh: applyAll
    };

    const start = () => {
        applyAll();
        observer.observe(document.documentElement, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ATTRS
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
