<?php

declare(strict_types=1);

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
