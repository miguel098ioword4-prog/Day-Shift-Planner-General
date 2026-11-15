<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if ((error_reporting() & $no) === 0) {
        return false; // operador @
    }

    throw new ErrorException($msg, 0, $no, $file, $line);
});

require __DIR__ . '/unit.php';
require __DIR__ . '/http.php';

T::cleanup();

foreach (T::$failures as $failure) {
    fwrite(STDERR, "FALHOU  $failure\n");
}

printf("%d passaram, %d falharam\n", T::$passed, count(T::$failures));
exit(T::$failures === [] ? 0 : 1);
