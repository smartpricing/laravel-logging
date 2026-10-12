#!/usr/bin/env bash
set -euo pipefail
package_root="$(cd "$(dirname "$0")/../.." && pwd)"
app_dir="${1:?Pass a new, disposable application directory}"
laravel_version="${2:-13}"
composer create-project laravel/laravel "$app_dir" "^$laravel_version.0" --no-install --no-scripts --prefer-dist --no-interaction
php "$package_root/tests/smoke/prepare.php" "$app_dir" "$package_root"
(
    cd "$app_dir"
    composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
    php artisan package:discover --ansi
    php "$package_root/tests/smoke/probe.php" "$app_dir" disabled
    php artisan vendor:publish --tag=structured-logging-config --force
    STRUCTURED_LOGGING_ENABLED=true php artisan config:cache
    php "$package_root/tests/smoke/probe.php" "$app_dir" cli
    APP_RUNNING_IN_CONSOLE=false php "$package_root/tests/smoke/probe.php" "$app_dir" http
    php "$package_root/tests/smoke/probe.php" "$app_dir" verify
)
