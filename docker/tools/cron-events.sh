#!/usr/bin/env bash
# In-container scheduler for the named cron events: fires `minutely`,
# `hourly`, `daily`, and `weekly` into the composed tree's event bus
# (context Emergence\Site) so any layer can add scheduled work purely by
# contributing an event-handlers/ file — no external plumbing per activity.
# See docker/README.md ("Scheduled events") for the contract.
#
# Runs as a first-class multirun-supervised process (see entrypoint.sh)
# alongside php-fpm/nginx/mysqld unless CRON_EVENTS=0.
# Operational semantics carried over from the gen-3 (menunet) CronJobs:
#   - runs of the same event never overlap (per-event flock; late runs skip)
#   - per-tier execution deadlines (`timeout`): minutely 50s, hourly 50m,
#     daily/weekly 15m — a run past its deadline is killed (exit 124)
#   - every firing and its exit status is logged to the container log
#
# Env (all optional; times are UTC, zero-padded MM / HH:MM):
#   CRON_HOURLY_MINUTE  minute-of-hour for `hourly` (default 20)
#   CRON_DAILY_TIME     time-of-day for `daily` (default 02:40)
#   CRON_WEEKLY_DAY     ISO day-of-week for `weekly`, 1=Mon..7=Sun (default 7)
#   CRON_WEEKLY_TIME    time-of-day for `weekly` (default 03:10)
set -uo pipefail

CONTEXT='Emergence\Site'
LOCK_DIR=/run/cron-events

HOURLY_MINUTE="${CRON_HOURLY_MINUTE:-20}"
DAILY_TIME="${CRON_DAILY_TIME:-02:40}"
WEEKLY_DAY="${CRON_WEEKLY_DAY:-7}"
WEEKLY_TIME="${CRON_WEEKLY_TIME:-03:10}"

mkdir -p "${LOCK_DIR}"

log() {
    printf '[cron-events] %s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

# fire <event> <deadline-seconds> — in the background, so a long daily run
# never delays the minutely tick; the per-event lock is what serializes
fire() {
    local event="$1" deadline="$2"
    (
        exec 9>"${LOCK_DIR}/${event}.lock"
        if ! flock -n 9; then
            log "skip ${event}: previous run still active"
            exit 0
        fi

        log "fire ${event}"
        local started status=0
        started="$(date +%s)"
        timeout -k 10 "${deadline}" \
            php /opt/emergence/tools/console-run.php events:fire "${event}" "${CONTEXT}" \
            || status=$?

        local elapsed=$(( $(date +%s) - started ))
        if [ "${status}" -eq 124 ]; then
            log "kill ${event}: exceeded ${deadline}s deadline"
        else
            log "done ${event}: exit ${status} after ${elapsed}s"
        fi
    ) &
}

log "scheduler started (hourly at :${HOURLY_MINUTE}, daily at ${DAILY_TIME} UTC, weekly at ${WEEKLY_TIME} UTC on ISO day ${WEEKLY_DAY})"

last_tick=''
while :; do
    # align to the next wall-clock minute boundary (10# guards octal "08"/"09")
    sleep $(( 60 - 10#$(date -u +%S) ))

    tick="$(date -u '+%Y-%m-%dT%H:%M')"
    [ "${tick}" = "${last_tick}" ] && continue
    last_tick="${tick}"

    read -r minute hhmm dow <<< "$(date -u '+%M %H:%M %u')"

    fire minutely 50
    [ "${minute}" = "${HOURLY_MINUTE}" ] && fire hourly 3000
    [ "${hhmm}" = "${DAILY_TIME}" ] && fire daily 900
    [ "${dow}" = "${WEEKLY_DAY}" ] && [ "${hhmm}" = "${WEEKLY_TIME}" ] && fire weekly 900
done
