#!/usr/bin/env bash
set -euo pipefail

SLUG="gt-link-manager"
VERSION=$(grep -m1 "Version:" gt-link-manager.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
BUILD_DIR="/tmp/${SLUG}-build"
ZIP_NAME="${SLUG}-${VERSION}.zip"

echo "Building ${SLUG} v${VERSION}..."

# Compile block editor assets.
for block_dir in blocks/*/; do
	if [ -f "${block_dir}package.json" ]; then
		echo "Compiling ${block_dir}..."
		(cd "${block_dir}" && npm install --silent && npm run build)
	fi
done

rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${SLUG}"

# Assemble distribution using .distignore.
if [ -f ".distignore" ]; then
	rsync -a \
		--exclude-from=".distignore" \
		--exclude="${BUILD_DIR}" \
		. "${BUILD_DIR}/${SLUG}/"
else
	# Fallback: copy plugin files manually.
	cp gt-link-manager.php "${BUILD_DIR}/${SLUG}/"
	cp uninstall.php "${BUILD_DIR}/${SLUG}/"
	cp readme.txt "${BUILD_DIR}/${SLUG}/"
	cp -r includes "${BUILD_DIR}/${SLUG}/"
	cp -r assets "${BUILD_DIR}/${SLUG}/"
	cp -r blocks "${BUILD_DIR}/${SLUG}/"
	cp -r languages "${BUILD_DIR}/${SLUG}/"

	# Remove block source and dev artifacts (only ship build output).
	find "${BUILD_DIR}/${SLUG}/blocks" -type d -name "src" -exec rm -rf {} + 2>/dev/null || true
	find "${BUILD_DIR}/${SLUG}/blocks" -type d -name "node_modules" -exec rm -rf {} + 2>/dev/null || true
	find "${BUILD_DIR}/${SLUG}/blocks" -name "package.json" -delete 2>/dev/null || true
	find "${BUILD_DIR}/${SLUG}/blocks" -name "package-lock.json" -delete 2>/dev/null || true
fi

# Clean junk files.
find "${BUILD_DIR}" -name ".DS_Store" -delete 2>/dev/null || true
find "${BUILD_DIR}" -name "__MACOSX" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}" -name ".gitignore" -delete 2>/dev/null || true
find "${BUILD_DIR}" -type d -empty -delete 2>/dev/null || true

# Verify critical files.
MISSING=0
for f in \
	"${SLUG}.php" \
	"uninstall.php" \
	"readme.txt" \
	"includes/class-gt-link-db.php" \
	"includes/class-gt-link-redirect.php" \
	"assets/css/admin.css" \
	"assets/js/admin.js" \
	"blocks/link-inserter/build/index.js" \
	"blocks/link-inserter/build/index.asset.php"; do
		if [ ! -f "${BUILD_DIR}/${SLUG}/${f}" ]; then
			echo "ERROR: Missing critical file: ${f}"
			MISSING=$((MISSING + 1))
		fi
done

if [ "$MISSING" -gt 0 ]; then
	echo "Build failed: ${MISSING} critical files missing"
	rm -rf "${BUILD_DIR}"
	exit 1
fi

# Build zip.
cd "${BUILD_DIR}"
zip -rq "${ZIP_NAME}" "${SLUG}"
mv "${ZIP_NAME}" "${OLDPWD}/"
cd "${OLDPWD}"

# Cleanup.
rm -rf "${BUILD_DIR}"

FILE_COUNT=$(zipinfo -t "${ZIP_NAME}" 2>/dev/null | sed 's/[^0-9]*\([0-9]*\) file.*/\1/' || echo "?")
echo "Done: ${ZIP_NAME} ($(du -h "${ZIP_NAME}" | cut -f1), ${FILE_COUNT} files)"
