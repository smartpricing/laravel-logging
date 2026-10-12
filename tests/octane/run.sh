#!/usr/bin/env bash
set -euo pipefail
package_root="$(cd "$(dirname "$0")/../.." && pwd)"
app_dir="${1:?Pass the disposable application created by tests/smoke/install.sh}"
php "$package_root/tests/octane/prepare.php" "$app_dir"
(
    cd "$app_dir"
    composer update laravel/octane --with-all-dependencies --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
)
project="logging-octane-$$"
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker logs --tail 15 "$project-a" 2>&1 || true
        docker logs --tail 15 "$project-b" 2>&1 || true
    fi
    docker rm -f "$project-a" "$project-b" >/dev/null 2>&1 || true
    docker image rm "$project" >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT
docker build -f "$package_root/tests/octane/Dockerfile" -t "$project" "$app_dir"
for replica in a b; do
    docker run -d --name "$project-$replica" -p 127.0.0.1::8000 -e LOG_CHANNEL=stderr "$project" >/dev/null
done
python3 "$package_root/tests/octane/verify.py" "$project-a" "$project-b"
