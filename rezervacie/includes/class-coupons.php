<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Zľavové kódy – pre rezervácie, poukážky alebo oboje. */
class Coupons
{
    const TYPES = ['percent' => 'Percentá', 'fixed' => 'Pevná suma'];
    const SCOPES = ['all' => 'Rezervácie aj poukážky', 'bookings' => 'Len rezervácie', 'vouchers' => 'Len poukážky'];

    public static function find(string $code): ?array
    {
        global $wpdb;
        $code = self::normalize($code);
        if ($code === '') {
            return null;
        }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::t('coupons') . ' WHERE code = %s', $code), ARRAY_A);
        return $r ?: null;
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    /**
     * Overí kód a vráti výšku zľavy.
     * @param string $scope bookings|vouchers
     * @return float|\WP_Error
     */
    public static function discount(array $c, string $scope, float $amount, int $service_id = 0, string $email = '')
    {
        global $wpdb;
        $today = wp_date('Y-m-d');
        if (!$c['active']) {
            return new \WP_Error('rzv_coupon', 'Zľavový kód nie je platný.');
        }
        if ($c['scope'] !== 'all' && $c['scope'] !== $scope) {
            return new \WP_Error('rzv_coupon', $scope === 'bookings' ? 'Tento kód platí len pre nákup poukážok.' : 'Tento kód platí len pre rezervácie.');
        }
        if (($c['valid_from'] && $today < $c['valid_from']) || ($c['valid_to'] && $today > $c['valid_to'])) {
            return new \WP_Error('rzv_coupon', 'Platnosť zľavového kódu vypršala.');
        }
        if ($c['max_uses'] !== null && $c['max_uses'] !== '' && (int) $c['uses'] >= (int) $c['max_uses']) {
            return new \WP_Error('rzv_coupon', 'Zľavový kód už bol vyčerpaný.');
        }
        if ((float) $c['min_amount'] > 0 && $amount < (float) $c['min_amount']) {
            return new \WP_Error('rzv_coupon', sprintf('Zľavový kód platí od sumy %s.', Settings::money($c['min_amount'])));
        }
        if ($scope === 'bookings' && $service_id) {
            $services = Db::pivot('coupon_service', 'coupon_id', (int) $c['id'], 'service_id');
            if ($services && !in_array($service_id, $services, true)) {
                return new \WP_Error('rzv_coupon', 'Zľavový kód neplatí pre zvolenú službu.');
            }
        }
        if ($c['per_customer'] !== null && $c['per_customer'] !== '' && (int) $c['per_customer'] > 0 && $email) {
            $used = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Db::t('bookings') . ' b JOIN ' . Db::t('customers') . " c ON c.id = b.customer_id
                 WHERE b.coupon_code = %s AND c.email = %s AND b.status <> 'cancelled'",
                $c['code'], $email
            ));
            $used += (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Db::t('vouchers') . " WHERE coupon_code = %s AND buyer_email = %s AND status <> 'cancelled'",
                $c['code'], $email
            ));
            if ($used >= (int) $c['per_customer']) {
                return new \WP_Error('rzv_coupon', 'Tento zľavový kód ste už použili.');
            }
        }
        $d = $c['type'] === 'percent' ? $amount * (float) $c['value'] / 100 : (float) $c['value'];
        return round(min($amount, max(0, $d)), 2);
    }

    public static function count_use(string $code, int $delta = 1): void
    {
        global $wpdb;
        if ($code === '') {
            return;
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . Db::t('coupons') . ' SET uses = GREATEST(0, uses + %d) WHERE code = %s', $delta, $code));
    }

    public static function label(array $c): string
    {
        return $c['type'] === 'percent' ? rtrim(rtrim(number_format((float) $c['value'], 2, ',', ''), '0'), ',') . ' %' : Settings::money($c['value']);
    }
}
