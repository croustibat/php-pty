<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

use function Croustibat\Pty\Examples\cleanup;
use function Croustibat\Pty\Examples\stty;
use function Croustibat\Pty\Examples\terminalSize;

require __DIR__.'/bootstrap.php';

$command = array_slice($argv ?? [], 1);
if ($command === ['--help']) {
    echo "Usage: php examples/interactive.php [-- command arg ...]\n";
    echo "Defaults to /bin/sh. Exit the child or press Ctrl-D to leave the shell.\n";
    exit(0);
}
if (($command[0] ?? null) === '--') {
    array_shift($command);
}
$command = $command === [] ? ['/bin/sh'] : $command;
if (! posix_isatty(STDIN) || ! posix_isatty(STDOUT)) {
    fwrite(STDERR, "Run this example directly in a terminal, with no pipe or redirection.\n");
    exit(1);
}

$session = null;
$savedMode = null;
$exitCode = 1;
$signals = new class
{
    public ?int $stop = null;

    public bool $resize = false;

    /** @phpstan-impure */
    public function interrupted(): bool
    {
        pcntl_signal_dispatch();

        return $this->resize || $this->stop !== null;
    }
};
$previousHandlers = [];
$previousAsync = pcntl_async_signals(true);
$stdinBlocking = stream_get_meta_data(STDIN)['blocked'];
$stdoutBlocking = stream_get_meta_data(STDOUT)['blocked'];

try {
    foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT, SIGWINCH] as $signal) {
        $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
        pcntl_signal($signal, function (int $received) use ($signals): void {
            if ($received === SIGWINCH) {
                $signals->resize = true;
            } else {
                $signals->stop = $received;
            }
        });
    }

    $savedMode = stty(['-g']);
    [$rows, $cols] = terminalSize();
    $session = Pty::spawn($command, rows: $rows, cols: $cols);
    stty(['raw', '-echo']);
    stream_set_blocking(STDIN, false);
    stream_set_blocking(STDOUT, false);
    stream_set_write_buffer(STDOUT, 0);

    $master = $session->stream();
    $input = $output = '';
    $outputClosed = false;
    $limit = 65536;

    while ($signals->stop === null) {
        if ($signals->resize) {
            $signals->resize = false;
            [$rows, $cols] = terminalSize();
            $session->resize($rows, $cols);
        }
        $running = $session->isRunning();
        if (! $running || $outputClosed) {
            $input = '';
        }
        if (! $running && $outputClosed && $output === '') {
            $exitCode = $session->wait(0.0);
            break;
        }

        // Apply backpressure in both directions, keeping each queue <= 64 KB.
        $read = $write = $except = [];
        if ($running && ! $outputClosed && strlen($input) < $limit) {
            $read[] = STDIN;
        }
        if (! $outputClosed && strlen($output) < $limit) {
            $read[] = $master;
        }
        if ($input !== '') {
            $write[] = $master;
        }
        if ($output !== '') {
            $write[] = STDOUT;
        }
        if ($read === [] && $write === []) {
            // Closing terminal descriptors does not imply process exit.
            usleep(10_000);

            continue;
        }
        $selected = @stream_select($read, $write, $except, 0, 100_000);
        if ($selected === false) {
            // Signals interrupt select. Process their flags on the next turn.
            if ($signals->interrupted()) {
                continue;
            }
            throw new RuntimeException('Cannot select the terminal streams.');
        }

        foreach ($read as $stream) {
            if ($stream === STDIN) {
                $chunk = fread(STDIN, min(8192, $limit - strlen($input)));
                if ($chunk === false || ($chunk === '' && feof(STDIN))) {
                    throw new RuntimeException('The local terminal input closed.');
                }
                $input .= $chunk;
            } else {
                $chunk = $session->read(min(8192, $limit - strlen($output)));
                $output .= $chunk;
                if ($chunk === '' && (feof($master) || ! $session->isRunning())) {
                    $outputClosed = true;
                }
            }
        }

        foreach ($write as $stream) {
            if ($stream === $master && ($outputClosed || ! $session->isRunning())) {
                $input = '';

                continue;
            }
            // A single non-blocking write, with its suffix retained. Calling
            // Session::write(timeout: 0) here would never write any bytes.
            $buffer = $stream === STDOUT ? $output : $input;
            $written = @fwrite($stream, substr($buffer, 0, 8192));
            if ($written === false) {
                throw new RuntimeException('A terminal write failed.');
            }
            if ($stream === STDOUT) {
                $output = substr($output, $written);
            } else {
                $input = substr($input, $written);
            }
        }
    }
    $exitCode = $signals->stop === null ? ($exitCode < 0 ? 1 : $exitCode) : 128 + $signals->stop;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $exitCode = 1;
} finally {
    stream_set_blocking(STDIN, $stdinBlocking);
    stream_set_blocking(STDOUT, $stdoutBlocking);
    try {
        if ($savedMode !== null) {
            stty([$savedMode]);
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $exitCode = 1;
    } finally {
        if ($session !== null) {
            cleanup($session);
        }
        foreach ($previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($previousAsync);
    }
}
if (isset($message)) {
    fwrite(STDERR, $message.PHP_EOL);
}
exit($exitCode);
