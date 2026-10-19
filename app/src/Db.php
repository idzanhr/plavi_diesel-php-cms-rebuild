<?php
declare(strict_types=1);

namespace PlavaNafta;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            self::$pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if (self::driver() === 'sqlite') self::$pdo->exec('PRAGMA foreign_keys = ON');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Runs sql/schema.<driver>.sql. Safe to re-run (CREATE TABLE IF NOT EXISTS). */
    /** Columns added after the first release. Existing databases get them on the next migrate(). */
    private const UPGRADES = [
        'stations' => [
            'status'       => "VARCHAR(16) NOT NULL DEFAULT 'open'",   // open | closed (temporarily)
            'status_note'  => "VARCHAR(255) NOT NULL DEFAULT ''",      // e.g. "Radovi na postaji"
            'closed_from'  => "VARCHAR(10) NOT NULL DEFAULT ''",       // YYYY-MM-DD, empty = already closed
            'closed_until' => "VARCHAR(10) NOT NULL DEFAULT ''",       // YYYY-MM-DD, empty = until further notice
            'ina_notice'   => "VARCHAR(500) NOT NULL DEFAULT ''",      // closure wording found on ina.hr (sync)
            'status_source' => "VARCHAR(8) NOT NULL DEFAULT ''",       // who closed it: admin | ina (auto, from the hours table)
            'blue_price'    => "VARCHAR(16) NOT NULL DEFAULT ''",      // blue diesel €/l from ina.hr, e.g. 1.43
            'blue_price_name' => "VARCHAR(80) NOT NULL DEFAULT ''",    // the name INA uses, e.g. Eurodiesel plavi
            'blue_price_at' => "VARCHAR(19) NOT NULL DEFAULT ''",      // when it was picked up (Y-m-d H:i:s)
            'blue_price_prev' => "VARCHAR(16) NOT NULL DEFAULT ''",    // the price before the last change
            'blue_price_changed_at' => "VARCHAR(19) NOT NULL DEFAULT ''", // when the sync first saw the new price
        ],
    ];

    public static function migrate(): void
    {
        $file = dirname(__DIR__, 2) . '/sql/schema.' . (self::driver() === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
        foreach (self::statements((string) file_get_contents($file)) as $sql) self::pdo()->exec($sql);
        foreach (self::UPGRADES as $table => $columns) {
            foreach ($columns as $column => $definition) self::addColumnIfMissing($table, $column, $definition);
        }
    }

    public static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $existing = self::driver() === 'sqlite'
            ? array_column(self::all("PRAGMA table_info($table)"), 'name')
            : array_column(self::all('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'name');
        if (!in_array($column, $existing, true)) self::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }

    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(";\n", $sql . "\n"))));
    }
}
