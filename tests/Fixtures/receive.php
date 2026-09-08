<?php

declare(strict_types=1);

// The launching shell has already applied raw mode with echo disabled.
$length = (int) $argv[1];
$ready = ($argv[2] ?? '') !== 'wait';
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, function () use (&$ready): void {
    $ready = true;
});
fwrite(STDOUT, "READY\n");

while (! $ready) {
    usleep(1_000);
}

$received = 0;
$hash = hash_init('sha256');
while ($received < $length) {
    $chunk = fread(STDIN, min(65536, $length - $received));
    if ($chunk === false || $chunk === '') {
        exit(1);
    }
    $received += strlen($chunk);
    hash_update($hash, $chunk);
}

printf("RECEIVED:%d:%s\n", $received, hash_final($hash));
