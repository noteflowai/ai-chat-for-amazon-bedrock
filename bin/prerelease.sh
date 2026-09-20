#!/usr/bin/env bash
#
# Pre-release gate.
#
# Runs everything that must pass before a version is published, in the order that
# fails fastest. Nothing here talks to WordPress.org: publishing stays a separate,
# deliberate step.
#
# Usage:
#   bin/prerelease.sh [options]
#
#   --php PATH          PHP used for linting and the test suites.
#   --phpcs-php PATH    PHP used for phpcs, if the first one lacks the extensions
#                       phpcs needs (tokenizer, xmlwriter, SimpleXML).
#   --wp-cli CMD        Command that runs WP-CLI against a WordPress install, so the
#                       official Plugin Check can run too. Quote it if it has spaces.
#   --skip-phpcs        Skip the coding standards check.
#   --skip-plugin-check Skip the Plugin Check step.
#
# Run bin/setup-tools.sh first to install phpcs and the WordPress standards. Any check
# that cannot run says so rather than reporting a pass.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${AICFAB_PHP:-php}"
PHPCS_PHP="${AICFAB_PHPCS_PHP:-}"
WP_CLI="${AICFAB_WP_CLI:-}"
RUN_PHPCS=1
RUN_PLUGIN_CHECK=1
FAILED=0

while [ $# -gt 0 ]; do
	case "$1" in
		--php) PHP_BIN="$2"; shift 2 ;;
		--phpcs-php) PHPCS_PHP="$2"; shift 2 ;;
		--wp-cli) WP_CLI="$2"; shift 2 ;;
		--skip-phpcs) RUN_PHPCS=0; shift ;;
		--skip-plugin-check) RUN_PLUGIN_CHECK=0; shift ;;
		*) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
	esac
done

# Wiring written by bin/setup-tools.sh.
if [ -f "$PLUGIN_DIR/.tools/phpcs-paths.env" ]; then
	# shellcheck disable=SC1091
	. "$PLUGIN_DIR/.tools/phpcs-paths.env"
fi
[ -n "$PHPCS_PHP" ] || PHPCS_PHP="$PHP_BIN"

step() { printf '\n== %s ==\n' "$1"; }
ok() { printf '  ok    %s\n' "$1"; }
bad() { printf '  FAIL  %s\n' "$1"; FAILED=1; }
skip() { printf '  skip  %s\n' "$1"; }

cd "$PLUGIN_DIR" || exit 2

step "PHP available"
if ! "$PHP_BIN" -v >/dev/null 2>&1; then
	printf 'No usable PHP at %s. Pass --php /path/to/php.\n' "$PHP_BIN" >&2
	exit 2
fi
ok "$("$PHP_BIN" -v | head -1)"

step "Syntax"
LINT_ERRORS="$(find . -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l 2>&1 | grep -v 'No syntax errors' || true)"
if [ -n "$LINT_ERRORS" ]; then
	printf '%s\n' "$LINT_ERRORS"
	bad 'php -l reported errors'
else
	ok 'every PHP file parses'
fi

step "Version consistency"
HEADER_VERSION="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9.]+' ai-chat-for-amazon-bedrock.php || true)"
RUNTIME_VERSION="$(grep -m1 -oP "AI_CHAT_BEDROCK_VERSION',\s*'\K[0-9.]+" ai-chat-for-amazon-bedrock.php || true)"
STABLE_TAG="$(grep -m1 -oP '^Stable tag:\s*\K[0-9.]+' readme.txt || true)"
printf '  header=%s runtime=%s stable=%s\n' "$HEADER_VERSION" "$RUNTIME_VERSION" "$STABLE_TAG"
if [ -n "$HEADER_VERSION" ] && [ "$HEADER_VERSION" = "$RUNTIME_VERSION" ] && [ "$HEADER_VERSION" = "$STABLE_TAG" ]; then
	ok 'all three agree'
else
	bad 'version mismatch between plugin header, runtime constant and readme'
fi

if grep -q "^= ${HEADER_VERSION} =$" readme.txt; then
	ok 'changelog documents this version'
else
	bad "readme changelog has no entry for ${HEADER_VERSION}"
fi

step "Readme limits"
"$PHP_BIN" -r '
$readme = file_get_contents( "readme.txt" );

function aicfab_section( $readme, $heading, $next_heading = null ) {
	$start = strpos( $readme, $heading );
	if ( false === $start ) {
		fwrite( STDERR, "  missing readme section: " . $heading . "\n" );
		exit( 1 );
	}
	if ( null === $next_heading ) {
		return substr( $readme, $start );
	}
	$end = strpos( $readme, $next_heading );
	if ( false === $end || $end < $start ) {
		fwrite( STDERR, "  readme sections are out of order around " . $heading . "\n" );
		exit( 1 );
	}
	return substr( $readme, $start, $end - $start );
}

function aicfab_words( $text ) {
	$text = trim( (string) $text );
	return "" === $text ? 0 : count( preg_split( "/\s+/", $text ) );
}

// The directory counts the description together with the privacy section.
$description = aicfab_section( $readme, "== Description ==", "== Installation ==" );
$privacy     = aicfab_section( $readme, "== Privacy Policy ==" );
$words       = aicfab_words( $description ) + aicfab_words( $privacy );
printf( "  description+privacy words=%d limit=2500\n", $words );
if ( $words >= 2500 ) {
	fwrite( STDERR, "  description is over the directory limit\n" );
	exit( 1 );
}

$short = "";
foreach ( explode( "\n", $readme ) as $line ) {
	$line = trim( $line );
	if ( "" === $line || 0 === strpos( $line, "=" ) || false !== strpos( $line, ":" ) ) {
		continue;
	}
	$short = $line;
	break;
}
printf( "  short description chars=%d limit=150\n", strlen( $short ) );
if ( strlen( $short ) > 150 ) {
	fwrite( STDERR, "  short description is too long\n" );
	exit( 1 );
}

$notice_section = aicfab_section( $readme, "== Upgrade Notice ==", "== Privacy Policy ==" );
preg_match_all( "/= ([0-9.]+) =\n(.*?)(?=\n= |\z)/s", $notice_section, $notices, PREG_SET_ORDER );
$over = array();
foreach ( $notices as $notice ) {
	if ( strlen( trim( $notice[2] ) ) > 300 ) {
		$over[] = $notice[1];
	}
}
printf( "  upgrade notices checked=%d over 300 chars: %s\n", count( $notices ), $over ? implode( ", ", $over ) : "none" );
exit( $over ? 1 : 0 );
' && ok 'readme within directory limits' || bad 'readme limits exceeded'

step "Test suites"
SUITES=0
for suite in tests/*.php; do
	[ -f "$suite" ] || continue
	SUITES=$((SUITES + 1))
	# Marked so a suite that drives this script can recognise the situation and skip,
	# instead of running the gate inside the gate.
	if OUTPUT="$( AICFAB_GATE_SELFTEST=1 "$PHP_BIN" "$suite" 2>&1 )"; then
		case "$OUTPUT" in
			SKIP:*) skip "$(basename "$suite"): ${OUTPUT#SKIP: }" ;;
			*) ok "$(basename "$suite")" ;;
		esac
	else
		printf '%s\n' "$OUTPUT" | head -8
		bad "$(basename "$suite")"
	fi
done
printf '  %d suites run\n' "$SUITES"

step "Coding standards"
PHPCS_BIN="${AICFAB_PHPCS:-}"
if [ "$RUN_PHPCS" -eq 0 ]; then
	skip 'phpcs skipped by request'
elif [ -z "$PHPCS_BIN" ] || [ ! -f "$PLUGIN_DIR/$PHPCS_BIN" ]; then
	skip 'phpcs not installed; run bin/setup-tools.sh'
elif ! "$PHPCS_PHP" -m 2>/dev/null | grep -qi '^tokenizer$'; then
	skip "phpcs needs tokenizer, xmlwriter and SimpleXML; pass --phpcs-php with a PHP that has them"
else
	# phpcs resolves installed_paths against its own configuration, not the working
	# directory, so they have to be absolute. Ask the runtime where it thinks it is:
	# with a containerised PHP that is not the host path.
	RUNTIME_DIR="$( cd "$PLUGIN_DIR" && "$PHPCS_PHP" -r 'echo getcwd();' 2>/dev/null )"
	if [ -z "$RUNTIME_DIR" ]; then
		RUNTIME_DIR="$PLUGIN_DIR"
	fi
	PHPCS_PATHS="$(printf '%s' "${AICFAB_PHPCS_INSTALLED_PATHS:-}" | awk -v dir="$RUNTIME_DIR" -F, '{
		out = "";
		for ( i = 1; i <= NF; i++ ) {
			out = out ( i > 1 ? "," : "" ) dir "/" $i;
		}
		print out;
	}')"

	PHPCS_JSON="$( cd "$PLUGIN_DIR" && "$PHPCS_PHP" -d memory_limit=512M "$PHPCS_BIN" -q --no-colors --report=json \
		--runtime-set installed_paths "$PHPCS_PATHS" \
		--standard=phpcs.xml.dist . 2>/dev/null )"
	PHPCS_STATUS=$?

	PHPCS_SUMMARY="$(printf '%s' "$PHPCS_JSON" | python3 -c '
import json, sys

raw = sys.stdin.read().strip()
if not raw:
    print("unreadable no output")
    raise SystemExit
try:
    report = json.loads(raw)
except ValueError:
    print("unreadable not json")
    raise SystemExit

totals = report.get("totals", {})
files = report.get("files", {})
print("%d %d %d" % (totals.get("errors", 0), totals.get("warnings", 0), len(files)))
' 2>/dev/null)"

	set -- $PHPCS_SUMMARY
	if [ "${1:-unreadable}" = "unreadable" ]; then
		printf '  phpcs produced no usable report (exit %s)\n' "$PHPCS_STATUS"
		bad 'phpcs could not run'
	elif [ "${3:-0}" -lt 20 ]; then
		printf '  phpcs only looked at %s files, which means it did not scan the plugin\n' "${3:-0}"
		bad 'phpcs scanned too few files to be meaningful'
	elif [ "$1" -gt 0 ] || [ "$2" -gt 0 ]; then
		printf '  errors=%s warnings=%s across %s files\n' "$1" "$2" "$3"
		printf '%s' "$PHPCS_JSON" | python3 -c '
import json, sys
report = json.loads(sys.stdin.read())
shown = 0
for path, info in report.get("files", {}).items():
    for message in info.get("messages", []):
        print("    %s:%s %s" % (path.split("/")[-1], message["line"], message["source"]))
        shown += 1
        if shown >= 8:
            raise SystemExit
' 2>/dev/null
		bad 'phpcs reported findings'
	else
		ok "phpcs clean across $3 files"
	fi
fi

step "Plugin Check"
if [ "$RUN_PLUGIN_CHECK" -eq 0 ]; then
	skip 'plugin check skipped by request'
elif [ -z "$WP_CLI" ]; then
	skip 'no --wp-cli given, so the official Plugin Check did not run'
else
	if ! $WP_CLI plugin is-installed plugin-check >/dev/null 2>&1; then
		printf '  installing plugin-check\n'
		$WP_CLI plugin install plugin-check --activate >/dev/null 2>&1
	else
		$WP_CLI plugin activate plugin-check >/dev/null 2>&1
	fi

	PC_OUTPUT="$($WP_CLI plugin check ai-chat-for-amazon-bedrock \
		--exclude-directories=tests,bin,.tools,dist --format=csv --fields=type,code 2>/dev/null)"
	PC_ERRORS="$(printf '%s' "$PC_OUTPUT" | grep -c '^ERROR,' || true)"
	PC_WARNINGS="$(printf '%s' "$PC_OUTPUT" | grep -c '^WARNING,' || true)"
	printf '  errors=%s warnings=%s\n' "$PC_ERRORS" "$PC_WARNINGS"

	# Findings that only concern files this project never ships.
	PC_REAL="$(printf '%s' "$PC_OUTPUT" | grep '^ERROR,' | grep -vE 'hidden_files|application_detected' || true)"
	if [ -n "$PC_REAL" ]; then
		printf '%s\n' "$PC_REAL" | head -10
		bad 'plugin check reported errors in shipped code'
	else
		ok 'plugin check clean for shipped code'
	fi
fi

step "Live integration"
# The suites call this plugin's own methods, so they cannot see whether the hook a method is
# attached to is one WordPress actually fires. That is not hypothetical: the Abilities API
# integration registered on a hook name WordPress does not have, every suite passed, and the
# feature was inert on every install. This step asks a real WordPress instead.
if [ -z "$WP_CLI" ]; then
	skip 'no --wp-cli given, so the integration points were not verified against WordPress'
else
	if ! $WP_CLI plugin is-active ai-chat-for-amazon-bedrock >/dev/null 2>&1; then
		$WP_CLI plugin activate ai-chat-for-amazon-bedrock >/dev/null 2>&1
	fi
	# The WP-CLI command may be a plain wp on this filesystem, or a wrapper whose view of the
	# filesystem differs from ours, so the script is offered by each path that could reach it
	# rather than assuming one. Stdin is not used: a wrapper need not forward it, and a step that
	# cannot be exercised locally is a step nobody trusts.
	LIVE_OUTPUT=''
	for LIVE_PATH in \
		"wp-content/plugins/ai-chat-for-amazon-bedrock/bin/live-integration.php" \
		"$PLUGIN_DIR/bin/live-integration.php"
	do
		LIVE_TRY="$($WP_CLI eval-file "$LIVE_PATH" 2>&1)"
		if ! printf '%s' "$LIVE_TRY" | grep -q 'does not exist'; then
			LIVE_OUTPUT="$LIVE_TRY"
			break
		fi
	done
	if [ -z "$LIVE_OUTPUT" ]; then
		LIVE_OUTPUT='Error: could not reach live-integration.php from the WP-CLI process'
	fi
	if printf '%s' "$LIVE_OUTPUT" | grep -q '^OK:'; then
		printf '%s\n' "$LIVE_OUTPUT" | grep -E '^\s+(ok|FAIL|skip)' | head -20
		ok "$(printf '%s' "$LIVE_OUTPUT" | grep '^OK:' | sed 's/^OK: //')"
	else
		printf '%s\n' "$LIVE_OUTPUT" | head -20
		bad 'the plugin does not attach to WordPress where it says it does'
	fi
fi

step "Package contents"
if command -v python3 >/dev/null 2>&1; then
	if PACKAGE_OUTPUT="$(python3 "$PLUGIN_DIR/bin/build-package.py" --list 2>&1 >/dev/null)"; then
		printf '  %s\n' "$PACKAGE_OUTPUT"
		ok 'the shipping file list resolves from .distignore'
	else
		printf '%s\n' "$PACKAGE_OUTPUT" | head -10
		bad 'the package would contain something it should not'
	fi
else
	skip 'python3 not available, so the package contents were not audited'
fi

step "Result"
if [ "$FAILED" -eq 0 ]; then
	printf '  Ready to package and publish %s.\n' "$HEADER_VERSION"
	exit 0
fi
printf '  Fix the failures above before publishing.\n'
exit 1
