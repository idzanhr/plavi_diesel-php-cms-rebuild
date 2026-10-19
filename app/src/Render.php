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

        return strtr($template, [
            '{{SUMMARY}}' => count($rows) . ' postaja · ' . count($counts) . ' županija',
            '{{TOTAL}}' => (string) count($rows),
            '{{COUNTY_OPTIONS}}' => $options,
            '{{CARDS}}' => implode("\n", array_map([self::class, 'card'], $rows)),
            '{{GENERATED_AT}}' => self::e($generatedAt),
            '{{GENERATED_HUMAN}}' => self::e(date('j. n. Y. H:i', strtotime($generatedAt))),
        ]);
    }

    public static function card(array $s): string
    {
        $search = implode(' ', [$s['name'], $s['city'], $s['postal_code'], $s['address'], $s['phone'], $s['aliases'], $s['county']]);
        $place = trim($s['address'] . ', ' . trim($s['postal_code'] . ' ' . $s['city']), ', ');
        $tel = preg_replace('/[^\d+]/', '', $s['phone']);

        $h = '<article class="card" data-county="' . self::e($s['county']) . '" data-search="' . self::e($search) . '">';
        $h .= '<p class="card-county">' . self::e($s['county']) . '</p>';
        $h .= '<h2 class="card-title">' . self::e($s['name']) . '</h2>';
        $h .= '<p class="card-address">' . self::e($place) . '</p>';
        if ($s['aliases'] !== '') {
            $h .= '<p class="card-aliases"><span>Zovu je: </span>' . self::e($s['aliases']) . '</p>';
        }
        if ($s['working_hours']) {
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
