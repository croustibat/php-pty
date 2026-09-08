<?php

declare(strict_types=1);

$process = proc_open(['stty', 'raw', '-echo'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (! is_resource($process) || proc_close($process) !== 0) {
    exit(1);
}

pcntl_async_signals(true);
pcntl_signal(SIGINT, function (): void {
    fwrite(STDOUT, "INTERRUPTED\n");
    exit(42);
});

if (($argv[1] ?? '') === 'signal') {
    // This mode retains ISIG so the literal Ctrl-C byte reaches SIGINT.
    $process = proc_open(['stty', 'isig'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0) {
        exit(1);
    }
    echo 'RELAY:'.posix_getppid()."\nREADY\n";
    while (true) {
        usleep(10_000);
    }
}

$payload = str_repeat("a\033[7Gb\n", 80_000);
fwrite(STDOUT, "READY\n");
fread(STDIN, 1);
fwrite(STDOUT, "BEGIN\n");
$offset = 0;
while ($offset < strlen($payload)) {
    $written = fwrite(STDOUT, substr($payload, $offset, 8192));
    if ($written === false) {
        exit(1);
    }
    $offset += $written;
}
fwrite(STDOUT, "END\n");
