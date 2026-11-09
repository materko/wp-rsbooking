<?php
namespace Rzv;

defined('ABSPATH') || exit;

/**
 * Vernostný program – úrovne podľa počtu návštev alebo útraty, každá úroveň má % zľavu.
 * Návšteva = zrealizovaná rezervácia (alebo potvrdená, ktorej termín už prebehol).
 */
class Loyalty
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('loyalty_enabled') && self::levels();
    }

    /** Úrovne zoradené od najnižšej. */
    public static function levels(): array
    {
        $levels = array_values(array_filter((array) Settings::get('loyalty_levels', []), fn($l) => !empty($l['name']) && (float) ($l['min'] ?? 0) > 0));
        usort($levels, fn($a, $b) => (float) $a['min'] <=> (float) $b['min']);
        return $levels;
    }

    /** Počet návštev a útrata zákazníka. */
    public static function progress(int $customer_id): array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS visits, COALESCE(SUM(price - discount), 0) AS spend FROM ' . Db::t('bookings') . "
             WHERE customer_id = %d AND (status = 'completed' OR (status = 'confirmed' AND end_at < %s))",
            $customer_id, Db::now()
        ), ARRAY_A);
        return ['visits' => (int) ($r['visits'] ?? 0), 'spend' => (float) ($r['spend'] ?? 0)];
    }

    /** @return array|null ['name','discount','value','next' => ['name','min','missing']|null] */
    public static function for_customer(int $customer_id): ?array
    {
        if (!self::enabled() || !$customer_id) {
            return null;
        }
        $p = self::progress($customer_id);
        $value = Settings::get('loyalty_basis') === 'spend' ? $p['spend'] : $p['visits'];
        $current = null;
        $next = null;
        foreach (self::levels() as $l) {
            if ($value >= (float) $l['min']) {
                $current = $l;
            } elseif (!$next) {
                $next = $l;
            }
        }
        return [
            'name'     => $current['name'] ?? '',
            'discount' => (float) ($current['discount'] ?? 0),
            'value'    => $value,
            'visits'   => $p['visits'],
            'spend'    => $p['spend'],
            'next'     => $next ? ['name' => $next['name'], 'min' => (float) $next['min'], 'missing' => (float) $next['min'] - $value] : null,
        ];
    }

    public static function for_email(string $email): ?array
    {
        if (!self::enabled() || !is_email($email)) {
            return null;
        }
        $c = Customers::find($email, '');
        return $c ? self::for_customer((int) $c['id']) : null;
    }

    public static function describe(?array $l): string
    {
        if (!$l) {
            return '';
        }
        $unit = Settings::get('loyalty_basis') === 'spend';
        $have = $unit ? Settings::money($l['value']) : (int) $l['value'] . ' návštev';
        $s = $l['name'] ? sprintf('Vernostná úroveň %s – zľava %s %% (%s).', $l['name'], self::num($l['discount']), $have) : sprintf('Zatiaľ máte %s.', $have);
        if ($l['next']) {
            $s .= ' ' . sprintf('Do úrovne %s chýba %s.', $l['next']['name'], $unit ? Settings::money($l['next']['missing']) : (int) ceil($l['next']['missing']) . ' návštev');
        }
        return $s;
    }

    public static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }
}
