<?php
require_once __DIR__ . '/db.php';

function sw_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS screenshot_worker_state (
            root_id INT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'idle',
            total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
            completed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
            failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
            current_file_name VARCHAR(1024) NULL,
            current_frame TINYINT UNSIGNED NOT NULL DEFAULT 0,
            current_frame_total TINYINT UNSIGNED NOT NULL DEFAULT 10,
            message TEXT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_screenshot_worker_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function sw_join_path(string $base, string $child): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . ltrim($child, "\\/");
}

function sw_worker_lock_path(int $rootId): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'video_catalog_screenshot_worker_' . $rootId . '.lock';
}

function sw_worker_is_active(int $rootId): bool
{
    $handle = @fopen(sw_worker_lock_path($rootId), 'c+');
    if ($handle === false) {
        // Без доступа к lock-файлу безопаснее считать, что worker еще может работать.
        return true;
    }

    $acquired = @flock($handle, LOCK_EX | LOCK_NB);
    if ($acquired) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }

    fclose($handle);
    return true;
}

function sw_worker_pid(int $rootId): int
{
    $contents = @file_get_contents(sw_worker_lock_path($rootId));
    if ($contents === false) return 0;
    $pid = (int)trim($contents);
    return $pid > 0 ? $pid : 0;
}

function sw_force_terminate_worker(int $rootId): bool
{
    $pid = sw_worker_pid($rootId);
    if ($pid <= 0) return false;

    if (PHP_OS_FAMILY === 'Windows') {
        if (!function_exists('exec')) return false;
        @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
    } elseif (function_exists('posix_kill')) {
        @posix_kill($pid, 15);
        usleep(300000);
        if (sw_worker_is_active($rootId)) @posix_kill($pid, 9);
    } elseif (function_exists('exec')) {
        @exec('kill -TERM ' . $pid . ' >/dev/null 2>&1');
        usleep(300000);
        if (sw_worker_is_active($rootId)) @exec('kill -KILL ' . $pid . ' >/dev/null 2>&1');
    } else {
        return false;
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        if (!sw_worker_is_active($rootId)) return true;
        usleep(100000);
    }
    return !sw_worker_is_active($rootId);
}

function sw_state_age_seconds(array $state): ?int
{
    if (empty($state['updated_at'])) return null;
    $timestamp = strtotime((string)$state['updated_at']);
    if ($timestamp === false) return null;
    return max(0, time() - $timestamp);
}

function sw_find_in_path(string $name): ?string
{
    if (!function_exists('exec')) return null;
    $command = PHP_OS_FAMILY === 'Windows'
        ? 'where.exe ' . escapeshellarg($name) . ' 2>NUL'
        : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
    $lines = [];
    $code = 1;
    @exec($command, $lines, $code);
    if ($code === 0) {
        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B\"");
            if ($line !== '' && is_file($line)) return $line;
        }
    }
    return null;
}

function sw_resolve_executable(string $configured, string $binary): string
{
    $configured = trim($configured);
    if ($configured !== '') {
        $configured = trim($configured, "\"");
        if (is_file($configured)) return $configured;
        if (!str_contains($configured, '\\') && !str_contains($configured, '/')) {
            $found = sw_find_in_path($configured);
            if ($found !== null) return $found;
        }
        throw new RuntimeException("Не найден исполняемый файл {$binary}: {$configured}");
    }

    $exe = PHP_OS_FAMILY === 'Windows' ? $binary . '.exe' : $binary;
    $candidates = [
        __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $exe,
        'C:\\ffmpeg\\bin\\' . $exe,
        'C:\\laragon\\bin\\ffmpeg\\bin\\' . $exe,
    ];

    if (PHP_OS_FAMILY === 'Windows') {
        foreach (glob('C:\\laragon\\bin\\ffmpeg\\*\\bin\\' . $exe) ?: [] as $path) $candidates[] = $path;
        foreach (glob('C:\\laragon\\bin\\ffmpeg\\*\\' . $exe) ?: [] as $path) $candidates[] = $path;
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return $candidate;
    }

    $found = sw_find_in_path($binary);
    if ($found !== null) return $found;

    throw new RuntimeException(
        "Не найден {$binary}. Установите FFmpeg либо задайте абсолютный путь в config.php."
    );
}

function sw_ffmpeg_path(): string
{
    return sw_resolve_executable(defined('FFMPEG_PATH') ? (string)FFMPEG_PATH : '', 'ffmpeg');
}

function sw_ffprobe_path(): string
{
    return sw_resolve_executable(defined('FFPROBE_PATH') ? (string)FFPROBE_PATH : '', 'ffprobe');
}

function sw_php_cli_path(): string
{
    $configured = defined('PHP_CLI_PATH') ? trim((string)PHP_CLI_PATH) : '';
    if ($configured !== '') {
        if (!is_file($configured)) throw new RuntimeException('PHP CLI не найден: ' . $configured);
        return $configured;
    }

    $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    if (is_file($candidate)) return $candidate;

    $found = sw_find_in_path(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    if ($found !== null) return $found;
    throw new RuntimeException('Не найден PHP CLI. Укажите PHP_CLI_PATH в config.php.');
}

function sw_update_state(int $rootId, array $values): void
{
    sw_ensure_schema();
    $defaults = [
        'status' => 'idle',
        'total_jobs' => 0,
        'completed_jobs' => 0,
        'failed_jobs' => 0,
        'current_file_name' => null,
        'current_frame' => 0,
        'current_frame_total' => defined('VIDEO_SCREENSHOT_COUNT') ? VIDEO_SCREENSHOT_COUNT : 10,
        'message' => null,
        'started_at' => null,
        'finished_at' => null,
    ];
    $row = array_merge($defaults, $values);

    db()->prepare(
        'INSERT INTO screenshot_worker_state
            (root_id, status, total_jobs, completed_jobs, failed_jobs, current_file_name,
             current_frame, current_frame_total, message, started_at, finished_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status), total_jobs = VALUES(total_jobs),
            completed_jobs = VALUES(completed_jobs), failed_jobs = VALUES(failed_jobs),
            current_file_name = VALUES(current_file_name), current_frame = VALUES(current_frame),
            current_frame_total = VALUES(current_frame_total), message = VALUES(message),
            started_at = VALUES(started_at), finished_at = VALUES(finished_at)'
    )->execute([
        $rootId, $row['status'], (int)$row['total_jobs'], (int)$row['completed_jobs'],
        (int)$row['failed_jobs'], $row['current_file_name'], (int)$row['current_frame'],
        (int)$row['current_frame_total'], $row['message'], $row['started_at'], $row['finished_at'],
    ]);
}

function sw_get_state(int $rootId): array
{
    sw_ensure_schema();

    $fetchState = static function (int $id): array|false {
        $stmt = db()->prepare('SELECT * FROM screenshot_worker_state WHERE root_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    };
    $fetchCounts = static function (int $id): array {
        $stmt = db()->prepare(
            "SELECT
                SUM(status = 'pending') AS pending_count,
                SUM(status = 'processing') AS processing_count,
                SUM(status = 'ready') AS ready_count,
                SUM(status = 'error') AS error_count,
                SUM(status = 'paused') AS paused_count
             FROM root_video_screenshot_sets WHERE root_id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    };

    $state = $fetchState($rootId);
    $counts = $fetchCounts($rootId);

    if ($state) {
        $status = (string)$state['status'];
        $age = sw_state_age_seconds($state);
        $active = in_array($status, ['queued', 'running', 'stopping'], true)
            ? sw_worker_is_active($rootId)
            : false;

        // После ручного завершения PHP/FFmpeg старое состояние stopping могло
        // остаться навсегда. Если PHP-worker сам не завершился за 15 секунд,
        // завершаем его дерево процессов по PID из lock-файла.
        if ($status === 'stopping' && $active && $age !== null && $age >= 15) {
            sw_force_terminate_worker($rootId);
            $active = sw_worker_is_active($rootId);
        }

        if ($status === 'stopping' && !$active) {
            $message = 'Предыдущая остановка завершена. Очередь поставлена на паузу.';
            sw_pause_screenshot_jobs($rootId, $message);
            sw_update_state($rootId, [
                'status' => 'paused',
                'total_jobs' => (int)$state['total_jobs'],
                'completed_jobs' => (int)$state['completed_jobs'],
                'failed_jobs' => (int)$state['failed_jobs'],
                'current_file_name' => null,
                'current_frame' => 0,
                'current_frame_total' => (int)$state['current_frame_total'],
                'message' => $message . ' Нажмите «Продолжить» или обновите кэш.',
                'started_at' => $state['started_at'],
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $state = $fetchState($rootId);
            $counts = $fetchCounts($rootId);
        } elseif ($status === 'running' && !$active && ($age === null || $age >= 3)) {
            $message = 'Фоновый PHP-worker завершился внештатно. Очередь поставлена на паузу.';
            sw_pause_screenshot_jobs($rootId, $message);
            sw_update_state($rootId, [
                'status' => 'paused',
                'total_jobs' => (int)$state['total_jobs'],
                'completed_jobs' => (int)$state['completed_jobs'],
                'failed_jobs' => (int)$state['failed_jobs'],
                'current_file_name' => $state['current_file_name'],
                'current_frame' => 0,
                'current_frame_total' => (int)$state['current_frame_total'],
                'message' => $message . ' Нажмите «Продолжить» или обновите кэш.',
                'started_at' => $state['started_at'],
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $state = $fetchState($rootId);
            $counts = $fetchCounts($rootId);
        } elseif ($status === 'queued' && !$active && $age !== null && $age >= 60) {
            sw_update_state($rootId, [
                'status' => 'error',
                'total_jobs' => (int)$state['total_jobs'],
                'completed_jobs' => (int)$state['completed_jobs'],
                'failed_jobs' => (int)$state['failed_jobs'],
                'message' => 'Фоновый PHP-процесс не запустился. Проверьте PHP_CLI_PATH и разрешение функций popen/proc_open.',
                'started_at' => $state['started_at'],
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $state = $fetchState($rootId);
        }
    }

    if (!$state) {
        $state = [
            'root_id' => $rootId,
            'status' => ((int)($counts['pending_count'] ?? 0) > 0) ? 'idle' : 'finished',
            'total_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'current_file_name' => null,
            'current_frame' => 0,
            'current_frame_total' => defined('VIDEO_SCREENSHOT_COUNT') ? VIDEO_SCREENSHOT_COUNT : 10,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => null,
        ];
    }

    return [
        'root_id' => (int)$rootId,
        'status' => (string)$state['status'],
        'total_jobs' => (int)$state['total_jobs'],
        'completed_jobs' => (int)$state['completed_jobs'],
        'failed_jobs' => (int)$state['failed_jobs'],
        'current_file_name' => $state['current_file_name'],
        'current_frame' => (int)$state['current_frame'],
        'current_frame_total' => (int)$state['current_frame_total'],
        'message' => $state['message'],
        'started_at' => $state['started_at'],
        'finished_at' => $state['finished_at'],
        'updated_at' => $state['updated_at'],
        'pending_count' => (int)($counts['pending_count'] ?? 0),
        'processing_count' => (int)($counts['processing_count'] ?? 0),
        'ready_count' => (int)($counts['ready_count'] ?? 0),
        'error_count' => (int)($counts['error_count'] ?? 0),
        'paused_count' => (int)($counts['paused_count'] ?? 0),
    ];
}

function sw_stop_requested(int $rootId): bool
{
    sw_ensure_schema();
    $stmt = db()->prepare('SELECT status FROM screenshot_worker_state WHERE root_id = ?');
    $stmt->execute([$rootId]);
    return in_array((string)$stmt->fetchColumn(), ['stopping', 'paused'], true);
}

function sw_pause_screenshot_jobs(int $rootId, string $message): void
{
    db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'paused', last_error = ?
         WHERE root_id = ? AND status IN ('pending', 'processing')"
    )->execute([$message, $rootId]);
}

function sw_resume_paused_jobs(int $rootId): int
{
    $stmt = db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'pending', last_error = NULL
         WHERE root_id = ? AND status = 'paused'"
    );
    $stmt->execute([$rootId]);
    return $stmt->rowCount();
}

function sw_count_pending_jobs(int $rootId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM root_video_screenshot_sets WHERE root_id = ? AND status = 'pending'");
    $stmt->execute([$rootId]);
    return (int)$stmt->fetchColumn();
}

function sw_launch_worker(int $rootId, bool $resumePaused = false): array
{
    sw_ensure_schema();
    // Validate dependencies before reporting that the worker has started.
    sw_ffmpeg_path();
    sw_ffprobe_path();
    $php = sw_php_cli_path();

    $state = sw_get_state($rootId);
    if (in_array($state['status'], ['queued', 'running', 'stopping'], true)) return $state;
    if ($state['status'] === 'paused') {
        if (!$resumePaused) return $state;
        sw_resume_paused_jobs($rootId);
        sw_update_state($rootId, [
            'status' => 'idle',
            'total_jobs' => (int)$state['total_jobs'],
            'completed_jobs' => (int)$state['completed_jobs'],
            'failed_jobs' => (int)$state['failed_jobs'],
            'current_frame_total' => defined('VIDEO_SCREENSHOT_COUNT') ? VIDEO_SCREENSHOT_COUNT : 10,
            'message' => 'Очередь кадров возобновляется.',
            'started_at' => $state['started_at'],
            'finished_at' => null,
        ]);
        $state = sw_get_state($rootId);
    }

    $pending = sw_count_pending_jobs($rootId);
    if ($pending <= 0) return $state;

    sw_update_state($rootId, [
        'status' => 'queued',
        'total_jobs' => $pending,
        'completed_jobs' => 0,
        'failed_jobs' => 0,
        'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
        'message' => 'Фоновый обработчик поставлен в очередь.',
        'started_at' => date('Y-m-d H:i:s'),
        'finished_at' => null,
    ]);

    $script = __DIR__ . DIRECTORY_SEPARATOR . 'screenshot_worker.php';
    if (!is_file($script)) throw new RuntimeException('Не найден screenshot_worker.php.');

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd.exe /D /S /C start "" /B '
            . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --root-id=' . $rootId . ' >NUL 2>&1';
        $handle = @popen($command, 'r');
        if ($handle === false) throw new RuntimeException('Не удалось запустить фоновый обработчик кадров.');
        pclose($handle);
    } else {
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --root-id=' . $rootId . ' >/dev/null 2>&1 &';
        @exec($command, $output, $code);
        if ($code !== 0) throw new RuntimeException('Не удалось запустить фоновый обработчик кадров.');
    }

    usleep(120000);
    return sw_get_state($rootId);
}
