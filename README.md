<img width="1575" height="791" alt="solanace-logo" src="https://github.com/user-attachments/assets/a6fb13f2-38a5-4712-8a5b-3194e9fd1b45" />

# Solanace

**Solanace** is a local web-based media catalog and toolbox for video archives. It indexes server folders, stores cards and categories, generates preview frames, cuts and merges video, extracts audio, and supports transcription and transcript translation.

The project is intended primarily for **internal use** — a LAN, VPN, or access through a reverse proxy. It is not designed as a public multi-user cloud service.

## Main features

- cached file tree with list and thumbnail views;
- video cards, notes, categories, and attached images;
- full-text search including transcripts;
- 10 automatically generated frames per video and custom thumbnail selection;
- pinned videos;
- bulk move, delete, category assignment, and merge actions;
- FFmpeg integration for frames, audio, clips, MP4 conversion, and video merging;
- pluggable transcription providers (currently Groq / Whisper);
- machine and user-provided transcript translations;
- editable timestamped segments with seek-to-time links;
- Russian / English UI;
- local authentication with Argon2id password hashing.

## Requirements

- PHP **8.1+** (8.2/8.3 recommended);
- MySQL 8+ or a current MariaDB release;
- Apache or Nginx;
- FFmpeg + ffprobe;
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `simplexml`;
- `gd` is recommended for lightweight thumbnail tiles;
- `zip` is recommended for XLSX import (fallback readers are available);
- Python is required only for the current Groq transcription/translation bridge.

For Groq:

```bash
python -m pip install groq
```

## Fresh installation

### 1. Copy the project

Windows/Laragon example:

```text
C:\laragon\www\solanace
```

Linux/Apache example:

```text
/var/www/solanace
```

The `uploads/` directory must be writable by PHP. Media roots must be readable by the PHP service account and writable if you want move/delete/clip/merge operations.

### 2. Create the database and DB user

The supplied `config.php` defaults to:

```text
DB_HOST = 127.0.0.1
DB_NAME = solanace
DB_USER = admin
DB_PASS = admin
```

On a clean local MySQL/MariaDB server, run as a database administrator:

```bash
mysql -u root -p < database_bootstrap.sql
```

This creates database `solanace` and DB account `admin/admin`, granted privileges **only on the Solanace database**.

> The database password `admin` is only a convenient installation default. Change it after installation and update `DB_PASS` in `config.php`.

### 3. Create the schema

```bash
mysql -u admin -p solanace < install.sql
```

Default password: `admin`.

The same files can be imported with phpMyAdmin: run `database_bootstrap.sql` as an administrative DB user, then run `install.sql`.

### 4. FFmpeg

If `ffmpeg`, `ffprobe`, and PHP CLI are available in `PATH`, no configuration is required.

You may also place FFmpeg under:

```text
tools/ffmpeg/bin/ffmpeg.exe
tools/ffmpeg/bin/ffprobe.exe
```

or set explicit paths in `config.php`:

```php
define('FFMPEG_PATH', '');
define('FFPROBE_PATH', '');
define('PHP_CLI_PATH', '');
```

Empty values enable automatic discovery. The same configuration works on Windows and Linux.

### 5. First login

Open Solanace in a browser.

Default application credentials:

```text
username: admin
password: admin
```

While the application password remains `admin`, a warning banner is displayed at the top of the main UI. Change it via:

**Actions → Settings → Username and password**.

Application passwords are stored with Argon2id. For emergency manual hash replacement, use:

```text
tools/make_password_hash.php
tools/make_password_hash.py
```

## Groq configuration

Open **Actions → Settings** and configure:

- provider;
- model;
- API key;
- Python path when required.

A Windows/Laragon Python path can look like:

```text
C:\laragon\bin\python\python-3.13\python.exe
```

## Restricting filesystem access

By design, an authenticated Solanace administrator can select server directories readable by PHP. If the server also contains unrelated sensitive directories, restrict media roots in `config.php`:

```php
define('ALLOWED_MEDIA_ROOTS', [
    '/srv/media',
    '/mnt/archive',
]);
```

Windows example:

```php
define('ALLOWED_MEDIA_ROOTS', [
    'D:\\Video',
    'E:\\Archive',
]);
```

An empty array disables this additional boundary.

## Reverse proxy / HTTPS

Solanace does not trust `X-Forwarded-*` headers by default. If the application is reachable **only** through a trusted reverse proxy that sets `X-Forwarded-Proto` correctly, enable:

```php
define('TRUST_PROXY_HEADERS', true);
```

This lets the application set a `Secure` session cookie when HTTPS terminates at the proxy.

Do not enable this option when clients can reach the backend directly from an untrusted network.

## Apache

The package includes `.htaccess` rules that:

- disable directory listing;
- block direct web access to `config.php`, library/worker files, SQL and Python files;
- block direct HTTP access to `uploads/`;
- automatically protect `.video_catalog_screenshots` service directories with their own `.htaccess`.

Apache must allow overrides (`AllowOverride`) for the application directory.

## Nginx

Nginx ignores `.htaccess`. Add equivalent restrictions, for example:

```nginx
location ~* /(config|db|auth|library_identity|library_categories|.*_lib|.*_worker)\.php$ {
    deny all;
}
location ^~ /uploads/ {
    deny all;
}
location ~* \.(sql|py|pyc|md|log|ini|bak)$ {
    deny all;
}
```

Adjust the paths if Solanace is mounted below a URL prefix.

## Security notes

The fresh-install package received a focused security review appropriate for an internal application. The following changes were applied:

- removed the unauthenticated diagnostic `wrtest.php` file that could write a test file to disk;
- `media.php` no longer accepts an arbitrary base64 filesystem path; it serves only videos registered in `library_files`;
- cards can no longer be created from arbitrary paths outside the cache;
- attached images are no longer served directly from `uploads/`; authenticated `image.php` is used instead;
- added CSRF protection to all state-changing JSON requests;
- added CSP, X-Frame-Options, nosniff, Referrer-Policy, and Permissions-Policy headers;
- enabled strict PHP sessions with HttpOnly, SameSite=Lax, and Secure cookies when HTTPS is detected;
- limited manual image upload count and file size;
- filesystem operations verify that targets remain inside the selected library root;
- SQL statements containing user input use prepared statements;
- worker scripts are CLI-only and are not normal web endpoints;
- the suggested MySQL account is granted privileges only on database `solanace`.

Solanace should still remain behind a VPN / reverse proxy and should not be exposed directly to the public Internet without additional external protection.

## Data layout

Source videos remain in the directories selected by the user. Derived media is stored under:

```text
<library root>/.video_catalog_screenshots/
```

This includes generated frames, derived audio, clips, and related processing output.

Cards, categories, transcripts, translations, and job state are stored in MySQL/MariaDB.

## Backups

A complete backup should include:

1. database `solanace`;
2. source media roots;
3. `.video_catalog_screenshots` inside those roots;
4. the application's `uploads/` directory.

## License

Solanace is licensed under the GNU Affero General Public License v3.0
or later (AGPL-3.0-or-later).
