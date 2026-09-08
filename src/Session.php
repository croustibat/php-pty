<?php

declare(strict_types=1);

namespace Croustibat\Pty;

use WeakMap;

/**
 * One process attached to one pseudo-terminal.
 *
 * The master side is exposed as a non-blocking PHP stream so it can be driven
 * from stream_select() alongside other resources.
 */
final class Session
{
    /** @var WeakMap<self, true>|null Does not keep abandoned sessions alive. */
    private static ?WeakMap $sessions = null;

    /** @var resource|null */
    private $stream;

    private ?int $exitCode = null;

    private bool $reaped = false;

    /**
     * @param  resource  $stream
     *
     * @internal Build sessions through Pty::spawn().
     */
    public function __construct(
        private readonly int $pid,
        $stream,
        private readonly int $masterFd,
        private readonly string $ttyName,
    ) {
        $this->stream = $stream;
        self::$sessions ??= new WeakMap;
        self::$sessions[$this] = true;
    }

    /** @internal Close only the forked child's copies, before exec. */
    public static function closeInheritedSessions(): void
    {
        if (self::$sessions !== null) {
            $inherited = [];
            foreach (self::$sessions as $session => $_) {
                $inherited[] = $session;
            }

            // close() removes its entry. Snapshot keys first so deleting the
            // current WeakMap entry cannot skip the next inherited session.
            foreach ($inherited as $session) {
                $session->close();
            }
        }
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /** The slave device path, e.g. `/dev/ttys018`. */
    public function ttyName(): string
    {
        return $this->ttyName;
    }

    /**
     * The pty master, non-blocking. Pass it to stream_select().
     *
     * @return resource
     */
    public function stream()
    {
        if (! is_resource($this->stream)) {
            throw PtyException::alreadyClosed();
        }

        return $this->stream;
    }

    /**
     * Writes everything, looping over partial writes.
     *
     * This loop is the whole point. fwrite() on a pty master routinely writes
     * fewer bytes than asked — the kernel buffer is small and the reader may
     * be slow. Ignoring the return value drops the tail silently, and when the
     * cut lands mid escape sequence the terminal renders garbage: a stray
     * `7G` on screen instead of a cursor move. Diagnosed the hard way,
     * 2026-08-14. `tests/PartialWriteTest.php` guards it.
     */
    public function write(string $data, float $timeout = 5.0): int
    {
        self::validateTimeout($timeout);
        if ($data === '') {
            return 0;
        }

        $stream = $this->stream();
        $total = 0;
        $length = strlen($data);
        $deadline = hrtime(true) / 1_000_000_000 + $timeout;

        while ($total < $length) {
            // A bounded deadline is not belt-and-braces, it is required. If
            // the child stops reading — because it is blocked writing back to
            // a master nobody drains, or because the line discipline is in
            // canonical mode and waiting for a newline that never comes — the
            // pty buffer stays full forever. Without this, write() hangs the
            // caller with no way out.
            if (hrtime(true) / 1_000_000_000 >= $deadline) {
                break;
            }

            $written = @fwrite($stream, substr($data, $total, 65536));

            if ($written === false || $written === 0) {
                // EAGAIN on a non-blocking master: wait for writability.
                $read = [];
                $write = [$stream];
                $except = [];

                $remaining = max(0.0, $deadline - hrtime(true) / 1_000_000_000);
                $seconds = (int) $remaining;
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);

                if (@stream_select($read, $write, $except, $seconds, $microseconds) < 1) {
                    break;
                }

                continue;
            }

            $total += $written;
        }

        return $total;
    }

    /** Reads whatever is available. Returns '' when nothing is pending. */
    public function read(int $length = 65536): string
    {
        $chunk = @fread($this->stream(), $length);

        return $chunk === false ? '' : $chunk;
    }

    /**
     * Resizes the terminal and delivers SIGWINCH to the child's foreground
     * process group.
     */
    public function resize(int $rows, int $cols): void
    {
        $this->stream();

        Pty::setWinSize($this->masterFd, $rows, $cols);
    }

    /** @return array{0:int,1:int} [rows, cols] */
    public function winSize(): array
    {
        $this->stream();

        return Pty::getWinSize($this->masterFd);
    }

    public function signal(int $signal): bool
    {
        return $this->isRunning() && posix_kill($this->pid, $signal);
    }

    public function terminate(): bool
    {
        return $this->signal(SIGTERM);
    }

    public function kill(): bool
    {
        return $this->signal(SIGKILL);
    }

    /**
     * Non-blocking liveness check. Reaps the child if it has exited.
     *
     * @phpstan-impure
     */
    public function isRunning(): bool
    {
        if ($this->reaped) {
            return false;
        }

        $status = 0;
        do {
            $result = pcntl_waitpid($this->pid, $status, WNOHANG);
        } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        if ($result === $this->pid) {
            $this->reap($status);

            return false;
        }

        if ($result === -1 && pcntl_get_last_error() === PCNTL_ECHILD) {
            $this->reaped = true;
        }

        return $result === 0;
    }

    /**
     * Waits for the child to exit and returns its exit code.
     *
     * Bounded on purpose. `pcntl_waitpid()` without WNOHANG blocks forever if
     * the child never dies — and a library call that can wedge the caller with
     * no escape is a defect, however unlikely the case. Pass `null` for the
     * old blocking behaviour, explicitly.
     *
     * @phpstan-impure
     *
     * @return int Exit code, `128 + signal` when killed, or -1 on timeout/unavailable status.
     */
    public function wait(?float $timeout = 10.0): int
    {
        if ($timeout !== null) {
            self::validateTimeout($timeout);
        }

        if ($this->reaped) {
            return $this->exitCode ?? -1;
        }

        $status = 0;

        if ($timeout === null) {
            do {
                $result = pcntl_waitpid($this->pid, $status);
            } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);

            if ($result === $this->pid) {
                $this->reap($status);
            } elseif ($result === -1 && pcntl_get_last_error() === PCNTL_ECHILD) {
                $this->reaped = true;
            }

            return $this->exitCode ?? -1;
        }

        $deadline = hrtime(true) / 1_000_000_000 + $timeout;

        do {
            $result = pcntl_waitpid($this->pid, $status, WNOHANG);

            if ($result === $this->pid) {
                $this->reap($status);

                return $this->exitCode ?? -1;
            }

            if ($result === -1) {
                if (pcntl_get_last_error() === PCNTL_ECHILD) {
                    $this->reaped = true;
                }

                if (pcntl_get_last_error() !== PCNTL_EINTR) {
                    return -1;
                }
            }

            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining > 0) {
                usleep((int) min(2_000, $remaining * 1_000_000));
            }
        } while (hrtime(true) / 1_000_000_000 < $deadline);

        return -1;
    }

    /** Available once the child has been reaped, null before. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Closes the master. The child receives SIGHUP once no descriptor keeps
     * the pty open — without this the process keeps running invisibly.
     */
    public function close(): void
    {
        if ($this->stream !== null) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }

            // php://fd owns a duplicate, not the original used by ioctl.
            Pty::closeFd($this->masterFd);
            $this->stream = null;
            unset(self::$sessions[$this]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function reap(int $status): void
    {
        $this->reaped = true;

        $this->exitCode = pcntl_wifexited($status)
            ? pcntl_wexitstatus($status)
            : (pcntl_wifsignaled($status) ? 128 + pcntl_wtermsig($status) : -1);
    }

    private static function validateTimeout(float $timeout): void
    {
        if (! is_finite($timeout) || $timeout < 0) {
            throw PtyException::invalidTimeout();
        }
    }
}
