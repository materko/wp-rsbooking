<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Výpočet ceny rezervácie: služba + doplnky − zľavový kód − poukážka, a koľko treba zaplatiť online. */
class Pricing
{
    /**
     * @param array $codes zadané kódy (zľavový kód a/alebo poukážka)
     * @return array|\WP_Error
     */
    public static function quote(int $service_id, array $addon_ids, array $codes, string $email = '', bool $admin = false)
    {
        $svc = Db::row('services', $service_id);
        if (!$svc) {
            return new \WP_Error('rzv_input', 'Neplatná služba.');
        }
        $addons = Booking::addon_rows($service_id, array_map('intval', $addon_ids));
        $service_price = (float) $svc['price'];
        $base = round($service_price + array_sum(array_column($addons, 'price')), 2);

        $q = [
            'base' => $base, 'discount' => 0.0, 'coupon' => null, 'voucher' => null,
            'voucher_amount' => 0.0, 'voucher_entries' => 0, 'total' => $base, 'pay_online' => 0.0, 'loyalty_level' => null,
            'online_required' => false, 'applied' => [],
        ];

        $codes = array_values(array_unique(array_filter(array_map(fn($c) => Coupons::normalize((string) $c), $codes))));
        foreach ($codes as $code) {
            if ($c = Coupons::find($code)) {
                if ($q['coupon']) {
                    return new \WP_Error('rzv_coupon', 'Použiť sa dá len jeden zľavový kód.');
                }
                $d = Coupons::discount($c, 'bookings', $base, $service_id, $email);
                if (is_wp_error($d)) {
                    return $d;
                }
                $q['coupon'] = $c['code'];
                $q['discount'] = $d;
                $q['applied'][] = ['code' => $c['code'], 'kind' => 'coupon', 'label' => 'Zľava ' . Coupons::label($c), 'amount' => $d];
                continue;
            }
            if ($v = Vouchers::find($code)) {
                if ($q['voucher']) {
                    return new \WP_Error('rzv_voucher', 'Použiť sa dá len jedna poukážka.');
                }
                $q['voucher'] = $v;
                continue;
            }
            return new \WP_Error('rzv_code', sprintf('Kód %s neexistuje.', $code));
        }

        // vernostná zľava (bez kódu, alebo spolu s kódom ak je to povolené)
        if ($email && (!$q['coupon'] || Settings::get('loyalty_combine'))) {
            $l = Loyalty::for_email($email);
            if ($l && $l['discount'] > 0) {
                $ld = round(($base - $q['discount']) * $l['discount'] / 100, 2);
                if ($ld > 0) {
                    $q['discount'] = round($q['discount'] + $ld, 2);
                    $q['loyalty_level'] = $l['name'];
                    $q['applied'][] = ['code' => '', 'kind' => 'loyalty', 'label' => 'Vernostná zľava ' . $l['name'] . ' ' . Loyalty::num($l['discount']) . ' %', 'amount' => $ld];
                }
            }
        }

        if ($q['voucher']) {
            $v = $q['voucher'];
            $r = Vouchers::check_for_booking($v, $service_id, $service_price, $base - $q['discount']);
            if (is_wp_error($r)) {
                return $r;
            }
            $q['voucher_amount'] = $r['amount'];
            $q['voucher_entries'] = $r['entries'];
            $q['applied'][] = ['code' => $v['code'], 'kind' => 'voucher', 'label' => 'Poukážka ' . $v['name'], 'amount' => $r['amount']];
            $q['voucher'] = ['id' => (int) $v['id'], 'code' => $v['code']];
        }

        $q['total'] = round(max(0, $base - $q['discount'] - $q['voucher_amount']), 2);

        // online platba
        if (!$admin && Settings::get('online_enabled') && Stripe::configured() && $q['total'] > 0) {
            $mode = Settings::get('payment_mode', 'deposit');
            $deposit = (float) $svc['deposit'];
            if ($mode === 'full') {
                $q['pay_online'] = $q['total'];
                $q['online_required'] = true;
            } elseif ($deposit > 0) {
                $q['pay_online'] = round(min($deposit, $q['total']), 2);
                $q['online_required'] = true;
            } elseif ($mode === 'optional') {
                $q['pay_online'] = $q['total'];
            }
        }
        return $q;
    }

    /** Po uložení rezervácie: započítať kód a čerpanie poukážky. */
    public static function commit(array $b, array $q): void
    {
        if (!empty($q['coupon'])) {
            Coupons::count_use($q['coupon']);
        }
        if (!empty($q['voucher']) && ($q['voucher_amount'] > 0 || $q['voucher_entries'] > 0)) {
            Vouchers::redeem((int) $q['voucher']['id'], (int) $b['id'], (float) $q['voucher_amount'], (int) $q['voucher_entries'], 'Rezervácia #' . $b['id']);
        }
    }

    /** Pri zrušení rezervácie: vrátiť kód a poukážku. */
    public static function release(array $b): void
    {
        if (!empty($b['coupon_code'])) {
            Coupons::count_use($b['coupon_code'], -1);
        }
        if (!empty($b['voucher_id'])) {
            Vouchers::refund_booking((int) $b['id']);
        }
    }

    /** Koľko ešte zostáva zaplatiť. */
    public static function due(array $b): float
    {
        return round(max(0, (float) $b['price'] - (float) $b['discount'] - (float) $b['voucher_amount'] - (float) $b['paid']), 2);
    }

    public static function total(array $b): float
    {
        return round(max(0, (float) $b['price'] - (float) $b['discount'] - (float) $b['voucher_amount']), 2);
    }
}
