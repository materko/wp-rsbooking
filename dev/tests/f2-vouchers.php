<?php
/**
 * Test F2 – poukážky: zaplatenie, uplatnenie pri rezervácii, storno s vrátením, permanentka.
 * Spustenie: docker compose run --rm cli wp eval-file /dev-scripts/tests/f2-vouchers.php
 */
use Rzv\Booking;
use Rzv\Payments;
use Rzv\Vouchers;
use Rzv\Db;

$ok = function ($cond, $msg) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $msg . PHP_EOL;
};

// 1) zaplatenie objednanej poukážky
$v = Vouchers::find(Db::row('vouchers', 1)['code']);
if ($v['status'] === 'unpaid') {
    $pending = array_values(array_filter(Payments::for_voucher((int) $v['id']), fn($p) => $p['status'] === 'pending'));
    Payments::mark_paid((int) $pending[0]['id'], 'transfer');
}
$v = Vouchers::get((int) $v['id']);
$ok($v['status'] === 'active' && $v['valid_to'], "poukážka {$v['code']} aktívna, platí do {$v['valid_to']}, zostatok {$v['balance']}");

$person = ['first_name' => 'Test', 'last_name' => 'Poukazka', 'email' => 'pouk@example.test', 'phone' => '0905222333'];

// 2) rezervácia s poukážkou: služba 1 (45) + doplnok (10) = 55, poukážka 50 → doplatok 5
$b = Booking::create($person + ['service_id' => 1, 'worker_id' => 0, 'date' => '2026-11-16', 'time' => '13:00', 'addons' => [1], 'codes' => [$v['code']]]);
$ok(!is_wp_error($b), 'rezervácia s poukážkou vytvorená' . (is_wp_error($b) ? ': ' . $b->get_error_message() : ''));
$ok((float) $b['voucher_amount'] === 50.0 && \Rzv\Pricing::total($b) === 5.0, "čerpanie {$b['voucher_amount']}, doplatok " . \Rzv\Pricing::total($b));
$v = Vouchers::get((int) $v['id']);
$ok($v['status'] === 'used' && (float) $v['balance'] === 0.0, "poukážka po čerpaní: {$v['status']}, zostatok {$v['balance']}");

// opakované použitie vyčerpanej
$b2 = Booking::create($person + ['service_id' => 2, 'worker_id' => 0, 'date' => '2026-11-16', 'time' => '15:00', 'codes' => [$v['code']]]);
$ok(is_wp_error($b2), 'vyčerpaná poukážka odmietnutá: ' . (is_wp_error($b2) ? $b2->get_error_message() : 'NIE'));

// 3) storno → vrátenie
Booking::set_status((int) $b['id'], 'cancelled', false);
$v = Vouchers::get((int) $v['id']);
$ok($v['status'] === 'active' && (float) $v['balance'] === 50.0, "po zrušení rezervácie: {$v['status']}, zostatok {$v['balance']}");

// 4) obnovenie zrušenej rezervácie → znova čerpá
Booking::set_status((int) $b['id'], 'confirmed', false);
$v = Vouchers::get((int) $v['id']);
$ok((float) $v['balance'] === 0.0, "po obnovení rezervácie zostatok {$v['balance']}");
Booking::set_status((int) $b['id'], 'cancelled', false);

// 5) permanentka 5× na službu 2, vystavená adminom
$type = Db::row('voucher_types', 2);
$pass = Vouchers::create_from_type($type, ['status' => 'unpaid', 'source' => 'admin', 'buyer_name' => 'Perm Test', 'buyer_email' => 'perm@example.test']);
Vouchers::activate((int) $pass['id'], false);
$pass = Vouchers::get((int) $pass['id']);
$ok($pass['status'] === 'active' && (int) $pass['entries_left'] === 5, "permanentka {$pass['code']}: {$pass['entries_left']}/{$pass['entries_total']}");
$wrong = Booking::create($person + ['service_id' => 1, 'worker_id' => 0, 'date' => '2026-11-17', 'time' => '09:00', 'codes' => [$pass['code']]]);
$ok(is_wp_error($wrong), 'permanentka na inú službu odmietnutá: ' . (is_wp_error($wrong) ? $wrong->get_error_message() : 'NIE'));
$b3 = Booking::create($person + ['service_id' => 2, 'worker_id' => 0, 'date' => '2026-11-17', 'time' => '09:00', 'addons' => [1], 'codes' => [$pass['code']]]);
$pass = Vouchers::get((int) $pass['id']);
$ok(!is_wp_error($b3) && (int) $pass['entries_left'] === 4 && \Rzv\Pricing::total($b3) === 10.0, 'permanentka čerpaná: zostáva ' . $pass['entries_left'] . ', doplatok za doplnok ' . (is_wp_error($b3) ? '?' : \Rzv\Pricing::total($b3)));

// 6) zaplatenie zostatku v hotovosti adminom
$pid = Payments::create(['booking_id' => (int) $b3['id'], 'method' => 'cash', 'amount' => 10, 'status' => 'paid']);
$b3 = Booking::get((int) $b3['id']);
$ok((float) $b3['paid'] === 10.0 && \Rzv\Pricing::due($b3) === 0.0, "zaplatené {$b3['paid']}, zostáva " . \Rzv\Pricing::due($b3));

// 7) Stripe podpis webhooku
$secret = 'whsec_test123';
$payload = '{"type":"x"}';
$t = time();
$sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
$ok(\Rzv\Stripe::verify_signature($payload, $sig, $secret), 'Stripe podpis platný');
$ok(!\Rzv\Stripe::verify_signature($payload . ' ', $sig, $secret), 'Stripe podpis – zmenený obsah odmietnutý');
$old = 't=' . ($t - 1000) . ',v1=' . hash_hmac('sha256', ($t - 1000) . '.' . $payload, $secret);
$ok(!\Rzv\Stripe::verify_signature($payload, $old, $secret), 'Stripe podpis – starý timestamp odmietnutý');
