<?php

declare(strict_types=1);

use Croustibat\Pty\Pty;
use Croustibat\Pty\Session;

/**
 * Reads from a session until $until matches, or the deadline passes.
 */
function drain(Session $session, float $seconds = 2.0, ?string $until = null): string
{
    $output = '';
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $read = [$session->stream()];
        $write = [];
        $except = [];

        if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
            $chunk = $session->read();

            if ($chunk === '' && feof($session->stream())) {
                break;
            }

            $output .= $chunk;
        }

        if ($until !== null && preg_match($until, $output) === 1) {
            break;
        }
    }

    return $output;
}

/**
 * Extracts the "<rows> <cols>" pair printed by `stty size`.
 *
 * @return array{0:int,1:int}|null
 */
function parseSttySize(string $output): ?array
{
    $normalised = preg_replace('/\s+/', ' ', $output) ?? $output;

    if (preg_match('/(\d+) (\d+)/', $normalised, $matches) !== 1) {
        return null;
    }

    return [(int) $matches[1], (int) $matches[2]];
}

function readySession(): Session
{
    $session = Pty::spawn(['/bin/sh', '-c', 'stty -echo; printf "READY\n"; exec cat']);

    try {
        expect(drain($session, 3.0, '/READY/'))->toContain('READY');
    } catch (Throwable $error) {
        stopSession($session);
        throw $error;
    }

    return $session;
}

function stopSession(Session $session): void
{
    if ($session->isRunning()) {
        $session->kill();
    }
    $session->wait(2.0);
    $session->close();
}

/** @return list<int> */
function openDescriptors(): array
{
    static $ffi;
    $ffi ??= FFI::cdef('int fcntl(int fd, int cmd, ...);');
    $open = [];

    // All descriptors allocated by these small test processes are below 1024.
    // F_GETFD is 1 on both supported platforms; this creates no descriptors.
    for ($fd = 0; $fd < 1024; $fd++) {
        if ($ffi->fcntl($fd, 1) !== -1) {
            $open[] = $fd;
        }
    }

    return $open;
}
