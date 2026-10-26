# Image for a PHP web application with PDO on PostgreSQL or SQLite.
# Settings arrive from the tier's environment file (compose.yaml env_file);
# config.php, data files and installer lock files never enter the image.
# Pinned to the PHP release the suite passes on (tools\php is 8.3.14) and to
# the Debian release, so a rebuild on Radiant gets the same runtime. Raise the
# tag on purpose, after the tests pass on the new release.
FROM php:8.3.14-apache-bookworm

# PDO drivers: pdo_pgsql for PostgreSQL on Radiant, pdo_sqlite for an app
# still on SQLite. The official image builds pdo_sqlite in already; the last
# command fails the build if either driver is missing.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/* \
 && php -m | grep -qi '^pdo_pgsql$' \
 && php -m | grep -qi '^pdo_sqlite$'

# Production php.ini: errors logged, never shown to the browser.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Serve the app's document root on port 8080, so Apache runs entirely as
# www-data and never as root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/.
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri -e 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
 && a2enmod rewrite headers

# Identity on Radiant comes from the university's authenticating proxy (NetID),
# and authority from role_grants (D-015). The image therefore starts in the
# proxy mode; a tier's env file may name the header and groups settings, and
# nothing in it can turn the development sign-in on (it needs PHP's built-in
# server over loopback).
ENV PACELAB_AUTH_MODE=proxy

# Code only; .dockerignore keeps data, config.php and lock files out.
COPY --chown=www-data:www-data . /var/www/html/

USER www-data
EXPOSE 8080

# health.php?db=1 answers 503 when the database is unreachable, and
# file_get_contents returns false on any status other than 2xx.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/health.php?db=1') === false ? 1 : 0);"]
