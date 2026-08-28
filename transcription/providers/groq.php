<?php
function tr_provider_groq_config(): array
{
    return [
        'label' => 'Groq',
        'default_model' => 'whisper-large-v3',
        'models' => ['whisper-large-v3', 'whisper-large-v3-turbo'],
        'max_upload_bytes' => 24 * 1024 * 1024,
    ];
}

function tr_provider_groq_python_candidates(string $preferred = ''): array
{
    $candidates = [];
    $preferred = trim($preferred);
    if ($preferred !== '') $candidates[] = $preferred;

    // Prefer the Python shipped with the same Laragon installation as PHP.
    if (PHP_OS_FAMILY === 'Windows') {
        $php = PHP_BINARY;
        if ($php !== '') {
            $laragonRoot = dirname(dirname(dirname(dirname($php))));
            $pythonRoot = $laragonRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
            if (is_dir($pythonRoot)) {
                foreach (glob($pythonRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'python.exe') ?: [] as $path) {
                    $candidates[] = $path;
                }
                $candidates[] = $pythonRoot . DIRECTORY_SEPARATOR . 'python.exe';
            }
        }

        $candidates[] = 'python.exe';
        $candidates[] = 'python';
    } else {
        $candidates[] = 'python3';
        $candidates[] = 'python';
        $candidates[] = '/usr/bin/python3';
        $candidates[] = '/usr/local/bin/python3';
    }

    return array_values(array_unique(array_filter($candidates)));
}

function tr_provider_groq_python_path(string $preferred = ''): ?string
{
    static $cache = [];
    $cacheKey = trim($preferred);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    foreach (tr_provider_groq_python_candidates($preferred) as $candidate) {
        $isPath = str_contains($candidate, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\/]/', $candidate);
        if ($isPath && !is_file($candidate)) continue;

        $cmd = [$candidate, '-c', 'import groq,sys;print(sys.executable)'];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) continue;
        fclose($pipes[0]);
        $stdout = trim((string)stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0 && $stdout !== '') {
            $cache[$cacheKey] = $candidate;
            return $candidate;
        }
    }
    $cache[$cacheKey] = null;
    return null;
}

function tr_provider_groq_transcribe(string $audioPath, string $apiKey, array $options = []): array
{
    if (!is_file($audioPath)) throw new RuntimeException('Аудиофайл для Groq не найден.');

    $python = tr_provider_groq_python_path((string)($options['python_path'] ?? ''));
    if ($python === null) {
        throw new RuntimeException(
            'Не найден Python с установленным пакетом groq. Установите groq в Python Laragon: python -m pip install groq.'
        );
    }

    $script = __DIR__ . DIRECTORY_SEPARATOR . 'groq_bridge.py';
    if (!is_file($script)) throw new RuntimeException('Не найден Python-адаптер Groq: ' . $script);

    $config = tr_provider_groq_config();
    $model = trim((string)($options['model'] ?? $config['default_model']));
    if (!in_array($model, $config['models'], true)) $model = (string)$config['default_model'];
    $args = [
        $python,
        $script,
        '--file', $audioPath,
        '--model', $model,
    ];
    $language = trim((string)($options['language'] ?? ''));
    if ($language !== '') {
        $args[] = '--language';
        $args[] = $language;
    }

    $env = $_ENV;
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && is_scalar($value) && !isset($env[$key])) $env[$key] = (string)$value;
    }
    $env['GROQ_API_KEY'] = trim($apiKey);
    // Force UTF-8 for Russian transcript text on Windows consoles/pipes.
    $env['PYTHONIOENCODING'] = 'utf-8';
    $env['PYTHONUTF8'] = '1';

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($args, $desc, $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Не удалось запустить Python-адаптер Groq.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timeout = defined('TRANSCRIPTION_HTTP_TIMEOUT') ? max(30, (int)TRANSCRIPTION_HTTP_TIMEOUT) : 900;
    $pid = 0;

    while (true) {
        $status = proc_get_status($proc);
        if ($pid <= 0) $pid = (int)($status['pid'] ?? 0);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        if (!$status['running']) break;
        if (microtime(true) - $started > $timeout) {
            if (PHP_OS_FAMILY === 'Windows' && $pid > 0) @exec('taskkill.exe /PID ' . $pid . ' /T /F >NUL 2>&1');
            else @proc_terminate($proc, 9);
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            @proc_close($proc);
            throw new RuntimeException('Groq: превышено время ожидания Python SDK.');
        }
        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
    $exitFromStatus = isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
    $code = proc_close($proc);
    if ($code === -1 && $exitFromStatus >= 0) $code = $exitFromStatus;

    $json = json_decode(trim($stdout), true);
    if (!is_array($json)) {
        $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        throw new RuntimeException('Groq Python SDK вернул некорректный ответ' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 1000) : '.'));
    }
    if ($code !== 0 || empty($json['ok'])) {
        $message = trim((string)($json['error'] ?? ''));
        if ($message === '') $message = trim($stderr);
        if ($message === '') $message = 'неизвестная ошибка Python SDK';
        throw new RuntimeException('Groq Python SDK: ' . $message);
    }

    $payload = $json['result'] ?? null;
    if (!is_array($payload)) throw new RuntimeException('Groq Python SDK не вернул объект транскрипции.');

    $segments = [];
    foreach (($payload['segments'] ?? []) as $seg) {
        if (!is_array($seg)) continue;
        $text = trim((string)($seg['text'] ?? ''));
        if ($text === '') continue;
        $segments[] = [
            'start' => (float)($seg['start'] ?? 0),
            'end' => (float)($seg['end'] ?? ($seg['start'] ?? 0)),
            'text' => $text,
        ];
    }

    return [
        'model' => $model,
        'language' => trim((string)($payload['language'] ?? $language)),
        'text' => trim((string)($payload['text'] ?? '')),
        'segments' => $segments,
    ];
}
