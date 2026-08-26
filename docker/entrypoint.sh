#!/usr/bin/env bash
# Live-image entrypoint: bundled MySQL 8 + PHP-FPM + nginx in one container so
# a reviewer can `docker run` -> `curl` with zero orchestration. Production
# would run MySQL externally (Cloud SQL) — set DB_HOST to skip the bundled one.
#
# Env:
#   SITE_HANDLE  framework site handle (default: slate)
#   SITE_DB      database name (default: $SITE_HANDLE)
#   DB_HOST      external MySQL host — skips the bundled server entirely
#   CRON_EVENTS  set 0 to disable the named-cron-events scheduler (default on)
#   /opt/seed/*.sql.gz  (mount) — imported into $SITE_DB on first
#                initialization of the bundled server (real site data)
set -euo pipefail

SITE_HANDLE="${SITE_HANDLE:-slate}"
SITE_DB="${SITE_DB:-${SITE_HANDLE}}"

# render nginx fastcgi params from env (see nginx/default.conf placeholders)
sed -i "s/__SITE_HANDLE__/${SITE_HANDLE}/g; s/__SITE_DB__/${SITE_DB}/g" \
    /etc/nginx/sites-available/default

# The conf assumes TLS terminates upstream (Cloud Run/LB) and tells PHP the
# request is https so site-level force-https configs don't redirect-loop.
# For plain-HTTP serving (local dev, e2e containers) set ASSUME_HTTPS=0 so
# Site::isUsingHttps() reflects reality and redirects stay on http.
if [ "${ASSUME_HTTPS:-1}" = "0" ]; then
    sed -i '/fastcgi_param HTTPS on;/d; /fastcgi_param REQUEST_SCHEME https;/d' \
        /etc/nginx/sites-available/default
fi

if [ -z "${DB_HOST:-}" ]; then
    echo "--- starting bundled MySQL 8"
    mkdir -p /var/lib/mysql /var/lib/mysql-files /run/mysqld
    chown -R mysql:mysql /var/lib/mysql /var/lib/mysql-files /run/mysqld

    FRESH_DATADIR=0
    if [ ! -d /var/lib/mysql/mysql ]; then
        echo "--- initializing MySQL data directory"
        mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
        FRESH_DATADIR=1
    fi

    mysqld --user=mysql --datadir=/var/lib/mysql \
        --socket=/run/mysqld/mysqld.sock \
        --bind-address=127.0.0.1 \
        --disabled-storage-engines=MyISAM \
        >/var/log/mysqld.log 2>&1 &

    echo "--- waiting for MySQL"
    for i in $(seq 1 60); do
        if mysql --socket=/run/mysqld/mysqld.sock -uroot -e 'SELECT 1' >/dev/null 2>&1; then
            break
        fi
        sleep 1
        [ "$i" = 60 ] && { echo "MySQL failed to start"; tail -50 /var/log/mysqld.log; exit 1; }
    done

    # root@localhost is socket-only after --initialize-insecure; the framework
    # connects over 127.0.0.1, so provision a TCP-reachable root
    mysql --socket=/run/mysqld/mysqld.sock -uroot <<'SQL'
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

    mysql --socket=/run/mysqld/mysqld.sock -uroot \
        -e "CREATE DATABASE IF NOT EXISTS \`${SITE_DB}\` CHARACTER SET utf8mb4"

    # seed real site data on first init (mount dumps at /opt/seed; produced by
    # e.g. mysqldump on the source VFS host with VFS/session tables excluded
    # and ENGINE=MyISAM rewritten to InnoDB — see bin/load-host-dbs.mjs)
    if [ "${FRESH_DATADIR}" = "1" ] && ls /opt/seed/*.sql.gz >/dev/null 2>&1; then
        for dump in /opt/seed/*.sql.gz; do
            echo "--- seeding ${SITE_DB} from $(basename "${dump}") ($(du -h "${dump}" | cut -f1))"
            gunzip -c "${dump}" | mysql --socket=/run/mysqld/mysqld.sock -uroot \
                --init-command="SET SESSION FOREIGN_KEY_CHECKS=0" "${SITE_DB}"
        done
        echo "--- seed complete ($(mysql --socket=/run/mysqld/mysqld.sock -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${SITE_DB}'") tables)"
    fi

    echo "--- MySQL ready ($(mysql --socket=/run/mysqld/mysqld.sock -uroot -N -e 'SELECT VERSION()'))"
fi

echo "--- starting php-fpm"
php-fpm -D

# named cron events: fire minutely/hourly/daily/weekly into the composed
# tree (context Emergence\Site) so layers can ship scheduled work as plain
# event-handlers/ files — see README.md "Scheduled events"
if [ "${CRON_EVENTS:-1}" != "0" ]; then
    echo "--- starting cron-events scheduler"
    /opt/emergence/tools/cron-events.sh &
else
    echo "--- cron-events scheduler disabled (CRON_EVENTS=0)"
fi

echo "--- starting nginx"
exec nginx -g 'daemon off;'
