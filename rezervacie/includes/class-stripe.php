<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Stripe Checkout cez REST API (bez SDK). */
class Stripe
{
    const API = 'https://api.stripe.com/v1/';

    public static function configured(): bool
    {
        return (bool) preg_match('/^(sk|rk)_(test|live)_/', (string) Settings::get('stripe_secret'));
    }

    public static function test_mode(): bool
    {
        return str_contains((string) Settings::get('stripe_secret'), '_test_');
    }

    private static function request(string $method, string $path, array $body = [])
    {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization'  => 'Bearer ' . Settings::get('stripe_secret'),
                'Stripe-Version' => '2024-06-20',
            ],
        ];
        if ($body) {
            $args['body'] = $body;
        }
        $res = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($res)) {
            return $res;
        }
        $json = json_decode(wp_remote_retrieve_body($res), true);
        $code = wp_remote_retrieve_response_code($res);
        if ($code >= 300) {
            return new \WP_Error('rzv_stripe', 'Stripe: ' . ($json['error']['message'] ?? 'HTTP ' . $code));
        }
        return $json;
    }

    /** Vytvorí Checkout Session pre čakajúcu platbu a vráti URL na presmerovanie. */
    public static function checkout(array $p)
    {
        if ($p['status'] !== 'pending') {
            return new \WP_Error('rzv_pay', 'Platba už bola spracovaná.');
        }
        $name = 'Platba';
        $email = '';
        $back = Settings::booking_url();
        if ($p['booking_id']) {
            $b = Booking::get((int) $p['booking_id']);
            if (!$b || in_array($b['status'], ['cancelled', 'no_show'], true)) {
                return new \WP_Error('rzv_pay', 'Rezervácia už nie je platná.');
            }
            $name = $b['service_name'] . ' – ' . wp_date('j. n. Y H:i', Db::ts($b['start_at']), Db::tz());
            $email = $b['email'];
            $back = Booking::manage_url($b);
        } elseif ($p['voucher_id']) {
            $v = Vouchers::get((int) $p['voucher_id']);
            if (!$v || $v['status'] !== 'unpaid') {
                return new \WP_Error('rzv_pay', 'Poukážka už nie je na zaplatenie.');
            }
            $name = 'Poukážka ' . $v['name'];
            $email = (string) $v['buyer_email'];
            $back = Vouchers::url($v);
        }
        $return = rest_url(Rest::NS . '/stripe/return') . '?pid=' . $p['id'] . '&k=' . Payments::sign((int) $p['id']) . '&session_id={CHECKOUT_SESSION_ID}';
        $body = [
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => (int) round((float) $p['amount'] * 100),
            'line_items[0][price_data][product_data][name]' => mb_substr($name, 0, 200),
            'success_url' => $return,
            'cancel_url' => add_query_arg('rzv_pay', 'cancel', $back),
            'client_reference_id' => 'rzv-' . $p['id'],
            'metadata[rzv_payment]' => (string) $p['id'],
            'payment_intent_data[metadata][rzv_payment]' => (string) $p['id'],
            'locale' => 'sk',
        ];
        if (is_email($email)) {
            $body['customer_email'] = $email;
        }
        $timeout = (int) Settings::get('payment_timeout', 30);
        if ($p['booking_id'] && $timeout >= 30) {
            $body['expires_at'] = time() + $timeout * 60; // Stripe vyžaduje min. 30 min
        }
        $s = self::request('POST', 'checkout/sessions', $body);
        if (is_wp_error($s)) {
            return $s;
        }
        global $wpdb;
        $wpdb->update(Db::t('payments'), ['provider_ref' => $s['id']], ['id' => (int) $p['id']]);
        return $s['url'];
    }

    /** Overenie po návrate zo Stripe (funguje aj bez webhooku). */
    public static function verify_session(array $p, string $session_id): bool
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $session_id)) {
            return false;
        }
        $s = self::request('GET', 'checkout/sessions/' . rawurlencode($session_id));
        if (is_wp_error($s)) {
            return false;
        }
        if (($s['metadata']['rzv_payment'] ?? '') !== (string) $p['id']) {
            return false;
        }
        if (($s['payment_status'] ?? '') === 'paid') {
            Payments::mark_paid((int) $p['id'], 'stripe', (string) ($s['payment_intent'] ?? $s['id']));
            return true;
        }
        return false;
    }

    /** Webhook: checkout.session.completed / async_payment_succeeded. */
    public static function webhook(\WP_REST_Request $r)
    {
        $payload = $r->get_body();
        $sig = (string) $r->get_header('stripe-signature');
        if (!self::verify_signature($payload, $sig, (string) Settings::get('stripe_webhook_secret'))) {
            return new \WP_Error('rzv_sig', 'Neplatný podpis.', ['status' => 400]);
        }
        $e = json_decode($payload, true);
        $type = $e['type'] ?? '';
        $obj = $e['data']['object'] ?? [];
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && ($obj['payment_status'] ?? '') === 'paid') {
            $pid = (int) ($obj['metadata']['rzv_payment'] ?? 0);
            if ($pid && Payments::get($pid)) {
                Payments::mark_paid($pid, 'stripe', (string) ($obj['payment_intent'] ?? $obj['id'] ?? ''));
            }
        }
        return ['received' => true];
    }

    public static function verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if (!$t || !$sigs || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }
        return false;
    }
}
