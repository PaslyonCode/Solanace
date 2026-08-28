<img width="1000" height="500" alt="solanace-logo" src="https://github.com/user-attachments/assets/2009194f-cdb6-405a-ac25-668e00d617cf" />

# Solanace

**Solanace** is a self-hosted web catalog and toolbox for local video archives. It indexes server-side folders, shows folders and files in a desktop-style interface, stores metadata cards and categories, generates thumbnails, cuts and merges video, extracts audio, transcribes speech, translates transcripts, and supports portable library export/import between instances.

Solanace is designed primarily for **internal deployments**: LAN, VPN, or a trusted reverse proxy. It is a single-administrator application and intentionally has powerful access to selected media folders.

> The UI is bilingual: Russian / English. Windows and Linux are supported.

## Highlights

### Library and file manager

- attach any server-side folder as a library;
- cache the directory tree in MySQL/MariaDB instead of rescanning recursively on every page load;
- folder tree on the left, current-folder files on the right;
- local folder-tree search;
- clickable breadcrumbs for direct navigation to parent folders;
- list and thumbnail views;
- alphabetical A-Z / Z-A sorting and duration ascending / descending;
- create folders, move files/folders, drag-and-drop, physical deletion;
- multi-selection with bulk move, delete, category assignment, and merge actions;
- favorite folders and pinned videos;
- full-library search and category filtering;
- per-library category namespaces;
- cache refresh detects added, removed, changed, moved, and renamed files;
- cards survive moves and renames through stable `file_hash` identification;
- library roots are tracked using `.video_catalog_screenshots/library.id`, allowing drive-letter/root-path changes.

### Video card

The card opens as a wide modal with a **1/4 – 2/4 – 1/4** column layout and a bounded maximum width.

It includes:

- source file name and path;
- previous/next navigation inside the current folder;
- `Alt + Left` / `Alt + Right` keyboard navigation;
- selected thumbnail; clicking it opens video playback;
- file size, duration, and date added;
- custom title;
- category;
- note;
- pin state;
- new-category creation;
- all 10 generated frames visible directly;
- custom primary thumbnail selection;
- manually attached images;
- promoted-clip/source relationships;
- merge provenance;
- delete-card-only and delete-from-disk actions.

### FFmpeg tools and derivatives

The unified **Clip / Audio / Transcript** dialog supports a shared time range and any useful combination of:

- video clip extraction;
- MP3 or FLAC audio extraction;
- speech transcription.

Supported audio presets include MP3 64 / 96 / 192 kbps and FLAC.

Other media features:

- browser-incompatible video conversion to H.264/AAC MP4;
- derived files stored under the library service directory;
- promote a clip into a regular library video;
- transfer matching audio/transcript/translation data to a promoted clip;
- shift transcript timestamps to the new clip timeline.

Clip, audio, and transcript rows expose compact gear menus. Clip names open playback; transcript names open the transcript viewer.

### Automatic frames and thumbnails

- 10 generated frames per video;
- stored under `.video_catalog_screenshots/<file_hash>/`;
- detached PHP CLI / FFmpeg worker;
- progress and current-file status;
- stop/resume support;
- stale-worker recovery;
- lazy-loaded tile thumbnails using `IntersectionObserver`;
- user-selected frame as the primary thumbnail.

### Transcription

Current provider: **Groq / Whisper** through the Python SDK.

Supported models:

- `whisper-large-v3`;
- `whisper-large-v3-turbo`.

Language options: auto / Russian / English.

Stored data includes generated audio, TXT, full text, timestamped segments, provider/model information, and source interval. Large audio is chunked and merged with corrected timestamps.

### Transcript viewer and editing

- timestamped segment list;
- click a timestamp to open video at that position;
- edit/delete segments;
- manually add a segment using `[hh:mm:ss] text`;
- switch between original and translations;
- TXT download;
- direct **Translate** action from the viewer;
- transcript text participates in global search.

### Transcript translation

Current provider: Groq.

Supported models:

- `openai/gpt-oss-20b`;
- `openai/gpt-oss-120b`.

Features:

- machine translation to Russian and English;
- multiple translation variants per transcript;
- custom TXT translation import via file selection or drag-and-drop;
- custom translation names;
- independent custom segmentation;
- edit/delete/add translation segments;
- delete individual variants;
- UTF-8 BOM TXT downloads;
- selected viewer variant persisted in localStorage.

### Video merge

Selecting two or more videos enables **Merge**. The dialog supports:

- drag-to-reorder sources;
- output name;
- Auto / 1920×1080 / 1280×720;
- fit/pad or fill/crop;
- quality selection;
- fast concat without re-encoding when streams are compatible;
- forced H.264/AAC normalization otherwise.

Missing audio can be normalized with silence. Background progress includes percentage, processed/total time, stage, and heartbeat. The result is inserted into the library, gets its own frames, and stores ordered source provenance.

### Bulk utilities

The **Actions** menu contains:

- bulk metadata import from XLSX/CSV;
- bulk metadata view;
- bulk frame view;
- library export;
- library import;
- settings;
- sign out.

Metadata import can set custom title, note, and category; missing categories are created automatically.

### Portable library export/import

**Export library** creates in the current media root:

```text
solanace_export_YYYY-MM-DD_HH-MM-SS.zip
```

The archive contains cards, categories, category assignments, pin states, thumbnail selection, frames, derivatives, transcripts, translations, merge/promoted-clip relationships, attached images, and useful `.video_catalog_screenshots` content.

**Source videos are not included.** Application/DB credentials, Groq API keys, and provider settings are excluded.

Migration flow:

1. copy source videos to the new server/disk;
2. copy the export ZIP;
3. select the new root in Solanace;
4. open **Actions → Import library**;
5. optionally provide a relative **subfolder containing transferred files**;
6. choose the ZIP from the server root or upload it;
7. import.

Old absolute paths and old database IDs are not restored. Files are matched by relative path, optional subfolder prefix, and `file_hash`, and new IDs are created in the target instance without restructuring source files.

## UI layout

The main view targets widescreen displays without stretching small controls across the whole screen. Search and category filters remain centered and bounded.

The workspace contains:

- folder tree on the left;
- current-folder files on the right;
- clickable breadcrumbs;
- sort/view controls;
- pinned-video section;
- selection toolbar shown only when items are selected.

The folder tree expands vertically with its contents instead of using a separate internal vertical scrollbar. Folder search temporarily filters the tree while preserving parent chains.

## Requirements

- PHP **8.1+**; 8.2/8.3 recommended;
- MySQL 8+ or current MariaDB;
- Apache or Nginx;
- FFmpeg + ffprobe;
- PHP extensions:
  - `pdo_mysql`;
  - `mbstring`;
  - `fileinfo`;
  - `simplexml`;
  - `zip` / `ZipArchive` required for library export/import;
  - `gd` recommended for lightweight tile thumbnails;
- Python only for the current Groq bridge.

For Groq:

```bash
python -m pip install groq
```

## Fresh installation

### 1. Copy the project

Windows/Laragon:

```text
C:\laragon\www\solanace
```

Linux:

```text
/var/www/solanace
```

`uploads/` must be writable by PHP. Media roots must be readable; move/delete/FFmpeg/export operations also require write access.

### 2. Create the database

Default `config.php` values:

```text
DB_HOST = 127.0.0.1
DB_NAME = solanace
DB_USER = admin
DB_PASS = admin
```

As a DB administrator:

```bash
mysql -u root -p < database_bootstrap.sql
```

Then:

```bash
mysql -u admin -p solanace < install.sql
```

Default DB password: `admin`.

### 3. FFmpeg

If `ffmpeg`, `ffprobe`, and PHP CLI are in `PATH`, no extra setup is needed. You may also put binaries under:

```text
tools/ffmpeg/bin/
```

or configure explicit paths in `config.php`. Empty path settings enable auto-discovery on both Windows and Linux.

### 4. First login

```text
username: admin
password: admin
```

A warning banner remains visible while the default application password and/or default DB credentials are in use. Change the app credentials via **Actions → Settings → Username and password**.

Application passwords use Argon2id.

## Groq settings

Open **Actions → Settings** and set provider, model, API key, and optional Python path.

Laragon example:

```text
C:\laragon\bin\python\python-3.13\python.exe
```

If translation-specific Groq API/Python fields are empty, the transcription values are reused.

## Security and deployment model

Deploy Solanace behind a LAN, VPN, or trusted reverse proxy. Direct Internet exposure of this administrative app is not recommended.

Implemented protections include:

- local authentication;
- Argon2id;
- CSRF tokens;
- strict PHP sessions, HttpOnly, SameSite=Lax, and Secure under HTTPS;
- CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy;
- media-root containment checks;
- prepared SQL statements;
- authenticated media and attachment delivery;
- direct HTTP denial for `uploads/` and service files under Apache;
- image upload size/count limits;
- CLI-only workers;
- optional media-root allowlist.

Example:

```php
define('ALLOWED_MEDIA_ROOTS', [
    '/srv/media',
    '/mnt/archive',
]);
```

Windows:

```php
define('ALLOWED_MEDIA_ROOTS', [
    'D:\\Video',
    'E:\\Archive',
]);
```

## Reverse proxy

By default Solanace does not trust `X-Forwarded-*`. If PHP is reachable only through a trusted proxy that sets `X-Forwarded-Proto` correctly:

```php
define('TRUST_PROXY_HEADERS', true);
```

## Apache and Nginx

The repository ships `.htaccess` rules that protect service files and direct access to `uploads/`. Apache must allow `AllowOverride`.

Nginx ignores `.htaccess`, so equivalent deny rules must be configured manually.

## Data storage

Source video files remain in place.

Per-library service data lives under:

```text
<root>/.video_catalog_screenshots/
```

This contains frames, tile thumbnails, audio, clips, converted media, and other derivatives.

MySQL/MariaDB stores the cached tree, cards, categories, transcripts, translations, relationships, and background-job state.

Manually attached images are stored under the instance `uploads/` directory and are not exposed directly over HTTP.

## Backup

A complete backup should include:

1. the `solanace` database;
2. source media roots;
3. `.video_catalog_screenshots` directories;
4. the instance `uploads/` directory.

For transferring one library between instances, use the built-in export/import feature.

## License

Solanace is licensed under **GNU Affero General Public License v3.0 or later (`AGPL-3.0-or-later`)**. See [`LICENSE`](LICENSE).
