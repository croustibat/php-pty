<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

use function Croustibat\Pty\Examples\cleanup;

require __DIR__.'/bootstrap.php';

// Each command owns its terminal. One PHP loop reads both without threads.
$commands = [
    'fast' => [PHP_BINARY, '-r', 'for ($i = 1; $i <= 3; $i++) { echo "tick $i\n"; usleep(50000); }'],
    'slow' => [PHP_BINARY, '-r', 'for ($i = 1; $i <= 3; $i++) { echo "tick $i\n"; usleep(150000); }'],
];
$sessions = $buffers = $eof = [];
$exitCode = 0;

// These fixed commands emit short lines. Flush any final line at process exit.
$emit = function (string $name, string $chunk, bool $final = false) use (&$buffers): void {
    $buffers[$name] .= $chunk;
    while (($newline = strpos($buffers[$name], "\n")) !== false) {
        echo '['.$name.'] '.rtrim(substr($buffers[$name], 0, $newline), "\r").PHP_EOL;
        $buffers[$name] = substr($buffers[$name], $newline + 1);
    }
    if ($final && $buffers[$name] !== '') {
        echo '['.$name.'] '.$buffers[$name].PHP_EOL;
        $buffers[$name] = '';
    }
};

try {
    foreach ($commands as $name => $command) {
        $sessions[$name] = Pty::spawn($command);
        $buffers[$name] = '';
        $eof[$name] = false;
    }
    $deadline = hrtime(true) + 10_000_000_000;
    while ($sessions !== []) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('The examples did not finish within ten seconds.');
        }
        $read = [];
        foreach ($sessions as $name => $session) {
            if (! $eof[$name]) {
                $read[$name] = $session->stream();
            }
        }
        $write = $except = [];
        if ($read !== []) {
            if (stream_select($read, $write, $except, 0, 100_000) === false) {
                throw new RuntimeException('Cannot select the terminal streams.');
            }
            foreach ($read as $name => $stream) {
                $emit($name, $sessions[$name]->read());
                $eof[$name] = feof($stream);
            }
        } else {
            // EOF streams are always readable; retire them while awaiting exit.
            usleep(10_000);
        }

        foreach ($sessions as $name => $session) {
            if (! $session->isRunning()) {
                while (($tail = $session->read()) !== '') {
                    $emit($name, $tail);
                }
                $emit($name, '', final: true);
                $status = $session->wait(0.0);
                echo "[{$name}] exited {$status}".PHP_EOL;
                if ($status !== 0) {
                    $exitCode = 1;
                }
                $session->close();
                unset($sessions[$name]);
            }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ($sessions as $session) {
        cleanup($session);
    }
}
exit($exitCode);
