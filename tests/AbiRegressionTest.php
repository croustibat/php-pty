<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

/**
 * The most important test in this package.
 *
 * `ioctl` is variadic in C. On Darwin arm64 the ABI diverges from standard
 * AAPCS64: every variadic argument travels on the stack while fixed arguments
 * travel in registers. If someone ever "tidies up" the FFI declaration in
 * Pty::CDEF from
 *
 *     int ioctl(int fd, unsigned long request, ...);
 *
 * back to the fixed-arity form that circulates all over the web
 *
 *     int ioctl(int fd, unsigned long request, void *arg);
 *
 * then libffi puts the pointer in a register, the kernel reads it off the
 * stack, and TIOCSWINSZ writes whatever garbage lived at that address.
 *
 * The reason this needs a test rather than a comment: **ioctl still returns
 * 0**. There is no errno, no exception, nothing to catch. The only visible
 * symptom is a wrong window size — measured as "0 2046" instead of "30 120"
 * on PHP 8.5.8 / Darwin / arm64, 2026-08-14.
 *
 * On Linux x86-64 both ABIs coincide for integers and pointers, so this test
 * passes either way there. It only bites on Apple Silicon — which is exactly
 * why CI must run on macos-latest and not only ubuntu-latest.
 */
it('resizes through ioctl and the child agrees', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 0.6; stty size'], rows: 24, cols: 80);

    usleep(150_000);
    $session->resize(30, 120);

    $output = drain($session, 3.0, '/\d+\s+\d+/');
    $session->wait();
    $session->close();

    $size = parseSttySize($output);

    expect($size)->not->toBeNull(
        "The child printed nothing usable. Raw output: ".var_export($output, true)
    );

    expect($size)->toBe(
        [30, 120],
        'Window size is wrong after ioctl(TIOCSWINSZ). If this reads something '
        . 'like [0, 2046], the ioctl declaration in Pty::CDEF has lost its `...` '
        . 'and is being called with the wrong ABI.'
    );
});

it('reads the window size back through ioctl', function (): void {
    $session = Pty::spawn(['/bin/sh', '-c', 'sleep 1'], rows: 24, cols: 80);

    expect($session->winSize())->toBe([24, 80]);

    $session->resize(45, 132);

    expect($session->winSize())->toBe([45, 132]);

    $session->kill();
    $session->wait();
    $session->close();
});

it('honours the initial size passed to openpty', function (): void {
    // openpty() is NOT variadic, so this path is immune to the ABI trap.
    // If this passes while the resize test fails, the struct layout is fine
    // and the problem is purely the calling convention.
    $session = Pty::spawn(['/bin/sh', '-c', 'stty size'], rows: 40, cols: 100);

    $output = drain($session, 2.0, '/\d+\s+\d+/');
    $session->wait();
    $session->close();

    expect(parseSttySize($output))->toBe([40, 100]);
});
