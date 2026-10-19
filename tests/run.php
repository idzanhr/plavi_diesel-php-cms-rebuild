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
    eq([['url' => 'https://www.ina.hr/station/babina-greda-2/', 'phone' => '0914971292']],
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

echo "\n$count tests, $failures failed\n";
exit($failures ? 1 : 0);
