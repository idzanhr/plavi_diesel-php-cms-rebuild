<?php
declare(strict_types=1);

namespace PlavaNafta;

/** Renders the public station list into static HTML (works without JavaScript; JS only filters). */
final class Render
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Asset URLs carry a content hash so nginx can cache them for a year (immutable). */
    public static function assetUrl(string $webRoot, string $path): string
    {
        $file = $webRoot . $path;
        return $path . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 10) : '');
    }

    public static function headAssets(string $webRoot): string
    {
        $u = fn(string $p) => self::e(self::assetUrl($webRoot, $p));
        return '  <script src="' . $u('/assets/js/theme-init.js') . '"></script>' . "\n"
            . '  <link rel="stylesheet" href="' . $u('/assets/css/site.css') . '">' . "\n"
            . '  <script src="' . $u('/assets/js/hours.js') . '" defer></script>' . "\n"
            . '  <script src="' . $u('/assets/js/site.js') . '" defer></script>';
    }

    public static function page(string $template, string $webRoot): string
    {
        return strtr($template, ['{{HEAD_ASSETS}}' => self::headAssets($webRoot)]);
    }

    public static function index(string $template, array $rows, string $generatedAt): string
    {
        $counts = [];
        foreach ($rows as $s) if ($s['county'] !== '') $counts[$s['county']] = ($counts[$s['county']] ?? 0) + 1;
        $collator = class_exists(\Collator::class) ? new \Collator('hr_HR') : null;
        uksort($counts, fn($a, $b) => $collator ? $collator->compare($a, $b) : strcmp($a, $b));

        $options = '';
        foreach ($counts as $county => $n) {
            $options .= '<option value="' . self::e($county) . '">' . self::e($county) . " ($n)</option>\n";
        }

        $today = date('Y-m-d', strtotime($generatedAt));
        $closedNow = count(array_filter($rows, fn($s) => Stations::closureState($s, $today) === 'closed'));
        // The closed count is re-counted in the browser (site.js), so it stays right between publishes.
        $summary = self::e(count($rows) . ' postaja · ' . count($counts) . ' županija')
            . '<span id="closed-summary"' . ($closedNow ? '' : ' hidden') . '> · privremeno zatvoreno: <span id="closed-count">' . $closedNow . '</span></span>';

        return strtr($template, [
            '{{SUMMARY}}' => $summary,
            '{{CARDS}}' => implode("\n", array_map(fn($s) => self::card($s, $today), $rows)),
            '{{TOTAL}}' => (string) count($rows),
            '{{COUNTY_OPTIONS}}' => $options,
            '{{GENERATED_AT}}' => self::e($generatedAt),
            '{{GENERATED_HUMAN}}' => self::e(date('j. n. Y. H:i', strtotime($generatedAt))),
        ]);
    }

    /** 15. 11. 2026. */
    public static function date(string $ymd): string
    {
        return $ymd === '' ? '' : date('j. n. Y.', strtotime($ymd));
    }

    public static function card(array $s, ?string $today = null): string
    {
        $status = $s['status'] ?? 'open';
        $state = Stations::closureState($s, $today ?? date('Y-m-d'));
        $search = implode(' ', [$s['name'], $s['city'], $s['postal_code'], $s['address'], $s['phone'], $s['aliases'], $s['county'],
            $status === 'closed' ? 'privremeno zatvoreno ' . ($s['status_note'] ?? '') : '']);
        $place = trim($s['address'] . ', ' . trim($s['postal_code'] . ' ' . $s['city']), ', ');
        $tel = preg_replace('/[^\d+]/', '', $s['phone']);

        $h = '<article class="card' . ($state === 'closed' ? ' card-closed' : '') . '" data-county="' . self::e($s['county']) . '" data-search="' . self::e($search) . '"';
        if ($status === 'closed') {
            // The browser re-evaluates these dates on load (site.js), so closures start/end on time.
            $h .= ' data-status="closed" data-from="' . self::e($s['closed_from']) . '" data-until="' . self::e($s['closed_until']) . '"';
        }
        // Raw hours for the "open now" bubble, computed in the browser against the current time.
        if ($s['working_hours']) $h .= ' data-hours="' . self::e(json_encode($s['working_hours'], JSON_UNESCAPED_UNICODE)) . '"';
        $h .= '>';
        $h .= '<p class="card-county">' . self::e($s['county']) . '</p>';
        if ($status === 'closed') {
            $until = $s['closed_until'] !== '' ? ' do ' . self::date($s['closed_until']) : '';
            $h .= '<p class="card-badge badge-closed" data-badge="closed"' . ($state === 'closed' ? '' : ' hidden') . '>Privremeno zatvoreno' . self::e($until) . '</p>';
            $h .= '<p class="card-badge badge-upcoming" data-badge="upcoming"' . ($state === 'upcoming' ? '' : ' hidden') . '>Najavljeno zatvaranje od '
                . self::e(self::date($s['closed_from']) . $until) . '</p>';
            if ($s['status_note'] !== '') {
                $h .= '<p class="card-status-note" data-badge="note"' . ($state === 'open' ? ' hidden' : '') . '>' . self::e($s['status_note']) . '</p>';
            }
        }
        $h .= '<h2 class="card-title">' . self::e($s['name']) . '</h2>';
        $h .= '<p class="now" data-now hidden></p>';
        $h .= '<p class="card-address">' . self::e($place) . '</p>';
        if ($s['aliases'] !== '') {
            $h .= '<p class="card-aliases"><span>Zovu je: </span>' . self::e($s['aliases']) . '</p>';
        }
        // Rows that only say "PRIVREMENO ZATVORENO" (how ina.hr marks a closed station) are not
        // repeated as hours; the closure badge already says it.
        $hoursRows = array_values(array_filter($s['working_hours'], fn($w) => !InaHours::rowClosed($w)));
        if ($hoursRows) {
            $s['working_hours'] = $hoursRows;
            $multi = count($s['working_hours']) > 1;
            $h .= '<div class="card-hours">';
            foreach ($s['working_hours'] as $w) {
                $h .= '<div>';
                if ($multi) $h .= '<p class="hours-label">' . self::e($w['label']) . '</p>';
                $h .= '<p>Pon–Pet: <b>' . self::e($w['week'] ?: '—') . '</b> · Sub: <b>' . self::e($w['sat'] ?: '—') . '</b> · Ned: <b>' . self::e($w['sun'] ?: '—') . '</b></p>';
                if (($w['hol'] ?? '') !== '') $h .= '<p class="hours-hol">Blagdani: ' . self::e($w['hol']) . '</p>';
                $h .= '</div>';
            }
            $h .= '</div>';
        }
        $h .= '<div class="card-actions">';
        if ($s['phone'] !== '') $h .= '<a class="btn-phone" href="tel:' . self::e($tel) . '">☎ ' . self::e($s['phone']) . '</a>';
        if ($s['ina_url'] !== '') $h .= '<a class="btn-link" href="' . self::e($s['ina_url']) . '" target="_blank" rel="noopener noreferrer">INA.hr ↗</a>';
        $h .= '</div></article>';
        return $h;
    }
}
