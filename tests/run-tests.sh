#!/bin/bash
# Everything that runs without a server: lint, the cron parser and overlap rules, the collectors against example
# data, and a render of both tabs. Run it inside the build image: tests/in-container.sh tests/run-tests.sh
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); fail=0
mkdir -p "$HERE/tests/.work"
"$HERE/kit/bin/lint.sh" || fail=1
if command -v shellcheck >/dev/null; then mapfile -t SH < <(cd "$HERE" && git ls-files -- 'tests/*.sh' build.sh | while read -r f; do case $(sed -n 1p "$HERE/$f") in "#!"*bash*) echo "$f" ;; esac; done); (cd "$HERE" && shellcheck -x -S style "${SH[@]}" tests/.work/lint/plg-inline-*.sh) && echo "ok   shellcheck: ${#SH[@]} scripts + the .plg blocks" || fail=1; fi
php "$HERE/tests/unit.php" || fail=1
php "$HERE/tests/collect-test.php" || fail=1
php "$HERE/tests/render-test.php" || fail=1
I=$HERE/src/usr/local/emhttp/plugins/unraid-timetable/images/timetable.png
if [ -s "$I" ] && grep -q 'icon="timetable.png"' "$HERE/unraid-timetable.plg" && grep -q '^Icon="timetable.png"' "$HERE/src/usr/local/emhttp/plugins/unraid-timetable/Timetable.page" && [ -s "$HERE/icon.png" ] && grep -q '/main/icon.png<' "$HERE/plugins/unraid-timetable.xml"; then echo "ok   icon wired into the .plg, the page and the template"; else echo "FAIL icon wiring"; fail=1; fi
# read-only: no file writes, no exec of anything but reading, no POST handling in the shipped code
P=$HERE/src/usr/local/emhttp/plugins/unraid-timetable
# shellcheck disable=SC2016  # the pattern is literal
if grep -rnE 'file_put_contents|fwrite|unlink\(|rename\(|mkdir\(|touch\(|exec\(|shell_exec|system\(|passthru|proc_open|popen|\$_POST|method="post"' "$P" ; then echo "FAIL shipped code writes, runs or accepts POST"; fail=1; else echo "ok   shipped code has no writes, no exec, no POST"; fi
[ $fail = 0 ] && echo "ALL PASSED" || echo "FAILED"
exit $fail
