#!/bin/sh
set -eu

umask 0027
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

exec "$@"
