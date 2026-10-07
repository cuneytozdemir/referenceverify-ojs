#!/bin/bash
# Builds the PKP Plugin Gallery release package from a git branch and prints its <release> element.
#   tools/release.sh main            # OJS 3.4 / 3.5 package
#   tools/release.sh stable-3_3_0    # OJS 3.3 package
# The package is a .tar.gz with a single "referenceVerify/" directory (the plugin's product name),
# built with `git archive` so only committed files ship; paths marked export-ignore in .gitattributes
# (tools/, .gitattributes) are left out. Never rebuild a published version: the Plugin Gallery pins its MD5.
set -euo pipefail
BRANCH="${1:?branch: main | stable-3_3_0}"
cd "$(dirname "$0")/.."
VERSION=$(git show "$BRANCH:version.xml" | sed -n 's:.*<release>\(.*\)</release>.*:\1:p')
DATE=$(git show "$BRANCH:version.xml" | sed -n 's:.*<date>\(.*\)</date>.*:\1:p')
TAG="v$(echo "$VERSION" | sed 's/\.\([0-9]*\)$/-\1/; s/\./_/g')"     # 1.2.0.1 -> v1_2_0-1 (PKP convention)
OUT="dist/referenceVerify-$TAG.tar.gz"
mkdir -p dist
git archive --format=tar --prefix=referenceVerify/ "$BRANCH" | gzip -n -9 > "$OUT"
MD5=$(md5sum "$OUT" | cut -d' ' -f1)
case "$BRANCH" in
  stable-3_3_0) COMPAT="			<version>~3.3.0.0</version>" ;;
  *)            COMPAT="			<version>~3.4.0.0</version>
			<version>~3.5.0.0</version>" ;;
esac
echo "package: $OUT  ($(du -h "$OUT" | cut -f1), md5 $MD5)"
echo "tag:     $TAG  (create a GitHub release with this tag and attach the package)"
echo
cat <<XML
	<release date="$DATE" version="$VERSION" md5="$MD5">
		<package>https://github.com/OWNER/referenceverify-ojs/releases/download/$TAG/referenceVerify-$TAG.tar.gz</package>
		<compatibility application="ojs2">
$COMPAT
		</compatibility>
		<description locale="en"><![CDATA[<p>See the changelog in README.md.</p>]]></description>
	</release>
XML
