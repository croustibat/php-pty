<?php

declare(strict_types=1);

namespace Croustibat\Pty;

use FFI;
use Throwable;

/**
 * Opens pseudo-terminals and spawns processes attached to them.
 *
 * Everything here is pure PHP: FFI binds the libc calls, pcntl does the fork.
 * There is no extension to compile and no companion C library.
 */
final class Pty
{
    private const IS_DARWIN = PHP_OS_FAMILY === 'Darwin';

    /**
     * TIOCSWINSZ / TIOCGWINSZ are _IOW/_IOR-encoded and therefore differ per
     * platform. They cannot be derived at runtime — they are baked into the
     * kernel headers — so they are hardcoded and covered by CI on both OSes.
     */
    private const TIOCSWINSZ = self::IS_DARWIN ? 0x80087467 : 0x5414;

    private const TIOCGWINSZ = self::IS_DARWIN ? 0x40087468 : 0x5413;

    /**
     * NOTE — `ioctl` MUST be declared variadic.
     *
     * Its real C signature is `int ioctl(int, unsigned long, ...)`. On Darwin
     * arm64 the ABI diverges from standard AAPCS64: *every* variadic argument
     * is passed on the stack, while fixed arguments go in registers. Declaring
     * ioctl with fixed arity makes libffi place the pointer in a register,
     * while the kernel reads it off the stack. It then dereferences whatever
     * address happened to be there.
     *
     * The failure mode is the dangerous kind: ioctl returns 0. No errno, no
     * exception, just a silently corrupted winsize.
     *
     * Measured 2026-08-14, PHP 8.5.8 / Darwin / arm64, asking for 30x120:
     *
     *   openpty(winp)                        -> "30 120"  correct
     *   ioctl declared (int, ulong, void*)   -> "0 2046"  garbage, rc = 0
     *   ioctl declared (int, ulong, ...)     -> "30 120"  correct
     *
     * On Linux x86-64 both ABIs coincide for integers and pointers, which is
     * why the fixed-arity form is all over the web and nobody notices it is
     * broken on Apple Silicon. `tests/AbiRegressionTest.php` guards this.
     */
    private const CDEF = <<<'C'
        struct winsize {
            unsigned short ws_row;
            unsigned short ws_col;
            unsigned short ws_xpixel;
            unsigned short ws_ypixel;
        };
        int openpty(int *amaster, int *aslave, char *name, void *termp, struct winsize *winp);
        int login_tty(int fd);
        int ioctl(int fd, unsigned long request, ...);
        int close(int fd);
        C;

    private static ?FFI $ffi = null;

    /**
     * Spawns a command attached to a fresh pseudo-terminal.
     *
     * @param  list<string>          $command Argv. The first element is resolved against PATH.
     * @param  array<string,string>|null $env  Child environment. Null inherits the current one.
     */
    public static function spawn(
        array $command,
        int $rows = 24,
        int $cols = 80,
        ?array $env = null,
        ?string $cwd = null,
    ): Session {
        if ($command === []) {
            throw PtyException::executableNotFound('');
        }

        $ffi = self::ffi();

        // The initial size is passed straight to openpty(), which is NOT
        // variadic — so it is immune to the ABI trap documented above, and
        // the child sees the right geometry from its very first draw.
        $winsize = $ffi->new('struct winsize');
        $winsize->ws_row = $rows;
        $winsize->ws_col = $cols;

        $master = $ffi->new('int');
        $slave = $ffi->new('int');
        $name = $ffi->new('char[128]');

        $rc = $ffi->openpty(
            FFI::addr($master),
            FFI::addr($slave),
            $name,
            null,
            FFI::addr($winsize),
        );

        if ($rc !== 0) {
            throw PtyException::openptyFailed();
        }

        $masterFd = $master->cdata;
        $slaveFd = $slave->cdata;
        $ttyName = FFI::string($name);

        $executable = self::resolve($command[0]);
        $environment = $env ?? self::currentEnvironment();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $ffi->close($masterFd);
            $ffi->close($slaveFd);

            throw PtyException::forkFailed();
        }

        if ($pid === 0) {
            // ---- child ----
            // login_tty() does setsid + TIOCSCTTY + dup2 onto 0/1/2. Without
            // it the child has a tty but no *controlling* tty: no job control,
            // no signal delivery on Ctrl-C, and interactive TUIs misbehave.
            $ffi->close($masterFd);

            if ($ffi->login_tty($slaveFd) !== 0) {
                exit(127);
            }

            if ($cwd !== null) {
                @chdir($cwd);
            }

            pcntl_exec($executable, array_slice($command, 1), $environment);

            exit(127); // only reached if exec failed
        }

        // ---- parent ----
        $ffi->close($slaveFd);

        $stream = @fopen("php://fd/{$masterFd}", 'r+');

        if ($stream === false) {
            throw PtyException::masterUnreadable($masterFd);
        }

        stream_set_blocking($stream, false);

        // PHP buffers stream writes in userspace and retries the flush itself.
        // On a pty master whose buffer is full that retry loop is invisible to
        // us and cannot be interrupted — fwrite() simply does not return. Set
        // the buffer to 0 so every fwrite() maps to exactly one write(2) and
        // hands EAGAIN straight back.
        stream_set_write_buffer($stream, 0);
        stream_set_read_buffer($stream, 0);

        return new Session($pid, $stream, $masterFd, $ttyName);
    }

    /**
     * Sets the window size on a pty file descriptor and delivers SIGWINCH to
     * the foreground process group.
     *
     * @internal Called by Session::resize().
     */
    public static function setWinSize(int $fd, int $rows, int $cols): void
    {
        $ffi = self::ffi();

        $winsize = $ffi->new('struct winsize');
        $winsize->ws_row = $rows;
        $winsize->ws_col = $cols;

        if ($ffi->ioctl($fd, self::TIOCSWINSZ, FFI::addr($winsize)) !== 0) {
            throw PtyException::resizeFailed($rows, $cols);
        }
    }

    /**
     * Reads the window size back from a pty file descriptor.
     *
     * @return array{0:int,1:int} [rows, cols]
     *
     * @internal Called by Session::winSize().
     */
    public static function getWinSize(int $fd): array
    {
        $ffi = self::ffi();

        $winsize = $ffi->new('struct winsize');
        $ffi->ioctl($fd, self::TIOCGWINSZ, FFI::addr($winsize));

        return [$winsize->ws_row, $winsize->ws_col];
    }

    /** @internal Exposed so tests can close raw descriptors. */
    public static function closeFd(int $fd): void
    {
        self::ffi()->close($fd);
    }

    /**
     * Lazily binds libc. FFI::cdef parses C on every call, so the handle is
     * built once and reused.
     */
    private static function ffi(): FFI
    {
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        if (! in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            throw PtyException::unsupportedPlatform(PHP_OS_FAMILY);
        }

        foreach (['ffi', 'pcntl', 'posix'] as $extension) {
            if (! extension_loaded($extension)) {
                throw PtyException::missingExtension($extension);
            }
        }

        if (PHP_SAPI !== 'cli') {
            throw PtyException::ffiDisabled();
        }

        // On macOS openpty lives in libSystem, already loaded. On Linux it is
        // in libutil, which must be named explicitly.
        foreach (self::IS_DARWIN ? [null] : ['libutil.so.1', 'libutil.so', null] as $library) {
            try {
                self::$ffi = $library === null
                    ? FFI::cdef(self::CDEF)
                    : FFI::cdef(self::CDEF, $library);

                return self::$ffi;
            } catch (Throwable) {
                continue;
            }
        }

        throw PtyException::bindingFailed();
    }

    /** Resolves a binary against PATH without shelling out. */
    private static function resolve(string $binary): string
    {
        if (str_contains($binary, '/')) {
            return $binary;
        }

        foreach (explode(':', (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/').'/'.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw PtyException::executableNotFound($binary);
    }

    /** @return array<string,string> */
    private static function currentEnvironment(): array
    {
        $env = getenv();

        return is_array($env) ? $env : [];
    }
}
