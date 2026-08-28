<?php
function tl_provider_groq_python_candidates(string $preferred = ''): array
{
    if (function_exists('tr_provider_groq_python_candidates')) return tr_provider_groq_python_candidates($preferred);
    $out = [];
    if (trim($preferred) !== '') $out[] = trim($preferred);
    if (PHP_OS_FAMILY === 'Windows') {
        $php = PHP_BINARY;
        if ($php !== '') {
            $laragonRoot = dirname(dirname(dirname(dirname($php))));
            $pythonRoot = $laragonRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
            if (is_dir($pythonRoot)) {
                foreach (glob($pythonRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'python.exe') ?: [] as $path) $out[] = $path;
            }
        }
        $out[] = 'python.exe'; $out[] = 'python';
    } else { $out[] = 'python3'; $out[] = 'python'; }
    return array_values(array_unique($out));
}

function tl_provider_groq_python_path(string $preferred = ''): ?string
{
    if (function_exists('tr_provider_groq_python_path')) return tr_provider_groq_python_path($preferred);
    foreach (tl_provider_groq_python_candidates($preferred) as $candidate) {
        $cmd = [$candidate, '-c', 'from groq import Groq;import sys;print(sys.executable)'];
        $proc = @proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($proc)) continue;
        fclose($pipes[0]); $stdout=trim((string)stream_get_contents($pipes[1])); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc)===0 && $stdout!=='') return $candidate;
    }
    return null;
}

function tl_provider_groq_translate_batch(array $items, string $sourceLanguage, string $targetLanguage, array $settings): array
{
    $python = tl_provider_groq_python_path((string)($settings['python_path'] ?? ''));
    if ($python === null) throw new RuntimeException('Не найден Python с установленным пакетом groq.');
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'groq_bridge.py';
    if (!is_file($script)) throw new RuntimeException('Не найден Python-адаптер перевода Groq.');

    $input = json_encode(['items'=>$items,'source_language'=>$sourceLanguage,'target_language'=>$targetLanguage], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($input === false) throw new RuntimeException('Не удалось подготовить пакет текста к переводу.');
    $temp = tempnam(sys_get_temp_dir(), 'videocat_translate_');
    if ($temp === false || file_put_contents($temp, $input) === false) throw new RuntimeException('Не удалось создать временный файл перевода.');
    $args = [$python, $script, '--input', $temp, '--model', (string)$settings['model']];
    $env = $_ENV;
    foreach ($_SERVER as $k=>$v) if (is_string($k) && is_scalar($v) && !isset($env[$k])) $env[$k]=(string)$v;
    $env['GROQ_API_KEY'] = trim((string)$settings['api_key']);
    $env['PYTHONIOENCODING']='utf-8'; $env['PYTHONUTF8']='1';
    $proc = @proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env, ['bypass_shell'=>true]);
    if (!is_resource($proc)) { @unlink($temp); throw new RuntimeException('Не удалось запустить Python-адаптер перевода Groq.'); }
    fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]); $stderr=(string)stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code=proc_close($proc); @unlink($temp);
    $json=json_decode(trim($stdout), true);
    if ($code!==0 || !is_array($json) || empty($json['ok'])) {
        $msg=is_array($json)?trim((string)($json['error']??'')):trim($stderr);
        if ($msg==='') $msg=trim($stdout);
        if (is_array($json) && !empty($json['status']) && !str_contains($msg, (string)$json['status'])) {
            $msg = 'HTTP ' . (int)$json['status'] . ': ' . $msg;
        }
        if (is_array($json) && !empty($json['code']) && !str_contains(strtolower($msg), strtolower((string)$json['code']))) {
            $msg = strtoupper((string)$json['code']) . ': ' . $msg;
        }
        throw new RuntimeException('Groq translation: ' . ($msg!==''?$msg:'неизвестная ошибка'));
    }
    $translations=$json['translations']??null;
    if (!is_array($translations)) throw new RuntimeException('Groq не вернул массив переводов.');
    return $translations;
}
