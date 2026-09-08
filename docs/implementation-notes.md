# Implementation notes

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
