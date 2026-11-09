<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Booking
{
    const STATUSES = [
        'awaiting_payment' => 'Čaká na platbu',
        'pending'   => 'Čaká na potvrdenie',
        'confirmed' => 'Potvrdená',
        'completed' => 'Zrealizovaná',
        'no_show'   => 'Neprišiel',
        'cancelled' => 'Zrušená',
    ];

    /**
     * Vytvorí rezerváciu.
     * $d: service_id, worker_id (0 = ktokoľvek), date (Y-m-d), time (H:i), addons[], answers{field_id:val},
     *     first_name, last_name, email, phone, note, [status, internal_note, price, force] (admin)
     * @return array|\WP_Error riadok rezervácie
     */
    public static function create(array $d, bool $admin = false)
    {
        global $wpdb;

        $service_id = (int) ($d['service_id'] ?? 0);
        $worker_id  = (int) ($d['worker_id'] ?? 0);
        $date       = (string) ($d['date'] ?? '');
        $time       = (string) ($d['time'] ?? '');
        $addons     = array_map('intval', (array) ($d['addons'] ?? []));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return new \WP_Error('rzv_input', 'Neplatný dátum alebo čas.');
        }

        $customer = Customers::validate($d, $admin);
        if (is_wp_error($customer)) {
            return $customer;
        }
        if (!$admin && !empty($customer['id']) && !empty($customer['blocked'])) {
            return new \WP_Error('rzv_blocked', 'Online rezervácia nie je možná. Kontaktujte nás prosím telefonicky.');
        }

        $answers = Fields::validate($service_id, (array) ($d['answers'] ?? []), $admin);
        if (is_wp_error($answers)) {
            return $answers;
        }

        $slots = Slots::make($service_id, $addons, $worker_id, 0, $admin);
        if (!$slots || !$slots->worker_ids()) {
            return new \WP_Error('rzv_input', 'Služba nie je dostupná.');
        }

        $lock = 'rzv_book_' . $wpdb->prefix;
        $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        try {
            $slots->load($date, $date);
            $start_at = $date . ' ' . $time . ':00';

            // pracovník 0 = prenájom bez pracovníka, null = nič voľné
            $ids = $slots->worker_ids();
            $candidates = $worker_id && in_array($worker_id, $ids, true) ? [$worker_id] : $ids;
            if ($admin && !empty($d['force'])) {
                $chosen = $candidates[0] ?? null;
            } elseif ($admin) {
                $chosen = null;
                foreach ($candidates as $wid) {
                    if ($slots->check($wid, $start_at, true)) {
                        $chosen = $wid;
                        break;
                    }
                }
            } else {
                $free   = $slots->day($date)[$time] ?? [];
                $chosen = $free ? $free[0] : null;
            }
            if ($chosen === null) {
                return new \WP_Error('rzv_taken', 'Tento termín už nie je voľný. Vyberte prosím iný čas.');
            }

            $service = Db::row('services', $service_id);
            $addon_rows = self::addon_rows($service_id, $addons);
            $codes = array_filter(array_merge((array) ($d['codes'] ?? []), [(string) ($d['code'] ?? '')]));
            $quote = Pricing::quote($service_id, $addons, $codes, (string) ($customer['email'] ?? ''), $admin);
            if (is_wp_error($quote)) {
                return $quote;
            }
            $price = $quote['base'];
            if ($admin && isset($d['price']) && $d['price'] !== '') {
                $price = (float) $d['price'];
            }

            $start_ts = Db::ts($start_at);
            $end_at   = Db::fmt($start_ts + $slots->duration() * 60);

            $customer_id = Customers::upsert($customer);

            $status = $service['auto_confirm'] ? 'confirmed' : 'pending';
            if ($admin && !empty($d['status']) && isset(self::STATUSES[$d['status']])) {
                $status = $d['status'];
            }
            $expires = null;
            if (!$admin && $quote['online_required'] && $quote['pay_online'] > 0) {
                $status = 'awaiting_payment';
                $expires = Db::fmt(time() + max(10, (int) Settings::get('payment_timeout', 30)) * 60);
            }

            $now = Db::now();
            $row = [
                'token'         => self::token(),
                'customer_id'   => $customer_id,
                'service_id'    => $service_id,
                'worker_id'     => $chosen,
                'start_at'      => $start_at,
                'end_at'        => $end_at,
                'status'        => $status,
                'price'         => $price,
                'discount'      => $quote['discount'],
                'coupon_code'   => $quote['coupon'],
                'loyalty_level' => $quote['loyalty_level'],
                'voucher_id'    => $quote['voucher']['id'] ?? null,
                'voucher_amount'=> $quote['voucher_amount'],
                'pay_online'    => $quote['pay_online'],
                'expires_at'    => $expires,
                'addons'        => wp_json_encode($addon_rows),
                'answers'       => wp_json_encode($answers),
                'note'          => sanitize_textarea_field($d['note'] ?? ''),
                'internal_note' => $admin ? sanitize_textarea_field($d['internal_note'] ?? '') : '',
                'source'        => $admin ? 'admin' : 'web',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $row = apply_filters('rzv_booking_before_insert', $row, $d, $admin);
            if (is_wp_error($row)) {
                return $row;
            }
            $wpdb->insert(Db::t('bookings'), $row);
            $id = (int) $wpdb->insert_id;
            if ($id) {
                Pricing::commit(['id' => $id], $quote);
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }

        if (!$id) {
            return new \WP_Error('rzv_db', 'Rezerváciu sa nepodarilo uložiť.');
        }
        $booking = self::get($id);
        do_action('rzv_booking_created', $booking, $d, $admin);
        if ($status === 'awaiting_payment') {
            return $booking; // e-maily sa pošlú po zaplatení
        }
        if (!$admin || !empty($d['notify'])) {
            Mailer::booking($booking, $status === 'confirmed' ? 'confirmed' : 'pending');
        }
        if (!$admin) {
            Mailer::admin_new($booking);
        }
        return $booking;
    }

    /** Admin úprava (presun termínu, pracovník, cena, poznámky, stav). */
    public static function update(int $id, array $d)
    {
        global $wpdb;
        $b = self::get($id);
        if (!$b) {
            return new \WP_Error('rzv_404', 'Rezervácia neexistuje.');
        }
        $upd = [];
        $moved = false;

        $service_id = isset($d['service_id']) ? (int) $d['service_id'] : (int) $b['service_id'];
        $worker_id  = isset($d['worker_id']) ? (int) $d['worker_id'] : (int) $b['worker_id'];
        $date = $d['date'] ?? substr($b['start_at'], 0, 10);
        $time = $d['time'] ?? substr($b['start_at'], 11, 5);
        $start_at = $date . ' ' . $time . ':00';
        $addons = isset($d['addons']) ? array_map('intval', (array) $d['addons']) : array_map('intval', array_column($b['addons_list'], 'id'));

        if ($service_id !== (int) $b['service_id'] || $worker_id !== (int) $b['worker_id'] || $start_at !== $b['start_at'] || isset($d['addons'])) {
            $slots = Slots::make($service_id, $addons, $worker_id, $id, true);
            if (!$slots) {
                return new \WP_Error('rzv_input', 'Neplatná služba.');
            }
            $slots->load($date, $date);
            if (empty($d['force']) && !$slots->check($worker_id, $start_at, true)) {
                return new \WP_Error('rzv_taken', 'Termín koliduje s inou rezerváciou alebo blokáciou.');
            }
            $upd['service_id'] = $service_id;
            $upd['worker_id']  = $worker_id;
            $upd['start_at']   = $start_at;
            $upd['end_at']     = isset($d['end_at']) ? $d['end_at'] : Db::fmt(Db::ts($start_at) + $slots->duration() * 60);
            $upd['addons']     = wp_json_encode(self::addon_rows($service_id, $addons));
            $moved = $start_at !== $b['start_at'];
            if ($moved) {
                $upd['reminder_sent'] = 0;
            }
        }
        foreach (['price', 'paid', 'discount'] as $k) {
            if (isset($d[$k]) && $d[$k] !== '') {
                $upd[$k] = (float) $d[$k];
            }
        }
        foreach (['note', 'internal_note'] as $k) {
            if (isset($d[$k])) {
                $upd[$k] = sanitize_textarea_field($d[$k]);
            }
        }
        if ($upd) {
            $upd['updated_at'] = Db::now();
            $wpdb->update(Db::t('bookings'), $upd, ['id' => $id]);
        }
        if (!empty($d['status']) && $d['status'] !== $b['status']) {
            self::set_status($id, $d['status'], !empty($d['notify']));
        } elseif ($moved && !empty($d['notify'])) {
            Mailer::booking(self::get($id), 'changed');
        }
        if ($moved) {
            Waitlist::on_slot_freed($b);
        }
        do_action('rzv_booking_updated', self::get($id), $b);
        return self::get($id);
    }

    public static function set_status(int $id, string $status, bool $notify = true)
    {
        global $wpdb;
        $b = self::get($id);
        if (!$b || !isset(self::STATUSES[$status])) {
            return new \WP_Error('rzv_input', 'Neplatný stav.');
        }
        if ($b['status'] === $status) {
            return $b;
        }
        $wpdb->update(Db::t('bookings'), ['status' => $status, 'updated_at' => Db::now()], ['id' => $id]);
        if ($status === 'cancelled') {
            Pricing::release($b);
            $wpdb->update(Db::t('payments'), ['status' => 'cancelled'], ['booking_id' => $id, 'status' => 'pending']);
        } elseif ($b['status'] === 'cancelled') {
            // obnovenie zrušenej rezervácie – znovu započítať kód / poukážku
            if ($b['coupon_code']) {
                Coupons::count_use($b['coupon_code']);
            }
            if ($b['voucher_id'] && (float) $b['voucher_amount'] > 0) {
                $v = Vouchers::get((int) $b['voucher_id']);
                Vouchers::redeem((int) $b['voucher_id'], $id, (float) $b['voucher_amount'], $v && $v['kind'] !== 'value' ? 1 : 0, 'Obnovená rezervácia #' . $id);
            }
        }
        $nb = self::get($id);
        do_action('rzv_booking_status', $nb, $b['status']);
        if ($notify && in_array($status, ['confirmed', 'cancelled'], true) && $b['status'] !== 'awaiting_payment') {
            Mailer::booking($nb, $status);
        }
        if ($status === 'cancelled') {
            Waitlist::on_slot_freed($b);
        }
        return $nb;
    }

    /** Zrušenie zákazníkom cez odkaz z e-mailu. */
    public static function cancel_by_customer(string $token)
    {
        $b = self::by_token($token);
        if (!$b) {
            return new \WP_Error('rzv_404', 'Rezervácia neexistuje.');
        }
        $can = self::can_customer_cancel($b);
        if (is_wp_error($can)) {
            return $can;
        }
        $nb = self::set_status((int) $b['id'], 'cancelled', true);
        Mailer::admin_cancelled($nb);
        return $nb;
    }

    public static function can_customer_cancel(array $b)
    {
        if (!in_array($b['status'], ['pending', 'confirmed', 'awaiting_payment'], true)) {
            return new \WP_Error('rzv_state', 'Rezerváciu už nie je možné zrušiť.');
        }
        if (!Settings::get('cancel_allowed')) {
            return new \WP_Error('rzv_state', 'Online zrušenie nie je povolené. Kontaktujte nás prosím.');
        }
        $limit = (float) Settings::get('cancel_hours_before', 0) * HOUR_IN_SECONDS;
        if (Db::ts($b['start_at']) - time() < $limit) {
            return new \WP_Error('rzv_late', sprintf('Rezerváciu je možné zrušiť najneskôr %s h pred termínom. Kontaktujte nás prosím.', Settings::get('cancel_hours_before')));
        }
        return true;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(self::select_sql() . ' WHERE b.id = %d', $id), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    public static function by_token(string $token): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-zA-Z0-9]{20,64}$/', $token)) {
            return null;
        }
        $r = $wpdb->get_row($wpdb->prepare(self::select_sql() . ' WHERE b.token = %s', $token), ARRAY_A);
        return $r ? self::hydrate($r) : null;
    }

    /** Zoznam s filtrami: from, to (Y-m-d), status, worker_id, service_id, customer_id, q, limit, offset. */
    public static function query(array $f = []): array
    {
        global $wpdb;
        [$where, $args] = self::where($f);
        $sql = self::select_sql() . " WHERE $where ORDER BY b.start_at " . (($f['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC');
        if (!empty($f['limit'])) {
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', (int) $f['limit'], (int) ($f['offset'] ?? 0));
        }
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function count(array $f = []): int
    {
        global $wpdb;
        [$where, $args] = self::where($f);
        $sql = 'SELECT COUNT(*) FROM ' . Db::t('bookings') . ' b LEFT JOIN ' . Db::t('customers') . " c ON c.id = b.customer_id WHERE $where";
        return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    private static function where(array $f): array
    {
        global $wpdb;
        $w = ['1=1'];
        $a = [];
        if (!empty($f['from'])) { $w[] = 'b.start_at >= %s'; $a[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $w[] = 'b.start_at < %s'; $a[] = Db::dt($f['to'])->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
        if (!empty($f['status'])) {
            $st = array_intersect((array) $f['status'], array_keys(self::STATUSES));
            if ($st) {
                $w[] = "b.status IN ('" . implode("','", $st) . "')";
            }
        }
        foreach (['worker_id', 'service_id', 'customer_id'] as $k) {
            if (!empty($f[$k])) { $w[] = "b.$k = %d"; $a[] = (int) $f[$k]; }
        }
        if (!empty($f['q'])) {
            $like = '%' . $wpdb->esc_like($f['q']) . '%';
            $w[] = '(c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR CONCAT(c.first_name, " ", c.last_name) LIKE %s)';
            array_push($a, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $a];
    }

    private static function select_sql(): string
    {
        return 'SELECT b.*, c.first_name, c.last_name, c.email, c.phone, s.name AS service_name, s.color AS service_color,
                w.name AS worker_name, w.color AS worker_color
                FROM ' . Db::t('bookings') . ' b
                LEFT JOIN ' . Db::t('customers') . ' c ON c.id = b.customer_id
                LEFT JOIN ' . Db::t('services') . ' s ON s.id = b.service_id
                LEFT JOIN ' . Db::t('workers') . ' w ON w.id = b.worker_id';
    }

    private static function hydrate(array $r): array
    {
        $r['addons_list']  = json_decode($r['addons'] ?: '[]', true) ?: [];
        $r['answers_list'] = json_decode($r['answers'] ?: '[]', true) ?: [];
        $r['customer_name'] = trim($r['first_name'] . ' ' . $r['last_name']);
        $r['status_label'] = self::STATUSES[$r['status']] ?? $r['status'];
        return $r;
    }

    public static function addon_rows(int $service_id, array $ids): array
    {
        $allowed = Db::pivot('service_addon', 'service_id', $service_id, 'addon_id');
        $out = [];
        foreach (array_intersect($ids, $allowed) as $aid) {
            $a = Db::row('addons', $aid);
            if ($a) {
                $out[] = ['id' => (int) $a['id'], 'name' => $a['name'], 'price' => (float) $a['price'], 'duration' => (int) $a['duration']];
            }
        }
        return $out;
    }

    public static function token(): string
    {
        return wp_generate_password(32, false, false);
    }

    public static function manage_url(array $b): string
    {
        return Settings::booking_url(['rzv_manage' => $b['token']]);
    }
}
