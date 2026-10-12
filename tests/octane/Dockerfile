FROM dunglas/frankenphp:1-php8.4@sha256:7027c395e4a3b9f0624fd2091989eeaa1a5b7f7bd74677992df31a07a6655312
RUN install-php-extensions pcntl mbstring
WORKDIR /app
COPY . /app
RUN rm -f bootstrap/cache/config.php && php artisan package:discover
ENV APP_ENV=production STRUCTURED_LOGGING_ENABLED=true
ENTRYPOINT ["sh", "-c", "php artisan config:cache && exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=1 --max-requests=1000 --log-level=warn"]
