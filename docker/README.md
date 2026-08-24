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

## Runtime env

| Var | Meaning |
| --- | --- |
| `SITE_HANDLE` / `SITE_DB` | site handle / database name (db defaults to handle) |
| `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS` | external MySQL; unset `DB_HOST` = bundled MySQL 8 |
| `SITE_DEBUG` | Whoops debug pages |
| `ASSUME_HTTPS` | default 1: tells PHP requests are https (TLS terminates upstream, e.g. Cloud Run). Set `0` for plain-HTTP serving (local dev, e2e) so redirects stay on http |
| `MEDIA_GCS_BUCKET` (+ADC) | GCS-backed media; unset = local scratch |

## Tools

- `tools/console-run.php` — run a site console-command
  (`docker exec <ctr> php /opt/emergence/tools/console-run.php migrations:execute --all`)
- `tools/sync-search-indexes.php` — recreate the FULLTEXT indexes search
  needs; run after every DB seed/cutover
