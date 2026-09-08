<?php

declare(strict_types=1);

namespace Croustibat\Pty\Examples;

use Croustibat\Pty\Session;
use RuntimeException;

// Support both a repository checkout and vendor/croustibat/php-pty/examples.
$autoload = __DIR__.'/../vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname(__DIR__, 3).'/autoload.php';
}
if (! is_file($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run composer install in the checkout first.\n");
    exit(1);
}
require_once $autoload;

/** Release the PTY and reap the child, even when it ignores the hangup. */
function cleanup(Session $session): void
{
    $session->close();
    if ($session->wait(0.2) === -1 && $session->isRunning()) {
        $session->terminate();
        if ($session->wait(0.2) === -1 && $session->isRunning()) {
            $session->kill();
            $session->wait(2.0);
        }
    }
}

/** @param list<string> $arguments */
function stty(array $arguments): string
{
    $process = proc_open(['stty', ...$arguments], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start stty. Install it to run the interactive example.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if ($status !== 0 || $output === false) {
        throw new RuntimeException('stty failed. Run this example directly in a terminal.');
    }

    return trim($output);
}

/** @return array{int, int} */
function terminalSize(): array
{
    if (preg_match('/^(\d+)\s+(\d+)$/', stty(['size']), $size) !== 1) {
        throw new RuntimeException('Cannot read the local terminal size.');
    }

    return [max(1, (int) $size[1]), max(1, (int) $size[2])];
}
