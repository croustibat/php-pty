# croustibat/pty

Pseudo-terminals in pure PHP. No extension to compile, no companion C library —
just `ext-ffi` and `ext-pcntl`. macOS and Linux.

```php
use Croustibat\Pty\Pty;

$session = Pty::spawn(['claude', '--resume'], rows: 30, cols: 120);

$session->write("hello\n");
$session->resize(40, 100);          // real TIOCSWINSZ, real SIGWINCH
echo $session->read();

$session->stream();                 // non-blocking, for stream_select()
$session->wait();                   // exit code
```

## Why

PHP can already reach a pty through `proc_open()` with `['pty']` descriptors —
but it hands you no control over the window size. No `ioctl`, so no
`TIOCSWINSZ`, so no `SIGWINCH`. Interactive TUIs render at whatever geometry
they guess and never learn they were resized.

That gap is why terminal-driving PHP projects reach for Node's `node-pty`.
This package closes it without leaving PHP.

**What you get:** a real pty, a real controlling terminal (`login_tty`, so job
control and Ctrl-C work), a settable and resettable window size, and a
non-blocking master stream you can drive from `stream_select()`.

**What you don't:** an event loop, a scrollback buffer, a terminal emulator, or
a session daemon. This is the primitive, not the product.

## Requirements

- PHP 8.2+, **CLI SAPI only**
- `ext-ffi`, `ext-pcntl`, `ext-posix`
- macOS or Linux

## Read this before you use it

**`pcntl_fork()` duplicates the entire process.** Every open PDO connection,
Redis socket and file handle is inherited by the child. In a web request, an
FPM worker or a queue job, that is a foot-gun, not a feature. This package
targets long-running CLI processes — daemons, terminal multiplexers, agent
runners — and nothing else. It refuses to load outside the CLI SAPI.

**`ECHO` is on by default** on the slave, as on any terminal. The line
discipline echoes your writes straight back to the master before the child has
read anything. If you are measuring round-trip latency, you are measuring the
kernel, not the child. Send `stty -echo` or set the termios flags yourself.

## The `ioctl` ABI trap

This is the finding that made the package worth publishing.

`ioctl` is variadic in C: `int ioctl(int, unsigned long, ...)`. Nearly every
PHP + FFI snippet on the web declares it with fixed arity:

```c
int ioctl(int fd, unsigned long request, void *arg);   /* wrong */
```

On Linux x86-64 that works, because the variadic and non-variadic ABIs coincide
for integers and pointers. **On Darwin arm64 it does not.** Apple diverges from
standard AAPCS64: every variadic argument is passed on the stack, while fixed
arguments go in registers. libffi puts the pointer in a register, the kernel
reads it off the stack, and `TIOCSWINSZ` copies from whatever address happened
to be sitting there.

The failure mode is the nasty kind — **`ioctl` returns `0`.** No errno, no
exception. Just a silently wrong window size.

Measured on PHP 8.5.8 / Darwin / arm64, asking for 30×120:

| declaration | `stty size` in the child | return |
|---|---|---|
| `openpty(..., struct winsize *winp)` | `30 120` ✅ | 0 |
| `int ioctl(int, unsigned long, void *)` | `0 2046` ❌ | **0** |
| `int ioctl(int, unsigned long, ...)` | `30 120` ✅ | 0 |

The fix is one line of `cdef`. `tests/AbiRegressionTest.php` guards it, and CI
runs on `macos-latest` precisely because Ubuntu alone would give a false green.

## Partial writes

`fwrite()` on a pty master routinely writes fewer bytes than you asked for. Drop
the return value and you drop the tail — and when the cut lands mid escape
sequence, the terminal prints the remainder as literal text. A stray `7G` on
screen where a cursor move was meant.

This is not an edge case, it is the normal regime. Measured on PHP 8.5.8 /
Darwin / arm64, pushing 1 MB through a pty master in 8 KB calls: **1 677
`fwrite()` calls instead of the 128 a full write would need** — about 625 bytes
accepted per call on average. Code that ignores the return value loses bytes
thirteen times out of fourteen.

There is a second, nastier layer. PHP buffers stream writes in userspace and
retries the flush in a loop you cannot see or interrupt. On a pty master whose
buffer is full, `fwrite()` then simply never returns, and no amount of
application-level timeout will save you. `Pty::spawn()` sets
`stream_set_write_buffer($stream, 0)` so every `fwrite()` maps to exactly one
`write(2)` and hands `EAGAIN` straight back.

`Session::write()` loops until the buffer is drained, under a deadline. The
deadline is not paranoia: if the child stops reading — because it is blocked
writing back to a master nobody drains, or because the line discipline is in
canonical mode waiting for a newline that never comes — the buffer stays full
forever and an unbounded loop hangs your process.

Two related traps, both worth knowing before you write your own relay:

- **`stty -echo` is not `stty raw`.** The first only silences the echo; the
  line discipline stays canonical, holding at most `MAX_CANON` bytes while it
  waits for a newline. Push half a megabyte with no `\n` through it and it
  jams.
- **Never write a large payload to a child that echoes it back unless you
  drain as you go.** The master's output buffer fills, the child blocks
  writing, so it stops reading, so your write blocks. Interleave with
  `stream_select()` on both directions.

## API

| Method | |
|---|---|
| `Pty::spawn(array $command, int $rows, int $cols, ?array $env, ?string $cwd)` | Returns a `Session` |
| `Session::stream()` | Non-blocking master, for `stream_select()` |
| `Session::write(string, float $timeout = 5.0): int` | Loops over partial writes, bounded |
| `Session::read(int $length = 65536): string` | `''` when nothing is pending |
| `Session::resize(int $rows, int $cols)` | `TIOCSWINSZ` + `SIGWINCH` |
| `Session::winSize(): array` | `[rows, cols]` |
| `Session::ttyName(): string` | e.g. `/dev/ttys018` |
| `Session::pid()`, `isRunning()`, `signal()`, `terminate()`, `kill()` | |
| `Session::wait(): int` | Blocks; `128 + signal` when killed |
| `Session::close()` | Closes the master; the child gets `SIGHUP` |

## Scope

Out of scope, deliberately:

- **Windows.** It has no pty. ConPTY is an unrelated API and would be a
  different package.
- **Terminal emulation.** Bring your own — this hands you bytes.
- **Session daemons, scrollback, multiplexing.** Build them on top.

## Credits

The FFI/`pcntl` approach was validated against
[jerch](https://github.com/jerch)'s advice in the Coolify discussion on
terminal streaming, which recommended a single-process event loop over
per-session processes.

## Licence

MIT.
