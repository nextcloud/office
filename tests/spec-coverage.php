<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Prints WOPI spec coverage per operation and overall, from tests/wopi-spec-matrix.php.
 * Usage: php tests/spec-coverage.php
 */

$rows = require __DIR__ . '/wopi-spec-matrix.php';

$byOp = [];
foreach ($rows as $row) {
	$byOp[$row['op']][] = $row;
}

$statusOrder = ['tested', 'implemented-untested', 'not-implemented', 'n-a'];
$statusLabel = ['tested' => 'tested', 'implemented-untested' => 'untested', 'not-implemented' => 'no-impl', 'n-a' => 'n/a'];

$colOp = 28;
$colNum = 10;

printf("%-{$colOp}s", 'Operation');
foreach ($statusOrder as $status) {
	printf("%{$colNum}s", $statusLabel[$status]);
}
printf("%{$colNum}s\n", 'total');
echo str_repeat('-', $colOp + $colNum * (count($statusOrder) + 1)) . "\n";

$totals = array_fill_keys($statusOrder, 0);

ksort($byOp);
foreach ($byOp as $op => $opRows) {
	$counts = array_fill_keys($statusOrder, 0);
	foreach ($opRows as $row) {
		$counts[$row['status']]++;
		$totals[$row['status']]++;
	}

	printf("%-{$colOp}s", $op);
	foreach ($statusOrder as $status) {
		printf("%{$colNum}s", $counts[$status] ?: '.');
	}
	printf("%{$colNum}s\n", count($opRows));
}

echo str_repeat('-', $colOp + $colNum * (count($statusOrder) + 1)) . "\n";
$totalRows = count($rows);
printf("%-{$colOp}s", 'TOTAL');
foreach ($statusOrder as $status) {
	printf("%{$colNum}s", $totals[$status]);
}
printf("%{$colNum}s\n", $totalRows);

echo "\n";
$tested = $totals['tested'];
$implementedUntested = $totals['implemented-untested'];
$notImplemented = $totals['not-implemented'];
$implemented = $tested + $implementedUntested;

printf("Rows in matrix:            %d\n", $totalRows);
printf("Implemented (tested + untested): %d (%.1f%%)\n", $implemented, $totalRows > 0 ? $implemented / $totalRows * 100 : 0);
printf("  of which tested:         %d (%.1f%% of implemented, %.1f%% of total)\n",
	$tested,
	$implemented > 0 ? $tested / $implemented * 100 : 0,
	$totalRows > 0 ? $tested / $totalRows * 100 : 0,
);
printf("Not implemented:           %d (%.1f%%)\n", $notImplemented, $totalRows > 0 ? $notImplemented / $totalRows * 100 : 0);

$dishonest = [];
foreach ($rows as $row) {
	if ($row['status'] === 'tested' && empty($row['test'])) {
		$dishonest[] = $row['id'];
	}
}
if ($dishonest !== []) {
	fwrite(STDERR, "\nERROR: rows marked 'tested' with no named test: " . implode(', ', $dishonest) . "\n");
	exit(1);
}

exit(0);
