<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;
use Croustibat\Pty\Session;

/** @param list<string> $command */
function interactiveExample(array $command, int $rows = 32, int $cols = 104): Session
{
    return Pty::spawn([
        '/bin/sh', __DIR__.'/Fixtures/run-interactive.sh',
        PHP_BINARY, __DIR__.'/../examples/interactive.php', '--', ...$command,
    ], rows: $rows, cols: $cols);
}

it('runs the quickstart and reports the requested geometry', function (): void {
    $session = Pty::spawn([PHP_BINARY, __DIR__.'/../examples/quickstart.php']);
    try {
        expect(drain($session, 10.0, '/30\s+120/'))->toMatch('/30\s+120/');
        expect($session->wait(2.0))->toBe(0);
    } finally {
        stopSession($session);
    }
});

it('runs multiple sessions to completion with all output labelled once', function (): void {
    $session = Pty::spawn([PHP_BINARY, __DIR__.'/../examples/multiple-sessions.php']);
    try {
        $output = drain($session, 10.0, '/\[slow\] exited 0/');
        expect($session->wait(2.0))->toBe(0);
        foreach (['fast', 'slow'] as $name) {
            foreach (range(1, 3) as $tick) {
                expect(substr_count($output, "[{$name}] tick {$tick}"))->toBe(1);
            }
            expect(substr_count($output, "[{$name}] exited 0"))->toBe(1);
        }
    } finally {
        stopSession($session);
    }
});

it('relays input and initial geometry, preserves exit codes and restores the terminal', function (): void {
    $session = interactiveExample([
        '/bin/sh', '-c', 'stty -echo; stty size; echo READY; IFS= read -r line; printf "REPLY:%s\n" "$line"; exit 42',
    ]);
    try {
        $start = drain($session, 10.0, '/READY/');
        expect($start)->toMatch('/32\s+104/');
        $session->write("spaces and ; literal\n");
        $output = drain($session, 10.0, '/TERMINAL-RESTORED/');
        expect($output)->toContain('REPLY:spaces and ; literal', 'TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(42);
    } finally {
        stopSession($session);
    }
});

it('forwards local terminal resizes to the child', function (): void {
    $session = interactiveExample([PHP_BINARY, __DIR__.'/Fixtures/watch-resize.php']);
    try {
        expect(drain($session, 10.0, '/READY/'))->toContain('READY');
        $session->resize(45, 132);
        $output = drain($session, 10.0, '/TERMINAL-RESTORED/');
        expect($output)->toContain('GOT-WINCH', 'TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(0);
    } finally {
        stopSession($session);
    }
});

it('forwards Ctrl-C as terminal input to the foreground child', function (): void {
    $session = interactiveExample([PHP_BINARY, __DIR__.'/Fixtures/relay-child.php', 'signal']);
    try {
        expect(drain($session, 10.0, '/READY/'))->toContain('READY');
        $session->write("\x03");
        $output = drain($session, 10.0, '/TERMINAL-RESTORED/');
        expect($output)->toContain('INTERRUPTED', 'TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(42);
    } finally {
        stopSession($session);
    }
});

it('drains a large final output through a slow outer terminal without corruption', function (): void {
    $session = interactiveExample([PHP_BINARY, __DIR__.'/Fixtures/relay-child.php']);
    try {
        expect(drain($session, 10.0, '/READY/'))->toContain('READY');
        $session->write('x');
        // Let both the relay output queue and the outer PTY buffer fill.
        usleep(150_000);
        $output = drain($session, 10.0, '/TERMINAL-RESTORED/');
        expect($session->wait(2.0))->toBe(0);
        expect(preg_match('/BEGIN\n(.*)END\n/s', $output, $matches))->toBe(1);
        $expected = str_repeat("a\033[7Gb\n", 80_000);
        expect(strlen($matches[1]))->toBe(strlen($expected));
        expect(hash('sha256', $matches[1]))->toBe(hash('sha256', $expected));
        expect($output)->toContain('TERMINAL-RESTORED');
    } finally {
        stopSession($session);
    }
});

it('restores the terminal when the relay receives SIGTERM', function (): void {
    $session = interactiveExample([PHP_BINARY, __DIR__.'/Fixtures/relay-child.php', 'signal']);
    try {
        $start = drain($session, 10.0, '/READY/');
        expect(preg_match('/RELAY:(\d+)/', $start, $matches))->toBe(1);
        expect(posix_kill((int) $matches[1], SIGTERM))->toBeTrue();
        expect(drain($session, 10.0, '/TERMINAL-RESTORED/'))->toContain('TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(128 + SIGTERM);
    } finally {
        stopSession($session);
    }
});

it('reports startup errors without leaving the terminal in raw mode', function (): void {
    $session = interactiveExample(['/php-pty-no-such-executable']);
    try {
        $output = drain($session, 10.0, '/TERMINAL-RESTORED/');
        expect($output)->toContain('Executable', 'TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(1);
    } finally {
        stopSession($session);
    }
});

it('explains why interactive mode cannot be run with redirected streams', function (): void {
    $process = proc_open([PHP_BINARY, __DIR__.'/../examples/interactive.php'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(1);
    expect($error)->toContain('directly in a terminal');
});

it('waits for the child after it closes its terminal descriptors', function (): void {
    $session = interactiveExample([
        PHP_BINARY, '-r', 'echo "READY\n"; fclose(STDIN); fclose(STDOUT); fclose(STDERR); usleep(600000); exit(42);',
    ]);
    try {
        expect(drain($session, 10.0, '/TERMINAL-RESTORED/'))->toContain('READY', 'TERMINAL-RESTORED');
        expect($session->wait(2.0))->toBe(42);
    } finally {
        stopSession($session);
    }
});
