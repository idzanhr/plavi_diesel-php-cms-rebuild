<?php
// Dependency-free test runner:  php tests/run.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('PLAVA_NAFTA_CONFIG=' . __DIR__ . '/config.test.php');
require dirname(__DIR__) . '/app/bootstrap.php';

use PlavaNafta\{Crypto, Db, HttpError, InaHours, Render, Stations, Totp};

$failures = 0;
$count = 0;
function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    try { $fn(); echo "  ok  $name\n"; }
    catch (\Throwable $e) { $failures++; echo "  FAIL $name\n       " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
}
function eq(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) throw new \RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function throws(callable $fn): void
{
    try { $fn(); } catch (\Throwable) { return; }
    throw new \RuntimeException('expected an exception');
}

echo "TOTP\n";
$rfcSecret = Totp::base32Encode('12345678901234567890');
test('RFC 6238 SHA-1 test vectors (6-digit truncation)', function () use ($rfcSecret) {
    foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
        eq($code, Totp::code($rfcSecret, Totp::step($t)));
    }
});
test('base32 round trip', function () {
    $raw = random_bytes(20);
    eq($raw, Totp::base32Decode(Totp::base32Encode($raw)));
    eq('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode('12345678901234567890'));
});
test('accepts ±1 step of clock drift, rejects ±2', function () use ($rfcSecret) {
    $t = 1111111109;
    $s = Totp::step($t);
    eq($s - 1, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s - 1), 0, $t));
    eq($s + 1, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s + 1), 0, $t));
    eq(null, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s - 2), 0, $t));
    eq(null, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s + 2), 0, $t));
});
test('a used code cannot be replayed', function () use ($rfcSecret) {
    $t = 1111111109;
    $code = Totp::code($rfcSecret, Totp::step($t));
    $step = Totp::verify($rfcSecret, $code, 0, $t);
    eq(Totp::step($t), $step);
    eq(null, Totp::verify($rfcSecret, $code, $step, $t));
});
test('rejects malformed codes', function () use ($rfcSecret) {
    eq(null, Totp::verify($rfcSecret, '12345', 0));
    eq(null, Totp::verify($rfcSecret, 'abcdef', 0));
    eq(null, Totp::verify($rfcSecret, '', 0));
});
test('otpauth URI is what authenticator apps expect', function () {
    eq('otpauth://totp/Plava%20nafta:a%40b.hr?secret=ABC&issuer=Plava%20nafta&algorithm=SHA1&digits=6&period=30', Totp::uri('ABC', 'a@b.hr', 'Plava nafta'));
});

echo "Crypto\n";
test('AES-256-GCM round trip and tamper detection', function () {
    $key = random_bytes(32);
    $c = Crypto::encrypt('JBSWY3DPEHPK3PXP', $key);
    eq('JBSWY3DPEHPK3PXP', Crypto::decrypt($c, $key));
    throws(fn() => Crypto::decrypt($c, random_bytes(32)));
    $raw = base64_decode(substr($c, 3));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    throws(fn() => Crypto::decrypt('v1:' . base64_encode($raw), $key));
});

echo "INA working hours (ported from the original vitest suite)\n";
$fixture = '<table class="station__info__tablebox__table"><tr><th>Radno vrijeme</th><th>Radni dan</th><th>Subota</th><th>Nedjelja</th><th>Blagdani</th></tr><tr><td>Redovno</td><td>06-20</td><td>06-20</td><td>NE RADI</td><td>25.12.2024. NE RADI<br>26.12.2024. 06-20</td></tr></table>';
test('preserves weekday, Saturday, Sunday and holiday values', function () use ($fixture) {
    eq([['label' => 'Redovno', 'week' => '06-20', 'sat' => '06-20', 'sun' => 'NE RADI', 'hol' => '25.12.2024. NE RADI 26.12.2024. 06-20']], InaHours::parseHours($fixture));
});
test('keeps seasonal and temporary schedules', function () use ($fixture) {
    $extra = str_replace('</table>', '<tr><td>Sezonsko</td><td>07-22</td><td>07-22</td><td>08-16</td><td></td></tr><tr><td>Privremeno</td><td>08-20</td><td>08-20</td><td>NE RADI</td><td></td></tr></table>', $fixture);
    eq(['Redovno', 'Sezonsko', 'Privremeno'], array_column(InaHours::parseHours($extra), 'label'));
});
test('rejects missing or incomplete hours rather than erasing saved hours', function () use ($fixture) {
    throws(fn() => InaHours::parseHours('<html>Unavailable</html>'));
    throws(fn() => InaHours::parseHours(preg_replace('/<td>06-20<\/td>/', '<td></td>', $fixture, 1)));
});
test('covers all 129 stations exactly once daily in batches of at most three', function () {
    $rows = range(0, 128);
    $all = [];
    $max = 0;
    for ($m = 0; $m < 60; $m++) { $b = InaHours::dailyBatch($rows, $m); $all = array_merge($all, $b); $max = max($max, count($b)); }
    sort($all);
    eq($rows, $all);
    eq(3, $max);
});
test('accepts only INA station pages', function () {
    eq('https://www.ina.hr/station/babina-greda-2/', InaHours::safeUrl('https://www.ina.hr/station/babina-greda-2/'));
    eq(null, InaHours::safeUrl('https://example.com/station/test/'));
    eq(null, InaHours::safeUrl('https://www.ina.hr@localhost/station/test/'));
    eq(null, InaHours::safeUrl('http://www.ina.hr/station/test/'));
    eq(null, InaHours::safeUrl('https://www.ina.hr/station/test/?x=1'));
});
test('reads station links from the public finder', function () {
    eq([['url' => 'https://www.ina.hr/station/babina-greda-2/', 'phones' => ['0914971292']]],
        InaHours::parseDirectory('<div id="mdata" stations=\'[{"url":"https://www.ina.hr/station/babina-greda-2/","contact_phone":"+385 91 4971 292"}]\'></div>'));
});

echo "Database-backed\n";
Db::migrate();
Db::exec("INSERT INTO stations (name, address, phone, aliases, county, city, postal_code, ina_region, sales_area, ina_url, working_hours, updated_at) VALUES ('Babina Greda', 'Josipa Blažekovića 2/a', '091 497 1292', '', 'Vukovarsko-srijemska županija', '', '', '', '', 'https://www.ina.hr/station/babina-greda-2/', ?, ?)",
    [json_encode(InaHours::parseHours($fixture)), Db::now()]);

test('sync does not write anything when INA returns an invalid page', function () {
    $before = Db::one('SELECT working_hours, updated_at FROM stations');
    InaHours::$fetch = fn(string $url) => 'Unavailable';
    $r = InaHours::sync(0);
    eq(1, $r['failed']); eq(0, $r['updated']);
    eq($before, Db::one('SELECT working_hours, updated_at FROM stations'));
});
test('sync leaves identical saved hours untouched regardless of JSON key order', function () use ($fixture) {
    Db::exec('UPDATE stations SET working_hours = ?', [json_encode([['hol' => '25.12.2024. NE RADI 26.12.2024. 06-20', 'sun' => 'NE RADI', 'sat' => '06-20', 'week' => '06-20', 'label' => 'Redovno']])]);
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'Unavailable' : $fixture;
    $r = InaHours::sync(0);
    eq(1, $r['unchanged']); eq(0, $r['updated']); eq(0, $r['failed']);
});

test('validation: required name, known county, INA-only links, alias normalisation', function () {
    [$row, $err] = Stations::validate(['name' => '  Test  ', 'aliases' => ' a /b// a ', 'county' => 'Grad Zagreb']);
    eq([], $err); eq('Test', $row['name']); eq('a / b', $row['aliases']);
    eq(true, count(Stations::validate(['name' => ''])[1]) > 0);
    eq(true, count(Stations::validate(['name' => 'x', 'county' => 'Mordor'])[1]) > 0);
    eq(true, count(Stations::validate(['name' => 'x', 'ina_url' => 'javascript:alert(1)'])[1]) > 0);
    eq(true, count(Stations::validate(['name' => ['x']])[1]) > 0);
});
test('save rejects duplicate names; import upserts by name', function () {
    throws(fn() => Stations::save(null, ['name' => 'Babina Greda']));
    [$c, $u, $e] = Stations::import(json_encode([['name' => 'Babina Greda', 'aliases' => 'Greda'], ['name' => 'Nova', 'county' => 'Istarska županija']]), 'x.json');
    eq([1, 1, []], [$c, $u, $e]);
    $bg = Db::one("SELECT * FROM stations WHERE name = 'Babina Greda'");
    eq('Greda', $bg['aliases']);
    eq('091 497 1292', $bg['phone']); // absent fields stay
});
test('CSV import (semicolon, BOM) and export round trip', function () {
    $csv = Stations::toCsv(Stations::all());
    [$c, $u, $e] = Stations::import($csv, 'x.csv');
    eq([0, 2, []], [$c, $u, $e]);
});
test('map link searches INA + name + address on Google Maps, escaped', function () {
    eq('https://www.google.com/maps/search/?api=1&query=INA%20Barban%2C%20Barban%20106%2FD%2C%2052207%20Barban%2C%20Hrvatska',
        Render::mapUrl(['name' => 'Barban', 'address' => 'Barban 106/D', 'postal_code' => '52207', 'city' => 'Barban']));
    eq('https://www.google.com/maps/search/?api=1&query=INA%20%C4%8Cazma%2C%20Hrvatska', Render::mapUrl(['name' => 'Čazma', 'address' => '', 'postal_code' => '', 'city' => '']));
    $html = Render::card(['name' => 'A"<b>', 'address' => 'x', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '', 'aliases' => '', 'ina_url' => '', 'working_hours' => []]);
    eq(true, str_contains($html, 'target="_blank" rel="noopener noreferrer" aria-label="Karta: A&quot;&lt;b&gt; (Google Karte, nova kartica)">Karta ↗</a>'));
});
test('public page escapes station data', function () {
    $html = Render::card(['name' => '<script>x</script>', 'address' => '"a"', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '1', 'aliases' => '', 'ina_url' => '', 'working_hours' => []]);
    eq(false, str_contains($html, '<script>'));
    eq(true, str_contains($html, '&lt;script&gt;'));
});

echo "Temporary closures\n";
test('migrate() adds the new columns to an existing database and is re-runnable', function () {
    Db::migrate();
    Db::migrate();
    $cols = array_column(Db::all('PRAGMA table_info(stations)'), 'name');
    foreach (['status', 'status_note', 'closed_from', 'closed_until', 'ina_notice'] as $c) eq(true, in_array($c, $cols, true));
    eq('open', Db::one("SELECT status FROM stations WHERE name = 'Babina Greda'")['status']);
});
test('validation: status, real dates, until not before from; reopening clears the closure', function () {
    [$row, $err] = Stations::validate(['name' => 'x', 'status' => 'closed', 'status_note' => 'Radovi', 'closed_from' => '2026-11-03', 'closed_until' => '2026-11-10']);
    eq([], $err); eq('closed', $row['status']); eq('Radovi', $row['status_note']);
    eq(true, count(Stations::validate(['name' => 'x', 'status' => 'gone'])[1]) > 0);
    eq(true, count(Stations::validate(['name' => 'x', 'status' => 'closed', 'closed_until' => '2026-02-30'])[1]) > 0);
    eq(true, count(Stations::validate(['name' => 'x', 'status' => 'closed', 'closed_from' => '2026-11-10', 'closed_until' => '2026-11-03'])[1]) > 0);
    [$row] = Stations::validate(['name' => 'x', 'status' => 'open', 'status_note' => 'Radovi', 'closed_until' => '2026-11-10']);
    eq(['open', '', ''], [$row['status'], $row['status_note'], $row['closed_until']]);
    eq('open', Stations::validate(['name' => 'x'])[0]['status']); // rows from old imports
});
test('closure state follows the dates (announced → closed → open again)', function () {
    $s = ['status' => 'closed', 'closed_from' => '2026-11-03', 'closed_until' => '2026-11-10'];
    eq('upcoming', Stations::closureState($s, '2026-11-02'));
    eq('closed', Stations::closureState($s, '2026-11-03'));
    eq('closed', Stations::closureState($s, '2026-11-10'));
    eq('open', Stations::closureState($s, '2026-11-11'));
    eq('closed', Stations::closureState(['status' => 'closed', 'closed_from' => '', 'closed_until' => ''], '2030-01-01'));
    eq('open', Stations::closureState(['status' => 'open'], '2026-11-05'));
});
test('public card shows the closure badge, note and dates for the browser re-check', function () {
    $base = ['name' => 'P', 'address' => 'A', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '1', 'aliases' => '', 'ina_url' => '', 'working_hours' => [],
        'status' => 'closed', 'status_note' => 'Radovi <b>', 'closed_from' => '', 'closed_until' => '2026-11-10'];
    $html = Render::card($base, '2026-11-05');
    eq(true, str_contains($html, 'card-closed'));
    eq(true, str_contains($html, 'Privremeno zatvoreno do 10. 11. 2026.'));
    eq(true, str_contains($html, 'data-until="2026-11-10"'));
    eq(true, str_contains($html, 'Radovi &lt;b&gt;'));
    eq(1, preg_match('/data-badge="upcoming" hidden/', $html));
    $later = Render::card($base, '2026-11-20'); // expired: rendered, but hidden and not marked closed
    eq(false, str_contains($later, 'card-closed'));
    eq(1, preg_match('/data-badge="closed" hidden/', $later));
    eq(false, str_contains(Render::card(['status' => 'open', 'status_note' => ''] + $base, '2026-11-05'), 'card-badge'));
});
test('summary counts stations that are closed today', function () {
    $tpl = '{{SUMMARY}}|{{CARDS}}|{{COUNTY_OPTIONS}}|{{TOTAL}}|{{GENERATED_AT}}|{{GENERATED_HUMAN}}';
    $row = ['name' => 'P', 'address' => 'A', 'postal_code' => '', 'city' => '', 'county' => 'Istarska županija', 'phone' => '1', 'aliases' => '', 'ina_url' => '', 'working_hours' => [],
        'status' => 'closed', 'status_note' => '', 'closed_from' => '', 'closed_until' => ''];
    $out = Render::index($tpl, [$row, ['status' => 'open'] + $row], '2026-11-05T10:00:00+01:00');
    eq(true, str_starts_with($out, '2 postaja · 1 županija<span id="closed-summary"> · privremeno zatvoreno: <span id="closed-count">1</span></span>|'));
    eq(true, str_contains(Render::index($tpl, [['status' => 'open'] + $row], '2026-11-05T10:00:00+01:00'), '<span id="closed-summary" hidden>'));
});
test('ina.hr closure wording is found, quoted whole and ignored in scripts', function () {
    eq('Poštovani, postaja je PRIVREMENO ZATVORENA zbog radova', InaHours::closureNotice('<div><p>Poštovani, postaja je PRIVREMENO ZATVORENA zbog radova.</p></div>'));
    eq('Postaja ne radi zbog radova do 15.11.2026', InaHours::closureNotice('<p>Postaja ne radi zbog radova do 15.11.2026. Hvala.</p>'));
    eq('', InaHours::closureNotice('<script>x="privremeno zatvoreno"</script><p>Radi 0-24.</p>'));
    eq('', InaHours::closureNotice('<p>Otvoreno 06-22.</p>'));
});
test('sync flags closure wording even when the page has no hours table, and clears it later', function () {
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'x' : '<p>Postaja je privremeno zatvorena zbog radova.</p>';
    $r = InaHours::sync(0);
    eq(1, $r['notices']); eq(1, $r['failed']); // hours could not be read, saved hours untouched
    $row = Db::one("SELECT ina_notice, status FROM stations WHERE name = 'Babina Greda'");
    eq('Postaja je privremeno zatvorena zbog radova', $row['ina_notice']);
    eq('open', $row['status']); // never closed automatically
    InaHours::$fetch = fn(string $url) => '<p>Radi.</p>';
    InaHours::sync(0);
    eq('', Db::one("SELECT ina_notice FROM stations WHERE name = 'Babina Greda'")['ina_notice']);
});

echo "ina.hr hours table says PRIVREMENO ZATVORENO (e.g. https://www.ina.hr/station/porec-obala/)\n";
// Same structure as the live page: header row, then "Redovno" with every day closed.
$porec = '<table class="station__info__tablebox__table"><tr><th>Radno vrijeme</th><th>Radni dan</th><th>Subota</th><th>Nedjelja</th><th>Blagdani</th></tr>'
    . '<tr><td>Redovno</td><td>PRIVREMENO<br>ZATVORENO</td><td>PRIVREMENO ZATVORENO</td><td>PRIVREMENO ZATVORENO</td><td>PRIVREMENO ZATVORENO</td></tr></table>';
$bg = fn() => Db::one("SELECT * FROM stations WHERE name = 'Babina Greda'");
test('the closed hours table is parsed and recognised; normal hours are not', function () use ($porec, $fixture) {
    $h = InaHours::parseHours($porec);
    eq('PRIVREMENO ZATVORENO', $h[0]['week']);
    eq(true, InaHours::hoursClosed($h));
    eq(false, InaHours::hoursClosed(InaHours::parseHours($fixture)));
    eq(false, InaHours::hoursClosed([]));
});
test('sync closes the station automatically, marked as coming from ina.hr', function () use ($porec, $bg) {
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'x' : $porec;
    $r = InaHours::sync(0);
    eq([1, 0], [$r['closed'], $r['failed']]);
    $s = $bg();
    eq(['closed', 'ina', InaHours::INA_CLOSED_NOTE], [$s['status'], $s['status_source'], $s['status_note']]);
    eq('Radno vrijeme na ina.hr: PRIVREMENO ZATVORENO (svi dani)', $s['ina_notice']);
    eq(0, InaHours::sync(0)['closed']); // already closed: nothing happens twice
});
test('public card shows the badge but not "Pon–Pet: PRIVREMENO ZATVORENO" as hours', function () use ($porec) {
    $html = Render::card(Stations::publicRows()[0], date('Y-m-d'));
    eq(true, str_contains($html, 'Privremeno zatvoreno'));
    eq(false, str_contains($html, 'card-hours'));
    eq(false, str_contains($html, 'Pon–Pet: <b>PRIVREMENO'));
});
test('editing only the note keeps it automatic; adding dates makes it the admin\'s', function () use ($bg) {
    $id = (int) $bg()['id'];
    eq('ina', Stations::save($id, ['status_note' => 'Radovi, otvaranje uskoro'] + Stations::find($id))['status_source']);
    eq('admin', Stations::save($id, ['closed_until' => '2027-01-31'] + Stations::find($id))['status_source']);
    Stations::save($id, ['closed_until' => '', 'status' => 'closed'] + Stations::find($id)); // still admin
    Db::exec("UPDATE stations SET status_source = 'ina' WHERE id = ?", [$id]); // back to automatic for the next test
});
test('sync reopens its own closure as soon as ina.hr shows real hours again', function () use ($fixture, $bg) {
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'x' : $fixture;
    eq(1, InaHours::sync(0)['reopened']);
    $s = $bg();
    eq(['open', '', '', ''], [$s['status'], $s['status_source'], $s['status_note'], $s['ina_notice']]);
});
test('a closure set by the admin is never reopened by the sync', function () use ($fixture, $bg) {
    $id = (int) $bg()['id'];
    Stations::save($id, ['status' => 'closed', 'status_note' => 'Radovi'] + Stations::find($id));
    eq('admin', $bg()['status_source']);
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'x' : $fixture;
    eq(0, InaHours::sync(0)['reopened']);
    eq('closed', $bg()['status']);
    Stations::save($id, ['status' => 'open'] + Stations::find($id));
});
test("'ina_auto_close' => false only flags, never closes", function () use ($porec, $bg) {
    PlavaNafta\Config::load(['ina_auto_close' => false] + require __DIR__ . '/config.test.php');
    InaHours::$fetch = fn(string $url) => $url === InaHours::DIRECTORY_URL ? 'x' : $porec;
    $r = InaHours::sync(0);
    eq([0, 1], [$r['closed'], $r['notices']]);
    eq('open', $bg()['status']);
    PlavaNafta\Config::load(require __DIR__ . '/config.test.php');
});

echo "Finding the ina.hr page (Murter, Zadar I-istok)\n";
$hoursPage = fn(string $week) => '<table class="station__info__tablebox__table"><tr><th>Radno vrijeme</th><th>Radni dan</th><th>Subota</th><th>Nedjelja</th><th>Blagdani</th></tr>'
    . "<tr><td>Redovno</td><td>$week</td><td>06-21</td><td>07-20</td><td></td></tr></table>";
foreach ([['Murter', '091 497 1263 ; 091 497 1474', 'Šibensko-kninska županija'], ['Zadar I-istok', '091 497 0558', 'Zadarska županija']] as [$n, $ph, $c]) {
    Db::exec("INSERT INTO stations (name, address, phone, aliases, county, working_hours, updated_at) VALUES (?, '', ?, '', ?, '[]', ?)", [$n, $ph, $c, Db::now()]);
}
test('station addresses built from the name match ina.hr', function () {
    eq('murter', InaHours::slug('Murter'));
    eq('zadar-i-istok', InaHours::slug('Zadar I-istok'));
    eq('cadavica', InaHours::slug('Čađavica'));
    eq('dakovo-preradoviceva', InaHours::slug('Đakovo-Preradovićeva'));
});
test('a field with two numbers gives two call buttons, not one 20-digit number', function () {
    eq(['091 497 1263', '091 497 1474'], InaHours::phoneNumbers('091 497 1263 ; 091 497 1474'));
    eq(['0914971263', '0914971474'], InaHours::phoneKeys('+385 91 497 1263, 091 497 1474'));
    eq(['01/234-567'], InaHours::phoneNumbers('01/234-567')); // a slash is part of a number
    $html = Render::card(['name' => 'Murter', 'address' => '', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '091 497 1263 ; 091 497 1474', 'aliases' => '', 'ina_url' => '', 'working_hours' => []]);
    eq(true, str_contains($html, 'href="tel:0914971263">☎ 091 497 1263</a><a class="btn-phone" href="tel:0914971474">☎ 091 497 1474</a>'));
});
test('Murter: found through its second phone number in the ina.hr finder', function () use ($hoursPage) {
    $dir = '<div id="mdata" stations=\'[{"url":"https://www.ina.hr/station/murter/","contact_phone":"091 4971 474"}]\'></div>';
    InaHours::$fetch = fn(string $u) => match ($u) { InaHours::DIRECTORY_URL => $dir, 'https://www.ina.hr/station/murter/' => $hoursPage('06-22'), default => throw new RuntimeException('HTTP 404') };
    $log = [];
    $r = InaHours::sync(null, 'Murter', function ($l) use (&$log) { $log[] = $l; });
    eq(1, $r['updated']);
    $m = Db::one("SELECT ina_url, working_hours FROM stations WHERE name = 'Murter'");
    eq('https://www.ina.hr/station/murter/', $m['ina_url']);
    eq('06-22', json_decode($m['working_hours'], true)[0]['week']);
    eq(true, str_contains($log[0], 'Murter: ažurirano s https://www.ina.hr/station/murter/'));
});
test('Zadar I-istok: phone shared by two finder entries → matched by name instead', function () use ($hoursPage) {
    $dir = '<div id="mdata" stations=\'[{"url":"https://www.ina.hr/station/zadar-i-istok/","contact_phone":"091 497 0558"},'
        . '{"url":"https://www.ina.hr/station/zadar-ii/","contact_phone":"091 497 0558"}]\'></div>';
    $fetched = [];
    InaHours::$fetch = function (string $u) use ($dir, $hoursPage, &$fetched) {
        $fetched[] = $u;
        return match ($u) { InaHours::DIRECTORY_URL => $dir, 'https://www.ina.hr/station/zadar-i-istok/' => $hoursPage('0-24'), default => throw new RuntimeException('HTTP 404') };
    };
    eq(1, InaHours::sync(null, 'Zadar I-istok')['updated']);
    eq('https://www.ina.hr/station/zadar-i-istok/', Db::one("SELECT ina_url FROM stations WHERE name = 'Zadar I-istok'")['ina_url']);
    eq(false, in_array('https://www.ina.hr/station/zadar-ii/', $fetched, true)); // the ambiguous phone match is not used
});
test('finder down: the address from the name is tried directly', function () use ($hoursPage) {
    Db::exec("UPDATE stations SET ina_url = '', working_hours = '[]' WHERE name = 'Murter'");
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $hoursPage('07-21') : throw new RuntimeException('HTTP 503');
    $r = InaHours::sync(null, 'Murter');
    eq([1, false], [$r['updated'], $r['directoryAvailable']]);
});
test('a saved link that stopped working falls back to the next candidate', function () use ($hoursPage) {
    Db::exec("UPDATE stations SET ina_url = 'https://www.ina.hr/station/murter-staro/' WHERE name = 'Murter'");
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $hoursPage('06-20') : throw new RuntimeException('HTTP 404');
    eq(1, InaHours::sync(null, 'Murter')['updated']);
    eq('https://www.ina.hr/station/murter/', Db::one("SELECT ina_url FROM stations WHERE name = 'Murter'")['ina_url']);
});
test('nothing found: the log says what was tried, saved hours stay', function () {
    $before = Db::one("SELECT working_hours FROM stations WHERE name = 'Murter'")['working_hours'];
    InaHours::$fetch = fn(string $u) => throw new RuntimeException('HTTP 404');
    $log = [];
    $r = InaHours::sync(null, 'Murter', function ($l) use (&$log) { $log[] = $l; });
    eq(1, $r['failed']);
    // The saved link and the name address are the same page here, so it is tried once, not twice.
    eq(true, str_contains($log[1], 'Murter: stranica na ina.hr nije pronađena (spremljena poveznica https://www.ina.hr/station/murter/ → HTTP 404)'));
    eq($before, Db::one("SELECT working_hours FROM stations WHERE name = 'Murter'")['working_hours']);
});

echo "Bunny CDN purge\n";
test('no API key: nothing is sent', function () {
    $calls = 0;
    PlavaNafta\Bunny::$http = function () use (&$calls) { $calls++; return 200; };
    Stations::publish();
    eq(0, $calls);
    eq(null, PlavaNafta\Bunny::purge());
});
test('with a key: every publish purges https://domain/* with the AccessKey header', function () {
    PlavaNafta\Config::load(['bunny' => ['api_key' => 'k-123', 'pullzone_id' => '42', 'site_url' => 'https://plava-nafta.crodesign93.eu/']] + require __DIR__ . '/config.test.php');
    $calls = [];
    PlavaNafta\Bunny::$http = function (string $url, array $headers) use (&$calls) { $calls[] = [$url, $headers]; return 204; };
    Stations::publish();
    eq(1, count($calls));
    eq('https://api.bunny.net/purge?url=https%3A%2F%2Fplava-nafta.crodesign93.eu%2F%2A&async=false', $calls[0][0]);
    eq(true, in_array('AccessKey: k-123', $calls[0][1], true));
    eq(null, PlavaNafta\Bunny::purge(true));
    eq('https://api.bunny.net/pullzone/42/purgeCache', $calls[1][0]); // --zone
});
test('a failing purge never blocks a save; it is written to the Dnevnik', function () {
    PlavaNafta\Bunny::$http = fn() => 401;
    $id = (int) Db::one("SELECT id FROM stations WHERE name = 'Murter'")['id'];
    Stations::save($id, ['aliases' => 'Murter centar'] + Stations::find($id));
    Stations::publish();
    eq('Murter centar', Stations::find($id)['aliases']);
    eq('bunny-purge', Db::one("SELECT action FROM audit_log ORDER BY id DESC LIMIT 1")['action']);
    PlavaNafta\Bunny::$http = fn() => throw new RuntimeException('api.bunny.net nije dostupan');
    Stations::publish(); // still fine
    PlavaNafta\Config::load(require __DIR__ . '/config.test.php');
});

echo "Blue diesel price (\"Goriva u ponudi\")\n";
// Same list as https://www.ina.hr/station/babina-greda-2/
$fuels = '<nav><ul><li>O kompaniji</li><li>Kupci</li></ul></nav><h3>Goriva u ponudi</h3><ul><li>Eurodiesel plavi - 1,43 €</li>'
    . '<li>Eurosuper 95 Class Plus - 1,73 €</li><li>Eurodiesel Class Plus - 1,87 €</li><li>Eurodiesel Class Plus Premium - 2,12 €</li></ul>';
test('picks the blue diesel line, whatever INA calls it', function () use ($fuels) {
    eq(['name' => 'Eurodiesel plavi', 'price' => '1.43'], InaHours::bluePrice($fuels));
    eq(['name' => 'Plava nafta', 'price' => '1.45'], InaHours::bluePrice('<ul><li>Eurodiesel - 1,87 €</li><li>Plava nafta – 1.45 EUR</li></ul>'));
    eq(['name' => 'Plavi dizel', 'price' => '1.43'], InaHours::bluePrice('<ul><li>Plavi dizel: 1,430 €</li></ul>'));
    eq(['name' => 'Eurosuper plavi', 'price' => '1.44'], InaHours::bluePrice('<ul><li>Eurosuper plavi - 1,44 €</li></ul>'));
    eq(null, InaHours::bluePrice('<ul><li>Eurodiesel Class Plus - 1,87 €</li></ul>'));
    eq(null, InaHours::bluePrice('<ul><li>Plavi diesel - 143 €</li></ul>')); // not a believable €/l price
});
test('sync stores the price with the time it was picked up; a page without one keeps it', function () use ($fuels, $hoursPage) {
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $hoursPage('06-22') . $fuels : throw new RuntimeException('HTTP 404');
    $r = InaHours::sync(null, 'Murter');
    eq(1, $r['prices']);
    $m = Db::one("SELECT blue_price, blue_price_name, blue_price_at FROM stations WHERE name = 'Murter'");
    eq(['1.43', 'Eurodiesel plavi'], [$m['blue_price'], $m['blue_price_name']]);
    eq(date('Y-m-d'), substr($m['blue_price_at'], 0, 10));
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $hoursPage('06-22') : throw new RuntimeException('HTTP 404');
    eq(0, InaHours::sync(null, 'Murter')['prices']);
    eq('1.43', Db::one("SELECT blue_price FROM stations WHERE name = 'Murter'")['blue_price']);
});
test('banner shows one price or the range, with the date; card shows its own price', function () {
    $row = fn($p, $at) => ['name' => 'X', 'address' => '', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '', 'aliases' => '', 'ina_url' => '', 'working_hours' => [],
        'blue_price' => $p, 'blue_price_name' => 'Eurodiesel <plavi>', 'blue_price_at' => $at];
    $one = Render::priceBanner([$row('1.43', '2026-10-19 08:05:00'), $row('1.43', '2026-10-20 04:12:00'), $row('', '')]);
    eq(true, str_contains($one, '<p class="price-value"><span class="nw">1,43 € <span class="price-unit">/ l</span></span></p>'));
    eq(true, str_contains($one, 'Preuzeto s ina.hr 20. 10. 2026. u 04:12'));
    $range = Render::priceBanner([$row('1.43', '2026-10-20 04:00:00'), $row('1.47', '2026-10-20 04:00:00')]);
    eq(true, str_contains($range, '<span class="nw">1,43 €</span> – <span class="nw">1,47 € <span class="price-unit">/ l</span></span>'));
    eq('', Render::priceBanner([$row('', '')]));
    $card = Render::card($row('1.43', '2026-10-20 04:12:00'));
    eq(true, str_contains($card, '<span class="price-name">Eurodiesel &lt;plavi&gt;</span> <b>1,43 €</b> <span class="price-date">ina.hr, 20. 10. 2026.</span>'));
    eq(false, str_contains(Render::card($row('', '')), 'card-price'));
});

echo "Weekly price changes and announcements\n";
test('sync records a price change (old price, date, Dnevnik); the first pick-up is not a change', function () use ($hoursPage) {
    $page = fn($p) => $hoursPage('06-22') . "<ul><li>Eurodiesel plavi - $p €</li></ul>";
    Db::exec("UPDATE stations SET blue_price = '', blue_price_prev = '', blue_price_changed_at = '' WHERE name = 'Murter'");
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $page('1,47') : throw new RuntimeException('HTTP 404');
    InaHours::sync(null, 'Murter');
    eq(['1.47', '', ''], array_values(Db::one("SELECT blue_price, blue_price_prev, blue_price_changed_at FROM stations WHERE name = 'Murter'")));
    InaHours::$fetch = fn(string $u) => $u === 'https://www.ina.hr/station/murter/' ? $page('1,43') : throw new RuntimeException('HTTP 404');
    $log = [];
    InaHours::sync(null, 'Murter', function ($l) use (&$log) { $log[] = $l; });
    $m = Db::one("SELECT blue_price, blue_price_prev, blue_price_changed_at FROM stations WHERE name = 'Murter'");
    eq(['1.43', '1.47', date('Y-m-d')], [$m['blue_price'], $m['blue_price_prev'], substr($m['blue_price_changed_at'], 0, 10)]);
    eq('1,47 € → 1,43 € (Eurodiesel plavi)', Db::one("SELECT detail FROM audit_log WHERE action = 'price' ORDER BY id DESC LIMIT 1")['detail']);
    eq(true, in_array('Murter: Eurodiesel plavi 1,47 € → 1,43 €', $log, true));
    InaHours::sync(null, 'Murter'); // same price again: the change date stays
    eq($m['blue_price_changed_at'], Db::one("SELECT blue_price_changed_at FROM stations WHERE name = 'Murter'")['blue_price_changed_at']);
});
$priced = fn($p, $prev = '', $changed = '') => ['name' => 'X', 'address' => '', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '', 'aliases' => '', 'ina_url' => '', 'working_hours' => [],
    'blue_price' => $p, 'blue_price_name' => 'Eurodiesel plavi', 'blue_price_at' => '2026-10-21 04:10:00', 'blue_price_prev' => $prev, 'blue_price_changed_at' => $changed];
test('banner: NOVA CIJENA with the previous price for a week, then "Zadnja promjena"', function () use ($priced) {
    $rows = [$priced('1.43', '1.47', '2026-10-21 00:20:00'), $priced('1.43', '1.47', '2026-10-21 00:40:00')];
    $b = Render::priceBanner($rows, '2026-10-23 10:00:00');
    eq(true, str_contains($b, '<span class="price-flag">Nova cijena</span> od 21. 10. 2026. – prije 1,47 € (pojeftinjenje −0,04 €)'));
    $up = Render::priceBanner([$priced('1.51', '1.47', '2026-10-21 00:20:00')], '2026-10-22 10:00:00');
    eq(true, str_contains($up, 'prije 1,47 € (poskupljenje +0,04 €)'));
    $later = Render::priceBanner($rows, '2026-11-02 10:00:00');
    eq(false, str_contains($later, 'price-flag'));
    eq(true, str_contains($later, 'Zadnja promjena 21. 10. 2026. (prije 1,47 €)'));
});
test('announcement: NAJAVA before the date, warning while ina.hr differs, gone when it matches', function () use ($priced) {
    $ann = ['price' => '1.39', 'from' => '2026-10-27', 'note' => 'Odluka Vlade RH']; // a Tuesday
    $rows = [$priced('1.43')];
    $before = Render::priceBanner($rows, '2026-10-26 12:00:00', $ann);
    eq(true, str_contains($before, '<span class="price-flag">Najava</span> od uto 27. 10. 2026.: <span class="nw">1,39 € <span class="price-unit">/ l</span></span> (sada 1,43 €) · Odluka Vlade RH'));
    $after = Render::priceBanner($rows, '2026-10-27 06:00:00', $ann);
    eq(true, str_contains($after, '<span class="price-flag">Najava</span> vrijedi od uto 27. 10. 2026.: <span class="nw">1,39 € <span class="price-unit">/ l</span></span> · Odluka Vlade RH – ina.hr još prikazuje 1,43 €'));
    eq(false, str_contains(Render::priceBanner([$priced('1.39')], '2026-10-27 08:00:00', $ann), 'price-flag')); // ina.hr caught up
    eq(false, str_contains(Render::priceBanner($rows, '2026-11-10 08:00:00', $ann), 'Odluka')); // a week later
    eq(true, str_contains(Render::priceBanner([], '2026-10-26 12:00:00', $ann), 'Najava')); // works with no ina.hr prices yet
});
test('card says "nova cijena, prije …" for a week after the change', function () use ($priced) {
    eq(true, str_contains(Render::card($priced('1.43', '1.47', '2026-10-21 00:20:00'), '2026-10-25'), '<span class="price-new">nova cijena, prije 1,47 €</span>'));
    eq(false, str_contains(Render::card($priced('1.43', '1.47', '2026-10-21 00:20:00'), '2026-11-05'), 'price-new'));
});
test('the announcement is stored and published with the page', function () {
    PlavaNafta\Settings::set('price_announcement', ['price' => '1.39', 'from' => date('Y-m-d', strtotime('+3 days')), 'note' => '']);
    PlavaNafta\Settings::set('price_announcement', ['price' => '1.38', 'from' => date('Y-m-d', strtotime('+3 days')), 'note' => '']); // update, not a 2nd row
    eq(1, (int) Db::one("SELECT COUNT(*) n FROM settings")['n']);
    Stations::publish();
    eq(true, str_contains(file_get_contents(PlavaNafta\Config::get('web_root') . '/index.html'), '1,38 €'));
    PlavaNafta\Settings::delete('price_announcement');
    eq(null, PlavaNafta\Settings::get('price_announcement'));
});

echo "\n$count tests, $failures failed\n";
exit($failures ? 1 : 0);
