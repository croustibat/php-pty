<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

use function Croustibat\Pty\Examples\cleanup;

require __DIR__.'/bootstrap.php';

$session = null;
$exitCode = 1;
try {
    $session = Pty::spawn(['/bin/sh', '-c', 'stty size'], rows: 30, cols: 120);
    $deadline = hrtime(true) + 10_000_000_000;

    do {
        $read = [$session->stream()];
        $write = $except = [];
        if (stream_select($read, $write, $except, 0, 100_000) > 0) {
            echo $session->read();
        }
        // Reap only after draining; a child can wait for its output to be read.
        $running = $session->isRunning();
    } while ($running && hrtime(true) < $deadline);

    // Exiting and having no more output are separate events.
    while (($tail = $session->read()) !== '') {
        echo $tail;
    }
    $exitCode = $session->wait(0.0);
    if ($exitCode < 0) {
        throw new RuntimeException('The example did not finish within ten seconds.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if ($session !== null) {
        cleanup($session);
    }
}
exit($exitCode);
