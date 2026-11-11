#!/bin/bash
# vm-deploy.sh <ssh port> <user@host> - put the built package and the .plg on a TEST server and install it.
# The .plg names its package by URL; a package already in /boot/config/plugins/<name>/ with the right MD5 is used
# as it is, so nothing is downloaded. Never point this at a production server.
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=unraid-timetable
PORT=${1:?ssh port}; DEST=${2:?user@host}
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
PKG=$HERE/packages/$NAME-$VER-x86_64-1.txz
[ -f "$PKG" ] || { echo "FATAL: no package for $VER, build first"; exit 1; }
ssh -p "$PORT" "$DEST" "mkdir -p /boot/config/plugins/$NAME"
scp -q -P "$PORT" "$PKG" "$DEST:/boot/config/plugins/$NAME/"
scp -q -P "$PORT" "$HERE/$NAME.plg" "$DEST:/tmp/$NAME.plg"
ssh -p "$PORT" "$DEST" "plugin install /tmp/$NAME.plg 2>&1 | tail -n 25"
