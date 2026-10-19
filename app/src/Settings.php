<?php
declare(strict_types=1);

namespace PlavaNafta;

/** Tiny key/value store (JSON values) in the `settings` table. */
final class Settings
{
    public static function get(string $name): mixed
    {
        $row = Db::one('SELECT value FROM settings WHERE name = ?', [$name]);
        return $row ? json_decode($row['value'], true) : null;
    }

    public static function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (Db::exec('UPDATE settings SET value = ? WHERE name = ?', [$json, $name]) === 0 && self::get($name) === null) {
            Db::exec('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $json]);
        }
    }

    public static function delete(string $name): void
    {
        Db::exec('DELETE FROM settings WHERE name = ?', [$name]);
    }
}
