<?php

declare(strict_types=1);

pcntl_async_signals(true);
$resized = false;
pcntl_signal(SIGWINCH, function () use (&$resized): void {
    $resized = true;
});
fwrite(STDOUT, "READY\n");

$deadline = hrtime(true) + 10_000_000_000;
while (! $resized && hrtime(true) < $deadline) {
    usleep(1_000);
}

if (! $resized) {
    exit(1);
}
fwrite(STDOUT, "GOT-WINCH\n");
