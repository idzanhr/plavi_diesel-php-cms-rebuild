<?php
// Creates the tables, loads the 129 seed stations (only into an empty table),
// creates THE admin account and generates the static public page.
//
//   php bin/install.php                      (interactive)
//   php bin/install.php --email=you@x.hr     (password from prompt or PLAVA_NAFTA_ADMIN_PASSWORD)
//   php bin/install.php --no-seed
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use PlavaNafta\{Auth, Db, Stations};

PlavaNafta\Config::appKey(); // fail early on a missing/invalid key
Db::migrate();
echo "Tablice su spremne (" . Db::driver() . ").\n";

$count = (int) Db::one('SELECT COUNT(*) AS n FROM stations')['n'];
if ($count === 0 && !opt('no-seed')) {
    [$created, , $errors] = Stations::import((string) file_get_contents(dirname(__DIR__) . '/sql/seed-stations.json'), 'seed.json');
    echo "Učitano postaja: $created\n";
    foreach ($errors as $e) fwrite(STDERR, "  $e\n");
} else {
    echo "Postaja u bazi: $count (seed preskočen)\n";
}

if (Auth::admin()) {
    echo "Administrator već postoji (" . Auth::admin()['email'] . "). Za promjenu: php bin/set-admin.php\n";
} else {
    $email = opt('email') ?: ask('E-mail administratora: ');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Neispravan e-mail.\n"); exit(1); }
    Auth::setAdmin($email, askPassword(), mustChange: false);
    echo "Administrator kreiran: $email\nPri prvoj prijavi na admin stranici postavit ćete obaveznu 2FA (QR kod).\n";
}

Stations::publish();
echo "Javna stranica generirana (public/index.html, public/data/stations.json).\n";
