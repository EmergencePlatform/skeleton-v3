# PHP-8 site runtime image

Container build context for serving a hologit-projected skeleton-v3 site
(e.g. Slate's `emergence-site` holobranch, or a leaf repo's projection):
`php:8.3-fpm-bookworm` + nginx, `emergence/php-core` composer-installed at
image build, single-site front controller, optional bundled MySQL 8
(`DB_HOST` unset) that seeds `/opt/seed/*.sql.gz` on first init.

Provenance: extracted from the Track A live-pipeline image that serves the
first modernized production sites (proven there since 2026-07), moved here so
the runtime lives beside the skeleton it pairs with and so public CI (e.g.
Cypress e2e in consuming site repos) can build it without private-repo
access.

## Build

Stage a projected site tree at `.build/site/` inside this directory, then:

```bash
git holo project emergence-site --fetch --lens   # in the site repo
# stage the tree: git archive <tree> | tar -x -C <this dir>/.build/site
docker build -t my-site docker/
```

## Process model

Init runs sequentially in `entrypoint.sh` — nginx config rendering, then
(bundled MySQL only) datadir initialize / root provisioning / first-boot
seeding via a **temporary** mysqld that is shut down cleanly — before the
entrypoint execs [multirun](https://github.com/nicolas-van/multirun)
(pinned, checksum-verified) as PID 1 supervising every long-running
process as a first-class child:

- `php-fpm -F`
- `nginx -g 'daemon off;'`
- `mysqld` (only when `DB_HOST` is unset — logs to the container log; the
  provisioning instance's log stays in `/var/log/mysqld.log`)
- `tools/cron-events.sh` (only when `CRON_EVENTS` != 0)

If **any** supervised process dies, multirun stops the rest and the
container exits so the orchestrator restarts it; signals (`docker stop`,
Cloud Run shutdown) are forwarded to all children so mysqld shuts down
cleanly — give the container a stop grace period that covers an InnoDB
shutdown (e.g. `docker stop -t 30`); zombies are reaped by PID 1. The
services start together **after** provisioning completes, so requests in
the first seconds of a boot (and throughout first-boot seeding) can see
connection errors/502s until nginx, php-fpm, and mysqld are all up.

## Runtime env

| Var | Meaning |
| --- | --- |
| `SITE_HANDLE` / `SITE_DB` | site handle / database name (db defaults to handle) |
| `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS` | external MySQL; unset `DB_HOST` = bundled MySQL 8 |
| `SITE_DEBUG` | Whoops debug pages |
| `ASSUME_HTTPS` | default 1: tells PHP requests are https (TLS terminates upstream, e.g. Cloud Run). Set `0` for plain-HTTP serving (local dev, e2e) so redirects stay on http |
| `MEDIA_GCS_BUCKET` (+ADC) | GCS-backed media; unset = local scratch |
| `CRON_EVENTS` | default 1: run the named-cron-events scheduler (below). Set `0` to disable |
| `CRON_HOURLY_MINUTE` | minute-of-hour the `hourly` event fires (UTC, zero-padded `MM`; default `20`) |
| `CRON_DAILY_TIME` | time-of-day the `daily` event fires (UTC `HH:MM`; default `02:40`) |
| `CRON_WEEKLY_DAY` / `CRON_WEEKLY_TIME` | ISO day-of-week (1=Mon..7=Sun; default `7`) and time-of-day (UTC `HH:MM`; default `03:10`) the `weekly` event fires |

## Scheduled events

The container fires four **named cron events** into the composed site's
event bus on fixed cadences — this is the contract every composed layer
targets to ship scheduled work with no external plumbing per activity:

| Event | Context | Cadence | Deadline |
| --- | --- | --- | --- |
| `minutely` | `Emergence\Site` | every minute | 50s |
| `hourly` | `Emergence\Site` | hourly (`CRON_HOURLY_MINUTE`) | 50m |
| `daily` | `Emergence\Site` | daily (`CRON_DAILY_TIME`) | 15m |
| `weekly` | `Emergence\Site` | weekly (`CRON_WEEKLY_DAY`/`_TIME`) | 15m |

A layer schedules an activity by contributing one file, e.g.
`event-handlers/Emergence/Site/daily/50_sync-reports.php` — the numeric
prefix orders handlers across layers (`ksort`). The event names carry **no
purpose and no time-of-day**: the event is just the tick (purpose lives in
handlers; when the tick lands is the scheduler's business, tuned via the
`CRON_*` env vars above). Do not introduce suffixed variants
(`daily-maintenance`, `nightly`, ...) — new scheduled work belongs under
one of these four names.

Mechanics (`tools/cron-events.sh`, a multirun-supervised process): each firing
runs `console-run.php events:fire <event> Emergence\Site` — a `system`-user
session, handlers aggregated across all composed layers by
`Emergence\EventBus`. Runs of the same event never overlap (per-event
`flock`; a tick that finds the previous run still active is skipped and
logged); a run past its deadline is killed (`timeout`, exit 124); every
firing and exit status is logged to the container log with a
`[cron-events]` prefix. Events with no handlers are cheap no-ops.

## Tools

- `tools/console-run.php` — run a site console-command
  (`docker exec <ctr> php /opt/emergence/tools/console-run.php migrations:execute --all`)
- `tools/sync-search-indexes.php` — recreate the FULLTEXT indexes search
  needs; run after every DB seed/cutover
