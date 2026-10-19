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
            $out[] = ['url' => $url, 'phone' => is_string($e['contact_phone'] ?? null) ? self::phoneKey($e['contact_phone']) : ''];
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

    public static function sync(?int $minute): array
    {
        $rows = Db::all('SELECT id, name, phone, ina_url, working_hours, ina_notice, status, status_source FROM stations ORDER BY id');
        $batch = $minute === null ? $rows : self::dailyBatch($rows, $minute);
        $result = ['checked' => count($batch), 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'notices' => 0,
            'closed' => 0, 'reopened' => 0, 'directoryAvailable' => true];
        $autoClose = (bool) Config::get('ina_auto_close');
        $directory = [];
        try { $directory = self::parseDirectory((self::$fetch)(self::DIRECTORY_URL)); }
        catch (\Throwable) { $result['directoryAvailable'] = false; }

        foreach ($batch as $station) {
            try {
                $matches = array_values(array_filter($directory, fn($d) => $d['phone'] !== '' && $d['phone'] === self::phoneKey($station['phone'])));
                $url = count($matches) === 1 ? $matches[0]['url'] : self::safeUrl($station['ina_url']);
                if (!$url) throw new \RuntimeException('No safe station URL');
                $html = (self::$fetch)($url);
                try { $hours = self::parseHours($html); } catch (\Throwable) { $hours = null; }
                $closedByIna = $hours !== null && self::hoursClosed($hours);

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
                if ($autoClose && $closedByIna && $station['status'] === 'open') {
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
                if ($hours === null) throw new \RuntimeException('No working hours');
                $saved = json_decode((string) $station['working_hours'], true) ?: [];
                if ($url === $station['ina_url'] && self::sameHours($saved, $hours)) { $result['unchanged']++; continue; }
                Db::exec('UPDATE stations SET ina_url = ?, working_hours = ?, updated_at = ? WHERE id = ?',
                    [$url, json_encode($hours, JSON_UNESCAPED_UNICODE), Db::now(), $station['id']]);
                $result['updated']++;
            } catch (\Throwable) {
                $result['failed']++;
            }
        }
        if ($result['updated'] + $result['notices'] + $result['closed'] + $result['reopened'] > 0) {
            Audit::log('ina-sync', '', json_encode($result));
            Stations::publish();
        }
        return $result;
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
        if ($body === false || $status !== 200) throw new \RuntimeException("INA HTTP $status");
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
