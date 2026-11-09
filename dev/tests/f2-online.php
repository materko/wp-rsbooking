<?php
/**
 * Test F2 – online platby (bez skutočného Stripe): stav "čaká na platbu", expirácia, uvoľnenie termínu.
 * Spustenie: docker compose run --rm cli wp eval-file /dev-scripts/tests/f2-online.php
 */
use Rzv\Booking;
use Rzv\Db;
use Rzv\Payments;
use Rzv\Settings;
use Rzv\Slots;

$ok = function ($cond, $msg) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $msg . PHP_EOL;
};
global $wpdb;

$orig = Settings::all();
Settings::set('online_enabled', 1);
Settings::set('stripe_secret', 'sk_test_neplatny_kluc_na_test');
Settings::set('payment_mode', 'deposit');
$wpdb->update(Db::t('services'), ['deposit' => 10], ['id' => 2]);

try {
    $person = ['first_name' => 'Online', 'last_name' => 'Platba', 'email' => 'online@example.test', 'phone' => '0905333444'];
    $b = Booking::create($person + ['service_id' => 2, 'worker_id' => 1, 'date' => '2026-11-18', 'time' => '09:00']);
    $ok(!is_wp_error($b) && $b['status'] === 'awaiting_payment' && (float) $b['pay_online'] === 10.0 && $b['expires_at'],
        'rezervácia so zálohou: ' . ($b['status'] ?? '?') . ', online ' . ($b['pay_online'] ?? '?') . ', do ' . ($b['expires_at'] ?? '?'));

    // termín je počas čakania na platbu obsadený
    $s = Slots::make(2, [], 1);
    $s->load('2026-11-18', '2026-11-18');
    $ok(!isset($s->day('2026-11-18')['09:00']), 'termín 09:00 počas čakania na platbu obsadený');

    // žiadny e-mail zákazníkovi pred zaplatením
    $mails = json_decode(wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/search?query=to:online@example.test')), true);
    $ok(($mails['messages_count'] ?? 0) === 0, 'pred zaplatením zákazník nedostal e-mail');

    // Stripe s neplatným kľúčom → chyba, nie pád
    $ins = Payments::for_booking_due($b);
    $url = \Rzv\Stripe::checkout(Payments::get((int) $ins['payment_id']));
    $ok(is_wp_error($url), 'Stripe s neplatným kľúčom vráti chybu: ' . (is_wp_error($url) ? $url->get_error_message() : $url));

    // expirácia → uvoľnenie
    $wpdb->update(Db::t('bookings'), ['expires_at' => Db::fmt(time() - 60)], ['id' => (int) $b['id']]);
    Payments::cron();
    $b2 = Booking::get((int) $b['id']);
    $ok($b2['status'] === 'cancelled', 'po expirácii: ' . $b2['status']);
    $s = Slots::make(2, [], 1);
    $s->load('2026-11-18', '2026-11-18');
    $ok(isset($s->day('2026-11-18')['09:00']), 'termín 09:00 je opäť voľný');

    // zaplatenie (simulácia webhooku) → potvrdenie + e-maily
    $b = Booking::create($person + ['service_id' => 2, 'worker_id' => 1, 'date' => '2026-11-18', 'time' => '10:00']);
    $ins = Payments::for_booking_due($b);
    Payments::mark_paid((int) $ins['payment_id'], 'stripe', 'pi_test_123');
    Payments::mark_paid((int) $ins['payment_id'], 'stripe', 'pi_test_123'); // duplicitný webhook
    $b = Booking::get((int) $b['id']);
    $ok($b['status'] === 'confirmed' && (float) $b['paid'] === 10.0 && !$b['expires_at'], "po zaplatení: {$b['status']}, zaplatené {$b['paid']}, zostáva " . \Rzv\Pricing::due($b));
    sleep(1);
    $mails = json_decode(wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/search?query=to:online@example.test')), true);
    $ok(($mails['messages_count'] ?? 0) === 1, 'po zaplatení presne 1 e-mail zákazníkovi (' . ($mails['messages_count'] ?? 0) . ')');
} finally {
    Settings::save($orig);
    $wpdb->update(Db::t('services'), ['deposit' => 0], ['id' => 2]);
}
