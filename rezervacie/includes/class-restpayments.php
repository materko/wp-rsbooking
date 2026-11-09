<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** REST: zľavové kódy, platby, Stripe, poukážky (používa trieda Rest). */
trait RestPayments
{
    /** Platobný blok pre formulár / správu rezervácie. */
    private static function payment_block(array $b): ?array
    {
        if (Pricing::due($b) <= 0 || in_array($b['status'], ['cancelled', 'no_show', 'completed'], true)) {
            return null;
        }
        $online = Settings::get('online_enabled') && Stripe::configured();
        $qr = Settings::get('qr_enabled') && Settings::get('iban');
        if (!$online && !$qr) {
            return null;
        }
        $ins = Payments::for_booking_due($b);
        if (!$ins) {
            return null;
        }
        $ins['amount_label'] = Settings::money($ins['amount']);
        $ins['required'] = $b['status'] === 'awaiting_payment';
        $ins['expires'] = $b['expires_at'] ? wp_date('H:i', Db::ts($b['expires_at']), Db::tz()) : '';
        return $ins;
    }

    private static function codes_enabled(): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var('SELECT 1 FROM ' . Db::t('coupons') . " WHERE active = 1 AND scope <> 'vouchers' LIMIT 1")
            || (bool) $wpdb->get_var('SELECT 1 FROM ' . Db::t('vouchers') . " WHERE status = 'active' LIMIT 1");
    }

    public static function quote(\WP_REST_Request $r)
    {
        $d = $r->get_json_params() ?: [];
        if ($e = self::throttle('quote', 60)) {
            return $e;
        }
        $q = Pricing::quote((int) ($d['service_id'] ?? 0), (array) ($d['addons'] ?? []), (array) ($d['codes'] ?? []), sanitize_email($d['email'] ?? ''));
        if (is_wp_error($q)) {
            $q->add_data(['status' => 400]);
            return $q;
        }
        return [
            'base'             => $q['base'],
            'discount'         => $q['discount'],
            'voucher_amount'   => $q['voucher_amount'],
            'total'            => $q['total'],
            'pay_online'       => $q['pay_online'],
            'online_required'  => $q['online_required'],
            'applied'          => array_map(fn($a) => $a + ['amount_label' => '−' . Settings::money($a['amount'])], $q['applied']),
            'total_label'      => Settings::money($q['total']),
            'pay_online_label' => Settings::money($q['pay_online']),
        ];
    }

    public static function manage_pay(\WP_REST_Request $r)
    {
        $b = Booking::by_token((string) $r['token']);
        if (!$b) {
            return new \WP_Error('rzv_404', 'Rezervácia neexistuje.', ['status' => 404]);
        }
        $ins = self::payment_block($b);
        if (!$ins || empty($ins['online_url'])) {
            return new \WP_Error('rzv_pay', 'Online platba nie je dostupná.', ['status' => 400]);
        }
        $url = Stripe::checkout(Payments::get((int) $ins['payment_id']));
        if (is_wp_error($url)) {
            $url->add_data(['status' => 502]);
            return $url;
        }
        return ['redirect' => $url];
    }

    private static function check_sig(\WP_REST_Request $r, int $id): bool
    {
        return hash_equals(Payments::sign($id), (string) $r['k']);
    }

    /** Odkaz z e-mailu / tlačovej stránky → Stripe Checkout. */
    public static function pay_redirect(\WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        $p = Payments::get($id);
        if (!$p || !self::check_sig($r, $id)) {
            return new \WP_Error('rzv_404', 'Platba neexistuje.', ['status' => 404]);
        }
        if ($p['status'] === 'paid') {
            self::redirect(self::after_pay_url($p));
        }
        $url = Stripe::checkout($p);
        if (is_wp_error($url)) {
            $url->add_data(['status' => 400]);
            return $url;
        }
        self::redirect($url);
    }

    public static function stripe_return(\WP_REST_Request $r)
    {
        $id = (int) $r['pid'];
        $p = Payments::get($id);
        if (!$p || !self::check_sig($r, $id)) {
            return new \WP_Error('rzv_404', 'Platba neexistuje.', ['status' => 404]);
        }
        if ($p['status'] !== 'paid') {
            Stripe::verify_session($p, (string) $r['session_id']);
            $p = Payments::get($id);
        }
        self::redirect(add_query_arg('rzv_paid', $p['status'] === 'paid' ? '1' : '0', self::after_pay_url($p)));
    }

    public static function stripe_webhook(\WP_REST_Request $r)
    {
        return Stripe::webhook($r);
    }

    private static function after_pay_url(array $p): string
    {
        if ($p['booking_id'] && ($b = Booking::get((int) $p['booking_id']))) {
            return Booking::manage_url($b);
        }
        if ($p['voucher_id'] && ($v = Vouchers::get((int) $p['voucher_id']))) {
            return Vouchers::url($v);
        }
        return home_url('/');
    }

    private static function redirect(string $url): void
    {
        wp_redirect($url, 303);
        exit;
    }

    /** Jednoduchá stránka s QR kódom (odkaz z e-mailu). */
    public static function qr_page(\WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        $p = Payments::get($id);
        if (!$p || !self::check_sig($r, $id) || !Settings::get('qr_enabled')) {
            return new \WP_Error('rzv_404', 'Platba neexistuje.', ['status' => 404]);
        }
        $note = $p['booking_id'] ? 'Rezervacia ' . $p['booking_id'] : 'Poukazka ' . $p['voucher_id'];
        $ins = Payments::instructions($p, $note, null);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR platba</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:420px;margin:30px auto;padding:0 16px;color:#222;text-align:center}#qr svg{width:260px;height:260px}'
            . 'dl{text-align:left;display:grid;grid-template-columns:auto 1fr;gap:4px 12px}dt{color:#777}dd{margin:0;font-weight:600}</style></head><body>';
        echo '<h1>' . esc_html(Settings::get('business_name')) . '</h1>';
        if ($p['status'] === 'paid') {
            echo '<p>✓ Táto platba je už uhradená. Ďakujeme.</p>';
        } elseif ($p['status'] !== 'pending') {
            echo '<p>Táto platba už nie je aktuálna.</p>';
        } else {
            echo '<p>Naskenujte QR kód v aplikácii svojej banky.</p><div id="qr" data-qr="' . esc_attr($ins['qr'] ?? '') . '"></div>';
            echo '<dl><dt>Suma</dt><dd>' . esc_html(Settings::money($p['amount'])) . '</dd><dt>IBAN</dt><dd>' . esc_html($ins['iban'] ?? '')
                . '</dd><dt>VS</dt><dd>' . esc_html($p['reference']) . '</dd><dt>Príjemca</dt><dd>' . esc_html($ins['beneficiary'] ?? '') . '</dd></dl>';
            echo '<script src="' . esc_url(RZV_URL . 'assets/vendor/qrcode.js?ver=' . RZV_VERSION) . '"></script>';
            echo '<script>var e=document.getElementById("qr"),q=qrcode(0,"M");q.addData(e.getAttribute("data-qr"));q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true});</script>';
        }
        echo '</body></html>';
        exit;
    }

    /* ---------- poukážky ---------- */

    public static function voucher_types(\WP_REST_Request $r)
    {
        global $wpdb;
        $out = [];
        foreach (Db::all('voucher_types', 'active = 1 AND sell_online = 1') as $t) {
            $svc = $t['service_id'] ? Db::row('services', (int) $t['service_id']) : null;
            $out[] = [
                'id'            => (int) $t['id'],
                'name'          => $t['name'],
                'description'   => (string) $t['description'],
                'kind'          => $t['kind'],
                'price'         => (float) $t['price'],
                'price_label'   => Settings::money($t['price']),
                'value_label'   => $t['kind'] === 'value' ? Settings::money($t['value']) : (($t['kind'] === 'pass' ? (int) $t['entries'] . '× ' : '') . ($svc['name'] ?? '')),
                'validity_days' => (int) $t['validity_days'],
                'image'         => (string) $t['image'],
                'color'         => (string) $t['color'],
            ];
        }
        $s = Settings::all();
        return [
            'types'       => $out,
            'intro'       => $s['voucher_intro'],
            'terms'       => $s['voucher_terms'],
            'terms_url'   => $s['terms_url'],
            'privacy_url' => $s['privacy_url'],
            'business'    => $s['business_name'],
            'currency'    => $s['currency'],
            'online'      => (bool) ($s['online_enabled'] && Stripe::configured()),
            'coupons'     => (bool) $wpdb->get_var('SELECT 1 FROM ' . Db::t('coupons') . " WHERE active = 1 AND scope <> 'bookings' LIMIT 1"),
        ];
    }

    public static function voucher_order(\WP_REST_Request $r)
    {
        $d = $r->get_json_params() ?: [];
        if (!empty($d['website'])) {
            return new \WP_Error('rzv_spam', 'Chyba.', ['status' => 400]);
        }
        if (empty($d['terms'])) {
            return new \WP_Error('rzv_input', 'Je potrebný súhlas s podmienkami.', ['status' => 400]);
        }
        if ($e = self::throttle('voucher', 10)) {
            return $e;
        }
        $v = Vouchers::order($d);
        if (is_wp_error($v)) {
            $v->add_data(['status' => 400]);
            return $v;
        }
        $ins = Payments::pending_for_voucher((int) $v['id']);
        $redirect = null;
        if ($ins && !empty($ins['online_url']) && !empty($d['pay_online'])) {
            $url = Stripe::checkout(Payments::get((int) $ins['payment_id']));
            if (!is_wp_error($url)) {
                $redirect = $url;
            }
        }
        return [
            'ok'       => true,
            'status'   => $v['status'],
            'code'     => $v['status'] === 'active' ? $v['code'] : null,
            'url'      => Vouchers::url($v),
            'payment'  => $ins ? $ins + ['amount_label' => Settings::money($ins['amount'])] : null,
            'redirect' => $redirect,
        ];
    }

    /* ---------- admin ---------- */

    public static function admin_payment(\WP_REST_Request $r)
    {
        global $wpdb;
        $d = $r->get_json_params() ?: [];
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0 || (empty($d['booking_id']) && empty($d['voucher_id']))) {
            return new \WP_Error('rzv_input', 'Zadajte sumu.', ['status' => 400]);
        }
        // čakajúcu platbu (QR) nahradí skutočná
        if (!empty($d['booking_id'])) {
            $wpdb->update(Db::t('payments'), ['status' => 'cancelled'], ['booking_id' => (int) $d['booking_id'], 'status' => 'pending']);
        }
        $id = Payments::create([
            'booking_id' => $d['booking_id'] ?? null,
            'voucher_id' => $d['voucher_id'] ?? null,
            'method'     => (string) ($d['method'] ?? 'cash'),
            'amount'     => $amount,
            'status'     => 'paid',
            'note'       => $d['note'] ?? '',
        ]);
        return Payments::get($id);
    }

    public static function admin_payment_paid(\WP_REST_Request $r)
    {
        $d = $r->get_json_params() ?: [];
        return Payments::mark_paid((int) $r['id'], (string) ($d['method'] ?? 'transfer')) ?: new \WP_Error('rzv_404', 'Neexistuje.', ['status' => 404]);
    }

    public static function admin_voucher_paid(\WP_REST_Request $r)
    {
        $v = Vouchers::get((int) $r['id']);
        if (!$v) {
            return new \WP_Error('rzv_404', 'Neexistuje.', ['status' => 404]);
        }
        $pending = array_values(array_filter(Payments::for_voucher((int) $v['id']), fn($p) => $p['status'] === 'pending'));
        if ($pending) {
            Payments::mark_paid((int) $pending[0]['id'], 'transfer');
        } else {
            Vouchers::activate((int) $v['id']);
        }
        return Vouchers::get((int) $v['id']);
    }
}
