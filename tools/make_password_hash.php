<?php
// Generate a password hash using exactly the same PHP runtime as Solanace.
// Usage from CMD:
//   php tools\make_password_hash.php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(2);
}

function read_hidden_password(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        // PowerShell SecureString avoids echoing the password on Windows.
        $command = 'powershell -NoProfile -Command "$p=Read-Host -AsSecureString ' . escapeshellarg($prompt) . '; ' .
                   '$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); ' .
                   'try {[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}"';
        $value = shell_exec($command);
        if (is_string($value)) return rtrim($value, "\r\n");
    }
    fwrite(STDOUT, $prompt . ': ');
    return rtrim((string)fgets(STDIN), "\r\n");
}

$password = read_hidden_password('Новый пароль');
$repeat = read_hidden_password('Повторите пароль');
if ($password === '' || $password !== $repeat) {
    fwrite(STDERR, "Пароль пустой или значения не совпадают.\n");
    exit(1);
}

if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
    $algo = PASSWORD_ARGON2ID;
    $options = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];
} else {
    $algo = PASSWORD_DEFAULT;
    $options = ['cost' => 12];
}

$hash = password_hash($password, $algo, $options);
if (!$hash) {
    fwrite(STDERR, "Не удалось создать хэш.\n");
    exit(1);
}

fwrite(STDOUT, "\nHash:\n\n" . $hash . "\n\n");
$sqlHash = str_replace("'", "''", $hash);
fwrite(STDOUT, "SQL:\n\nUPDATE app_auth SET password_hash = '" . $sqlHash . "', password_md5 = NULL WHERE id = 1;\n");
