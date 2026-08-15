<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;

/**
 * Guards Session::write() against partial writes.
 *
 * fwrite() on a pty master routinely writes fewer bytes than you asked for:
 * the kernel buffer is small and the reader may be slow. Code that ignores the
 * return value drops the tail silently. When the cut lands in the middle of an
 * escape sequence, the terminal renders the remainder as literal text — a
 * stray `7G` on screen where a cursor move was intended.
 *
 * Observed 2026-08-14 while relaying Claude Code through a pty.
 *
 * Two traps these tests exist to avoid, both hit while writing them:
 *
 *  1. `stty -echo` alone is NOT enough. It silences the echo but leaves the
 *     line discipline in canonical mode, where the input queue holds at most
 *     MAX_CANON bytes while it waits for a newline. Push half a megabyte with
 *     no `\n` and the queue jams. You need `raw`, which clears ICANON.
 *
 *  2. Never write a large payload to a child that echoes it back without
 *     draining as you go. The master's output buffer fills, the child blocks
 *     writing, so it stops reading, so your write blocks. Textbook deadlock.
 */
it('writes large payloads completely', function (): void {
    // `raw` clears ICANON (no MAX_CANON jam) and `-echo` stops the line
    // discipline bouncing everything back. `cat > /dev/null` consumes without
    // producing, so there is nothing to drain and nothing to deadlock on.
    $session = Pty::spawn(['/bin/sh', '-c', 'stty raw -echo; exec cat > /dev/null']);

    usleep(400_000); // let stty apply before the first byte goes out

    $payload = str_repeat("abcdefghij\n", 46_000); // ~506 KB

    $written = $session->write($payload, timeout: 10.0);

    $session->kill();
    $session->wait();
    $session->close();

    expect($written)->toBe(
        strlen($payload),
        'Session::write() returned a short count, which means it stopped at a '
        . 'partial write instead of looping. Escape sequences would be truncated.'
    );
});

it('does not mangle escape sequences it writes', function (): void {
    // Deliberately small. A pty is not a lossless pipe: saturate an echoing
    // child and bytes go missing, which is a property of the kernel buffers
    // and the scheduler, not of this package. Testing that would be testing
    // the OS. What we promise is narrower and worth guarding: an escape
    // sequence handed to write() arrives byte-for-byte, and write() reports
    // the full length.
    $session = Pty::spawn(['/bin/sh', '-c', 'stty raw -echo; exec cat']);

    usleep(400_000);

    $needle = "\033[7GMARKER\033[0m";
    $payload = str_repeat('.', 512).$needle.str_repeat('.', 512);

    $written = $session->write($payload, timeout: 5.0);

    // Read until we have as many bytes as we sent — NOT until some marker
    // shows up. Stopping on a marker that sits in the middle of the needle
    // truncates the very thing being asserted.
    $received = '';
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline && strlen($received) < strlen($payload)) {
        $read = [$session->stream()];
        $write = [];
        $except = [];

        if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
            $received .= $session->read();
        }
    }

    $session->kill();
    $session->wait();
    $session->close();

    expect($written)->toBe(strlen($payload), 'write() reported a short count.');

    // Asserted in widening order so a failure localises itself: wrong length
    // means bytes were dropped, wrong ESC count means the line discipline ate
    // the escapes, and only then does the needle itself get checked.
    //
    // NOTE — do not pass a message to toContain(). It is variadic: every
    // argument is treated as another needle to look for, so a "message"
    // silently becomes a second assertion that can never pass. That mistake
    // cost an hour of chasing phantom kernel bugs on 2026-08-14.
    expect(strlen($received))->toBe(strlen($payload), 'Bytes were lost in the round trip.');
    expect(substr_count($received, "\033"))->toBe(2, 'Escape bytes were stripped.');
    expect(str_contains($received, $needle))->toBeTrue();
});
