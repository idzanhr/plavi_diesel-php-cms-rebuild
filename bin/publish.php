<?php
// Regenerates public/index.html and public/data/stations.json from the database.
declare(strict_types=1);
require __DIR__ . '/_cli.php';

PlavaNafta\Stations::publish();
echo "Objavljeno.\n";
