<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;
use Croustibat\Pty\PtyException;

it('closes both master descriptors and delivers SIGHUP', function (): void {
    $before = openDescriptors();
    $session = readySession();

    try {
        $session->close();
        expect($session->wait(2.0))->toBe(128 + SIGHUP);
        expect(openDescriptors())->toBe($before);
    } finally {
        stopSession($session);
    }
});

it('does not leak descriptors when executable resolution fails', function (): void {
    $before = openDescriptors();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => Pty::spawn(['php-pty-nonexistent-executable']))->toThrow(PtyException::class);
    }

    expect(openDescriptors())->toBe($before);
});

it('does not leak descriptors across repeated successful sessions', function (): void {
    $before = openDescriptors();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $session = Pty::spawn(['/bin/sh', '-c', 'exit 42']);
        try {
            expect($session->wait(2.0))->toBe(42);
        } finally {
            stopSession($session);
        }
    }

    expect(openDescriptors())->toBe($before);
});

it('closes every inherited session in subsequent children', function (): void {
    $sessions = [];
    try {
        for ($i = 0; $i < 3; $i++) {
            $sessions[] = readySession();
        }

        foreach (array_slice($sessions, 0, 2) as $session) {
            $session->close();
            expect($session->wait(2.0))->toBe(128 + SIGHUP);
        }

        $sessions[2]->write("still-alive\n");
        expect(drain($sessions[2], 2.0, '/still-alive/'))->toContain('still-alive');
    } finally {
        foreach ($sessions as $session) {
            stopSession($session);
        }
    }
});

it('does not keep abandoned sessions alive in the registry', function (): void {
    $session = readySession();
    $pid = $session->pid();
    $reference = WeakReference::create($session);
    unset($session);

    try {
        expect($reference->get())->toBeNull();
        $deadline = microtime(true) + 2.0;
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result !== 0) {
                break;
            }
            usleep(2_000);
        } while (microtime(true) < $deadline);
        expect($result)->toBe($pid);
        expect(pcntl_wifsignaled($status))->toBeTrue();
        expect(pcntl_wtermsig($status))->toBe(SIGHUP);
    } finally {
        if ($result === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
});

it('does not close reused descriptors on repeated close or destruction', function (): void {
    $first = readySession();
    $second = null;
    try {
        $first->close();
        expect($first->wait(2.0))->toBe(128 + SIGHUP);
        $second = readySession();
        $first->close();
        unset($first);

        $second->resize(30, 120);
        expect($second->winSize())->toBe([30, 120]);
        $second->write("second-session\n");
        expect(drain($second, 2.0, '/second-session/'))->toContain('second-session');
    } finally {
        if (isset($first)) {
            stopSession($first);
        }
        if ($second !== null) {
            stopSession($second);
        }
    }
});

it('releases the raw master even when the caller already closed the stream', function (): void {
    $before = openDescriptors();
    $session = readySession();
    try {
        fclose($session->stream());
        expect(fn () => $session->stream())->toThrow(PtyException::class);
        $session->close();
        expect($session->wait(2.0))->toBe(128 + SIGHUP);
        expect(openDescriptors())->toBe($before);
    } finally {
        stopSession($session);
    }
});

it('keeps an unavailable child status unknown', function (?float $timeout, bool $checkRunning): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'exit 42']);
    try {
        expect(pcntl_waitpid($session->pid(), $status))->toBe($session->pid());
        expect(pcntl_wexitstatus($status))->toBe(42);
        if ($checkRunning) {
            expect($session->isRunning())->toBeFalse();
        }
        expect($session->wait($timeout))->toBe(-1);
        expect($session->wait())->toBe(-1);
        expect($session->exitCode())->toBeNull();
        expect($session->signal(0))->toBeFalse();
    } finally {
        stopSession($session);
    }
})->with([[null, false], [1.0, false], [null, true], [1.0, true]]);

it('can wait again after a timeout without losing the exit status', function (): void {
    $session = readySession();
    try {
        $start = hrtime(true);
        expect($session->wait(0.05))->toBe(-1);
        expect((hrtime(true) - $start) / 1e9)->toBeLessThan(1.0);
        expect($session->exitCode())->toBeNull();
        expect($session->isRunning())->toBeTrue();
        $session->kill();
        expect($session->wait(2.0))->toBe(128 + SIGKILL);
        expect($session->wait(null))->toBe(128 + SIGKILL);
        expect($session->isRunning())->toBeFalse();
    } finally {
        stopSession($session);
    }
});

it('retries an interrupted blocking wait without inventing an exit status', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'stty -echo; echo READY; read -r line; exit 42']);
    $previous = pcntl_signal_get_handler(SIGUSR1);
    $async = pcntl_async_signals(true);
    $sender = null;
    try {
        expect(drain($session, 3.0, '/READY/'))->toContain('READY');
        $interrupted = false;
        pcntl_signal(SIGUSR1, function () use (&$interrupted, $session): void {
            $interrupted = true;
            $session->write("exit\n");
        }, restart_syscalls: false);

        // The target cannot exit until our signal handler releases it. The
        // short delay only arranges interruption, not child readiness.
        $sender = Pty::spawn([
            PHP_BINARY, '-r', 'usleep(100000); posix_kill((int) $argv[1], SIGUSR1);', (string) getmypid(),
        ]);
        expect($session->wait(null))->toBe(42);
        expect($interrupted)->toBeTrue();
        expect($session->exitCode())->toBe(42);
    } finally {
        if ($sender !== null) {
            stopSession($sender);
        }
        stopSession($session);
        pcntl_signal(SIGUSR1, $previous);
        pcntl_async_signals($async);
    }
});

it('makes a non-blocking status check with a zero timeout', function (): void {
    $session = readySession();
    try {
        expect($session->wait(0.0))->toBe(-1);
        expect($session->write('not-sent', timeout: 0.0))->toBe(0);
        expect($session->isRunning())->toBeTrue();
        $session->kill();
        $deadline = microtime(true) + 2.0;
        do {
            $exit = $session->wait(0.0);
            if ($exit !== -1) {
                break;
            }
            usleep(2_000);
        } while (microtime(true) < $deadline);
        expect($exit)->toBe(128 + SIGKILL);
    } finally {
        stopSession($session);
    }
});

it('rejects invalid working directories before launching', function (): void {
    $before = openDescriptors();
    expect(fn () => Pty::spawn(['/bin/pwd'], cwd: __DIR__.'/nonexistent-directory'))
        ->toThrow(PtyException::class, 'Working directory');
    expect(openDescriptors())->toBe($before);
});

it('validates explicit executable paths', function (): void {
    $before = openDescriptors();
    expect(fn () => Pty::spawn(['/php-pty-no-such-binary']))->toThrow(PtyException::class);
    expect(openDescriptors())->toBe($before);
});

it('preserves the invoked executable symlink', function (): void {
    $link = tempnam(sys_get_temp_dir(), 'php-pty-shell-');
    unlink($link);
    symlink('/bin/sh', $link);
    $session = null;
    try {
        $session = Pty::spawn([$link, '-c', 'printf "%s" "$0"']);
        expect(drain($session))->toBe($link);
        expect($session->wait(2.0))->toBe(0);
    } finally {
        if ($session !== null) {
            stopSession($session);
        }
        unlink($link);
    }
});

it('resolves relative PATH entries against the child directory', function (): void {
    $path = getenv('PATH');
    $session = null;
    try {
        putenv('PATH=.');
        $session = Pty::spawn(['sh', '-c', 'printf "%s" "$0"'], cwd: '/bin');
        expect(drain($session))->toBe('/bin/./sh');
        expect($session->wait(2.0))->toBe(0);
    } finally {
        if ($session !== null) {
            stopSession($session);
        }
        putenv($path === false ? 'PATH' : 'PATH='.$path);
    }
});

it('does not run inherited PHP shutdown code when exec fails', function (): void {
    $executable = tempnam(sys_get_temp_dir(), 'php-pty-invalid-');
    file_put_contents($executable, "#!/php-pty-no-such-interpreter\n");
    chmod($executable, 0700);
    $session = null;
    try {
        $session = Pty::spawn([PHP_BINARY, __DIR__.'/Fixtures/failed-exec.php', $executable]);
        $output = drain($session, 3.0);
        expect($session->wait(2.0))->toBe(0);
        expect($output)->toContain('EXIT:127');
        // Only the outer PHP process may run its own shutdown callback.
        expect(substr_count($output, 'SHUTDOWN'))->toBe(1);
        expect($output)->not->toContain('Fatal error');
    } finally {
        if ($session !== null) {
            stopSession($session);
        }
        unlink($executable);
    }
});

it('honours the child working directory and literal arguments', function (): void {
    $session = Pty::spawn(
        ['./sh', '-c', 'printf "%s|%s|%s" "$PWD" "$1" "$PTY_TEST"', 'sh', 'a b;$(literal)'],
        cwd: '/bin',
        env: ['PTY_TEST' => 'environment-ok'],
    );
    try {
        expect(drain($session))->toBe(realpath('/bin').'|a b;$(literal)|environment-ok');
        expect($session->wait(2.0))->toBe(0);
    } finally {
        stopSession($session);
    }
});

it('rejects invalid dimensions without allocating or changing a terminal', function (int $rows, int $cols): void {
    $before = openDescriptors();
    expect(fn () => Pty::spawn(['/bin/cat'], rows: $rows, cols: $cols))->toThrow(PtyException::class);
    expect(openDescriptors())->toBe($before);

    $session = readySession();
    try {
        expect(fn () => $session->resize($rows, $cols))->toThrow(PtyException::class);
        expect($session->winSize())->toBe([24, 80]);
    } finally {
        stopSession($session);
    }
})->with([[-1, 80], [24, -1], [0, 80], [24, 0], [65536, 80], [24, 65536]]);

it('rejects invalid timeouts', function (float $timeout): void {
    $session = readySession();
    try {
        expect(fn () => $session->wait($timeout))->toThrow(PtyException::class);
        expect(fn () => $session->write('data', $timeout))->toThrow(PtyException::class);
    } finally {
        stopSession($session);
    }
})->with([-1.0, INF, NAN]);
