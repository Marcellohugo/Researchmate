#!/bin/sh
set -eu

if [ "$APP_DB_USERNAME" = "$POSTGRES_USER" ]; then
    echo 'DB_USERNAME must differ from the PostgreSQL administrator.' >&2
    exit 1
fi
if [ "$POSTGRES_DB" = researchmate_testing ]; then
    echo 'DB_DATABASE must differ from the dedicated test database.' >&2
    exit 1
fi

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=ON_ERROR_STOP=1 \
    --set=app_user="$APP_DB_USERNAME" --set=app_password="$APP_DB_PASSWORD" \
    --set=app_db="$POSTGRES_DB" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user') \gexec
CREATE DATABASE researchmate_testing;
SELECT format('ALTER DATABASE researchmate_testing OWNER TO %I', :'app_user') \gexec
SQL
