#!/usr/bin/env bash
# Every check in one command: eslint, the translation check, phpstan, the
# php checks and the integration test. Use it before a release.
#
#   ./tests/run.sh                  all checks
#   SKIP_INTEGRATION=1 ./tests/run.sh   leave out the container test
#
# crypt15_check, memdb_check and reader_check need SQLite with the memdb VFS
# and the sqlite_dbpage table. They always run in the image IMG: php:8.3-cli
# when a probe finds both there, else nextcloud:34-fpm.
set -euo pipefail
cd "$(dirname "$0")/.."

PHPFLAGS=(-d zend.assertions=1 -d assert.exception=1)

step() { echo; echo "== $* =="; }

# Container engine: docker in CI, rootless podman otherwise
CTR=${CTR:-}
if [ -z "$CTR" ]; then
	if [ -n "${CI:-}" ]; then
		CTR=docker
	else
		# Reject root run outside CI: rootless podman root run lands in another store and leaks
		if [ "$(id -u)" = 0 ]; then
			echo "FAIL: tests must run as a non-root user (not in CI); use rootless podman" >&2
			exit 1
		fi
		CTR=podman
	fi
fi

IMG=php:8.3-cli
# shellcheck disable=SC2016  # reason: the single quotes hold PHP code; PHP, not the shell, reads its variables
MEMDB_PROBE='$p = new PDO("sqlite:file:/probe?vfs=memdb"); $p->query("SELECT count(*) FROM sqlite_dbpage"); exit($p->query("PRAGMA database_list")->fetch()["file"] === "" ? 0 : 1);'
if ! $CTR run --rm --entrypoint php "$IMG" -r "$MEMDB_PROBE" >/dev/null 2>&1; then
	IMG=nextcloud:34-fpm
fi
# Runs a php check in IMG, with the repository mounted read-only.
img_php() {
	$CTR run --rm --entrypoint php -v "$PWD:/app:ro" -w /app "$IMG" "${PHPFLAGS[@]}" "$@"
}

step "eslint"
npx eslint .

step "translations"
node scripts/l10n.mjs --check

step "phpstan"
composer lint

step "crypt15 ($IMG)"
img_php tests/crypt15_check.php

step "memdb ($IMG)"
img_php tests/memdb_check.php

step "media linker"
php "${PHPFLAGS[@]}" tests/media_linker_check.php

step "waha mapper"
php "${PHPFLAGS[@]}" tests/waha_mapper_check.php

step "sync resume"
php "${PHPFLAGS[@]}" tests/sync_resume_check.php

step "display text"
php "${PHPFLAGS[@]}" tests/display_text_check.php

step "reader ($IMG)"
img_php tests/reader_check.php

if [ "${SKIP_INTEGRATION:-0}" = 1 ]; then
	echo
	echo "integration test skipped (SKIP_INTEGRATION=1)"
else
	step "integration"
	./tests/nc_integration.sh
fi

echo
echo "ALL CHECKS OK"
