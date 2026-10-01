# Development/test-only image for the reseller project (WP1+).
# NOT the production image: the production Dockerfile at the repository root is unchanged.
# PHP 8.2 is the V1 runtime target (owner decision C5).
FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /repo
