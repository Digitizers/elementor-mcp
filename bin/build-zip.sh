#!/usr/bin/env bash
#
# Build an installable plugin ZIP from the current working tree.
#
# Why this exists: releases carried no asset, so both the updater
# (`enableReleaseAssets( '/elementor-mcp.*\.zip/i' )`) and Digitizer Pro Tools'
# onboarding installer fell back to GitHub's source archive — and every client
# site ended up with the test suite, the CI config and the internal docs sitting
# in wp-content/plugins. See FIELD-REPORT-6.
#
# The plugin lives at the repository root, so files are staged into an
# elementor-mcp/ directory first: WordPress requires the archive to contain
# exactly one folder named after the plugin, and PUC matches the asset by that
# name.
#
# Usage:
#   bin/build-zip.sh                    # -> dist/elementor-mcp.zip
#   bin/build-zip.sh my-name.zip        # -> dist/my-name.zip
#
set -euo pipefail

SLUG="elementor-mcp"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_NAME="${1:-$SLUG.zip}"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Ship only what git tracks: no stray local files (the FIELD-REPORT-*.md drafts
# are untracked and stay that way), no dist/, no vendor/ — the MCP adapter is
# vendored under includes/ on purpose and Composer's autoload is deliberately
# not shipped (see class-mcp-adapter-bootstrap.php).
#
# Development-only paths are skipped by the case below — filtered in bash rather
# than with `grep -z`, whose --null-data flag is a GNU extension missing from
# older macOS and busybox builds.
#
# What is NOT excluded, and why:
#   prompts/   — the admin Prompts page reads ELEMENTOR_MCP_DIR . 'prompts/'
#                at runtime and counts the files (includes/admin/class-admin.php)
#   bin/       — mcp-proxy.mjs is the client-side connector an operator runs;
#                this script is the only build-time file here and excludes itself
#   assets/angie-bridge/dist/ — enqueued by class-angie-bridge.php
cd "$ROOT"
mkdir -p "$STAGE/$SLUG"
while IFS= read -r -d '' f; do
	case "$f" in
		.github/*|.claude/*|dist/*|tests/*|tools/*) continue ;;
		.gitignore|.mcp.json|phpunit.xml|phpunit.xml.dist|composer.json|composer.lock) continue ;;
		CLAUDE.md|CONTRIBUTING.md|FIELD-*.md|*.code-workspace) continue ;;
		bin/build-zip.sh) continue ;;
		# Build inputs for the Angie bridge bundle; the built dist/ file ships.
		assets/angie-bridge/src/*|assets/angie-bridge/package*.json) continue ;;
		assets/angie-bridge/tsconfig.json|assets/angie-bridge/vite.config.ts|assets/angie-bridge/.gitignore) continue ;;
	esac
	mkdir -p "$STAGE/$SLUG/$(dirname "$f")"
	cp "$f" "$STAGE/$SLUG/$f"
done < <( git ls-files -z )

# A build that lost the plugin's own entry point would still zip cleanly and
# install as a directory WordPress ignores, so check the things that make it a
# plugin rather than a folder.
for required in "elementor-mcp.php" "readme.txt" "includes/class-plugin.php" "assets/angie-bridge/dist/angie-bridge.js"; do
	if [[ ! -f "$STAGE/$SLUG/$required" ]]; then
		echo "build aborted: $required missing from the staged tree" >&2
		exit 1
	fi
done

# The exclusion list above is a claim about which files the plugin never reads
# at runtime, and nothing else checks it: a wrong entry produces a ZIP that
# builds, installs, and fatals on the site instead of here. prompts/ was one
# such near-miss — the admin page loads it by path. So derive the claim's
# counter-evidence from the source: every path the code names through
# elementor_mcp_require() or ELEMENTOR_MCP_DIR must exist in the staged tree.
missing=0
while IFS= read -r ref; do
	[[ -z "$ref" ]] && continue
	if [[ ! -e "$STAGE/$SLUG/$ref" ]]; then
		echo "build aborted: the code loads $ref, which the exclusion list dropped" >&2
		missing=1
	fi
done < <(
	grep -rhoE "elementor_mcp_require\(\s*'[^']+'|ELEMENTOR_MCP_DIR \. '[^']+'" \
		--include='*.php' "$ROOT/includes" "$ROOT/elementor-mcp.php" |
		sed -E "s/.*'([^']+)'.*/\1/" | sort -u
)
[[ "$missing" -eq 0 ]] || exit 1

mkdir -p "$DIST"
rm -f "$DIST/$OUT_NAME"
( cd "$STAGE" && zip -rq "$DIST/$OUT_NAME" "$SLUG" -x '*.DS_Store' )

VERSION="$(grep -m1 "^ \* Version:" "$ROOT/$SLUG.php" | tr -d ' ' | cut -d: -f2)"
echo "built  : dist/$OUT_NAME"
echo "version: $VERSION"
echo "files  : $(unzip -l "$DIST/$OUT_NAME" | tail -1 | awk '{print $2}')"
echo "size   : $(du -h "$DIST/$OUT_NAME" | cut -f1)"
