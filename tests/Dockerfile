# The build image: tests/in-container.sh runs the build and the tests in it, on a build host, never on a server.
# docker --context <host> build -t timetable-build:2 - < tests/Dockerfile
FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends git xz-utils shellcheck nodejs ca-certificates librsvg2-bin \
 && rm -rf /var/lib/apt/lists/*
RUN git config --system --add safe.directory "*"
