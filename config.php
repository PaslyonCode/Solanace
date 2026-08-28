<?php
// Настройки приложения. Поменяйте под вашу MySQL базу.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'solanace');
define('DB_USER', 'admin');
define('DB_PASS', 'admin');
define('DB_CHARSET', 'utf8mb4');

// Куда складывать прикрепленные картинки. Папка должна быть доступна на запись Apache/PHP.
define('UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads');
define('UPLOAD_URL', 'uploads');

// Автоматические кадры из видео.
// Для каждого корневого каталога кадры хранятся внутри него:
// <корневая папка>/.video_catalog_screenshots/<file_hash>/frame_01.jpg
define('VIDEO_SCREENSHOT_DIRNAME', '.video_catalog_screenshots');
define('VIDEO_SCREENSHOT_COUNT', 10);

// Какие файлы считать видео.
define('VIDEO_EXTENSIONS', ['mp4','mkv','avi','mov','wmv','flv','webm','m4v','mpeg','mpg','ts']);


// Фоновое создание кадров через FFmpeg.
// Оставьте пустыми для автоматического поиска в PATH, C:\ffmpeg\bin,
// C:\laragon\bin\ffmpeg и tools/ffmpeg/bin внутри проекта.
define('FFMPEG_PATH', '');
define('FFPROBE_PATH', '');
// Обычно определяется автоматически через PHP_BINDIR.
define('PHP_CLI_PATH', '');
// Максимальное время обработки одного видео, секунд.
define('SCREENSHOT_FFMPEG_TIMEOUT', 3600);

// Операции «Работа с файлом».
// Форматы, которые считаем напрямую воспроизводимыми современным браузером.
define('BROWSER_PLAYABLE_VIDEO_EXTENSIONS', ['mp4','m4v','webm']);
// Максимальное время одной фоновой операции FFmpeg (аудио/фрагмент/конвертация), секунд.
define('FILE_TOOL_FFMPEG_TIMEOUT', 21600);


// Транскрибация. Провайдер и API-ключ задаются через интерфейс приложения.
// Язык отправляется провайдеру как подсказка; для русского оставьте 'ru'.
// Тайм-аут одного HTTP-запроса к сервису транскрибации, секунд.
define('TRANSCRIPTION_HTTP_TIMEOUT', 900);


// Security / deployment.
// Set true only when Solanace is reachable exclusively through a trusted reverse proxy
// that sets X-Forwarded-Proto correctly.
define('TRUST_PROXY_HEADERS', false);

// Maximum size of one manually attached image.
define('MAX_IMAGE_UPLOAD_BYTES', 20 * 1024 * 1024);

// Optional security boundary for media roots. Empty array means any directory readable
// by the PHP service account can be selected after authentication. Example:
// define('ALLOWED_MEDIA_ROOTS', ['/srv/media', '/mnt/archive']);
define('ALLOWED_MEDIA_ROOTS', []);
