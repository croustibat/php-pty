<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Croustibat\Pty\Pty;

register_shutdown_function(function (): void {
    echo "SHUTDOWN\n";
});
set_error_handler(function (int $severity, string $message): never {
    throw new RuntimeException($message);
});

$session = Pty::spawn([$argv[1]]);
// Only the forked child should turn exec warnings into exceptions. Linux
// can report EIO while the parent drains a PTY whose slave has closed.
restore_error_handler();
try {
    $exit = $session->wait(2.0);
    echo 'CHILD-OUTPUT:'.$session->read()."\n";
    echo 'EXIT:'.$exit."\n";
} finally {
    if ($session->isRunning()) {
        $session->kill();
        $session->wait(2.0);
    }
    $session->close();
}
