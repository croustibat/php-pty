# Changelog

## [Unreleased]

## [0.1.1] - 2026-09-08

### Added

- Runnable quickstart, interactive terminal relay and multiple-session examples.
- Interactive relay coverage for input, initial geometry, live resizing,
  Ctrl-C, large final output, signal cleanup, terminal restoration and exit after terminal EOF.
- Contributor instructions and practical installation/troubleshooting guidance.
- PHP 8.5 CI coverage and explicit PHPStan, Pint and Composer validation.

### Fixed

- Close both the original PTY master and the PHP stream duplicate; keep cleanup
  idempotent even when callers close the stream themselves.
- Close inherited php-pty sessions in new children without retaining abandoned
  sessions in the parent.
- Resolve executables before allocating descriptors, preserve executable
  symlinks and reject invalid working directories and terminal dimensions.
- Exit failed children without running inherited PHP shutdown callbacks.
- Retry interrupted waits, preserve unknown externally collected statuses,
  validate timeouts and use monotonic deadlines.
- Verify real child responses and payload hashes instead of terminal echo or
  only the write count.

### Changed

- CI tests PHP 8.2–8.5 on Linux and PHP 8.5 on macOS ARM64, with one additional
  quality job: six jobs total. The minimum PHP requirement remains `^8.2`.
- Move detailed ABI and partial-write findings into implementation notes.

## [0.1.0] - 2026-08-15

- Initial public release as `croustibat/php-pty`.
- FFI-backed PTY creation, controlling terminals, resizing, non-blocking streams,
  bounded writes and process lifecycle methods for macOS and Linux.

[Unreleased]: https://github.com/croustibat/php-pty/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/croustibat/php-pty/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/croustibat/php-pty/tree/v0.1.0
