#!/usr/bin/env bash
# Build the app store tarball. The top folder in the tarball is chat_archive.
# This script does not sign. Signing needs the private key, which is not in the
# repository. The Release section of docs/DEVELOPER-GUIDE.md gives those steps.
#
#   ./scripts/pack.sh            writes build/chat_archive-<version>.tar.gz
#
# Run `npm ci && npm run build` first: js/ is built, not stored in git.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml | head -1)
[ -n "$VERSION" ] || { echo "no <version> in appinfo/info.xml" >&2; exit 1; }

# Everything the app needs at run time. src, tests, vendor and node_modules
# stay out.
CONTENT=(appinfo img js l10n lib templates CHANGELOG.md LICENSE README.md)
for path in "${CONTENT[@]}"; do
	[ -e "$path" ] || { echo "missing: $path (did you run npm run build?)" >&2; exit 1; }
done

OUT="build/chat_archive-$VERSION.tar.gz"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/chat_archive" build
cp -r "${CONTENT[@]}" "$STAGE/chat_archive/"
# source maps are for the browser debugger, not for the store
find "$STAGE/chat_archive" -name '*.map' -delete

tar -czf "$OUT" -C "$STAGE" chat_archive
echo "$OUT"
# sed reads all input: head would exit early and SIGPIPE tar under pipefail
tar -tzf "$OUT" | sed -n 1,5p
