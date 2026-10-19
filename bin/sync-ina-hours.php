<?php
// Refreshes working hours from ina.hr. Spread over the day like the original job:
// each minute of one hour handles 1/60 of the stations (2-3 page fetches).
//
//   cron:  * 4 * * *  php /path/to/bin/sync-ina-hours.php        (minute = current minute)
//   once:  php bin/sync-ina-hours.php --all [--verbose]
//          php bin/sync-ina-hours.php --minute=7
//   one:   php bin/sync-ina-hours.php --station="Murter" --verbose   (shows which ina.hr page was used, or why not)
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use PlavaNafta\InaHours;

$station = opt('station');
$minute = opt('all') || $station !== null ? null : (int) (opt('minute') ?? date('i'));
$log = opt('verbose') || $station !== null ? fn(string $line) => print("  $line\n") : null;
$result = InaHours::sync($minute, $station, $log);
if ($station !== null && $result['checked'] === 0) { fwrite(STDERR, "Postaja \"$station\" ne postoji.\n"); exit(1); }
echo json_encode(['minute' => $minute] + $result) . "\n";
exit($result['failed'] > 0 && $result['updated'] === 0 && $result['unchanged'] === 0 ? 1 : 0);
