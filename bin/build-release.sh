#!/usr/bin/env bash
# =============================================================================
# bin/build-release.sh
#
# Builds a deployment-ready zip for Telepage.
# Run from the project root:
#
#   bash bin/build-release.sh
#
# Output: telepage-vX.Y.Z.zip in the project root.
#
# The zip contains everything needed for a fresh install or in-place upgrade:
#   - All PHP source files
#   - vendor/ (Composer dependencies, generated fresh without dev packages)
#   - assets/, lang/, install/, admin/, api/, app/, bin/ directories
#   - .htaccess, index.php, config.example.json, INSTALL.md, CHANGELOG.md
#
# The zip deliberately EXCLUDES:
#   - config.json          (per-installation secret)
#   - data/app.sqlite      (per-installation database)
#   - data/backups/        (per-installation backups)
#   - assets/img/logo.png  (per-installation logo)
#   - assets/img/favicon-* (generated from logo)
#   - assets/media/        (user media files)
#   - .git/ .github/       (not needed on server)
#   - tests/               (not needed on server)
#   - phpstan*             (dev tooling)
#   - phpunit*             (dev tooling)
#   - docs/                (not needed on server)
#   - vendor/ from any prior run (regenerated fresh)
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Read version from CHANGELOG.md (first ## [X.Y.Z] line)
# ---------------------------------------------------------------------------
VERSION=$(grep -m1 '## \[' CHANGELOG.md | sed 's/.*\[\(.*\)\].*/\1/')
if [[ -z "$VERSION" ]]; then
    echo "ERROR: Could not determine version from CHANGELOG.md"
    exit 1
fi

ZIPNAME="telepage-v${VERSION}.zip"
TMPDIR=$(mktemp -d)
SRCDIR="${TMPDIR}/telepage"

echo "Building Telepage v${VERSION} → ${ZIPNAME}"
echo ""

# ---------------------------------------------------------------------------
# Step 1 — copy source tree into temp dir (rsync excludes unwanted files)
# ---------------------------------------------------------------------------
echo "[1/4] Copying source files…"
rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitignore' \
    --exclude='.vscode' \
    --exclude='.idea' \
    --exclude='vendor/' \
    --exclude='config.json' \
    --exclude='config.json.lock' \
    --exclude='config.json.tmp.*' \
    --exclude='data/app.sqlite' \
    --exclude='data/app.sqlite-wal' \
    --exclude='data/app.sqlite-shm' \
    --exclude='data/backups/*.sqlite' \
    --exclude='data/import_queue_*.json' \
    --exclude='data/logs/' \
    --exclude='data/*.log' \
    --exclude='assets/media/*' \
    --exclude='assets/img/logo.png' \
    --exclude='assets/img/favicon-32.png' \
    --exclude='assets/img/favicon-64.png' \
    --exclude='tmp/' \
    --exclude='tests/' \
    --exclude='phpstan*' \
    --exclude='phpunit*' \
    --exclude='docs/' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='.phpstan.cache' \
    --exclude='.phpunit.cache' \
    --exclude='.phpunit.result.cache' \
    --exclude="${ZIPNAME}" \
    ./ "${SRCDIR}/"

echo "         OK"

# ---------------------------------------------------------------------------
# Step 2 — generate vendor/ (production only, no dev dependencies)
# ---------------------------------------------------------------------------
echo "[2/4] Running composer install --no-dev --optimize-autoloader…"
cd "${SRCDIR}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
echo "         OK ($(ls vendor | wc -l | tr -d ' ') packages)"
cd - > /dev/null

# ---------------------------------------------------------------------------
# Step 3 — create .htaccess for data/ inside the zip (belt-and-suspenders
# for hosts where the root .htaccess RewriteRule might not cover subdirs)
# ---------------------------------------------------------------------------
echo "[3/4] Writing data/.htaccess guard…"
cat > "${SRCDIR}/data/.htaccess" << 'EOF'
# Deny direct HTTP access to the data directory.
# This protects the SQLite database, logs, and backups.
Require all denied
EOF
echo "         OK"

# ---------------------------------------------------------------------------
# Step 4 — zip
# ---------------------------------------------------------------------------
echo "[4/4] Creating ${ZIPNAME}…"
cd "${TMPDIR}"
zip -r -q "${OLDPWD}/${ZIPNAME}" telepage/
cd - > /dev/null

# ---------------------------------------------------------------------------
# Cleanup
# ---------------------------------------------------------------------------
rm -rf "${TMPDIR}"

SIZE=$(du -sh "${ZIPNAME}" | cut -f1)
echo ""
echo "Done! ${ZIPNAME} (${SIZE})"
echo ""
echo "Contents preview:"
unzip -l "${ZIPNAME}" | tail -5
