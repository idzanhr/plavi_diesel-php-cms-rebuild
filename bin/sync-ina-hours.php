<?php
// Refreshes working hours from ina.hr. Spread over the day like the original job:
// each minute of one hour handles 1/60 of the stations (2-3 page fetches).
//
//   cron:  * 4 * * *  php /path/to/bin/sync-ina-hours.php        (minute = current minute)
//   once:  php bin/sync-ina-hours.php --all
//          php bin/sync-ina-hours.php --minute=7
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use PlavaNafta\InaHours;

$minute = opt('all') ? null : (int) (opt('minute') ?? date('i'));
$result = InaHours::sync($minute);
echo json_encode(['minute' => $minute] + $result) . "\n";
exit($result['failed'] > 0 && $result['updated'] === 0 && $result['unchanged'] === 0 ? 1 : 0);
