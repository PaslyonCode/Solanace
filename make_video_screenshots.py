#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Автономное создание кадров для Local Video Catalog.

Пример запуска из CMD:
    py make_video_screenshots.py "D:\\Video"

Скрипт:
  * рекурсивно обходит видео в указанной корневой папке;
  * вычисляет file_hash точно так же, как PHP-приложение;
  * создает 10 равномерно распределенных JPEG через FFmpeg;
  * хранит их в <root>/.video_catalog_screenshots/<file_hash>/;
  * при наличии доступа к MySQL регистрирует кадры в таблицах проекта;
  * не пересоздает полный существующий набор, если не указан --force.

По умолчанию config.php ищется рядом со скриптом. Для работы с MySQL
установите один из драйверов:
    py -m pip install pymysql
или:
    py -m pip install mysql-connector-python
"""

from __future__ import annotations

import argparse
import hashlib
import math
import os
import re
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Optional, Sequence

DEFAULT_EXTENSIONS = (
    "mp4", "mkv", "avi", "mov", "wmv", "flv", "webm", "m4v", "mpeg", "mpg", "ts"
)
DEFAULT_SCREENSHOT_DIRNAME = ".video_catalog_screenshots"
DEFAULT_SCREENSHOT_COUNT = 10
HASH_CHUNK_SIZE = 1024 * 1024


class AppError(RuntimeError):
    """Понятная пользователю ошибка."""


@dataclass
class Settings:
    root: Path
    project_dir: Path
    config_path: Path
    screenshot_dirname: str
    screenshot_count: int
    extensions: set[str]
    ffmpeg: str
    ffprobe: str
    timeout: int
    max_width: int
    jpeg_quality: int
    force: bool
    dry_run: bool
    no_db: bool
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str


class Database:
    def __init__(self, settings: Settings):
        self.kind = ""
        self.conn: Any = None
        self.cursor_factory: Any = None

        pymysql_error: Optional[Exception] = None
        try:
            import pymysql  # type: ignore
            from pymysql.cursors import DictCursor  # type: ignore

            self.kind = "pymysql"
            self.cursor_factory = DictCursor
            self.conn = pymysql.connect(
                host=settings.db_host,
                port=settings.db_port,
                user=settings.db_user,
                password=settings.db_password,
                database=settings.db_name,
                charset="utf8mb4",
                autocommit=False,
                cursorclass=DictCursor,
            )
            return
        except ImportError as exc:
            pymysql_error = exc
        except Exception as exc:
            raise AppError(f"Не удалось подключиться к MySQL через PyMySQL: {exc}") from exc

        try:
            import mysql.connector  # type: ignore

            self.kind = "mysql.connector"
            self.conn = mysql.connector.connect(
                host=settings.db_host,
                port=settings.db_port,
                user=settings.db_user,
                password=settings.db_password,
                database=settings.db_name,
                charset="utf8mb4",
                use_unicode=True,
                autocommit=False,
            )
            return
        except ImportError as exc:
            raise AppError(
                "Не найден Python-драйвер MySQL. Установите его командой:\n"
                "  py -m pip install pymysql\n"
                "или запустите скрипт с --no-db (тогда галерея не появится в приложении автоматически)."
            ) from (pymysql_error or exc)
        except Exception as exc:
            raise AppError(f"Не удалось подключиться к MySQL: {exc}") from exc

    def cursor(self):
        if self.kind == "mysql.connector":
            return self.conn.cursor(dictionary=True)
        return self.conn.cursor()

    def fetchone(self, sql: str, params: Sequence[Any] = ()) -> Optional[dict[str, Any]]:
        cur = self.cursor()
        try:
            cur.execute(sql, params)
            return cur.fetchone()
        finally:
            cur.close()

    def fetchall(self, sql: str, params: Sequence[Any] = ()) -> list[dict[str, Any]]:
        cur = self.cursor()
        try:
            cur.execute(sql, params)
            return list(cur.fetchall())
        finally:
            cur.close()

    def execute(self, sql: str, params: Sequence[Any] = ()) -> int:
        cur = self.cursor()
        try:
            cur.execute(sql, params)
            return int(cur.rowcount)
        finally:
            cur.close()

    def executemany(self, sql: str, rows: Sequence[Sequence[Any]]) -> int:
        cur = self.cursor()
        try:
            cur.executemany(sql, rows)
            return int(cur.rowcount)
        finally:
            cur.close()

    def commit(self) -> None:
        self.conn.commit()

    def rollback(self) -> None:
        self.conn.rollback()

    def close(self) -> None:
        try:
            self.conn.close()
        except Exception:
            pass


def print_line(text: str = "") -> None:
    print(text, flush=True)


def normalize_existing_path(path: Path) -> Path:
    try:
        return path.expanduser().resolve(strict=True)
    except FileNotFoundError as exc:
        raise AppError(f"Папка не найдена: {path}") from exc


def parse_php_config(config_path: Path) -> dict[str, Any]:
    if not config_path.is_file():
        return {}

    text = config_path.read_text(encoding="utf-8", errors="replace")
    values: dict[str, Any] = {}

    string_pattern = re.compile(
        r"define\(\s*['\"](?P<name>[A-Z0-9_]+)['\"]\s*,\s*['\"](?P<value>(?:\\.|[^'\"])*)['\"]\s*\)\s*;",
        re.IGNORECASE,
    )
    for match in string_pattern.finditer(text):
        raw = match.group("value")
        # Достаточно для обычных путей и значений из config.php проекта.
        value = raw.replace("\\\\", "\\").replace("\\'", "'").replace('\\"', '"')
        values[match.group("name")] = value

    number_pattern = re.compile(
        r"define\(\s*['\"](?P<name>[A-Z0-9_]+)['\"]\s*,\s*(?P<value>\d+)\s*\)\s*;",
        re.IGNORECASE,
    )
    for match in number_pattern.finditer(text):
        values[match.group("name")] = int(match.group("value"))

    extensions_match = re.search(
        r"define\(\s*['\"]VIDEO_EXTENSIONS['\"]\s*,\s*\[(?P<body>.*?)\]\s*\)\s*;",
        text,
        re.IGNORECASE | re.DOTALL,
    )
    if extensions_match:
        values["VIDEO_EXTENSIONS"] = re.findall(r"['\"]([^'\"]+)['\"]", extensions_match.group("body"))

    return values


def executable_candidates(project_dir: Path, configured: str, name: str) -> Iterable[Path | str]:
    if configured:
        yield Path(configured)

    from_path = shutil.which(name)
    if from_path:
        yield from_path

    exe = f"{name}.exe" if os.name == "nt" else name
    yield project_dir / "tools" / "ffmpeg" / "bin" / exe

    if os.name == "nt":
        yield Path("C:/ffmpeg/bin") / exe
        yield Path("C:/tools/ffmpeg/bin") / exe
        laragon = Path("C:/laragon/bin/ffmpeg")
        if laragon.is_dir():
            for item in sorted(laragon.glob("**/" + exe), reverse=True):
                yield item


def resolve_executable(project_dir: Path, configured: str, name: str) -> str:
    checked: list[str] = []
    for candidate in executable_candidates(project_dir, configured, name):
        candidate_text = str(candidate)
        checked.append(candidate_text)
        if isinstance(candidate, str):
            return candidate
        if candidate.is_file():
            return str(candidate.resolve())

    raise AppError(
        f"Не найден {name}.exe. Укажите путь через --{name} или положите программу в "
        f"<проект>/tools/ffmpeg/bin/.\nПроверено:\n  " + "\n  ".join(checked)
    )


def canonical_windows_path(path: Path) -> str:
    value = str(path).rstrip("\\/")
    if re.fullmatch(r"[A-Za-z]:", value):
        value += "\\"
    value = value.replace("/", "\\")
    if os.name == "nt":
        value = value.lower()
    return value


def root_key(path: Path) -> str:
    return hashlib.sha1(canonical_windows_path(path).encode("utf-8")).hexdigest()


def video_file_hash(path: Path) -> str:
    """Полностью повторяет PHP file_hash(): video-file-v2 + размер + первый/последний 1 MiB."""
    size = path.stat().st_size
    digest = hashlib.sha1()
    digest.update(f"video-file-v2|{size}|".encode("ascii"))

    with path.open("rb") as stream:
        first = stream.read(min(HASH_CHUNK_SIZE, size))
        digest.update(first)

        if size > HASH_CHUNK_SIZE:
            stream.seek(max(0, size - HASH_CHUNK_SIZE))
            digest.update(stream.read(HASH_CHUNK_SIZE))

    return digest.hexdigest()


def iter_videos(root: Path, extensions: set[str], screenshot_dirname: str) -> Iterable[Path]:
    for current_root, dirs, files in os.walk(root):
        dirs[:] = [name for name in dirs if name != screenshot_dirname]
        current = Path(current_root)
        for name in files:
            path = current / name
            if path.suffix.lower().lstrip(".") in extensions:
                yield path


def expected_frame_paths(root: Path, screenshot_dirname: str, file_hash: str, count: int) -> list[Path]:
    directory = root / screenshot_dirname / file_hash
    return [directory / f"frame_{index:02d}.jpg" for index in range(1, count + 1)]


def complete_frame_set(paths: Sequence[Path]) -> bool:
    return all(path.is_file() and path.stat().st_size > 0 for path in paths)


def remove_existing_frames(directory: Path) -> None:
    if not directory.exists():
        return
    for child in directory.iterdir():
        if child.is_file() and child.name.lower().startswith("frame_") and child.suffix.lower() == ".jpg":
            try:
                child.unlink()
            except OSError as exc:
                raise AppError(f"Не удалось удалить старый кадр {child}: {exc}") from exc


def run_capture(command: Sequence[str], timeout: int) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(
            list(command),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
            check=False,
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
        )
    except subprocess.TimeoutExpired as exc:
        raise AppError(f"Превышено допустимое время выполнения внешней программы ({timeout} с).") from exc
    except OSError as exc:
        raise AppError(f"Не удалось запустить внешнюю программу: {exc}") from exc


def probe_duration(ffprobe: str, video: Path, timeout: int) -> float:
    result = run_capture(
        [
            ffprobe,
            "-v", "error",
            "-show_entries", "format=duration:stream=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            str(video),
        ],
        min(timeout, 120),
    )
    if result.returncode != 0:
        raise AppError("FFprobe: " + (result.stderr.strip() or result.stdout.strip() or "неизвестная ошибка"))

    durations: list[float] = []
    for line in result.stdout.splitlines():
        try:
            value = float(line.strip())
        except ValueError:
            continue
        if math.isfinite(value) and value > 0:
            durations.append(value)

    if not durations:
        raise AppError("FFprobe не смог определить длительность видео.")
    return max(durations)


def generate_frames(
    ffmpeg: str,
    video: Path,
    output_dir: Path,
    duration: float,
    count: int,
    max_width: int,
    quality: int,
    timeout: int,
) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    remove_existing_frames(output_dir)

    interval = duration / (count + 1)
    first = interval
    # Та же схема, что используется фоновым PHP worker проекта.
    video_filter = (
        f"fps=fps=1/{interval:.9f}:start_time={first:.9f}:round=near,"
        f"scale=w=min({max_width}\\,iw):h=-2"
    )
    pattern = output_dir / "frame_%02d.jpg"

    command = [
        ffmpeg,
        "-hide_banner",
        "-loglevel", "error",
        "-nostdin",
        "-y",
        "-i", str(video),
        "-vf", video_filter,
        "-frames:v", str(count),
        "-q:v", str(quality),
        str(pattern),
    ]
    result = run_capture(command, timeout)
    if result.returncode != 0:
        raise AppError("FFmpeg: " + (result.stderr.strip() or result.stdout.strip() or "неизвестная ошибка"))

    frames = [output_dir / f"frame_{index:02d}.jpg" for index in range(1, count + 1)]
    if not complete_frame_set(frames):
        raise AppError("FFmpeg создал неполный набор кадров.")


def ensure_database_schema(db: Database) -> None:
    required = {
        "library_roots",
        "root_video_screenshot_sets",
        "root_video_screenshots",
    }
    placeholders = ",".join(["%s"] * len(required))
    rows = db.fetchall(
        f"SELECT table_name AS table_name FROM information_schema.tables "
        f"WHERE table_schema = DATABASE() AND table_name IN ({placeholders})",
        tuple(required),
    )
    found = {str(row["table_name"]) for row in rows}
    missing = sorted(required - found)
    if missing:
        raise AppError(
            "В базе отсутствуют таблицы: " + ", ".join(missing) +
            ". Импортируйте SQL-миграции текущей версии проекта."
        )


def find_root_id(db: Database, root: Path) -> int:
    key = root_key(root)
    row = db.fetchone("SELECT id, root_path FROM library_roots WHERE root_key = %s LIMIT 1", (key,))
    if row:
        return int(row["id"])

    # Запасной поиск полезен, если корень был записан старой версией с иным регистром пути.
    rows = db.fetchall("SELECT id, root_path FROM library_roots")
    target = canonical_windows_path(root)
    for candidate in rows:
        try:
            candidate_path = Path(str(candidate["root_path"])).resolve(strict=False)
        except OSError:
            candidate_path = Path(str(candidate["root_path"]))
        if canonical_windows_path(candidate_path) == target:
            return int(candidate["id"])

    raise AppError(
        "Эта корневая папка отсутствует в library_roots. "
        "Сначала откройте ее в веб-каталоге и выполните «Обновить кэш»."
    )



def database_frames_ready(db: Database, root_id: int, file_hash: str, count: int) -> bool:
    row = db.fetchone(
        "SELECT rvss.status, rvss.expected_count, COUNT(rvs.id) AS frame_count "
        "FROM root_video_screenshot_sets rvss "
        "LEFT JOIN root_video_screenshots rvs "
        "ON rvs.root_id = rvss.root_id AND rvs.file_hash = rvss.file_hash "
        "WHERE rvss.root_id = %s AND rvss.file_hash = %s "
        "GROUP BY rvss.root_id, rvss.file_hash, rvss.status, rvss.expected_count",
        (root_id, file_hash),
    )
    if not row:
        return False
    return (
        str(row.get("status", "")) == "ready"
        and int(row.get("expected_count", 0)) == count
        and int(row.get("frame_count", 0)) >= count
    )

def register_frames_in_database(
    db: Database,
    root_id: int,
    file_hash: str,
    video: Path,
    duration: float,
    count: int,
) -> None:
    stat = video.stat()
    rows = [
        (
            root_id,
            file_hash,
            f"{file_hash}/frame_{index:02d}.jpg",
            duration * index / (count + 1),
            index - 1,
        )
        for index in range(1, count + 1)
    ]

    try:
        db.execute(
            "DELETE FROM root_video_screenshots WHERE root_id = %s AND file_hash = %s",
            (root_id, file_hash),
        )
        db.executemany(
            "INSERT INTO root_video_screenshots "
            "(root_id, file_hash, relative_path, position_seconds, sort_order) "
            "VALUES (%s, %s, %s, %s, %s)",
            rows,
        )
        db.execute(
            "INSERT INTO root_video_screenshot_sets "
            "(root_id, file_hash, status, expected_count, source_file_size, source_file_mtime, last_error) "
            "VALUES (%s, %s, 'ready', %s, %s, %s, NULL) "
            "ON DUPLICATE KEY UPDATE "
            "status = 'ready', expected_count = VALUES(expected_count), "
            "source_file_size = VALUES(source_file_size), "
            "source_file_mtime = VALUES(source_file_mtime), last_error = NULL",
            (root_id, file_hash, count, int(stat.st_size), int(stat.st_mtime)),
        )
        db.commit()
    except Exception:
        db.rollback()
        raise


def mark_database_error(db: Optional[Database], root_id: Optional[int], file_hash: str, video: Path, message: str, count: int) -> None:
    if db is None or root_id is None:
        return
    try:
        stat = video.stat()
        db.execute(
            "INSERT INTO root_video_screenshot_sets "
            "(root_id, file_hash, status, expected_count, source_file_size, source_file_mtime, last_error) "
            "VALUES (%s, %s, 'error', %s, %s, %s, %s) "
            "ON DUPLICATE KEY UPDATE status = 'error', "
            "expected_count = VALUES(expected_count), "
            "source_file_size = VALUES(source_file_size), "
            "source_file_mtime = VALUES(source_file_mtime), "
            "last_error = VALUES(last_error)",
            (root_id, file_hash, count, int(stat.st_size), int(stat.st_mtime), message[:2000]),
        )
        db.commit()
    except Exception:
        db.rollback()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Создать недостающие скриншоты видео для Local Video Catalog.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("root", help="Корневая папка с видео")
    parser.add_argument(
        "--project-dir",
        default=str(Path(__file__).resolve().parent),
        help="Папка PHP-проекта; используется для поиска config.php и FFmpeg",
    )
    parser.add_argument("--config", default="", help="Путь к config.php; по умолчанию <project-dir>/config.php")
    parser.add_argument("--ffmpeg", default="", help="Полный путь к ffmpeg.exe")
    parser.add_argument("--ffprobe", default="", help="Полный путь к ffprobe.exe")
    parser.add_argument("--count", type=int, default=0, help="Количество кадров; 0 = значение VIDEO_SCREENSHOT_COUNT")
    parser.add_argument("--max-width", type=int, default=1280, help="Максимальная ширина JPEG")
    parser.add_argument("--quality", type=int, default=3, help="FFmpeg q:v, обычно 2–5; меньше = лучше качество")
    parser.add_argument("--timeout", type=int, default=3600, help="Таймаут обработки одного видео, секунд")
    parser.add_argument("--force", action="store_true", help="Пересоздать кадры, даже если полный набор уже существует")
    parser.add_argument("--dry-run", action="store_true", help="Только показать, что было бы сделано")
    parser.add_argument("--no-db", action="store_true", help="Не читать и не обновлять MySQL")
    parser.add_argument("--db-host", default="", help="MySQL host; пусто = DB_HOST из config.php")
    parser.add_argument("--db-port", type=int, default=3306, help="MySQL port")
    parser.add_argument("--db-name", default="", help="Имя базы; пусто = DB_NAME из config.php")
    parser.add_argument("--db-user", default="", help="Пользователь; пусто = DB_USER из config.php")
    parser.add_argument("--db-password", default=None, help="Пароль; по умолчанию DB_PASS из config.php")
    parser.add_argument(
        "--extensions",
        default="",
        help="Расширения через запятую; пусто = VIDEO_EXTENSIONS из config.php",
    )
    return parser


def load_settings(args: argparse.Namespace) -> Settings:
    project_dir = Path(args.project_dir).expanduser().resolve(strict=False)
    config_path = Path(args.config).expanduser() if args.config else project_dir / "config.php"
    config_path = config_path.resolve(strict=False)
    config = parse_php_config(config_path)

    root = normalize_existing_path(Path(args.root))
    if not root.is_dir():
        raise AppError(f"Это не папка: {root}")

    extension_source = (
        [item.strip() for item in args.extensions.split(",") if item.strip()]
        if args.extensions
        else config.get("VIDEO_EXTENSIONS", DEFAULT_EXTENSIONS)
    )
    extensions = {str(item).lower().lstrip(".") for item in extension_source}

    screenshot_dirname = str(config.get("VIDEO_SCREENSHOT_DIRNAME", DEFAULT_SCREENSHOT_DIRNAME))
    screenshot_count = int(args.count or config.get("VIDEO_SCREENSHOT_COUNT", DEFAULT_SCREENSHOT_COUNT))
    if screenshot_count <= 0 or screenshot_count > 100:
        raise AppError("Количество кадров должно быть от 1 до 100.")
    if args.max_width < 64:
        raise AppError("--max-width должен быть не меньше 64.")
    if not (1 <= args.quality <= 31):
        raise AppError("--quality должен быть от 1 до 31.")

    ffmpeg = resolve_executable(project_dir, args.ffmpeg or str(config.get("FFMPEG_PATH", "")), "ffmpeg")
    ffprobe = resolve_executable(project_dir, args.ffprobe or str(config.get("FFPROBE_PATH", "")), "ffprobe")

    return Settings(
        root=root,
        project_dir=project_dir,
        config_path=config_path,
        screenshot_dirname=screenshot_dirname,
        screenshot_count=screenshot_count,
        extensions=extensions,
        ffmpeg=ffmpeg,
        ffprobe=ffprobe,
        timeout=max(30, int(args.timeout)),
        max_width=int(args.max_width),
        jpeg_quality=int(args.quality),
        force=bool(args.force),
        dry_run=bool(args.dry_run),
        no_db=bool(args.no_db),
        db_host=str(args.db_host or config.get("DB_HOST", "127.0.0.1")),
        db_port=int(args.db_port),
        db_name=str(args.db_name or config.get("DB_NAME", "solanace")),
        db_user=str(args.db_user or config.get("DB_USER", "root")),
        db_password=str(config.get("DB_PASS", "") if args.db_password is None else args.db_password),
    )


def run(settings: Settings) -> int:
    print_line(f"Корневая папка: {settings.root}")
    print_line(f"Служебная папка: {settings.root / settings.screenshot_dirname}")
    print_line(f"FFmpeg: {settings.ffmpeg}")
    print_line(f"FFprobe: {settings.ffprobe}")
    print_line(f"Кадров на видео: {settings.screenshot_count}")
    if settings.config_path.is_file():
        print_line(f"Конфигурация: {settings.config_path}")
    else:
        print_line(f"config.php не найден, используются значения по умолчанию: {settings.config_path}")
    print_line()

    db: Optional[Database] = None
    root_id: Optional[int] = None
    if not settings.no_db:
        db = Database(settings)
        ensure_database_schema(db)
        root_id = find_root_id(db, settings.root)
        print_line(f"MySQL: подключено, root_id={root_id}")
    else:
        print_line("MySQL: отключено параметром --no-db")
    print_line()

    videos = list(iter_videos(settings.root, settings.extensions, settings.screenshot_dirname))
    videos.sort(key=lambda p: str(p).lower())
    print_line(f"Найдено видео: {len(videos)}")
    if not videos:
        if db:
            db.close()
        return 0

    created = 0
    skipped = 0
    repaired_db = 0
    failed = 0
    duplicate_hashes = 0
    completed_hashes: set[str] = set()
    started = time.monotonic()

    try:
        for number, video in enumerate(videos, 1):
            relative = video.relative_to(settings.root)
            prefix = f"[{number}/{len(videos)}] {relative}"
            file_hash = ""
            try:
                file_hash = video_file_hash(video)
                if file_hash in completed_hashes:
                    duplicate_hashes += 1
                    print_line(f"{prefix} — дубликат содержимого, уже обработан ({file_hash[:10]}…)")
                    continue

                frame_paths = expected_frame_paths(
                    settings.root,
                    settings.screenshot_dirname,
                    file_hash,
                    settings.screenshot_count,
                )
                output_dir = frame_paths[0].parent
                frames_ready = complete_frame_set(frame_paths)

                if frames_ready and not settings.force:
                    if settings.dry_run:
                        print_line(f"{prefix} — кадры уже есть; база была бы проверена")
                        skipped += 1
                        completed_hashes.add(file_hash)
                        continue

                    if db is not None and root_id is not None:
                        if database_frames_ready(db, root_id, file_hash, settings.screenshot_count):
                            print_line(f"{prefix} — кадры и ссылки в базе уже есть, пропуск")
                        else:
                            duration = probe_duration(settings.ffprobe, video, settings.timeout)
                            register_frames_in_database(
                                db, root_id, file_hash, video, duration, settings.screenshot_count
                            )
                            repaired_db += 1
                            print_line(f"{prefix} — кадры уже есть, ссылки в базе восстановлены")
                    else:
                        print_line(f"{prefix} — кадры уже есть, пропуск")
                    skipped += 1
                    completed_hashes.add(file_hash)
                    continue

                if settings.dry_run:
                    action = "пересоздать" if settings.force else "создать"
                    print_line(f"{prefix} — требуется {action} {settings.screenshot_count} кадров")
                    completed_hashes.add(file_hash)
                    continue

                print_line(f"{prefix} — определение длительности…")
                duration = probe_duration(settings.ffprobe, video, settings.timeout)
                print_line(f"{prefix} — создание {settings.screenshot_count} кадров, длительность {duration:.1f} с…")
                generate_frames(
                    settings.ffmpeg,
                    video,
                    output_dir,
                    duration,
                    settings.screenshot_count,
                    settings.max_width,
                    settings.jpeg_quality,
                    settings.timeout,
                )

                if db is not None and root_id is not None:
                    register_frames_in_database(
                        db, root_id, file_hash, video, duration, settings.screenshot_count
                    )

                created += 1
                completed_hashes.add(file_hash)
                print_line(f"{prefix} — ГОТОВО ({file_hash[:10]}…)")

            except KeyboardInterrupt:
                raise
            except Exception as exc:
                failed += 1
                message = str(exc)
                try:
                    if len(file_hash) == 40:
                        mark_database_error(
                            db, root_id, file_hash, video, message, settings.screenshot_count
                        )
                except Exception:
                    pass
                print_line(f"{prefix} — ОШИБКА: {message}")

    except KeyboardInterrupt:
        print_line("\nОстановлено пользователем.")
        return_code = 130
    else:
        return_code = 1 if failed else 0
    finally:
        if db is not None:
            db.close()

    elapsed = time.monotonic() - started
    print_line()
    print_line("Итог:")
    print_line(f"  Создано наборов:       {created}")
    print_line(f"  Уже существовало:      {skipped}")
    if not settings.no_db:
        print_line(f"  Записи БД проверены:   {repaired_db}")
    print_line(f"  Дубликатов содержимого:{duplicate_hashes}")
    print_line(f"  Ошибок:                {failed}")
    print_line(f"  Время:                 {elapsed:.1f} с")
    return return_code


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        settings = load_settings(args)
        return run(settings)
    except AppError as exc:
        print_line(f"ОШИБКА: {exc}")
        return 2
    except KeyboardInterrupt:
        print_line("\nОстановлено пользователем.")
        return 130
    except Exception as exc:
        print_line(f"НЕОЖИДАННАЯ ОШИБКА: {exc}")
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
