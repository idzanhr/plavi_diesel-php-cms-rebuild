<?php
declare(strict_types=1);

namespace PlavaNafta;

final class Stations
{
    public const COUNTIES = [
        'Bjelovarsko-bilogorska županija', 'Brodsko-posavska županija', 'Dubrovačko-neretvanska županija', 'Grad Zagreb',
        'Istarska županija', 'Karlovačka županija', 'Koprivničko-križevačka županija', 'Krapinsko-zagorska županija',
        'Ličko-senjska županija', 'Međimurska županija', 'Osječko-baranjska županija', 'Požeško-slavonska županija',
        'Primorsko-goranska županija', 'Sisačko-moslavačka županija', 'Splitsko-dalmatinska županija',
        'Šibensko-kninska županija', 'Varaždinska županija', 'Virovitičko-podravska županija',
        'Vukovarsko-srijemska županija', 'Zadarska županija', 'Zagrebačka županija',
    ];

    public const TEXT_FIELDS = ['name', 'address', 'phone', 'aliases', 'county', 'city', 'postal_code', 'ina_region', 'sales_area', 'ina_url',
        'status', 'status_note', 'closed_from', 'closed_until'];
    private const MAX = ['name' => 190, 'address' => 255, 'phone' => 64, 'aliases' => 2000, 'county' => 120, 'city' => 120,
        'postal_code' => 16, 'ina_region' => 120, 'sales_area' => 120, 'ina_url' => 255,
        'status' => 16, 'status_note' => 255, 'closed_from' => 10, 'closed_until' => 10];
    public const STATUSES = ['open', 'closed'];
    private const HOURS_KEYS = ['label', 'week', 'sat', 'sun', 'hol'];

    // ---- queries -------------------------------------------------------

    public static function all(): array
    {
        $rows = array_map([self::class, 'hydrate'], Db::all('SELECT * FROM stations'));
        self::sortByName($rows);
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM stations WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $hours = json_decode((string) $row['working_hours'], true);
        $row['working_hours'] = is_array($hours) ? $hours : [];
        return $row;
    }

    public static function sortByName(array &$rows): void
    {
        $collator = class_exists(\Collator::class) ? new \Collator('hr_HR') : null;
        usort($rows, fn($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));
    }

    // ---- validation ----------------------------------------------------

    /** @return array{0: array, 1: string[]} [clean row, errors] */
    public static function validate(array $in): array
    {
        $row = [];
        $errors = [];
        foreach (self::TEXT_FIELDS as $f) {
            $v = $in[$f] ?? '';
            if (!is_string($v) && !is_int($v)) { $errors[] = "Neispravno polje: $f"; $v = ''; }
            $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v));
            if (mb_strlen($v) > self::MAX[$f]) $errors[] = "Polje $f je predugo (najviše " . self::MAX[$f] . ' znakova).';
            $row[$f] = $v;
        }
        $row['aliases'] = implode(' / ', self::splitAliases($row['aliases']));
        if ($row['name'] === '') $errors[] = 'Naziv je obavezan.';
        if ($row['county'] !== '' && !in_array($row['county'], self::COUNTIES, true)) $errors[] = 'Nepoznata županija.';
        if ($row['ina_url'] !== '' && InaHours::safeUrl($row['ina_url']) === null) $errors[] = 'INA poveznica mora biti oblika https://www.ina.hr/station/…/';

        // Temporary closure (works, renovation…): optional note and from/until dates.
        if ($row['status'] === '') $row['status'] = 'open';
        if (!in_array($row['status'], self::STATUSES, true)) $errors[] = 'Nepoznat status postaje.';
        foreach (['closed_from' => 'od', 'closed_until' => 'do'] as $f => $label) {
            if ($row[$f] !== '' && !self::isDate($row[$f])) $errors[] = "Neispravan datum zatvaranja ($label).";
        }
        if ($row['closed_from'] !== '' && $row['closed_until'] !== '' && $row['closed_until'] < $row['closed_from']) {
            $errors[] = 'Datum "do" je prije datuma "od".';
        }
        if ($row['status'] === 'open') $row['status_note'] = $row['closed_from'] = $row['closed_until'] = '';

        $hours = $in['working_hours'] ?? [];
        if (is_string($hours)) $hours = json_decode($hours, true) ?? [];
        $row['working_hours'] = [];
        if (!is_array($hours) || count($hours) > 6) {
            $errors[] = 'Neispravno radno vrijeme.';
        } else {
            foreach ($hours as $h) {
                if (!is_array($h)) { $errors[] = 'Neispravno radno vrijeme.'; break; }
                $clean = [];
                foreach (self::HOURS_KEYS as $k) $clean[$k] = mb_substr(trim((string) ($h[$k] ?? '')), 0, 300);
                if (implode('', $clean) !== '') $row['working_hours'][] = $clean;
            }
        }
        return [$row, $errors];
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * What a visitor should see on $today (YYYY-MM-DD): 'open', 'closed' or 'upcoming'
     * (closure announced for a later date). An expired closure counts as open again.
     * Mirrored in assets/js/site.js and plava-nafta/assets/admin.js, which re-check it in
     * the browser so the page is right even on days nothing was re-published.
     */
    public static function closureState(array $s, string $today): string
    {
        if (($s['status'] ?? 'open') !== 'closed') return 'open';
        if (($s['closed_until'] ?? '') !== '' && $today > $s['closed_until']) return 'open';
        if (($s['closed_from'] ?? '') !== '' && $today < $s['closed_from']) return 'upcoming';
        return 'closed';
    }

    public static function splitAliases(string $aliases): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('/', $aliases)), 'strlen')));
    }

    // ---- writes --------------------------------------------------------

    public static function save(?int $id, array $input): array
    {
        [$row, $errors] = self::validate($input);
        if ($errors) throw new HttpError(422, implode(' ', $errors));
        $dupe = Db::one('SELECT id FROM stations WHERE name = ? AND id <> ?', [$row['name'], $id ?? 0]);
        if ($dupe) throw new HttpError(422, 'Postaja s tim nazivom već postoji.');

        $before = $id ? self::find($id) : null;
        if ($id && !$before) throw new HttpError(404, 'Postaja ne postoji.');
        $source = self::statusSource($before, $row);
        $params = array_merge(array_map(fn($f) => $row[$f], self::TEXT_FIELDS), [json_encode($row['working_hours'], JSON_UNESCAPED_UNICODE), $source, Db::now()]);
        $cols = [...self::TEXT_FIELDS, 'working_hours', 'status_source', 'updated_at'];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = ?", $cols));
            Db::exec("UPDATE stations SET $set WHERE id = ?", [...$params, $id]);
        } else {
            $cols = implode(', ', $cols);
            Db::exec("INSERT INTO stations ($cols) VALUES (" . implode(', ', array_fill(0, count($params), '?')) . ')', $params);
            $id = (int) Db::pdo()->lastInsertId();
        }
        Audit::log($before ? 'update' : 'create', $row['name'], $before ? self::diff($before, $row) : '');
        return self::find($id);
    }

    /**
     * Who owns a closure. 'ina' = closed automatically from the ina.hr hours table, so the
     * sync may also reopen it. It stays 'ina' while the admin only edits the note or other
     * fields; setting dates (or closing an open station) makes it the admin's ('admin'),
     * and the sync never touches those.
     */
    private static function statusSource(?array $before, array $row): string
    {
        if ($row['status'] !== 'closed') return '';
        $keepIna = $before && ($before['status_source'] ?? '') === 'ina' && $before['status'] === 'closed'
            && $row['closed_from'] === '' && $row['closed_until'] === '';
        return $keepIna ? 'ina' : 'admin';
    }

    public static function delete(int $id): void
    {
        $s = self::find($id);
        if (!$s) throw new HttpError(404, 'Postaja ne postoji.');
        Db::exec('DELETE FROM stations WHERE id = ?', [$id]);
        Audit::log('delete', $s['name'], json_encode(array_intersect_key($s, array_flip(['address', 'phone', 'aliases', 'county'])), JSON_UNESCAPED_UNICODE));
    }

    private static function diff(array $before, array $after): string
    {
        $changes = [];
        foreach ([...self::TEXT_FIELDS, 'working_hours'] as $f) {
            if ($before[$f] != $after[$f]) $changes[] = $f;
        }
        return $changes ? 'Promijenjeno: ' . implode(', ', $changes) : 'Bez promjena';
    }

    /**
     * Upserts by station name. Accepts the JSON this CMS exports, a raw Supabase
     * export (array of rows) or CSV with a header row. Returns [created, updated, errors].
     */
    public static function import(string $content, string $filename): array
    {
        $rows = str_ends_with(strtolower($filename), '.csv') ? self::parseCsv($content) : json_decode($content, true);
        if (is_array($rows) && isset($rows['stations'])) $rows = $rows['stations'];
        if (!is_array($rows) || !array_is_list($rows)) throw new HttpError(422, 'Datoteka nije ispravan JSON/CSV popis postaja.');

        $created = $updated = 0;
        $errors = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $i => $in) {
                if (!is_array($in)) { $errors[] = 'Redak ' . ($i + 1) . ': neispravan zapis'; continue; }
                $existing = Db::one('SELECT * FROM stations WHERE name = ?', [trim((string) ($in['name'] ?? ''))]);
                // Fields absent from the file keep their current values.
                $merged = $existing ? array_merge(self::hydrate($existing), array_filter($in, fn($v) => $v !== null)) : $in;
                try {
                    self::save($existing ? (int) $existing['id'] : null, $merged);
                    $existing ? $updated++ : $created++;
                } catch (HttpError $e) {
                    $errors[] = 'Redak ' . ($i + 1) . ' (' . ($in['name'] ?? '?') . '): ' . $e->getMessage();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [$created, $updated, $errors];
    }

    private static function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_filter(preg_split('/\r\n|\n|\r/', $content), 'strlen');
        $first = reset($lines) ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = null;
        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, $sep, '"', '');
            if ($header === null) { $header = array_map(fn($h) => strtolower(trim($h)), $cells); continue; }
            $row = [];
            foreach ($header as $j => $h) if ($h !== '') $row[$h] = $cells[$j] ?? '';
            $rows[] = $row;
        }
        return $rows;
    }

    public static function toCsv(array $stations): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [...self::TEXT_FIELDS, 'working_hours'], ';', '"', '');
        foreach ($stations as $s) {
            fputcsv($fh, [...array_map(fn($f) => $s[$f], self::TEXT_FIELDS), json_encode($s['working_hours'], JSON_UNESCAPED_UNICODE)], ';', '"', '');
        }
        rewind($fh);
        return stream_get_contents($fh);
    }

    // ---- static site ---------------------------------------------------

    /** Public projection: everything the public page needs, nothing internal. */
    public static function publicRows(): array
    {
        return array_map(fn($s) => [
            'name' => $s['name'], 'address' => $s['address'], 'postal_code' => $s['postal_code'], 'city' => $s['city'],
            'county' => $s['county'], 'phone' => $s['phone'], 'aliases' => $s['aliases'],
            'ina_url' => InaHours::safeUrl($s['ina_url']) ?? '', 'working_hours' => $s['working_hours'],
            'status' => $s['status'], 'status_note' => $s['status_note'],
            'closed_from' => $s['closed_from'], 'closed_until' => $s['closed_until'],
        ], self::all());
    }

    /**
     * Regenerates every public file from the database and app/templates/:
     * index.html, privatnost.html, kolacici.html, 404.html, data/stations.json,
     * plus pre-compressed .gz copies that nginx serves directly (gzip_static).
     */
    public static function publish(): void
    {
        $dir = rtrim(Config::get('web_root'), '/');
        $templates = dirname(__DIR__) . '/templates';
        $rows = self::publicRows();
        $generatedAt = date('c');

        // Assets first, so their .gz exist before pages reference the new hashes.
        foreach (['/assets/css/site.css', '/assets/js/site.js', '/assets/js/hours.js', '/assets/js/theme-init.js', '/favicon.svg'] as $asset) {
            if (is_file($dir . $asset)) self::writeGzip($dir . $asset, (string) file_get_contents($dir . $asset));
        }
        self::atomicWrite("$dir/data/stations.json", json_encode(['generated_at' => $generatedAt, 'stations' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", true);
        $index = Render::page((string) file_get_contents("$templates/index.html"), $dir);
        self::atomicWrite("$dir/index.html", Render::index($index, $rows, $generatedAt), true);
        foreach (['privatnost', 'kolacici', '404'] as $page) {
            self::atomicWrite("$dir/$page.html", Render::page((string) file_get_contents("$templates/$page.html"), $dir), true);
        }
    }

    private static function writeGzip(string $path, string $content): void
    {
        $gz = $path . '.gz';
        if (is_file($gz) && filemtime($gz) >= filemtime($path)) return;
        self::atomicWrite($gz, gzencode($content, 9));
        touch($gz, filemtime($path)); // gzip_static expects matching mtimes for If-Modified-Since
    }

    private static function atomicWrite(string $path, string $content, bool $withGzip = false): void
    {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) throw new \RuntimeException("Cannot write $path");
        chmod($tmp, 0644);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException("Cannot replace $path"); }
        if ($withGzip) {
            clearstatcache(true, $path);
            self::atomicWrite("$path.gz", gzencode($content, 9));
            touch("$path.gz", filemtime($path));
        }
    }
}
