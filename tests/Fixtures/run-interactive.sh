#!/bin/sh

# macOS adds PENDIN when leaving raw mode. It is transient kernel state,
# not a persistent setting; mask just this bit in the comparison.
terminal_settings() {
    mode=$(stty -g) || return 1
    "$1" -r '
        $mode = $argv[1];
        if (PHP_OS_FAMILY === "Darwin") {
            $mode = preg_replace_callback("/(?<=:lflag=)[0-9a-f]+/", static fn ($m) => dechex(hexdec($m[0]) & ~0x20000000), $mode);
        }
        echo $mode;
    ' "$mode"
}
before=$(terminal_settings "$1") || exit 1
"$@"
status=$?
after=$(terminal_settings "$1") || exit 1
if [ "$before" = "$after" ]; then
    printf '\nTERMINAL-RESTORED\n'
else
    printf '\nTERMINAL-NOT-RESTORED\n'
    printf 'before=%s\nafter=%s\n' "$before" "$after"
    exit 1
fi
exit "$status"
