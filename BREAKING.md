# Breaking changes: skeleton-v1/v2 → skeleton-v3

Tracks what breaks for a site moving from the skeleton-v1/v2 composition
(PHP 5 / MySQL-VFS era) onto skeleton-v3 (PHP 8 / MySQL 8 / hologit-composed
containers). Seeded from the Track A PHP 8 port that first proved Slate
renders on this skeleton (JarvusInnovations/emergence-skeleton#192).

## PHP 8.0

- **`each()` is removed** (hard fatal). All 10 call sites lived in the
  vendored Dwoo 1.2 engine and its builtin plugins; fixed on the
  `EmergencePlatform/dwoo` `php8-compat` branch this skeleton pins as its
  `dwoo` holosource. Site-layer PHP that still calls `each()` must be
  rewritten (`foreach`, or the `key()`/`current()`/`next()` idiom where the
  code consumes the array pointer).
- **Uniform variable syntax** (semantic change since PHP 7.0, silent):
  `$obj->$m[2][$k]` parses as `($obj->$m)[2][$k]`. This had silently broken
  *all* Dwoo object-property navigation in templates (`{$node->RealPath}`)
  on PHP 7+; fixed in the pinned Dwoo branch by bracing to
  `$obj->{$m[2][$k]}`. Audit site-layer code for the same pattern.
- **`call_user_func_array()` treats string keys as named arguments.**
  Dwoo's compiler passed param maps with a `'*'` rest key → `Unknown named
  parameter $*` fatals; fixed with `array_values()` wraps in the pinned Dwoo
  branch. Site-layer dynamic invocation with string-keyed arg arrays breaks
  the same way.
- **Optional-before-required parameters are deprecated** (warn on 8.3):
  `Site::initialize($rootPath, $hostname = null, array $config)` became
  `array $config = []` in `emergence/php-core`'s `php8-compat` branch.
- PHP 8 promoted undefined array/index reads from `E_NOTICE` to `E_WARNING`.
  The framework's Whoops handler converts anything inside `error_reporting`
  into thrown `ErrorException`s, so the skeleton-v3 container runtime masks
  `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` to restore the PHP 5-era tolerance
  the codebase was written against. Real fatals (`Error`, `TypeError`,
  exceptions) still surface. Burning down the actual warning sites is
  tracked as hardening debt.

## PHP 8.1

- **mysqli defaults to exception mode** (`MYSQLI_REPORT_ERROR |
  MYSQLI_REPORT_STRICT`), which bypasses `DB::handleError`'s errno checks
  and kills the `TableNotFoundException` → auto-create-table flow the whole
  framework relies on. `emergence/php-core` now forces
  `mysqli_report(MYSQLI_REPORT_OFF)` before connecting. Site code that
  started relying on `mysqli_sql_exception` must not.

## MySQL 8

- **MyISAM is retired** (Cloud SQL disables it outright). skeleton-v3 sets
  `SQL::$mysqlStorageEngine = 'InnoDB'` so framework-generated DDL is
  InnoDB, and `emergence/php-core` rewrites `ENGINE=MyISAM` →
  `ENGINE=InnoDB` at the `DB::preprocessQuery` choke point to catch
  hand-written DDL in site layers. Verify imported legacy dumps
  (`people`, `history_*`) against InnoDB + MySQL 8 SQL modes.

## Composition & runtime model

- **No VFS.** There is no runtime parent-pulling, WebDAV editing, or
  `layer-vfs`; `Site::$autoPull` is disabled. Composition happens at build
  time via `git holo project` and the result runs as a container.
- **php-core is a Composer dependency**, not a projected tree: the composed
  site tree carries `composer.json` requiring `emergence/php-core`
  (`php8-compat` branch until it merges to `develop`), installed at image
  build. The front controller boots from the site tree's
  `vendor/autoload.php`.
- **Vendored libraries are holosources, not baked-in copies**: Dwoo
  (patched fork), psr/log, psr/http-message, Michelf markdown/smartypants,
  dflydev apache-mime-types, layer-events (which carries intouch-ical).
  Dropped outright — sites that used them must bring their own: extjs,
  gitonomy-gitlib, google/recaptcha (pinned pre-PHP 7.1!), mysqldump-php,
  jdenticon, colors.php, patchwork/utf8 (unneeded on PHP 8), stojg/crop,
  symfony 3.4 process/yaml (EOL, no PHP 8 support), and the Sencha
  apikit/hotfixes family.

## Purged subsystems

- **`sencha-workspace/` and the ExtJS 6.2 SDK build tooling.** The `/manage`
  ExtJS admin app is unbuilt and will not function; the Sencha *classes*
  under `php-classes/` remain (demand-loaded, never hit on the proven boot
  paths) pending a final keep/kill decision.
- `php-config/Git.config.d/` — gen-1/2 VFS git-source wiring.
- `cypress/`, `phpunit-tests/`, `fixtures/`, `docs/`, `helm-chart/`,
  `api-docs/`, `script/` developer/deploy assets.
- Skeleton-v1's superseded `event-handlers/RegistrationRequestHandler/`
  tree (replaced by skeleton-v2's namespaced handler).

## Known gaps (not yet breaking-change decisions)

- **No front-end asset pipeline**: pages render unstyled until a Sass →
  `css/main.css` build exists. `MinifiedRequestHandler` logs + skips
  missing bundle sources instead of fataling; revert that tolerance once a
  real asset build lands.
- Email handlers, cron/site-tasks, console-commands, and connectors are
  unverified on PHP 8 (only the web boot/render path is proven).
- Media storage is local-scratch; production needs GCS-backed
  `Media`/`MediaRequestHandler` or a persistent volume.

## Rector report (emergence/php-core)

A `LevelSetList::UP_TO_PHP_83` sweep over php-core found **no fatal-class
issues beyond the targeted fixes above** — the applied rules were
modernization-level only: short arrays, `__CLASS__`/class-name strings →
`::class`, first-class callables, arrow functions, `str_starts_with`/
`str_ends_with`, unused catch variables, defensive `(string)` casts for
PHP 8.1 strict string args, and `never` return types on always-throwing
stubs. The skeleton tree itself greps clean of removed constructs
(`each()`, `create_function()`, `mysql_*`, `ereg*`/`split()`, curly-brace
offsets, `(real)`, `strftime()`, `get_magic_quotes*`,
`FILTER_SANITIZE_STRING`).
