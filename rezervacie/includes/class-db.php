<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Tenká vrstva nad $wpdb – názvy tabuliek a časté dotazy. */
class Db
{
    public static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rzv_' . $name;
    }

    public static function row(string $table, int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t($table) . ' WHERE id = %d', $id), ARRAY_A);
        return $r ?: null;
    }

    public static function all(string $table, string $where = '1=1', string $order = 'priority DESC, id ASC'): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::t($table) . " WHERE $where ORDER BY $order", ARRAY_A) ?: [];
    }

    /** ID-čka z pivot tabuľky, napr. pivot('service_worker','service_id',5,'worker_id'). */
    public static function pivot(string $table, string $key, int $id, string $other): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT ' . $other . ' FROM ' . self::t($table) . ' WHERE ' . $key . ' = %d', $id
        )));
    }

    public static function set_pivot(string $table, string $key, int $id, string $other, array $ids): void
    {
        global $wpdb;
        $t = self::t($table);
        $wpdb->delete($t, [$key => $id]);
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $o) {
            $wpdb->insert($t, [$key => $id, $other => $o]);
        }
    }

    /** Celá mapa pivotu: [key_id => [other_ids]]. */
    public static function pivot_map(string $table, string $key, string $other): array
    {
        global $wpdb;
        $map = [];
        foreach ($wpdb->get_results("SELECT $key AS k, $other AS o FROM " . self::t($table), ARRAY_A) ?: [] as $r) {
            $map[(int) $r['k']][] = (int) $r['o'];
        }
        return $map;
    }

    public static function now(): string
    {
        return current_time('mysql');
    }

    public static function tz(): \DateTimeZone
    {
        return wp_timezone();
    }

    public static function dt(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, self::tz());
    }

    /** Unix timestamp z lokálneho 'Y-m-d H:i:s' (WP beží v UTC, strtotime by klamal). */
    public static function ts(string $s): int
    {
        return self::dt($s)->getTimestamp();
    }

    /** Lokálny 'Y-m-d H:i:s' z timestampu. */
    public static function fmt(int $ts, string $format = 'Y-m-d H:i:s'): string
    {
        return wp_date($format, $ts, self::tz());
    }
}
