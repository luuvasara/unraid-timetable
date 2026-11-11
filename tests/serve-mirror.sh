#!/bin/bash
# serve-mirror.sh <dir> [port] - serve a static copy of a page (and its assets) from a container on the dev host
# (the docker context in TT_DOCKER_CONTEXT), for screenshots. Replaces an earlier copy of the same container.
set -euo pipefail
DIR=${1:?dir}; PORT=${2:-8791}; CTX=${TT_DOCKER_CONTEXT:?set TT_DOCKER_CONTEXT to the docker context of the build host}; IMG=${TT_IMAGE:-timetable-build:2}
docker --context "$CTX" rm -f tt-mirror >/dev/null 2>&1 || true
docker --context "$CTX" create --name tt-mirror -p "$PORT:8080" "$IMG" php -S 0.0.0.0:8080 -t /srv >/dev/null
tar -C "$DIR" -cf - . | docker --context "$CTX" cp - tt-mirror:/srv
docker --context "$CTX" start tt-mirror >/dev/null
echo "serving $DIR on port $PORT"
