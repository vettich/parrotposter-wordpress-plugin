#!/usr/bin/env bash
# Layer A: syntax + existing CLI smokes on php:7.4-cli and php:8.5-cli (no WordPress).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

run_php_cell() {
	local ver="$1"
	echo "=== PHP ${ver}: php -l + bin/test-*.php ==="
	docker run --rm -v "$ROOT":/app -w /app "php:${ver}-cli" sh -c '
		set -e
		for f in parrotposter.php uninstall.php $(find src views includes bin -name "*.php"); do
			php -l "$f" >/dev/null
		done
		echo "php -l OK"
		for f in bin/test-*.php; do
			php "$f"
		done
	'
}

run_php_cell 7.4
run_php_cell 8.5
echo "=== compat-php OK ==="
