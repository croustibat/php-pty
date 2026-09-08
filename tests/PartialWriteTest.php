<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

it('delivers a large payload completely to the child', function (): void {
    $payload = str_repeat("abcdefghij\n", 46_000);
    $session = Pty::spawn([
        '/bin/sh', '-c', 'stty raw -echo; exec "$@"', 'sh',
        PHP_BINARY, __DIR__.'/Fixtures/receive.php', (string) strlen($payload),
    ]);

    try {
        expect(drain($session, 3.0, '/READY/'))->toContain('READY');
        expect($session->write($payload, timeout: 10.0))->toBe(strlen($payload));
        $receipt = 'RECEIVED:'.strlen($payload).':'.hash('sha256', $payload);
        expect(drain($session, 3.0, '/RECEIVED:[0-9]+:[a-f0-9]{64}/'))->toContain($receipt);
        expect($session->wait(2.0))->toBe(0);
    } finally {
        stopSession($session);
    }
});

it('bounds a saturated write and can resume without dropping or duplicating bytes', function (): void {
    $payload = str_repeat("\033[7Gabcdefghij\n", 150_000);
    $session = Pty::spawn([
        '/bin/sh', '-c', 'stty raw -echo; exec "$@"', 'sh',
        PHP_BINARY, __DIR__.'/Fixtures/receive.php', (string) strlen($payload), 'wait',
    ]);

    try {
        expect(drain($session, 3.0, '/READY/'))->toContain('READY');
        $start = hrtime(true);
        $written = $session->write($payload, timeout: 0.05);
        $elapsed = (hrtime(true) - $start) / 1e9;

        expect($written)->toBeGreaterThan(0)->toBeLessThan(strlen($payload));
        expect($elapsed)->toBeLessThan(1.0);
        expect($session->signal(SIGUSR1))->toBeTrue();
        expect($session->write(substr($payload, $written), timeout: 10.0))->toBe(strlen($payload) - $written);

        $receipt = 'RECEIVED:'.strlen($payload).':'.hash('sha256', $payload);
        expect(drain($session, 3.0, '/RECEIVED:[0-9]+:[a-f0-9]{64}/'))->toContain($receipt);
        expect($session->wait(2.0))->toBe(0);
    } finally {
        stopSession($session);
    }
});

it('does not mangle escape sequences it writes', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'stty raw -echo; printf "READY\n"; exec cat']);

    try {
        expect(drain($session, 3.0, '/READY/'))->toContain('READY');
        $payload = str_repeat('.', 512)."\033[7GMARKER\033[0m".str_repeat('.', 512);
        expect($session->write($payload, timeout: 5.0))->toBe(strlen($payload));

        $received = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && strlen($received) < strlen($payload)) {
            $read = [$session->stream()];
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, 0, 50_000) > 0) {
                $received .= $session->read();
            }
        }
        expect($received)->toBe($payload);
    } finally {
        stopSession($session);
    }
});
