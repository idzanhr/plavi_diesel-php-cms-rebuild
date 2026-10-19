<?php
declare(strict_types=1);

namespace PlavaNafta;

/**
 * Refreshes station working hours from the public ina.hr station pages.
 * Only `ina_url` and `working_hours` are ever written; admin edits stay untouched,
 * and a page that cannot be parsed never erases hours that are already saved.
 */
final class InaHours
{
    public const DIRECTORY_URL = 'https://www.ina.hr/trazilica-benzinskih-postaja/';
    public const DAILY_BATCHES = 60;

    /** @var callable(string): string */
    public static $fetch = [self::class, 'httpGet'];

    public static function safeUrl(string $value): ?string
    {
        $u = parse_url($value);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== 'www.ina.hr' || isset($u['port'])
            || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])
            || !preg_match('#^/station/[^/]+/$#', $u['path'] ?? '')) {
            return null;
        }
        return 'https://www.ina.hr' . $u['path'];
    }

    public static function phoneKey(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        return preg_replace(['/^00385/', '/^385/'], ['0', '0'], $digits, 1);
    }

    /** A phone field may hold several numbers ("091 497 1263 ; 091 497 1474"). Not split on "/": "01/234-567". */
    public static function phoneNumbers(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*[;,]\s*|\s+ili\s+/iu', $value)), 'strlen'));
    }

    public static function phoneKeys(string $value): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'phoneKey'], self::phoneNumbers($value)), fn($k) => strlen($k) >= 6)));
    }

    /** ina.hr builds station addresses from the name: "Zadar I-istok" → zadar-i-istok, "Čađavica" → cadavica. */
    public static function slug(string $name): string
    {
        $s = strtr(mb_strtolower($name, 'UTF-8'), ['dž' => 'dz', 'č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z']);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    /**
     * Station pages to try, best first: the saved link, a unique phone match in the ina.hr finder,
     * a unique name match in the finder (allowing INA's "-2" suffixes), and the address INA would
     * build from the name. Each is only used if its page has a readable hours table.
     * @return array<array{url: string, why: string}>
     */
    public static function candidates(array $station, array $directory): array
    {
        $out = [];
        if ($u = self::safeUrl((string) $station['ina_url'])) $out[] = ['url' => $u, 'why' => 'spremljena poveznica'];
        $keys = self::phoneKeys((string) $station['phone']);
        $byPhone = array_values(array_unique(array_column(array_filter($directory, fn($d) => array_intersect($d['phones'], $keys)), 'url')));
        if (count($byPhone) === 1) $out[] = ['url' => $byPhone[0], 'why' => 'telefon u tražilici ina.hr'];
        $slug = self::slug((string) $station['name']);
        if ($slug !== '') {
            $bySlug = array_values(array_filter(array_column($directory, 'url'), fn($u) => preg_match('#/station/' . preg_quote($slug, '#') . '(?:-\d+)?/$#', $u)));
            if (count($bySlug) === 1) $out[] = ['url' => $bySlug[0], 'why' => 'naziv u tražilici ina.hr'];
            $out[] = ['url' => 'https://www.ina.hr/station/' . $slug . '/', 'why' => 'adresa iz naziva'];
        }
        $seen = [];
        return array_values(array_filter($out, function ($c) use (&$seen) { if (isset($seen[$c['url']])) return false; return $seen[$c['url']] = true; }));
    }

    /** @return array<array{url: string, phone: string}> */
    public static function parseDirectory(string $html): array
    {
        $raw = self::xpath($html)->evaluate('string(//*[@id="mdata"]/@stations)');
        if ($raw === '') throw new \RuntimeException('INA directory missing');
        $entries = json_decode($raw, true);
        if (!is_array($entries)) throw new \RuntimeException('Invalid INA directory');
        $out = [];
        foreach ($entries as $e) {
            if (!is_array($e) || !is_string($e['url'] ?? null) || !($url = self::safeUrl($e['url']))) continue;
            $out[] = ['url' => $url, 'phones' => is_string($e['contact_phone'] ?? null) ? self::phoneKeys($e['contact_phone']) : []];
        }
        return $out;
    }

    /** @return array<array{label: string, week: string, sat: string, sun: string, hol: string}> */
    public static function parseHours(string $html): array
    {
        $xp = self::xpath($html);
        $table = null;
        foreach ($xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " station__info__tablebox__table ")]') as $t) {
            $firstRow = $xp->query('.//tr', $t)->item(0);
            if ($firstRow && str_contains(self::text($firstRow->textContent), 'Radno vrijeme')) { $table = $t; break; }
        }
        if (!$table) throw new \RuntimeException('INA working hours table missing');
        $hours = [];
        foreach ($xp->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xp->query('./td', $tr) as $td) {
                foreach (iterator_to_array($xp->query('.//br', $td)) as $br) $br->parentNode->replaceChild($td->ownerDocument->createTextNode(' '), $br);
                $cells[] = self::text($td->textContent);
            }
            if (!$cells) continue;
            if (count($cells) !== 5 || in_array('', array_slice($cells, 0, 4), true)) throw new \RuntimeException('Incomplete INA working hours');
            $hours[] = ['label' => $cells[0], 'week' => $cells[1], 'sat' => $cells[2], 'sun' => $cells[3], 'hol' => $cells[4]];
        }
        if (!$hours) throw new \RuntimeException('Empty INA working hours');
        return $hours;
    }

    /**
     * Sentence on an INA station page that says the station is temporarily closed
     * ("privremeno zatvoreno", "zatvoreno zbog radova", "ne radi zbog …"), or ''.
     */
    public static function closureNotice(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\b[^>]*>#i', '$0. ', $html);
        $text = self::text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // A sentence ends at . ! ? – except dots inside dates such as "15.11.2026".
        $chunk = '(?:[^.!?]|\.(?=\d))';
        $pattern = '/' . $chunk . '{0,120}(?<!\pL)(privremeno\s+(zatvoren\pL*|ne\s+radi)|zatvoren\pL*\s+zbog|ne\s+radi\s+zbog)(?!\pL)' . $chunk . '{0,160}(?:\.(?!\d))?/iu';
        if (!preg_match($pattern, $text, $m)) return '';
        return mb_substr(trim($m[0], " .\t"), 0, 300);
    }

    /**
     * Blue diesel price from the "Goriva u ponudi" list of a station page
     * ("Eurodiesel plavi - 1,43 €"). Any fuel whose name contains "plav…" counts
     * (Eurodiesel plavi, Plavi diesel/dizel, Plava nafta, Eurosuper plavi).
     * @return array{name: string, price: string}|null price as "1.43"
     */
    public static function bluePrice(string $html): ?array
    {
        $xp = self::xpath($html);
        foreach ($xp->query('//li | //tr | //p') as $node) {
            $text = self::text($node->textContent);
            if (mb_strlen($text) > 120) continue; // a whole paragraph of text, not a price line
            if (!preg_match('/^(.{2,60}?)\s*[-–—:]\s*(\d{1,2}[.,]\d{2,3})\s*(?:€|EUR\b)/iu', $text, $m)) continue;
            $name = trim($m[1]);
            if (!preg_match('/plav/iu', $name)) continue;
            $price = (float) str_replace(',', '.', $m[2]);
            if ($price < 0.3 || $price > 5) continue; // not a believable €/l price
            return ['name' => $name, 'price' => number_format($price, 2, '.', '')];
        }
        return null;
    }

    public static function dailyBatch(array $rows, int $minute): array
    {
        if ($minute < 0 || $minute >= self::DAILY_BATCHES) throw new \InvalidArgumentException('Invalid batch minute');
        return array_values(array_filter($rows, fn($i) => $i % self::DAILY_BATCHES === $minute, ARRAY_FILTER_USE_KEY));
    }

    /** @param int|null $minute batch 0-59, or null for every station */
    public const INA_CLOSED_NOTE = 'Privremeno zatvoreno (prema ina.hr)';

    /** True when every row of the ina.hr hours table says "PRIVREMENO ZATVORENO" for Mon–Fri, Sat and Sun. */
    public static function hoursClosed(array $hours): bool
    {
        if (!$hours) return false;
        foreach ($hours as $h) if (!self::rowClosed($h)) return false;
        return true;
    }

    public static function rowClosed(array $h): bool
    {
        foreach (['week', 'sat', 'sun'] as $k) {
            if (!preg_match('/privremeno\s+zatvoren/iu', (string) ($h[$k] ?? ''))) return false;
        }
        return true;
    }

    /**
     * @param int|null $minute batch 0-59, or null for every station
     * @param string|null $only station name (CLI --station / editor button); ignores the batch
     * @param callable(string): void|null $log receives one line per station (CLI --verbose, editor)
     */
    public static function sync(?int $minute, ?string $only = null, ?callable $log = null): array
    {
        $rows = Db::all('SELECT id, name, phone, ina_url, working_hours, ina_notice, status, status_source, blue_price FROM stations ORDER BY id');
        if ($only !== null) {
            $rows = array_values(array_filter($rows, fn($r) => mb_strtolower($r['name']) === mb_strtolower(trim($only)) || (string) $r['id'] === $only));
        }
        $batch = $minute === null || $only !== null ? $rows : self::dailyBatch($rows, $minute);
        $result = ['checked' => count($batch), 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'notices' => 0,
            'closed' => 0, 'reopened' => 0, 'prices' => 0, 'directoryAvailable' => true];
        $log ??= fn(string $line) => null;
        $directory = [];
        try { $directory = self::parseDirectory((self::$fetch)(self::DIRECTORY_URL)); }
        catch (\Throwable) { $result['directoryAvailable'] = false; $log('Tražilica ina.hr nije dostupna – pokušavam spremljene poveznice i adrese iz naziva.'); }

        foreach ($batch as $station) {
            try { self::syncStation($station, $directory, $result, $log); }
            catch (\Throwable $e) { $result['failed']++; $log($station['name'] . ': ' . $e->getMessage()); }
        }
        // Prices are re-published every time they are picked up, so the date on the site stays current.
        if ($result['updated'] + $result['notices'] + $result['closed'] + $result['reopened'] + $result['prices'] > 0) {
            Audit::log('ina-sync', $only ?? '', json_encode($result));
            Stations::publish();
        }
        return $result;
    }

    private static function syncStation(array $station, array $directory, array &$result, callable $log): void
    {
        $tried = [];
        $html = $hours = $url = null;
        $firstHtml = null; // a page that loaded but had no hours table (e.g. closed) – still checked for notices
        foreach (self::candidates($station, $directory) as $c) {
            try { $page = (self::$fetch)($c['url']); }
            catch (\Throwable $e) { $tried[] = "{$c['why']} {$c['url']} → {$e->getMessage()}"; continue; }
            try { $hours = self::parseHours($page); $html = $page; $url = $c['url']; break; }
            catch (\Throwable) { $firstHtml ??= ['html' => $page, 'url' => $c['url']]; $tried[] = "{$c['why']} {$c['url']} → nema tablice radnog vremena"; }
        }
        if ($hours === null && $firstHtml) { $html = $firstHtml['html']; $url = $firstHtml['url']; }
        if ($html === null) throw new \RuntimeException('stranica na ina.hr nije pronađena (' . ($tried ? implode('; ', $tried) : 'nema kandidata') . ')');
        $closedByIna = $hours !== null && self::hoursClosed($hours);

        // 0. Blue diesel price ("Goriva u ponudi"). Stored with the time it was picked up; a page
        //    without a price leaves the last known one (its date then shows how old it is).
        if ($price = self::bluePrice($html)) {
            Db::exec('UPDATE stations SET blue_price = ?, blue_price_name = ?, blue_price_at = ? WHERE id = ?',
                [$price['price'], mb_substr($price['name'], 0, 80), Db::now(), $station['id']]);
            $result['prices']++;
            if ($price['price'] !== (string) $station['blue_price']) {
                $log("{$station['name']}: {$price['name']} " . str_replace('.', ',', $price['price']) . ' €');
            }
        }

        // 1. Notice for the admin. The hours table saying "PRIVREMENO ZATVORENO" every day is
        //    INA's own signal; any other closure wording on the page is only flagged, because it
        //    could come from a site-wide banner. Checked even when the page has no hours table.
        $notice = $closedByIna ? 'Radno vrijeme na ina.hr: PRIVREMENO ZATVORENO (svi dani)' : self::closureNotice($html);
        if ($notice !== (string) $station['ina_notice']) {
            Db::exec('UPDATE stations SET ina_notice = ? WHERE id = ?', [$notice, $station['id']]);
            if ($notice !== '') {
                $result['notices']++;
                Audit::log('ina-notice', $station['name'], $notice);
            }
        }

        // 2. Status. Only closures the sync made itself (status_source = ina) are reopened;
        //    closures set by the admin are never changed.
        if (Config::get('ina_auto_close') && $closedByIna && $station['status'] === 'open') {
            Db::exec("UPDATE stations SET status = 'closed', status_source = 'ina', status_note = ?, closed_from = '', closed_until = '', updated_at = ? WHERE id = ?",
                [self::INA_CLOSED_NOTE, Db::now(), $station['id']]);
            Audit::log('ina-close', $station['name'], $notice);
            $result['closed']++;
        } elseif ($hours !== null && !$closedByIna && $station['status'] === 'closed' && $station['status_source'] === 'ina') {
            Db::exec("UPDATE stations SET status = 'open', status_source = '', status_note = '', closed_from = '', closed_until = '', updated_at = ? WHERE id = ?",
                [Db::now(), $station['id']]);
            Audit::log('ina-reopen', $station['name'], 'ina.hr ponovno prikazuje radno vrijeme');
            $result['reopened']++;
        }

        // 3. Working hours (kept even when they say closed, so a reopening is noticed).
        if ($hours === null) throw new \RuntimeException("stranica $url nema tablice radnog vremena");
        $saved = json_decode((string) $station['working_hours'], true) ?: [];
        if ($url === $station['ina_url'] && self::sameHours($saved, $hours)) {
            $result['unchanged']++;
            $log("{$station['name']}: bez promjene ($url)");
            return;
        }
        Db::exec('UPDATE stations SET ina_url = ?, working_hours = ?, updated_at = ? WHERE id = ?',
            [$url, json_encode($hours, JSON_UNESCAPED_UNICODE), Db::now(), $station['id']]);
        $result['updated']++;
        $log("{$station['name']}: ažurirano s $url – " . implode(' | ', array_map(fn($h) => "{$h['label']}: {$h['week']} / {$h['sat']} / {$h['sun']}", $hours)));
    }

    private static function sameHours(array $saved, array $hours): bool
    {
        if (count($saved) !== count($hours)) return false;
        foreach ($hours as $i => $h) {
            if (!is_array($saved[$i] ?? null)) return false;
            foreach ($h as $k => $v) if (($saved[$i][$k] ?? null) !== $v) return false;
        }
        return true;
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: text/html'], CURLOPT_USERAGENT => 'plava-nafta-cms/1.0',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status === 0) throw new \RuntimeException('ina.hr nije dostupan s poslužitelja (veza nije uspjela)');
        if ($status !== 200) throw new \RuntimeException("ina.hr je vratio HTTP $status");
        return $body;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return new \DOMXPath($doc);
    }

    private static function text(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
