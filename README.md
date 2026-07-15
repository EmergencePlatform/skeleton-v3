# skeleton-v3

The flat, minimal, **PHP 8** Emergence site skeleton — the successor to
[emergence-skeleton](https://github.com/JarvusInnovations/emergence-skeleton)
(skeleton-v1) and
[emergence-skeleton-v2](https://github.com/JarvusInnovations/emergence-skeleton-v2),
built for running legacy Emergence sites as containers on PHP 8.3 + MySQL 8
instead of the EOL PHP 5 / MySQL-VFS stack.

## How it composes

skeleton-v3 is a [hologit](https://github.com/JarvusInnovations/hologit)
holosource. Its `emergence-site` holobranch lays vendor holosources and this
repo's own flat tree into the standard Emergence site layout:

```
php-classes/      framework + vendor classes (Dwoo, Psr, Michelf, Dflydev ride in as holosources)
php-config/       stacked configuration
html-templates/   Dwoo templates
dwoo-plugins/     custom + builtin template plugins
event-handlers/   event bus handlers
site-root/        public entry points + static assets
composer.json     requires emergence/php-core (installed at image build)
```

Product repos (e.g. [slate](https://github.com/SlateFoundation/slate)) pin
this repo as a holosource and map `holosource = "=>emergence-skeleton"`
before their own trees — exactly how they consumed skeleton-v2 — then
`git holo project` produces the deployable flat tree.

The framework core (`emergence/php-core`) is deliberately a **Composer
dependency**, not a holosource, so it can pull its own dependencies; the
composed tree's `composer.json` is installed at container build time.

Only patched upstreams are forked into EmergencePlatform (currently just
[dwoo](https://github.com/EmergencePlatform/dwoo) for PHP 8 fixes);
unpatched vendor libraries pin their real upstreams.

## Scope

Initial scope is the minimal set proven to boot and render Slate on
PHP 8.3 against MySQL 8 (home page + login through the full
`Site::initialize` → routing → Dwoo render chain). Pieces are added as
migrating sites require them — not full v1/v2 parity up front. See
[BREAKING.md](./BREAKING.md) for what changed and what was purged.
