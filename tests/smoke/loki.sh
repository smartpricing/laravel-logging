#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd "$(dirname "$0")" && pwd)"
export SMOKE_LOG_DIR="$(cd "${1:?Pass the smoke application directory}/storage/logs" && pwd)"
project="logging-smoke-$$"
compose=(docker compose -p "$project" -f "$script_dir/compose.yaml")
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then "${compose[@]}" logs --tail 40; fi
    "${compose[@]}" down --volumes >/dev/null
    exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d
loki_address="$("${compose[@]}" port loki 3100)"
python3 "$script_dir/verify-loki.py" "http://$loki_address" "$SMOKE_LOG_DIR/laravel.log"
