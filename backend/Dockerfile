# ── backend/Dockerfile ──────────────────────────────────────────────────────
# Stages:
#   base        — PHP + system deps, no app code
#   production  — composer install (no-dev), copy src, run server
#   test        — composer install (with dev), run PHPUnit
# ─────────────────────────────────────────────────────────────────────────────

# ── base ─────────────────────────────────────────────────────────────────────
FROM php:8.2-cli AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        unzip \
        curl \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ── production ───────────────────────────────────────────────────────────────
FROM base AS production

# Copy dependency manifests first (layer cache)
COPY composer.json composer.lock ./

# Install runtime deps only (no dev)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy application source
COPY public/  public/
COPY src/      src/
COPY templates/ templates/

# Create writable data directory (actual data mounted via volume at runtime)
RUN mkdir -p data && chmod 755 data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]

# ── test ─────────────────────────────────────────────────────────────────────
FROM base AS test

# Copy dependency manifests
COPY composer.json composer.lock ./

# Install all deps including dev (PHPUnit)
RUN composer install --no-interaction --prefer-dist

# Copy full source including tests and fixtures
COPY public/    public/
COPY src/       src/
COPY templates/ templates/
COPY tests/     tests/
COPY phpunit.xml ./

# Ensure reports directory exists for JUnit output
RUN mkdir -p tests/reports

CMD ["vendor/bin/phpunit", "--colors=never"]
