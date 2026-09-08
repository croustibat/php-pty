# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |

This is a `0.x` package maintained by one person. Only the latest minor line
gets fixes.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Use GitHub's [private vulnerability reporting](https://github.com/croustibat/php-pty/security/advisories/new),
or email <bbouillot@gmail.com> with `php-pty security` in the subject.

What to expect, honestly, from a solo maintainer:

- Acknowledgement within a week.
- An assessment, and a fix or a documented refusal, within 30 days for anything
  I can reproduce.
- Credit in the advisory and the release notes unless you prefer otherwise.

If you get no reply within two weeks, ping me again — it means the mail was
lost, not ignored.

## Threat model — read this before using the library

This package does three things that are inherently dangerous, by design. None
of them are bugs, and all of them are your responsibility to contain.

### It calls into libc through FFI

`ext-ffi` executes arbitrary C with no memory safety. A wrong struct layout or
a bad pointer is a memory corruption, not an exception. The library only binds
`openpty`, `login_tty`, `ioctl`, `close` and `_exit`, and the declarations are covered
by tests — including `tests/AbiRegressionTest.php`, which exists because a
wrong `ioctl` declaration corrupts memory *while returning 0*.

If you run PHP with `ffi.enable=preload` in a web-facing SAPI, this library is
not your biggest problem, but it is not for you either.

### It forks the current process

`pcntl_fork()` duplicates everything: open database connections, Redis sockets,
file handles, credentials in memory, and any secret your process is holding.
The child then `exec`s, so the exposure window is short — but it exists, and if
`exec` fails the child exits with code 127 using `_exit`, without running
inherited PHP shutdown callbacks or destructors.

The library refuses to load outside the CLI SAPI for this reason. Do not work
around that check.

### It executes commands

`Pty::spawn()` takes an argv array. The first element is resolved against
`PATH` when it contains no slash.

- **Never pass user-controlled input as the executable.** A writable directory
  early in `PATH` is a code-execution primitive.
- Arguments are passed through `pcntl_exec()` as a real argv, so there is no
  shell and no shell-injection surface — unless *you* spawn a shell with
  `-c`, at which point quoting is entirely on you.
- The child inherits the environment you pass, or the current one if you pass
  none. Filter it if it holds secrets the child should not see.

## Out of scope

Reports that a program run inside the pty did something harmful. This library
gives that program a terminal; it does not sandbox it, and it never claimed to.
Sandboxing is the caller's job.
