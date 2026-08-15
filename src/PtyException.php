<?php

declare(strict_types=1);

namespace Croustibat\Pty;

use RuntimeException;

final class PtyException extends RuntimeException
{
    public static function missingExtension(string $name): self
    {
        return new self(
            "The `{$name}` extension is required by croustibat/pty but is not loaded. "
            . 'Check `php -m`; with Herd or Homebrew you may need to enable it in php.ini.'
        );
    }

    public static function ffiDisabled(): self
    {
        return new self(
            'FFI is not usable in this SAPI. croustibat/pty only supports the CLI SAPI, '
            . 'where `ffi.enable` defaults to true. Under FPM you would need `ffi.enable=preload`, '
            . 'which this library does not support — see the README section on fork safety.'
        );
    }

    public static function unsupportedPlatform(string $os): self
    {
        return new self(
            "croustibat/pty supports macOS and Linux; this platform reports `{$os}`. "
            . 'Windows has no PTY: it exposes ConPTY, an unrelated API, and is explicitly out of scope.'
        );
    }

    public static function bindingFailed(): self
    {
        return new self(
            'Could not bind openpty/login_tty through FFI. '
            . 'On Linux these live in libutil (install libutil / glibc dev headers are not needed, '
            . 'but `libutil.so.1` must be present).'
        );
    }

    public static function openptyFailed(): self
    {
        return new self('openpty() failed — no pseudo-terminal could be allocated.');
    }

    public static function forkFailed(): self
    {
        return new self('pcntl_fork() failed — could not fork the current process.');
    }

    public static function executableNotFound(string $binary): self
    {
        return new self("Executable `{$binary}` not found in PATH.");
    }

    public static function masterUnreadable(int $fd): self
    {
        return new self("php://fd/{$fd} could not be opened — the pty master is unusable.");
    }

    public static function resizeFailed(int $rows, int $cols): self
    {
        return new self("ioctl(TIOCSWINSZ) failed while resizing to {$rows}x{$cols}.");
    }

    public static function alreadyClosed(): self
    {
        return new self('This session has already been closed.');
    }
}
