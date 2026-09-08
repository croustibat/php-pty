# Contributing

Use PHP 8.2 or newer on macOS or Linux, with `ffi`, `pcntl` and `posix`
enabled for the CLI. The examples additionally use the system `stty` command.

```bash
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
composer validate --strict
```

Run tests where real pseudo-terminals and `/dev/tty` are accessible. A sandbox
that denies terminal access can fail integration tests despite the code being
correct. FFI can be enabled for a command with `php -d ffi.enable=1`.

CI runs the integration suite on PHP 8.2, 8.3, 8.4 and 8.5 under Linux, plus
PHP 8.5 on macOS ARM64. The macOS job guards the variadic `ioctl` ABI; it must
remain on Apple Silicon. A separate job runs PHPStan, Pint and Composer
validation. The package continues to require PHP `^8.2`.

For changes to process handling, exercise the child itself: terminal echo is
not proof that the program read its input. Signal readiness explicitly, drain
output before waiting, keep deadlines bounded and clean up sessions in
`finally`. The interactive example tests wrap the relay in another real PTY
and verify terminal settings before and after execution.

`phpstan/` contains analysis-only declarations for FFI. Never autoload them at
runtime. Keep their declared methods and fields consistent with `Pty::CDEF`.

Describe the problem, expected behaviour, PHP version, OS and architecture
when opening an issue. Include a minimal command or script that reproduces it.
For vulnerabilities, follow [SECURITY.md](SECURITY.md) instead of opening a
public issue. Record user-visible changes under Unreleased in
[CHANGELOG.md](CHANGELOG.md).
