# Track A live-site prototype: Slate composed on the draft skeleton-v3,
# running on PHP 8.3 + nginx + bundled MySQL 8 (single container so the
# prototype is `docker run` -> `curl` reviewable; DB_HOST env switches to an
# external MySQL for anything beyond the prototype).
#
# Build context is prepared by infra/live/build/project-and-build.sh, which
# runs the real hologit projection (Slate composed on skeleton-v3, pulling
# dwoo/php-core/etc from their sources) into .build/site. emergence-php-core
# is a Composer dependency of the projected tree — installed below, not
# copied. (The earlier rsync stand-in, compose-site.sh, is retired; see
# README.md and docs/analysis/skeleton-v3-spec.md.)
FROM php:8.3-fpm-bookworm

# --- system packages: nginx + MySQL 8 (Oracle apt repo; Debian only ships
# MariaDB, and the point of this prototype is proving real MySQL 8 with
# MyISAM disabled) ---
RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg curl ca-certificates lsb-release \
    && { curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025; curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023; } | gpg --dearmor -o /usr/share/keyrings/mysql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] http://repo.mysql.com/apt/debian/ bookworm mysql-8.0" > /etc/apt/sources.list.d/mysql.list \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx \
        unzip \
        mysql-community-server-core \
        mysql-community-client-core \
    && rm -rf /var/lib/apt/lists/* \
    # the -core packages ship bare binaries without the mysql system user
    && groupadd -r mysql && useradd -r -g mysql -s /usr/sbin/nologin mysql

# --- PHP extensions the framework touches on the boot/render path ---
# mysqli (DB layer), gd (Media/thumbnails), exif (PhotoMedia), gettext (the
# skeleton's _() translation calls — auth prompts, connector errors — fatal
# "undefined function _()" without it; legacy PHP 5 hosts had it built in)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" mysqli gd exif gettext \
    && apt-get purge -y libpng-dev libjpeg-dev libfreetype6-dev \
    && apt-get install -y --no-install-recommends libpng16-16 libjpeg62-turbo libfreetype6 \
    # imagemagick + ghostscript: PDFMedia/PhotoMedia shell out to `convert`
    # and `gs` for PDF/PSD/TIFF/EPS thumbnails; Debian's ImageMagick policy
    # blocks the PDF coder by default, so grant it read|write
    && apt-get install -y --no-install-recommends imagemagick ghostscript \
    && sed -i 's#<policy domain="coder" rights="none" pattern="PDF" />#<policy domain="coder" rights="read|write" pattern="PDF" />#' /etc/ImageMagick-6/policy.xml \
    && apt-get autoremove -y && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY php/zz-emergence.ini /usr/local/etc/php/conf.d/zz-emergence.ini
# clear_env=no so `docker run -e` secrets reach getenv() in php-config
COPY php/zz-emergence-pool.conf /usr/local/etc/php-fpm.d/zz-emergence-pool.conf

# --- composed site tree (hologit projection: slate on skeleton-v3) ---
# The extra site/ level is where Site::getFilesystem() roots the framework
# filesystem relative to SITE_ROOT (same layout as the archive image).
# Owned by www-data so the framework can write caches/generated files.
COPY --chown=www-data:www-data .build/site /opt/site/site

# emergence-php-core (+ its deps) is a Composer requirement of the projected
# tree's composer.json (pinned to the PHP-8 branch via a VCS repository).
# `update` not `install`: skeleton-v3 ships no lock, so resolve fresh.
# The optional composer_auth BuildKit secret (a composer auth.json with a
# github-oauth token) avoids GitHub's unauthenticated API rate limit when
# composer resolves the VCS repositories — the build script passes it from
# `gh auth token` when available; never baked into a layer.
RUN --mount=type=secret,id=composer_auth,target=/root/.composer/auth.json \
    cd /opt/site/site \
    && COMPOSER_ALLOW_SUPERUSER=1 composer update --no-dev --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data vendor

# --- front controller + nginx ---
COPY web/index.php /opt/emergence/web/index.php
COPY nginx/default.conf /etc/nginx/sites-available/default

# post-seed maintenance: recreate the FULLTEXT indexes the search feature
# needs (seeded DBs carry the source schema's fulltext indexes, which don't
# match what the modernized search MATCHes). Run against the target DB after
# each seed/cutover — see infra/live/README.md. Idempotent.
COPY tools/sync-search-indexes.php /opt/emergence/tools/sync-search-indexes.php
COPY tools/console-run.php /opt/emergence/tools/console-run.php

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
