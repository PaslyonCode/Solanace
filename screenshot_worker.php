<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/screenshot_worker_lib.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

final class WorkerStopRequestedException extends RuntimeException {}
final class WorkerProcessInterruptedException extends RuntimeException {}

function worker_arg_root_id(array $argv): int
{
    foreach ($argv as $arg) {
        if (preg_match('/^--root-id=(\d+)$/', $arg, $match)) return (int)$match[1];
    }
    return 0;
}

function worker_lock_handle(int $rootId)
{
    $path = sw_worker_lock_path($rootId);
    $handle = fopen($path, 'c+');
    if ($handle === false) throw new RuntimeException('Не удалось создать lock-файл обработчика.');
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string)getmypid());
    fflush($handle);
    return $handle;
}

function worker_terminate_process($process): void
{
    $status = proc_get_status($process);
    $pid = (int)($status['pid'] ?? 0);

    if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
        @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
        return;
    }

    @proc_terminate($process);
    usleep(250000);
    $status = proc_get_status($process);
    if (($status['running'] ?? false) && PHP_OS_FAMILY !== 'Windows') {
        @proc_terminate($process, 9);
    }
}

function worker_run_process(
    array $command,
    int $timeoutSeconds,
    ?callable $stdoutCallback = null,
    ?callable $stopRequested = null
): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $options = PHP_OS_FAMILY === 'Windows'
        ? ['bypass_shell' => true, 'create_process_group' => true]
        : ['bypass_shell' => true];

    $process = proc_open($command, $descriptors, $pipes, null, null, $options);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить внешний процесс.');

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $lineBuffer = '';
    $started = microtime(true);
    $exitCode = null;
    $stopWasRequested = false;
    $timedOut = false;

    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $stdout .= $chunk;
            if ($stdoutCallback) {
                $lineBuffer .= $chunk;
                while (($pos = strpos($lineBuffer, "
")) !== false) {
                    $line = trim(substr($lineBuffer, 0, $pos));
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    if ($line !== '') $stdoutCallback($line);
                }
            }
        }
        $errChunk = stream_get_contents($pipes[2]);
        if ($errChunk !== false && $errChunk !== '') $stderr .= $errChunk;

        if (!$stopWasRequested && $stopRequested && $stopRequested()) {
            $stopWasRequested = true;
            worker_terminate_process($process);
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (!$stopWasRequested && (microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            worker_terminate_process($process);
        }
        usleep(100000);
    }

    $chunk = stream_get_contents($pipes[1]);
    if ($chunk !== false) $stdout .= $chunk;
    $chunk = stream_get_contents($pipes[2]);
    if ($chunk !== false) $stderr .= $chunk;
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) $exitCode = $closeCode;

    if ($stopWasRequested) {
        throw new WorkerStopRequestedException('Создание кадров остановлено пользователем.');
    }
    if ($timedOut) {
        throw new RuntimeException('Внешний процесс превысил допустимое время выполнения.');
    }

    return ['code' => (int)$exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function worker_probe_duration(string $ffprobe, string $filePath, int $rootId): float
{
    $result = worker_run_process([
        $ffprobe, '-v', 'error', '-show_entries', 'format=duration:stream=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1', $filePath,
    ], 60, null, fn(): bool => sw_stop_requested($rootId));
    if ($result['code'] !== 0) {
        throw new RuntimeException('FFprobe: ' . trim($result['stderr'] ?: $result['stdout']));
    }

    $durations = [];
    foreach (preg_split('/\R+/', trim($result['stdout'])) as $line) {
        $value = (float)trim($line);
        if (is_finite($value) && $value > 0) $durations[] = $value;
    }
    if (!$durations) throw new RuntimeException('FFprobe не смог определить длительность видео.');
    return max($durations);
}

function worker_screenshot_root_dir(array $root): string
{
    return sw_join_path($root['root_path'], VIDEO_SCREENSHOT_DIRNAME);
}

function worker_hash_dir(array $root, string $hash): string
{
    return sw_join_path(worker_screenshot_root_dir($root), $hash);
}

function worker_clear_frames(array $root, string $hash): void
{
    $stmt = db()->prepare('SELECT relative_path FROM root_video_screenshots WHERE root_id = ? AND file_hash = ?');
    $stmt->execute([(int)$root['id'], $hash]);
    foreach ($stmt->fetchAll() as $row) {
        $file = sw_join_path(worker_screenshot_root_dir($root), str_replace('/', DIRECTORY_SEPARATOR, $row['relative_path']));
        if (is_file($file)) @unlink($file);
    }
    db()->prepare('DELETE FROM root_video_screenshots WHERE root_id = ? AND file_hash = ?')
        ->execute([(int)$root['id'], $hash]);

    $dir = worker_hash_dir($root, $hash);
    if (is_dir($dir)) {
        // IMPORTANT: this directory also stores derived media for the video
        // (audio, clips, transcript TXT, converted copies, etc.).  Regenerating
        // screenshots must never wipe those files.  Remove only screenshot
        // artifacts that may remain even if their DB rows were lost.
        $patterns = [
            $dir . DIRECTORY_SEPARATOR . 'frame_*.jpg',
            $dir . DIRECTORY_SEPARATOR . '.tile_frame_*.jpg',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) @unlink($file);
            }
        }

        // Keep the hash directory if it contains any non-screenshot derivative.
        $leftovers = array_values(array_filter(scandir($dir) ?: [], static fn($name) => $name !== '.' && $name !== '..'));
        if (!$leftovers) @rmdir($dir);
    }
}

function worker_process_job(array $root, array $job, string $ffmpeg, string $ffprobe, array &$progress): void
{
    $rootId = (int)$root['id'];
    $hash = $job['file_hash'];
    $filePath = $job['file_path'];
    $fileName = $job['file_name'];
    $count = VIDEO_SCREENSHOT_COUNT;

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('Видеофайл недоступен: ' . $filePath);
    }

    worker_clear_frames($root, $hash);
    $hashDir = worker_hash_dir($root, $hash);
    if (!is_dir($hashDir) && !mkdir($hashDir, 0775, true) && !is_dir($hashDir)) {
        throw new RuntimeException('Не удалось создать папку кадров: ' . $hashDir);
    }

    $duration = worker_probe_duration($ffprobe, $filePath, $rootId);
    $interval = $duration / ($count + 1);
    $first = $interval;
    $filter = sprintf(
        'fps=fps=1/%.9F:start_time=%.9F:round=near,scale=w=min(1280\\,iw):h=-2',
        $interval,
        $first
    );
    $pattern = $hashDir . DIRECTORY_SEPARATOR . 'frame_%02d.jpg';

    $lastFrame = 0;
    $result = worker_run_process([
        $ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
        '-i', $filePath,
        '-vf', $filter,
        '-frames:v', (string)$count,
        '-q:v', '3',
        '-progress', 'pipe:1', '-nostats',
        $pattern,
    ], defined('SCREENSHOT_FFMPEG_TIMEOUT') ? SCREENSHOT_FFMPEG_TIMEOUT : 3600,
        function (string $line) use ($rootId, $fileName, $count, &$progress, &$lastFrame): void {
            if (preg_match('/^frame=(\d+)$/', $line, $match)) {
                $frame = min($count, (int)$match[1]);
                if ($frame > $lastFrame) {
                    $lastFrame = $frame;
                    sw_update_state($rootId, [
                        'status' => 'running',
                        'total_jobs' => $progress['total'],
                        'completed_jobs' => $progress['completed'],
                        'failed_jobs' => $progress['failed'],
                        'current_file_name' => $fileName,
                        'current_frame' => $frame,
                        'current_frame_total' => $count,
                        'message' => 'FFmpeg создает кадры.',
                        'started_at' => $progress['started_at'],
                    ]);
                }
            }
        },
        fn(): bool => sw_stop_requested($rootId)
    );

    if ($result['code'] !== 0) {
        $stderr = trim($result['stderr']);
        if ($stderr === '') {
            throw new WorkerProcessInterruptedException(
                'FFmpeg был завершен извне. Очередь поставлена на паузу.'
            );
        }
        throw new RuntimeException('FFmpeg: ' . $stderr);
    }

    $frames = [];
    for ($index = 0; $index < $count; $index++) {
        $filename = 'frame_' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '.jpg';
        $absolute = $hashDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($absolute) || filesize($absolute) === 0) {
            throw new RuntimeException('FFmpeg создал неполный набор кадров.');
        }
        $frames[] = [
            'relative_path' => $hash . '/' . $filename,
            'position_seconds' => $duration * ($index + 1) / ($count + 1),
            'sort_order' => $index,
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO root_video_screenshots
                (root_id, file_hash, relative_path, position_seconds, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($frames as $frame) {
            $insert->execute([$rootId, $hash, $frame['relative_path'], $frame['position_seconds'], $frame['sort_order']]);
        }
        $pdo->prepare(
            "UPDATE root_video_screenshot_sets
             SET status = 'ready', last_error = NULL, duration_seconds = ?
             WHERE root_id = ? AND file_hash = ?"
        )->execute([$duration, $rootId, $hash]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$rootId = worker_arg_root_id($argv);
if ($rootId <= 0) exit(2);

$lock = null;
try {
    sw_ensure_schema();
    $lock = worker_lock_handle($rootId);
    if ($lock === null) exit(0);

    $rootStmt = db()->prepare('SELECT * FROM library_roots WHERE id = ?');
    $rootStmt->execute([$rootId]);
    $root = $rootStmt->fetch();
    if (!$root) throw new RuntimeException('Корневой каталог отсутствует в базе.');

    $ffmpeg = sw_ffmpeg_path();
    $ffprobe = sw_ffprobe_path();

    db()->prepare(
        "UPDATE root_video_screenshot_sets
         SET status = 'pending', last_error = 'Предыдущая фоновая обработка была прервана.'
         WHERE root_id = ? AND status = 'processing'"
    )->execute([$rootId]);

    $jobsStmt = db()->prepare(
        "SELECT rvss.file_hash, MIN(lf.file_path) AS file_path, MIN(lf.file_name) AS file_name
         FROM root_video_screenshot_sets rvss
         INNER JOIN library_files lf ON lf.root_id = rvss.root_id AND lf.file_hash = rvss.file_hash
         WHERE rvss.root_id = ? AND rvss.status = 'pending'
         GROUP BY rvss.file_hash
         ORDER BY MIN(lf.relative_path)"
    );
    $jobsStmt->execute([$rootId]);
    $jobs = $jobsStmt->fetchAll();

    $progress = [
        'total' => count($jobs),
        'completed' => 0,
        'failed' => 0,
        'started_at' => date('Y-m-d H:i:s'),
    ];
    $queuePaused = sw_stop_requested($rootId);
    if ($queuePaused) {
        sw_pause_screenshot_jobs($rootId, 'Создание кадров остановлено пользователем.');
        sw_update_state($rootId, [
            'status' => 'paused',
            'total_jobs' => $progress['total'],
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
            'message' => 'Создание кадров остановлено. Нажмите «Продолжить», чтобы возобновить очередь.',
            'started_at' => $progress['started_at'],
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        sw_update_state($rootId, [
            'status' => 'running',
            'total_jobs' => $progress['total'],
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
            'message' => $progress['total'] ? 'Фоновая обработка запущена.' : 'Нет видео, ожидающих обработки.',
            'started_at' => $progress['started_at'],
            'finished_at' => null,
        ]);
    }

    foreach ($jobs as $job) {
        if ($queuePaused) break;
        if (sw_stop_requested($rootId)) {
            sw_pause_screenshot_jobs($rootId, 'Создание кадров остановлено пользователем.');
            sw_update_state($rootId, [
                'status' => 'paused',
                'total_jobs' => $progress['total'],
                'completed_jobs' => $progress['completed'],
                'failed_jobs' => $progress['failed'],
                'current_file_name' => null,
                'current_frame' => 0,
                'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
                'message' => 'Создание кадров остановлено. Нажмите «Продолжить», чтобы возобновить очередь.',
                'started_at' => $progress['started_at'],
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $queuePaused = true;
            break;
        }

        $hash = $job['file_hash'];
        db()->prepare(
            "UPDATE root_video_screenshot_sets
             SET status = 'processing', last_error = NULL
             WHERE root_id = ? AND file_hash = ?"
        )->execute([$rootId, $hash]);

        sw_update_state($rootId, [
            'status' => 'running',
            'total_jobs' => $progress['total'],
            'completed_jobs' => $progress['completed'],
            'failed_jobs' => $progress['failed'],
            'current_file_name' => $job['file_name'],
            'current_frame' => 0,
            'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
            'message' => 'Определяется длительность видео.',
            'started_at' => $progress['started_at'],
        ]);

        try {
            worker_process_job($root, $job, $ffmpeg, $ffprobe, $progress);
            $progress['completed']++;
        } catch (WorkerStopRequestedException|WorkerProcessInterruptedException $e) {
            worker_clear_frames($root, $hash);
            $message = mb_substr($e->getMessage(), 0, 2000);
            sw_pause_screenshot_jobs($rootId, $message);
            sw_update_state($rootId, [
                'status' => 'paused',
                'total_jobs' => $progress['total'],
                'completed_jobs' => $progress['completed'],
                'failed_jobs' => $progress['failed'],
                'current_file_name' => $job['file_name'],
                'current_frame' => 0,
                'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
                'message' => $message . ' Нажмите «Продолжить», чтобы возобновить очередь.',
                'started_at' => $progress['started_at'],
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $queuePaused = true;
            break;
        } catch (Throwable $e) {
            worker_clear_frames($root, $hash);
            $message = mb_substr($e->getMessage(), 0, 2000);
            db()->prepare(
                "UPDATE root_video_screenshot_sets
                 SET status = 'error', last_error = ?
                 WHERE root_id = ? AND file_hash = ?"
            )->execute([$message, $rootId, $hash]);
            $progress['failed']++;
        }
    }

    if (!$queuePaused) sw_update_state($rootId, [
        'status' => 'finished',
        'total_jobs' => $progress['total'],
        'completed_jobs' => $progress['completed'],
        'failed_jobs' => $progress['failed'],
        'current_file_name' => null,
        'current_frame' => 0,
        'current_frame_total' => VIDEO_SCREENSHOT_COUNT,
        'message' => $progress['failed']
            ? 'Обработка завершена, некоторые видео не удалось обработать.'
            : 'Обработка кадров завершена.',
        'started_at' => $progress['started_at'],
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    try {
        sw_update_state($rootId, [
            'status' => 'error',
            'message' => mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable) {
        // Nothing else can be done from a detached worker.
    }
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
