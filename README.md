# php-pty

[![tests](https://github.com/croustibat/php-pty/actions/workflows/tests.yml/badge.svg)](https://github.com/croustibat/php-pty/actions/workflows/tests.yml)
[![licence](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

**[node-pty](https://github.com/microsoft/node-pty) for PHP.** Pseudo-terminals
in pure PHP — no extension to compile, no companion C library, and no Node.
Just `ext-ffi` and `ext-pcntl`. macOS and Linux.

```bash
composer require croustibat/php-pty
```

The namespace is `Croustibat\Pty\`. The package requires PHP CLI with FFI,
pcntl and posix; it has no runtime Composer dependencies.

## Try it

From a checkout, install dependencies and run the quickstart:

```bash
git clone https://github.com/croustibat/php-pty.git
cd php-pty
composer install
php examples/quickstart.php
```

It prints `30 120`: the terminal dimensions reported by the child process.
No API key, external service or additional application is needed.

Then open an interactive shell:

```bash
php examples/interactive.php
```

Inside it, run `stty size`, resize your terminal window and run `stty size`
again. Type `exit` or press Ctrl-D at an empty shell prompt to leave. Ctrl-C
is forwarded through the PTY to the child terminal's foreground process group.
Your original terminal settings are restored when the relay finishes.

Pass a command as separate arguments after `--`:

```bash
php examples/interactive.php -- /bin/sh
php examples/interactive.php -- top
php examples/multiple-sessions.php
```

| Example | What it demonstrates |
|---|---|
| [quickstart.php](examples/quickstart.php) | Spawn with a known size, drain output and collect the exit code |
| [interactive.php](examples/interactive.php) | Keyboard relay, live resizing, bounded queues, partial writes and terminal restoration |
| [multiple-sessions.php](examples/multiple-sessions.php) | One `stream_select()` loop reading two independently finishing commands |

The interactive relay requires a terminal on both STDIN and STDOUT and the
system `stty` command. It returns the child's exit code. It handles external
SIGINT, SIGTERM, SIGHUP and SIGQUIT with cleanup; SIGKILL cannot be intercepted.
The multiple-session example labels each output line with `fast` or `slow`;
ordering between processes is intentionally not guaranteed.

When installed with Composer in an application, the examples can also be run
under `vendor/croustibat/php-pty/examples/`. Run them as standalone CLI scripts.
They are reference implementations to adapt, not a public event-loop API.

## Why

PHP can already reach a pty through `proc_open()` with `['pty']` descriptors —
but it hands you no control over the window size. No `ioctl`, so no
`TIOCSWINSZ`, so no `SIGWINCH`. Interactive TUIs render at whatever geometry
they guess and never learn they were resized.

That single gap is why PHP projects that need to drive a terminal end up
shipping a Node sidecar just for `node-pty`. This package closes it without
leaving PHP.

### Compared to node-pty

|  | node-pty | php-pty |
|---|---|---|
| Runtime | Node | PHP CLI |
| Install | native module, needs a toolchain | `composer require`, nothing compiled |
| Platforms | macOS, Linux, Windows (ConPTY) | macOS, Linux |
| Concurrency | libuv event loop | your own `stream_select()` loop |
| Scope | pty + spawn | pty + spawn |

Windows is the honest gap: it has no pty. ConPTY is a different API and would
be a different package.

**What you get:** a real pty, a real controlling terminal (`login_tty`, so job
control and Ctrl-C work), a settable and resettable window size, and a
non-blocking master stream you can drive from `stream_select()`.

**What you don't:** an event loop, a scrollback buffer, a terminal emulator, or
a session daemon. This is the primitive, not the product.

## Requirements

- PHP 8.2+, **CLI SAPI only**
- `ext-ffi`, `ext-pcntl`, `ext-posix`
- macOS or Linux

## Troubleshooting

- **Missing extension:** inspect `php -m` and `php --ini` for the CLI binary
  actually running the example. Enabling an extension only in FPM is insufficient.
- **FFI disabled:** try `php -d ffi.enable=1 examples/quickstart.php`. The
  extension must still be installed. Do not run this library in a web request.
- **Binding fails on Linux:** check the installed C runtime and availability
  of `openpty` and `login_tty`. CI covers Ubuntu; Alpine/musl is not in the matrix.
- **No output yet:** `read()` is non-blocking; follow the select loop in the
  quickstart instead of assuming the child has already produced output.
- **A manually killed relay left the terminal unusable:** run `stty sane` in
  that terminal. Normal exit and handled signals restore the exact saved mode.

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

## Reading and writing

A PTY is a byte stream. `read()` is non-blocking: an empty string can mean
there is no data yet. Wait for readiness with `stream_select()` and continue
reading while the child is active. After it exits, drain any remaining output
before closing the session. Waiting for exit before reading can block a child
whose output is waiting to be consumed.

`write()` retries partial writes until its deadline and returns the number of
bytes accepted. Keep `substr($payload, $written)` when the count is short.
For full-duplex relays, interleave reads and writes and bound both queues, as
[the interactive example](examples/interactive.php) does. A writable stream
can still accept only part of a buffer. `write(timeout: 0)` sends no bytes.

The terminal echoes input by default. Use `stty -echo` inside the child to
turn echo off; use `stty raw -echo` for byte-oriented protocols. Changing the
local terminal is a separate operation, and its settings must be restored.

The measured partial-write behaviour and the Darwin ARM64 variadic `ioctl`
ABI regression are explained in [Implementation notes](docs/implementation-notes.md).

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
| `Session::wait(?float $timeout = 10.0): int` | Exit code, `128 + signal` when killed; `-1` on timeout or unavailable status. `null` waits indefinitely |
| `Session::close()` | Closes the master; the child gets `SIGHUP` |

Rows and columns must be between **1 and 65535**, both at spawn and resize.
An invalid working directory or executable is rejected before allocating a
terminal. Explicit relative executable paths are resolved against `cwd` when
provided. If the working directory disappears before the child enters it, the
child exits with code 127 without executing the command.

`write()` returns the number of bytes accepted before its deadline; on a short
write, retain and retry the remaining suffix. Timeouts must be finite and
non-negative. `write(timeout: 0)` returns immediately without writing;
`wait(timeout: 0)` makes one non-blocking status check. A timed-out `wait()`
does not stop the child. If another process manager has already reaped the
child, `wait()` returns `-1` and `exitCode()` remains `null`.

`close()` is idempotent and releases both the original master descriptor and
the PHP stream's duplicate. Newly spawned children close their inherited
copies of other php-pty sessions. Closing a session does not reap its process:
call `wait()` afterwards to collect its status. A child can handle or ignore
`SIGHUP`; use `terminate()` or `kill()` when explicit termination is needed.

## Scope

Out of scope, deliberately:

- **Windows.** It has no pty. ConPTY is an unrelated API and would be a
  different package.
- **Terminal emulation.** Bring your own — this hands you bytes.
- **Session daemons, scrollback, multiplexing.** Build them on top.

## Security

FFI, `pcntl_fork()` and command execution are all dangerous by design here.
[`SECURITY.md`](SECURITY.md) spells out the threat model and how to report a
vulnerability privately. Short version: never pass user-controlled input as the
executable, and do not work around the CLI-only check.

## Contributing and releases

See [CONTRIBUTING.md](CONTRIBUTING.md) for local checks and the CI matrix.
Changes not yet included in a tag are listed under **Unreleased** in the
[CHANGELOG](CHANGELOG.md). Check that section when comparing `main` with an
installed Composer version.

## Credits

The FFI/`pcntl` approach was validated against
[jerch](https://github.com/jerch)'s advice in the Coolify discussion on
terminal streaming, which recommended a single-process event loop over
per-session processes.

## Licence

MIT.
