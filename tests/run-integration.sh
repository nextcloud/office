#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs the WOPI integration suite inside the dev container. Requires the
# `nextcloud` dev container (this app mounted at /var/www/html/apps-extra/office)
# to be running - see the plan's Environment facts for the docker topology.
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
# Example: tests/run-integration.sh --filter WopiProtocolTest
#          (runs against whichever class(es) --filter matches, each still in its own process per file)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

exit_code=0
for test_file in tests/integration/*Test.php; do
	class_name="$(basename "$test_file" .php)"
	echo "=== $class_name ==="
	if ! docker exec -u www-data -w /var/www/html/apps-extra/office nextcloud \
		php vendor/bin/phpunit "$test_file" -c tests/phpunit.integration.xml --colors=always "$@"; then
		exit_code=1
	fi
	echo
done

exit $exit_code
