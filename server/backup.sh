#!/bin/sh
set -eu
umask 077

if [ "$#" -ne 3 ]; then
    printf '%s\n' 'Usage: backup.sh PRIVATE_CLIENT_CNF DATABASE PRIVATE_BACKUP_DIR' >&2
    exit 2
fi

credentials=$1
database=$2
directory=$3
stamp=$(date -u +%Y-%m-%d)
temporary=$(mktemp "$directory/.remi-XXXXXXXX.sql")
trap 'rm -f "$temporary" "$temporary.gz"' EXIT

"${REMI_DUMP:-mariadb-dump}" --defaults-extra-file="$credentials" --single-transaction --skip-lock-tables \
    --result-file="$temporary" -- "$database"
gzip -f "$temporary"
mv -f "$temporary.gz" "$directory/remi-$stamp.sql.gz"
