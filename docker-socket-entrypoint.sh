#!/bin/sh
set -eu

SOCKET_PATH="${DOCKER_SOCKET_PATH:-/var/run/docker.sock}"
APACHE_USER="${APACHE_RUN_USER:-www-data}"

if [ -S "$SOCKET_PATH" ]; then
    SOCKET_GID="$(stat -c '%g' "$SOCKET_PATH" 2>/dev/null || true)"

    if [ -n "$SOCKET_GID" ]; then
        SOCKET_GROUP="$(getent group "$SOCKET_GID" | cut -d: -f1 || true)"

        if [ -z "$SOCKET_GROUP" ]; then
            SOCKET_GROUP="docker-host"
            groupadd -g "$SOCKET_GID" "$SOCKET_GROUP" 2>/dev/null || true
            SOCKET_GROUP="$(getent group "$SOCKET_GID" | cut -d: -f1 || true)"
        fi

        if [ -n "$SOCKET_GROUP" ]; then
            usermod -aG "$SOCKET_GROUP" "$APACHE_USER"
        fi
    fi
fi

exec docker-php-entrypoint "$@"
