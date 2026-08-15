<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;
use Croustibat\Pty\PtyException;

it('gives the child a real tty', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'tty']);

    $output = drain($session, 2.0, '#/dev/#');
    $session->wait();
    $session->close();

    expect($output)->toContain('/dev/');
});

it('reports the slave device path', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 1']);

    expect($session->ttyName())->toStartWith('/dev/');

    $session->kill();
    $session->wait();
    $session->close();
});

it('gives the child a controlling terminal', function (): void {
    // Without login_tty() the child would have a tty but no *controlling*
    // tty. Opening /dev/tty is the canonical way to prove it has one.
    $session = Pty::spawn(['/bin/sh', '-c', 'echo probe > /dev/tty']);

    $output = drain($session, 2.0, '/probe/');
    $session->wait();
    $session->close();

    expect($output)->toContain('probe');
});

it('delivers SIGWINCH on resize', function (): void {
    $session = Pty::spawn(
        ['/bin/sh', '-c', 'trap "echo GOT-WINCH" WINCH; sleep 2 & wait'],
        rows: 24,
        cols: 80,
    );

    usleep(300_000);
    $session->resize(40, 100);

    $output = drain($session, 2.0, '/GOT-WINCH/');
    $session->kill();
    $session->wait();
    $session->close();

    expect($output)->toContain('GOT-WINCH');
});

it('round-trips data through the pty', function (): void {
    $session = Pty::spawn(['/bin/cat']);

    usleep(200_000);
    $session->write("hello pty\n");

    $output = drain($session, 2.0, '/hello pty/');
    $session->kill();
    $session->wait();
    $session->close();

    expect($output)->toContain('hello pty');
});

it('reports the exit code', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'exit 42']);

    expect($session->wait())->toBe(42);
    expect($session->exitCode())->toBe(42);
    expect($session->isRunning())->toBeFalse();

    $session->close();
});

it('reports 128 + signal when killed', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 5']);

    usleep(150_000);
    $session->kill();

    expect($session->wait())->toBe(128 + SIGKILL);

    $session->close();
});

it('tracks liveness without blocking', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 5']);

    usleep(150_000);
    expect($session->isRunning())->toBeTrue();

    $session->kill();
    usleep(200_000);

    expect($session->isRunning())->toBeFalse();

    $session->close();
});

it('rejects an unknown executable', function (): void {
    Pty::spawn(['definitely-not-a-real-binary-xyz']);
})->throws(PtyException::class);

it('throws once closed', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 1']);
    $session->kill();
    $session->wait();
    $session->close();

    $session->resize(20, 60);
})->throws(PtyException::class);
