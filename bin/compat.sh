#!/usr/bin/env bash
# Shared compatibility entrypoint for local and CI use.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHPCS_BIN="${ROOT}/vendor/bin/phpcs"
PHPCBF_BIN="${ROOT}/vendor/bin/phpcbf"
MATRIX_PHP="${ROOT}/bin/matrix.php"
METADATA_PHP="${ROOT}/bin/check-metadata.php"
FIXTURE="${ROOT}/tests/fixtures/below-floor-syntax.php"
PHPCOMPAT_STD="${ROOT}/phpcompat.xml.dist"
PHPCS_STD="${ROOT}/phpcs.xml.dist"

usage() {
	cat <<'EOF'
Usage: bin/compat.sh <command> [options]

Commands:
  static [--php=X.Y] [--self-test-fixture]
      PHPCompatibility at PHP_MIN-, optional php -l of plugin files,
      and optional below-floor fixture self-test.
  lint [--fix]
      PHPCS WordPress standard (or phpcbf with --fix).
  matrix
      Print version matrix JSON from readme.txt.
  metadata
      Check header consistency across readme, plugin, package.json.
  audit
      npm audit --omit=dev --audit-level=high

Environment:
  COMPAT_SELF_TEST_PHP  Below-floor PHP version for fixture expect-fail (e.g. 7.3)
  COMPAT_SELF_TEST_BIN  Optional path to a below-floor PHP binary
EOF
}

die() {
	echo "error: $*" >&2
	exit 1
}

require_phpcs() {
	if [[ ! -x "$PHPCS_BIN" ]]; then
		die "vendor/bin/phpcs not found. Run: composer install"
	fi
}

require_matrix() {
	if [[ ! -f "$MATRIX_PHP" ]]; then
		die "bin/matrix.php missing"
	fi
	command -v php >/dev/null 2>&1 || die "php CLI not found in PATH"
}

# Resolve matrix fields via bin/matrix.php.
matrix_json() {
	require_matrix
	php "$MATRIX_PHP"
}

matrix_field() {
	local key="$1"
	matrix_json | php -r '
		$j = json_decode(stream_get_contents(STDIN), true);
		if (!is_array($j)) { fwrite(STDERR, "invalid matrix JSON\n"); exit(1); }
		$key = $argv[1];
		if (!array_key_exists($key, $j)) { fwrite(STDERR, "missing matrix key: {$key}\n"); exit(1); }
		$v = $j[$key];
		if (is_array($v)) {
			echo json_encode($v);
		} else {
			echo $v;
		}
	' "$key"
}

# List plugin PHP files for php -l (exclude vendor, node_modules, fixtures, plans).
plugin_php_files() {
	find "$ROOT" -type f -name '*.php' \
		-not -path '*/vendor/*' \
		-not -path '*/node_modules/*' \
		-not -path '*/tests/fixtures/*' \
		-not -path '*/plans/*' \
		-not -path '*/.git/*' \
		-print | sort
}

run_php_lint_files() {
	local php_bin="${1:-php}"
	local fail=0
	local f
	echo "php -l via ${php_bin}"
	while IFS= read -r f; do
		if ! "$php_bin" -l "$f" >/dev/null; then
			echo "  FAIL: $f" >&2
			fail=1
		fi
	done < <(plugin_php_files)
	if [[ "$fail" -ne 0 ]]; then
		die "php -l reported syntax errors"
	fi
	echo "  OK: all plugin PHP files parse"
}

# Fixture must parse at PHP_MIN and above.
fixture_must_pass() {
	local php_bin="${1:-php}"
	[[ -f "$FIXTURE" ]] || die "fixture missing: $FIXTURE"
	echo "Fixture parse check (expect success) via ${php_bin}"
	if ! "$php_bin" -l "$FIXTURE"; then
		die "below-floor fixture failed to parse on ${php_bin}; fixture must pass at PHP_MIN+"
	fi
	echo "  OK: fixture parses"
}

# Fixture must fail to parse below the floor.
# Uses COMPAT_SELF_TEST_PHP or --self-test-fixture when a below-floor binary is available.
fixture_must_fail() {
	local php_bin="$1"
	[[ -f "$FIXTURE" ]] || die "fixture missing: $FIXTURE"
	echo "Fixture below-floor check (expect php -l failure) via ${php_bin}"
	if "$php_bin" -l "$FIXTURE" >/dev/null 2>&1; then
		die "fixture parsed on ${php_bin}; expected syntax failure below PHP floor"
	fi
	echo "  OK: fixture fails to parse below floor (as required)"
}

cmd_matrix() {
	require_matrix
	php "$MATRIX_PHP"
}

cmd_metadata() {
	command -v php >/dev/null 2>&1 || die "php CLI not found in PATH"
	[[ -f "$METADATA_PHP" ]] || die "bin/check-metadata.php missing"
	php "$METADATA_PHP"
}

cmd_audit() {
	command -v npm >/dev/null 2>&1 || die "npm not found in PATH"
	npm audit --omit=dev --audit-level=high
}

cmd_lint() {
	require_phpcs
	local fix=0
	local arg
	for arg in "$@"; do
		case "$arg" in
			--fix) fix=1 ;;
			-h|--help)
				usage
				exit 0
				;;
			*)
				die "unknown lint option: $arg"
				;;
		esac
	done
	if [[ "$fix" -eq 1 ]]; then
		[[ -x "$PHPCBF_BIN" ]] || die "vendor/bin/phpcbf not found. Run: composer install"
		echo "Running phpcbf --standard=phpcs.xml.dist"
		"$PHPCBF_BIN" --standard="$PHPCS_STD"
	else
		echo "Running phpcs --standard=phpcs.xml.dist"
		"$PHPCS_BIN" --standard="$PHPCS_STD"
	fi
}

cmd_static() {
	require_phpcs
	require_matrix
	[[ -f "$PHPCOMPAT_STD" ]] || die "phpcompat.xml.dist missing"

	local php_filter=""
	local self_test_fixture=0
	local arg

	for arg in "$@"; do
		case "$arg" in
			--php=*)
				php_filter="${arg#--php=}"
				;;
			--self-test-fixture)
				self_test_fixture=1
				;;
			-h|--help)
				usage
				exit 0
				;;
			*)
				die "unknown static option: $arg"
				;;
		esac
	done

	local php_min
	php_min="$(matrix_field php_min)"
	local php_below
	php_below="$(matrix_field php_below_floor)"

	echo "PHP_MIN=${php_min} (from readme.txt via bin/matrix.php)"

	if [[ -n "$php_filter" ]]; then
		echo "Limited messaging for --php=${php_filter}"
	fi

	echo "PHPCompatibilityWP testVersion ${php_min}-"
	"$PHPCS_BIN" --standard="$PHPCOMPAT_STD" --runtime-set testVersion "${php_min}-"

	# Syntax lint with the current php binary when available.
	if command -v php >/dev/null 2>&1; then
		local current_php
		current_php="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
		echo "Host PHP ${current_php}"

		if [[ -n "$php_filter" && "$php_filter" != "$current_php" ]]; then
			echo "Skipping host php -l (host ${current_php} != --php=${php_filter})"
		else
			run_php_lint_files php
			# At or above floor: fixture must parse on this interpreter.
			if php -r "exit(version_compare('${current_php}', '${php_min}', '>=') ? 0 : 1);"; then
				fixture_must_pass php
			else
				echo "Host PHP ${current_php} is below PHP_MIN ${php_min}; skipping fixture pass check"
			fi
		fi
	else
		echo "php not in PATH; skipping php -l"
	fi

	# Below-floor fixture expect-fail (COMPAT_SELF_TEST_PHP or --self-test-fixture).
	local self_php_ver="${COMPAT_SELF_TEST_PHP:-}"
	if [[ "$self_test_fixture" -eq 1 && -z "$self_php_ver" ]]; then
		self_php_ver="$php_below"
	fi

	if [[ -n "$self_php_ver" ]]; then
		local self_bin=""
		if command -v "php${self_php_ver}" >/dev/null 2>&1; then
			self_bin="php${self_php_ver}"
		elif command -v "php${self_php_ver/./}" >/dev/null 2>&1; then
			self_bin="php${self_php_ver/./}"
		elif [[ -n "${COMPAT_SELF_TEST_BIN:-}" && -x "${COMPAT_SELF_TEST_BIN}" ]]; then
			self_bin="${COMPAT_SELF_TEST_BIN}"
		fi

		if [[ -n "$self_bin" ]]; then
			fixture_must_fail "$self_bin"
		else
			echo "COMPAT_SELF_TEST_PHP=${self_php_ver}: no below-floor php binary on this host."
			echo "CI must run php -l on ${FIXTURE} with PHP ${self_php_ver} and expect failure."
			if [[ "$self_test_fixture" -eq 1 ]]; then
				die " --self-test-fixture requested but no PHP ${self_php_ver} binary found"
			fi
		fi
	fi

	echo "static checks passed"
}

main() {
	if [[ $# -lt 1 ]]; then
		usage
		exit 1
	fi

	local cmd="$1"
	shift || true

	case "$cmd" in
		static)   cmd_static "$@" ;;
		lint)     cmd_lint "$@" ;;
		matrix)   cmd_matrix "$@" ;;
		metadata) cmd_metadata "$@" ;;
		audit)    cmd_audit "$@" ;;
		-h|--help|help)
			usage
			exit 0
			;;
		*)
			usage
			die "unknown command: $cmd"
			;;
	esac
}

main "$@"
