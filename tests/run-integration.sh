#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs the WOPI integration suite against a running Nextcloud with this app
# enabled. Runs here by default (CI, or a shell inside the server container).
# Environment:
#   OFFICE_IT_CONTAINER   run inside this container instead, with the app at
#                         /var/www/html/apps-extra/office (the dev setup: nextcloud)
#   OFFICE_TEST_BASE_URL  the URL the server answers on (default http://localhost)
#   NEXTCLOUD_ROOT        the server checkout (default /var/www/html)
#
# Runs each *Test.php class as its OWN phpunit process rather than one
# combined run. Verified by reproduction: running the full suite in a single
# process works fine through the first two or three classes, then every
# subsequent class's IntegrationTestCase::createTestFile() starts throwing
# OCP\Files\NotPermittedException ("Could not create path") for the rest of
# the run - while every one of those same classes passes cleanly when run
# alone. Each class's setUpBeforeClass()/tearDownAfterClass() creates and
# deletes a same-named test user (wopi_it_user); most likely a NC
# LazyFolder/SetupManager mount-cache entry (or a SQLite-specific caching
# quirk) doesn't get invalidated across a same-process delete+recreate of the
# same uid, corrupting filesystem resolution for the rest of that process.
# One process per class sidesteps it entirely at a small time cost.
#
# Usage: tests/run-integration.sh [phpunit args...]
# Example: OFFICE_IT_CONTAINER=nextcloud tests/run-integration.sh --filter WopiProtocolTest
#          (runs against whichever class(es) --filter matches, each still in its own process per file)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

phpunit() {
	if [ -z "${OFFICE_IT_CONTAINER:-}" ]; then
		php vendor/bin/phpunit "$@"
		return
	fi

	local env=()
	for name in OFFICE_TEST_BASE_URL NEXTCLOUD_ROOT; do
		if [ -n "${!name:-}" ]; then
			env+=(-e "$name=${!name}")
		fi
	done
	docker exec -u www-data -w /var/www/html/apps-extra/office ${env[@]+"${env[@]}"} "$OFFICE_IT_CONTAINER" \
		php vendor/bin/phpunit "$@"
}

exit_code=0
for test_file in tests/integration/*Test.php; do
	class_name="$(basename "$test_file" .php)"
	echo "=== $class_name ==="
	if ! phpunit "$test_file" -c tests/phpunit.integration.xml --colors=always "$@"; then
		exit_code=1
	fi
	echo
done

exit $exit_code
