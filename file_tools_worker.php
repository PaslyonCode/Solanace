<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/file_tools_lib.php';

$jobId = 0;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--job-id=')) $jobId = (int)substr($arg, 9);
}
if ($jobId <= 0) exit(2);
ft_process_job($jobId);
