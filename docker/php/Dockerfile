FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    gnupg \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    zip \
    gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# System Chromium + Node.js for Browsershot (invoice PDF rendering — see
# InvoicePdfService). dompdf has no real text-shaping engine: it draws
# Khmer glyphs one codepoint at a time with no vowel reordering or coeng
# (subscript consonant) formation, corrupting anything but plain Latin.
# Browsershot drives this system Chromium via puppeteer-core (no bundled
# Chromium download — `apt`'s chromium is used directly), which shapes text
# the same way an actual browser tab would.
RUN apt-get update && apt-get install -y \
    chromium \
    fonts-noto \
    fonts-noto-cjk \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Installed globally (not under /var/www/html/node_modules) because that
# directory is shadowed at runtime by the ./backend bind mount — same reason
# vendor/ uses its own named volume instead of living in the image. Browsershot
# is pointed at this location via setNodeModulePath() in InvoicePdfService.
#
# vendor/spatie/browsershot's bin/browser.cjs unconditionally does
# `require('puppeteer')` — it has no puppeteer-core-aware code path in this
# version — so a bare `puppeteer-core` install alone leaves that require
# unresolved. The `puppeteer@npm:puppeteer-core@<version>` alias installs
# puppeteer-core's actual code (no bundled Chromium download) under the
# import name puppeteer-core doesn't otherwise provide.
RUN npm install -g puppeteer-core@24.43.1 puppeteer@npm:puppeteer-core@24.43.1

# postgresql-client-18 specifically, matching the postgres:18 service — the
# distro's own `postgresql-client` package trails the server by a major
# version, and pg_dump run against a newer server than it understands is
# the one thing this needs to get right for DatabaseBackupController.
RUN apt-get update && apt-get install -y curl gnupg lsb-release ca-certificates \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && sh -c 'echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list' \
    && apt-get update && apt-get install -y postgresql-client-18 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

CMD ["php-fpm"]