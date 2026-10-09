#!/bin/sh
# One package for every installation (e.g. cms.jinx-digital.com and excellent.jinx-digital.com):
# the API, the built admin app (public/), migrations, sample data and docs - with the Composer libraries
# for production. Written to runtime/release/excellent-cms-<version>.tar.gz. Run by `make release`
# (after `make generate`). Installing it: see docs/deployment.md.
set -e
cd "$(dirname "$0")/.."
VERSION=$(git describe --tags --always --dirty 2>/dev/null || date +%Y%m%d%H%M)
NAME="excellent-cms-$VERSION"
OUT=runtime/release
rm -rf "$OUT/$NAME"
mkdir -p "$OUT/$NAME"

# Everything the server needs - no sources of the admin app, tests, local settings or data
rsync -a \
  --exclude '.git' --exclude '.idea' --exclude '.DS_Store' \
  --exclude '/frontend' --exclude '/tests' --exclude '/codeception.yml' --exclude '/vendor' \
  --exclude '/.env' --exclude '/.env.*' --include '/.env.example' \
  --exclude '/runtime/*' --include '/runtime/.gitkeep' \
  --exclude '/storage/*' --include '/storage/.gitkeep' \
  --exclude '/plugins/*' \
  ./ "$OUT/$NAME/"
cp .env.example "$OUT/$NAME/.env.example"
mkdir -p "$OUT/$NAME/runtime" "$OUT/$NAME/storage" "$OUT/$NAME/plugins"
echo "$VERSION" > "$OUT/$NAME/VERSION"

(cd "$OUT/$NAME" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
test -f "$OUT/$NAME/public/200.html" || { echo "The admin app is missing - run make generate first"; exit 1; }

tar -czf "$OUT/$NAME.tar.gz" -C "$OUT" "$NAME"
rm -rf "${OUT:?}/$NAME"
echo "$OUT/$NAME.tar.gz ($(du -h "$OUT/$NAME.tar.gz" | cut -f1))"
