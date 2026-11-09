<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Rest
{
    use RestPayments;

    const NS = 'rzv/v1';

    public static function register(): void
    {
        $pub = '__return_true';
        $adm = fn() => current_user_can('rzv_manage');

        $routes = [
            ['/catalog', 'GET', 'catalog', $pub],
            ['/days', 'GET', 'days', $pub],
            ['/times', 'GET', 'times', $pub],
            ['/bookings', 'POST', 'book', $pub],
            ['/waitlist', 'POST', 'waitlist', $pub],
            ['/manage/(?P<token>[a-zA-Z0-9]+)', 'GET', 'manage_get', $pub],
            ['/manage/(?P<token>[a-zA-Z0-9]+)/cancel', 'POST', 'manage_cancel', $pub],
            ['/manage/(?P<token>[a-zA-Z0-9]+)/pay', 'POST', 'manage_pay', $pub],
            ['/quote', 'POST', 'quote', $pub],
            ['/pay/(?P<id>\d+)', 'GET', 'pay_redirect', $pub],
            ['/qr/(?P<id>\d+)', 'GET', 'qr_page', $pub],
            ['/stripe/return', 'GET', 'stripe_return', $pub],
            ['/stripe/webhook', 'POST', 'stripe_webhook', $pub],
            ['/voucher-types', 'GET', 'voucher_types', $pub],
            ['/vouchers/order', 'POST', 'voucher_order', $pub],
            ['/admin/payments', 'POST', 'admin_payment', $adm],
            ['/admin/payments/(?P<id>\d+)/paid', 'POST', 'admin_payment_paid', $adm],
            ['/admin/vouchers/(?P<id>\d+)/paid', 'POST', 'admin_voucher_paid', $adm],

            ['/admin/calendar', 'GET', 'admin_calendar', $adm],
            ['/admin/times', 'GET', 'admin_times', $adm],
            ['/admin/bookings', 'POST', 'admin_create', $adm],
            ['/admin/bookings/(?P<id>\d+)', 'GET', 'admin_get', $adm],
            ['/admin/bookings/(?P<id>\d+)', 'POST', 'admin_update', $adm],
            ['/admin/customers', 'GET', 'admin_customers', $adm],
            ['/admin/blocked', 'POST', 'admin_block', $adm],
            ['/admin/blocked/(?P<id>\d+)', 'DELETE', 'admin_unblock', $adm],
        ];
        foreach ($routes as [$path, $method, $cb, $perm]) {
            register_rest_route(self::NS, $path, [
                'methods'             => $method,
                'callback'            => [self::class, $cb],
                'permission_callback' => $perm,
            ]);
        }
    }

    /* ---------- verejné ---------- */

    public static function catalog(\WP_REST_Request $r)
    {
        $cats = array_map(fn($c) => [
            'id' => (int) $c['id'], 'name' => $c['name'], 'description' => (string) $c['description'],
        ], Db::all('categories', 'visible = 1'));

        $sw = Db::pivot_map('service_worker', 'service_id', 'worker_id');
        $sa = Db::pivot_map('service_addon', 'service_id', 'addon_id');
        $workers = [];
        foreach (Db::all('workers', 'active = 1 AND visible = 1') as $w) {
            $workers[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'description' => (string) $w['description'], 'photo' => (string) $w['photo']];
        }
        $visible_workers = array_column($workers, 'id');
        $active_workers = array_map('intval', array_column(Db::all('workers', 'active = 1'), 'id'));

        $services = [];
        foreach (Db::all('services', 'active = 1 AND visible = 1') as $s) {
            $wids = array_values(array_intersect($sw[(int) $s['id']] ?? [], $visible_workers));
            $rental = ($s['kind'] ?? 'service') === 'rental';
            if ($rental && !Db::pivot('service_resource', 'service_id', (int) $s['id'], 'resource_id')) {
                continue; // prenájom bez predmetu sa nedá rezervovať
            }
            if (!$rental && !array_intersect($sw[(int) $s['id']] ?? [], $active_workers)) {
                continue; // bez aktívneho pracovníka sa nedá rezervovať
            }
            $services[] = [
                'id'          => (int) $s['id'],
                'category_id' => (int) $s['category_id'],
                'kind'        => $rental ? 'rental' : 'service',
                'name'        => $s['name'],
                'description' => (string) $s['description'],
                'duration'    => (int) $s['duration'],
                'price'       => (float) $s['price'],
                'capacity'    => (int) $s['capacity'],
                'workers'     => $rental ? [] : $wids,
                'addons'      => $sa[(int) $s['id']] ?? [],
                'fields'      => Fields::for_service((int) $s['id']),
            ];
        }
        $addons = array_map(fn($a) => [
            'id' => (int) $a['id'], 'name' => $a['name'], 'description' => (string) $a['description'],
            'duration' => (int) $a['duration'], 'price' => (float) $a['price'],
        ], Db::all('addons', 'active = 1'));

        // dni v týždni, kedy pracuje aspoň jeden aktívny pracovník (pre čakaciu listinu)
        $open_days = [];
        foreach (Db::all('workers', 'active = 1') as $w) {
            foreach (Slots::worker_hours($w) as $n => $d) {
                if (!empty($d['open'])) {
                    $open_days[(int) $n] = (int) $n;
                }
            }
        }

        $s = Settings::all();
        return [
            'business'   => $s['business_name'],
            'categories' => $cats,
            'services'   => $services,
            'workers'    => $workers,
            'addons'     => $addons,
            'settings'   => [
                'currency'       => $s['currency'],
                'days_ahead'     => (int) $s['days_ahead'],
                'worker_choice'  => (bool) $s['allow_worker_choice'],
                'waitlist'       => (bool) $s['waitlist'],
                'require_phone'  => (bool) $s['require_phone'],
                'terms_url'      => $s['terms_url'],
                'privacy_url'    => $s['privacy_url'],
                'show_prices'    => (bool) $s['show_prices'],
                'show_duration'  => (bool) $s['show_duration'],
                'cancel_policy'  => $s['cancel_policy'],
                'open_days'      => array_values($open_days),
                'codes'          => self::codes_enabled(),
                'loyalty'        => Loyalty::enabled(),
                'qr'             => (bool) ($s['qr_enabled'] && $s['iban']),
                'online'         => (bool) ($s['online_enabled'] && Stripe::configured()),
            ],
            'extra' => apply_filters('rzv_catalog_extra', new \stdClass()),
        ];
    }

    public static function days(\WP_REST_Request $r)
    {
        $slots = Slots::make((int) $r['service'], self::ids($r['addons']), (int) $r['worker']);
        if (!$slots) {
            return new \WP_Error('rzv_input', 'Služba nie je dostupná.', ['status' => 400]);
        }
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $r['month']) ? $r['month'] : wp_date('Y-m');
        $first = Db::dt($month . '-01');
        $today = Db::dt(wp_date('Y-m-d'));
        $last_allowed = $today->modify('+' . (int) Settings::get('days_ahead', 30) . ' days');
        $from = max($first, $today);
        $to = min($first->modify('last day of this month'), $last_allowed);
        if ($from > $to) {
            return ['days' => []];
        }
        return ['days' => $slots->days($from->format('Y-m-d'), $to->format('Y-m-d')), 'max' => $last_allowed->format('Y-m-d')];
    }

    public static function times(\WP_REST_Request $r)
    {
        $slots = Slots::make((int) $r['service'], self::ids($r['addons']), (int) $r['worker']);
        $date = (string) $r['date'];
        if (!$slots || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('rzv_input', 'Neplatná požiadavka.', ['status' => 400]);
        }
        $slots->load($date, $date);
        return ['times' => array_keys($slots->day($date))];
    }

    public static function book(\WP_REST_Request $r)
    {
        $d = $r->get_json_params() ?: $r->get_params();
        if (!empty($d['website'])) { // honeypot
            return new \WP_Error('rzv_spam', 'Chyba.', ['status' => 400]);
        }
        if ($e = self::throttle('book', 10)) {
            return $e;
        }
        if (empty($d['terms'])) {
            return new \WP_Error('rzv_input', 'Je potrebný súhlas s podmienkami.', ['status' => 400]);
        }
        $b = Booking::create($d, false);
        if (is_wp_error($b)) {
            $b->add_data(['status' => 400]);
            return $b;
        }
        $payment = self::payment_block($b);
        if ($b['status'] === 'awaiting_payment' && !empty($payment['online_url'])) {
            $url = Stripe::checkout(Payments::get((int) $payment['payment_id']));
            if (is_wp_error($url)) {
                // platobná brána zlyhala – termín uvoľniť, nech ho neblokujeme
                Booking::set_status((int) $b['id'], 'cancelled', false);
                $url->add_data(['status' => 502]);
                return $url;
            }
            $payment['redirect'] = $url;
        }
        return [
            'ok'      => true,
            'status'  => $b['status'],
            'token'   => $b['token'],
            'payment' => $payment,
            'summary' => self::public_booking($b),
            'extra'   => apply_filters('rzv_booking_response_extra', new \stdClass(), $b),
        ];
    }

    public static function waitlist(\WP_REST_Request $r)
    {
        $d = $r->get_json_params() ?: $r->get_params();
        if (!empty($d['website'])) {
            return new \WP_Error('rzv_spam', 'Chyba.', ['status' => 400]);
        }
        if ($e = self::throttle('wait', 10)) {
            return $e;
        }
        $res = Waitlist::add($d);
        if (is_wp_error($res)) {
            $res->add_data(['status' => 400]);
            return $res;
        }
        return ['ok' => true];
    }

    public static function manage_get(\WP_REST_Request $r)
    {
        $b = Booking::by_token((string) $r['token']);
        if (!$b) {
            return new \WP_Error('rzv_404', 'Rezervácia neexistuje.', ['status' => 404]);
        }
        $can = Booking::can_customer_cancel($b);
        return [
            'booking'    => self::public_booking($b),
            'can_cancel' => $can === true,
            'payment'    => self::payment_block($b),
            'loyalty'    => Loyalty::describe(Loyalty::for_customer((int) $b['customer_id'])),
            'rate_url'   => Settings::get('ratings_enabled') && in_array($b['status'], ['completed', 'confirmed'], true) && Db::ts($b['start_at']) < time() && !Ratings::for_booking((int) $b['id']) ? Ratings::url($b) : '',
            'reason'     => is_wp_error($can) ? $can->get_error_message() : '',
            'extra'      => apply_filters('rzv_manage_extra', new \stdClass(), $b),
        ];
    }

    public static function manage_cancel(\WP_REST_Request $r)
    {
        $b = Booking::cancel_by_customer((string) $r['token']);
        if (is_wp_error($b)) {
            $b->add_data(['status' => 400]);
            return $b;
        }
        return ['ok' => true, 'booking' => self::public_booking($b)];
    }

    public static function public_booking(array $b): array
    {
        $ts = Db::ts($b['start_at']);
        return [
            'service'  => $b['service_name'],
            'worker'   => $b['worker_name'],
            'date'     => wp_date('l j. n. Y', $ts, Db::tz()),
            'time'     => wp_date('H:i', $ts, Db::tz()),
            'end'      => wp_date('H:i', Db::ts($b['end_at']), Db::tz()),
            'price'    => Settings::money(Pricing::total($b)),
            'paid'     => (float) $b['paid'] > 0 ? Settings::money($b['paid']) : '',
            'due'      => Pricing::due($b) > 0 && (float) $b['paid'] > 0 ? Settings::money(Pricing::due($b)) : '',
            'discounts'=> array_values(array_filter([
                (float) $b['discount'] > 0 ? 'Zľava ' . trim($b['coupon_code'] . ' ' . ($b['loyalty_level'] ? 'vernostná ' . $b['loyalty_level'] : '')) . ': −' . Settings::money($b['discount']) : '',
                (float) $b['voucher_amount'] > 0 ? 'Poukážka: −' . Settings::money($b['voucher_amount']) : '',
            ])),
            'addons'   => array_column($b['addons_list'], 'name'),
            'status'   => $b['status'],
            'status_label' => $b['status_label'],
            'customer' => $b['customer_name'],
        ];
    }

    /* ---------- admin ---------- */

    public static function admin_calendar(\WP_REST_Request $r)
    {
        global $wpdb;
        $from = (string) $r['from'];
        $to = (string) $r['to'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return new \WP_Error('rzv_input', 'Neplatný rozsah.', ['status' => 400]);
        }
        $f = ['from' => $from, 'to' => $to];
        if (!$r['cancelled']) {
            $f['status'] = ['awaiting_payment', 'pending', 'confirmed', 'completed', 'no_show'];
        }
        if ($r['worker']) {
            $f['worker_id'] = (int) $r['worker'];
        }
        $bookings = array_map(fn($b) => [
            'id' => (int) $b['id'], 'start' => $b['start_at'], 'end' => $b['end_at'], 'status' => $b['status'],
            'worker_id' => (int) $b['worker_id'], 'service_id' => (int) $b['service_id'],
            'title' => $b['customer_name'], 'service' => $b['service_name'], 'phone' => $b['phone'],
            'color' => $b['service_color'] ?: ($b['worker_color'] ?: ''), 'note' => (string) $b['note'],
        ], Booking::query($f));

        $end = Db::dt($to)->modify('+1 day')->format('Y-m-d');
        $blocked = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Db::t('blocked') . ' WHERE (start_at < %s AND end_at > %s) OR repeat_weekly = 1', $end . ' 00:00:00', $from . ' 00:00:00'
        ), ARRAY_A) ?: [] as $bl) {
            if (!$bl['repeat_weekly']) {
                $blocked[] = ['id' => (int) $bl['id'], 'start' => $bl['start_at'], 'end' => $bl['end_at'], 'worker_id' => (int) $bl['worker_id'], 'note' => (string) $bl['note']];
                continue;
            }
            // rozvinúť týždenné opakovanie
            $first = Db::dt($bl['start_at']);
            $len = Db::ts($bl['end_at']) - $first->getTimestamp();
            $d = Db::dt($from);
            while ($d->format('Y-m-d') <= $to) {
                if ($d->format('N') === $first->format('N') && $d >= $first->setTime(0, 0) && (!$bl['repeat_until'] || $d->format('Y-m-d') <= $bl['repeat_until'])) {
                    $s = $d->setTime((int) $first->format('H'), (int) $first->format('i'));
                    $blocked[] = ['id' => (int) $bl['id'], 'start' => $s->format('Y-m-d H:i:s'), 'end' => Db::fmt($s->getTimestamp() + $len), 'worker_id' => (int) $bl['worker_id'], 'note' => (string) $bl['note'], 'repeat' => true];
                }
                $d = $d->modify('+1 day');
            }
        }
        return ['bookings' => $bookings, 'blocked' => $blocked];
    }

    public static function admin_times(\WP_REST_Request $r)
    {
        $slots = Slots::make((int) $r['service'], self::ids($r['addons']), (int) $r['worker'], (int) $r['exclude'], true);
        $date = (string) $r['date'];
        if (!$slots || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('rzv_input', 'Neplatná požiadavka.', ['status' => 400]);
        }
        $slots->load($date, $date);
        return ['times' => $slots->day($date), 'duration' => $slots->duration()];
    }

    public static function admin_get(\WP_REST_Request $r)
    {
        $b = Booking::get((int) $r['id']);
        if (!$b) {
            return new \WP_Error('rzv_404', 'Neexistuje.', ['status' => 404]);
        }
        $b['payments'] = array_map(fn($p) => $p + [
            'method_label' => Payments::METHODS[$p['method']] ?? $p['method'],
            'status_label' => Payments::STATUSES[$p['status']] ?? $p['status'],
        ], Payments::for_booking((int) $b['id']));
        $b['total'] = Pricing::total($b);
        $b['due'] = Pricing::due($b);
        $b['voucher_code'] = $b['voucher_id'] ? (Vouchers::get((int) $b['voucher_id'])['code'] ?? '') : '';
        return $b;
    }

    public static function admin_create(\WP_REST_Request $r)
    {
        $b = Booking::create($r->get_json_params() ?: [], true);
        if (is_wp_error($b)) {
            $b->add_data(['status' => 400]);
        }
        return $b;
    }

    public static function admin_update(\WP_REST_Request $r)
    {
        $b = Booking::update((int) $r['id'], $r->get_json_params() ?: []);
        if (is_wp_error($b)) {
            $b->add_data(['status' => 400]);
        }
        return $b;
    }

    public static function admin_customers(\WP_REST_Request $r)
    {
        global $wpdb;
        $raw = trim((string) $r['q']);
        $q = '%' . $wpdb->esc_like($raw) . '%';
        // telefón hľadať bez medzier a aj v tvare +421… (0911 → +421911)
        $digits = preg_replace('/[^\d+]/', '', $raw);
        $p1 = '%' . $wpdb->esc_like($digits ?: $raw) . '%';
        $p2 = $p1;
        if ($digits !== '' && $digits[0] === '0' && ($digits[1] ?? '') !== '0') {
            $p2 = '%' . $wpdb->esc_like('+421' . substr($digits, 1)) . '%';
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, first_name, last_name, email, phone FROM ' . Db::t('customers') .
            ' WHERE CONCAT(first_name, " ", last_name) LIKE %s OR email LIKE %s OR phone LIKE %s OR phone LIKE %s ORDER BY last_name LIMIT 20',
            $q, $q, $p1, $p2
        ), ARRAY_A) ?: [];
    }

    public static function admin_block(\WP_REST_Request $r)
    {
        global $wpdb;
        $d = $r->get_json_params() ?: [];
        $s = (string) ($d['start'] ?? '');
        $e = (string) ($d['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $e) || $e <= $s) {
            return new \WP_Error('rzv_input', 'Neplatný čas.', ['status' => 400]);
        }
        $wpdb->insert(Db::t('blocked'), [
            'worker_id' => !empty($d['worker_id']) ? (int) $d['worker_id'] : null,
            'start_at'  => substr($s, 0, 16) . ':00',
            'end_at'    => substr($e, 0, 16) . ':00',
            'note'      => sanitize_text_field($d['note'] ?? ''),
        ]);
        return ['id' => (int) $wpdb->insert_id];
    }

    public static function admin_unblock(\WP_REST_Request $r)
    {
        global $wpdb;
        $wpdb->delete(Db::t('blocked'), ['id' => (int) $r['id']]);
        return ['ok' => true];
    }

    /* ---------- pomocné ---------- */

    private static function ids($v): array
    {
        if (is_array($v)) {
            return array_filter(array_map('intval', $v));
        }
        return array_filter(array_map('intval', explode(',', (string) $v)));
    }

    private static function throttle(string $key, int $per_hour): ?\WP_Error
    {
        if (current_user_can('rzv_manage')) {
            return null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $t = 'rzv_thr_' . $key . '_' . md5($ip);
        $n = (int) get_transient($t);
        if ($n >= $per_hour) {
            return new \WP_Error('rzv_throttle', 'Príliš veľa pokusov. Skúste to prosím neskôr.', ['status' => 429]);
        }
        set_transient($t, $n + 1, HOUR_IN_SECONDS);
        return null;
    }
}
