<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Payments
{
    const METHODS = [
        'pending'  => 'Nezaplatené',
        'stripe'   => 'Online kartou',
        'transfer' => 'Prevod / QR',
        'cash'     => 'Hotovosť',
        'card'     => 'Karta na prevádzke',
        'voucher'  => 'Poukážka',
        'other'    => 'Iné',
    ];
    const STATUSES = ['pending' => 'Čaká', 'paid' => 'Zaplatené', 'cancelled' => 'Zrušené', 'refunded' => 'Vrátené'];

    public static function create(array $d): int
    {
        global $wpdb;
        $row = [
            'booking_id'   => !empty($d['booking_id']) ? (int) $d['booking_id'] : null,
            'voucher_id'   => !empty($d['voucher_id']) ? (int) $d['voucher_id'] : null,
            'method'       => isset(self::METHODS[$d['method'] ?? '']) ? $d['method'] : 'other',
            'amount'       => round((float) ($d['amount'] ?? 0), 2),
            'status'       => $d['status'] ?? 'pending',
            'reference'    => $d['reference'] ?? null,
            'provider_ref' => $d['provider_ref'] ?? null,
            'note'         => isset($d['note']) ? sanitize_text_field($d['note']) : null,
            'created_at'   => Db::now(),
            'paid_at'      => ($d['status'] ?? '') === 'paid' ? Db::now() : null,
        ];
        $wpdb->insert(Db::t('payments'), $row);
        $id = (int) $wpdb->insert_id;
        if (!$row['reference']) {
            $wpdb->update(Db::t('payments'), ['reference' => self::vs_for($row)], ['id' => $id]);
        }
        if ($row['status'] === 'paid') {
            self::after_paid(self::get($id));
        }
        return $id;
    }

    /** Variabilný symbol: rezervácia = ID, poukážka = 9 000 000 + ID. */
    public static function vs_for(array $row): string
    {
        return !empty($row['voucher_id']) ? (string) (9000000 + (int) $row['voucher_id']) : (string) (int) $row['booking_id'];
    }

    public static function get(int $id): ?array
    {
        return Db::row('payments', $id);
    }

    public static function for_booking(int $booking_id): array
    {
        return Db::all('payments', 'booking_id = ' . (int) $booking_id, 'id ASC');
    }

    public static function for_voucher(int $voucher_id): array
    {
        return Db::all('payments', 'voucher_id = ' . (int) $voucher_id, 'id ASC');
    }

    /** Označí platbu ako zaplatenú (webhook, návrat zo Stripe alebo ručne v admine). */
    public static function mark_paid(int $id, string $method = '', string $provider_ref = ''): ?array
    {
        global $wpdb;
        $p = self::get($id);
        if (!$p || $p['status'] === 'paid') {
            return $p;
        }
        $upd = ['status' => 'paid', 'paid_at' => Db::now()];
        if ($method && isset(self::METHODS[$method])) {
            $upd['method'] = $method;
        }
        if ($provider_ref) {
            $upd['provider_ref'] = $provider_ref;
        }
        // atomicky – webhook a návrat zo Stripe môžu prísť naraz
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Db::t('payments') . " SET status = 'paid', paid_at = %s WHERE id = %d AND status <> 'paid'", $upd['paid_at'], $id
        ));
        if (!$n) {
            return self::get($id);
        }
        $wpdb->update(Db::t('payments'), $upd, ['id' => $id]);
        $p = self::get($id);
        self::after_paid($p);
        return $p;
    }

    private static function after_paid(array $p): void
    {
        global $wpdb;
        if ($p['booking_id']) {
            $bid = (int) $p['booking_id'];
            $sum = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . Db::t('payments') . " WHERE booking_id = %d AND status = 'paid'", $bid));
            $wpdb->update(Db::t('bookings'), ['paid' => $sum, 'updated_at' => Db::now()], ['id' => $bid]);
            $b = Booking::get($bid);
            if ($b && $b['status'] === 'awaiting_payment') {
                $svc = Db::row('services', (int) $b['service_id']);
                $status = !empty($svc['auto_confirm']) ? 'confirmed' : 'pending';
                $wpdb->update(Db::t('bookings'), ['status' => $status, 'expires_at' => null], ['id' => $bid]);
                $b = Booking::get($bid);
                Mailer::booking($b, $status);
                Mailer::admin_new($b);
            }
            do_action('rzv_booking_paid', $b, $p);
        }
        if ($p['voucher_id']) {
            $v = Vouchers::get((int) $p['voucher_id']);
            if ($v && $v['status'] === 'unpaid') {
                Vouchers::activate((int) $v['id']);
            }
        }
    }

    /** Platobné údaje pre nezaplatenú poukážku. */
    public static function pending_for_voucher(int $voucher_id): ?array
    {
        foreach (self::for_voucher($voucher_id) as $p) {
            if ($p['status'] === 'pending') {
                $v = Vouchers::get($voucher_id);
                return self::instructions($p, 'Poukazka ' . ($v['code'] ?? ''), null);
            }
        }
        return null;
    }

    /** Údaje na zaplatenie: QR (PAY by square), IBAN, VS, odkaz na online platbu. */
    public static function instructions(array $p, string $note, ?string $due_date): array
    {
        $out = ['amount' => (float) $p['amount'], 'vs' => $p['reference'], 'payment_id' => (int) $p['id']];
        if (Settings::get('qr_enabled') && Settings::get('iban')) {
            $out['iban'] = trim(chunk_split(strtoupper(preg_replace('/\s+/', '', Settings::get('iban'))), 4, ' '));
            $out['beneficiary'] = Settings::get('beneficiary');
            $out['qr'] = PayBySquare::encode([
                'iban'   => Settings::get('iban'),
                'bic'    => Settings::get('bic'),
                'amount' => (float) $p['amount'],
                'vs'     => $p['reference'],
                'note'   => $note,
                'name'   => Settings::get('beneficiary') ?: Settings::get('business_name'),
                'due'    => $due_date,
            ]);
        }
        if (Settings::get('online_enabled') && Stripe::configured()) {
            $out['online_url'] = rest_url(Rest::NS . '/pay/' . $p['id'] . '?k=' . self::sign((int) $p['id']));
        }
        return $out;
    }

    /** Údaje na zaplatenie zostatku rezervácie (vytvorí čakajúcu platbu, ak treba). */
    public static function for_booking_due(array $b): ?array
    {
        $due = Pricing::due($b);
        if ($due <= 0 || in_array($b['status'], ['cancelled', 'no_show'], true)) {
            return null;
        }
        $pending = null;
        foreach (self::for_booking((int) $b['id']) as $p) {
            if ($p['status'] === 'pending') {
                $pending = $p;
            }
        }
        $amount = $b['status'] === 'awaiting_payment' && (float) $b['pay_online'] > 0 ? (float) $b['pay_online'] : $due;
        if (!$pending) {
            $pending = self::get(self::create(['booking_id' => (int) $b['id'], 'method' => 'pending', 'amount' => $amount]));
        } elseif (abs((float) $pending['amount'] - $amount) > 0.001) {
            global $wpdb;
            $wpdb->update(Db::t('payments'), ['amount' => $amount], ['id' => (int) $pending['id']]);
            $pending['amount'] = $amount;
        }
        $qr_days = (int) Settings::get('qr_due_days', 0);
        $due_date = $qr_days > 0 ? wp_date('Y-m-d', time() + $qr_days * DAY_IN_SECONDS) : substr($b['start_at'], 0, 10);
        return self::instructions($pending, 'Rezervacia ' . $b['id'] . ' ' . $b['service_name'], $due_date);
    }

    public static function sign(int $id): string
    {
        return substr(hash_hmac('sha256', 'rzv-pay-' . $id, wp_salt('auth')), 0, 20);
    }

    /** Uvoľní termíny nezaplatené v časovom limite. */
    public static function cron(): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Db::t('bookings') . " WHERE status = 'awaiting_payment' AND expires_at IS NOT NULL AND expires_at < %s LIMIT 100", Db::now()
        ));
        foreach ($ids as $id) {
            Booking::set_status((int) $id, 'cancelled', false);
            $wpdb->update(Db::t('payments'), ['status' => 'cancelled'], ['booking_id' => (int) $id, 'status' => 'pending']);
        }
    }

    /** Text pre e-mail: {platba} */
    public static function email_text(?array $ins): string
    {
        if (!$ins) {
            return '';
        }
        $lines = [];
        if (!empty($ins['iban'])) {
            $qr_link = !empty($ins['payment_id']) ? rest_url(Rest::NS . '/qr/' . $ins['payment_id'] . '?k=' . self::sign((int) $ins['payment_id'])) : '';
            $lines[] = strtr(Settings::get('tpl_payment_text'), [
                '{iban}' => $ins['iban'], '{suma}' => Settings::money($ins['amount']), '{vs}' => $ins['vs'], '{qr_odkaz}' => $qr_link,
                '{prijemca}' => $ins['beneficiary'] ?? '',
            ]);
        }
        if (!empty($ins['online_url'])) {
            $lines[] = 'Zaplatiť kartou online: ' . $ins['online_url'];
        }
        return implode("\n\n", $lines);
    }
}
