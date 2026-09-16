#!/bin/sh
#
# Sync plugin files from this repo into a WordPress.org SVN working copy.
#
# Usage:
#   sync-with-svn.sh SVN_REPO_ROOT
#
# SVN_REPO_ROOT must contain trunk/ and tags/ (standard wordpress.org plugin layout).
# Allowed files match bin/make-zip.sh. trunk/ is updated via rsync, then copied to tags/{Version}/.

set -e

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
cd "${SCRIPT_DIR}/.." || exit 1

SVN_ROOT=$1

if [ -z "${SVN_ROOT}" ]; then
	echo "Usage: $0 SVN_REPO_ROOT" >&2
	echo "  SVN_REPO_ROOT: SVN checkout path (must contain trunk/ and tags/)" >&2
	exit 1
fi

if [ ! -d "${SVN_ROOT}" ]; then
	echo "SVN repo path does not exist: ${SVN_ROOT}" >&2
	exit 1
fi

TRUNK="${SVN_ROOT}/trunk"
TAGS="${SVN_ROOT}/tags"

if [ ! -d "${TRUNK}" ]; then
	echo "Missing trunk directory: ${TRUNK}" >&2
	exit 1
fi

if [ ! -d "${TAGS}" ]; then
	echo "Missing tags directory: ${TAGS}" >&2
	exit 1
fi

VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' parrotposter.php | head -1 | tr -d '\r')
if [ -z "${VERSION}" ]; then
	echo "Could not read plugin version from parrotposter.php" >&2
	exit 1
fi

TAG_DIR="${TAGS}/${VERSION}"

echo "Plugin version: ${VERSION}"
echo "Syncing files to ${TRUNK} ..."

rsync -a --delete \
	assets includes languages src views \
	index.php LICENSE parrotposter.php uninstall.php readme.txt \
	"${TRUNK}/"

echo "Copying trunk to ${TAG_DIR} ..."
rm -rf "${TAG_DIR}"
mkdir -p "${TAG_DIR}"
cp -a "${TRUNK}/." "${TAG_DIR}/"

echo "Done. Review with: svn status ${SVN_ROOT}"
