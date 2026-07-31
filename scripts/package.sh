#!/usr/bin/env bash
# Build a distributable zip for marketplace upload.
# Usage: bash scripts/package.sh [version]
set -euo pipefail

VERSION="${1:-dev}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="$(basename "$ROOT")"
STAGE="$(mktemp -d)/${NAME}"
OUT="$ROOT/dist/${NAME}-${VERSION}.zip"

echo "→ Staging to $STAGE"
mkdir -p "$STAGE" "$ROOT/dist"

rsync -a "$ROOT/" "$STAGE/" \
    --exclude .git \
    --exclude node_modules \
    --exclude vendor \
    --exclude dist \
    --exclude scripts \
    --exclude .env \
    --exclude .phpunit.result.cache \
    --exclude 'docs/PLAN.md' \
    --exclude 'docs/MARKETING.md' \
    --exclude 'database/*.sqlite' \
    --exclude 'storage/app/installed.lock' \
    --exclude 'storage/logs/*.log' \
    --exclude 'storage/framework/cache/*' \
    --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*.php' \
    --exclude 'storage/app/public/*' \
    --exclude 'public/storage'

# rsync excluded the cache contents wholesale — recreate the skeleton the
# framework expects.
mkdir -p "$STAGE/storage/framework/cache/data"

cd "$STAGE"

echo "→ Production composer install (mirrored path repos — real files in the zip)"
export COMPOSER_MIRROR_PATH_REPOS=1
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "→ Building assets"
npm install --no-audit --no-fund --silent
npm run build --silent
rm -rf node_modules

echo "→ Zipping to $OUT"
rm -f "$OUT"
cd ..
if command -v zip >/dev/null; then
    zip -rq "$OUT" "$NAME"
else
    python3 -m zipfile -c "$OUT" "$NAME"
fi

echo "✓ $(du -h "$OUT" | cut -f1) → $OUT"
