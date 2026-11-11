#!/bin/bash
# in-container.sh <command...> - run a command in this repository inside the build image on the dev host (the docker
# context in TT_DOCKER_CONTEXT): the work tree goes in as a tar stream, packages/ and CHANGELOG.md and the .plg come back.
# Nothing is built or run on the workstation itself.
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
CTX=${TT_DOCKER_CONTEXT:?set TT_DOCKER_CONTEXT to the docker context of the build host}
IMG=${TT_IMAGE:-timetable-build:2}
cd "$HERE"
OUT=$(mktemp -d "$HERE/tests/.work/out.XXXXXX" 2>/dev/null || { mkdir -p "$HERE/tests/.work"; mktemp -d "$HERE/tests/.work/out.XXXXXX"; })
trap 'rm -rf "$OUT"' EXIT
CMD=$(printf '%q ' "$@")
git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done \
  | tar --null -T - -cf - .git \
  | docker --context "$CTX" run --rm -i --network none "$IMG" bash -c "
      set -e; mkdir -p /w && cd /w && tar xf - && export TZ=Europe/Helsinki
      { $CMD; } >&2; rc=\$?
      tar cf - packages CHANGELOG.md *.plg 2>/dev/null || true
      exit \$rc" >"$OUT/back.tar"
tar -C "$OUT" -xf "$OUT/back.tar"
if [ "${TT_TAKE_BUILD:-0}" = 1 ]; then
  rm -rf "$HERE/packages"; cp -a "$OUT/packages" "$HERE/packages"
  cp "$OUT/CHANGELOG.md" "$OUT"/*.plg "$HERE/"
fi
