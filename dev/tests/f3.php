<?php
/**
 * Test F3 – prenájmy, vernostný program, hodnotenia, automatické dokončenie.
 * Spustenie: docker compose run --rm cli wp eval-file /dev-scripts/tests/f3.php
 */
use Rzv\Booking;
use Rzv\Db;
use Rzv\Loyalty;
use Rzv\Pricing;
use Rzv\Ratings;
use Rzv\Settings;
use Rzv\Slots;

$ok = function ($cond, $msg) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $msg . PHP_EOL;
};
global $wpdb;
$orig = Settings::all();

try {
    /* ---------- prenájmy ---------- */
    echo "Prenájmy\n";
    $wpdb->insert(Db::t('resources'), ['name' => 'Salónik', 'quantity' => 1, 'active' => 1]);
    $rid = (int) $wpdb->insert_id;
    $wpdb->insert(Db::t('services'), ['kind' => 'rental', 'category_id' => 2, 'name' => 'Prenájom salónika', 'duration' => 120, 'buffer_after' => 30, 'price' => 40, 'capacity' => 1, 'auto_confirm' => 1, 'active' => 1, 'visible' => 1]);
    $sid = (int) $wpdb->insert_id;
    Db::set_pivot('service_resource', 'service_id', $sid, 'resource_id', [$rid]);

    $cat = rest_do_request(new WP_REST_Request('GET', '/rzv/v1/catalog'))->get_data();
    $svc = array_values(array_filter($cat['services'], fn($s) => $s['id'] === $sid))[0] ?? null;
    $ok($svc && $svc['kind'] === 'rental' && $svc['workers'] === [], 'prenájom je v katalógu bez pracovníkov');

    $s = Slots::make($sid);
    $s->load('2026-11-20', '2026-11-20');
    $times = $s->day('2026-11-20');
    $ok(isset($times['09:00']) && $times['09:00'] === [0] && !isset($times['15:15']), 'voľné časy prenájmu 09:00–15:00 (koniec do 17:00): ' . implode(',', array_slice(array_keys($times), 0, 3)) . '… ' . array_key_last($times));

    $p = ['first_name' => 'Rent', 'last_name' => 'Test', 'email' => 'rent@example.test', 'phone' => '0905444555'];
    $b = Booking::create($p + ['service_id' => $sid, 'date' => '2026-11-20', 'time' => '10:00']);
    $ok(!is_wp_error($b) && (int) $b['worker_id'] === 0 && $b['status'] === 'confirmed', 'prenájom 10:00 vytvorený (pracovník 0)' . (is_wp_error($b) ? ': ' . $b->get_error_message() : ''));
    $b2 = Booking::create($p + ['service_id' => $sid, 'date' => '2026-11-20', 'time' => '11:00']);
    $ok(is_wp_error($b2), 'súbežný prenájom toho istého salónika odmietnutý');
    $b3 = Booking::create($p + ['service_id' => $sid, 'date' => '2026-11-20', 'time' => '10:00']);
    $ok(is_wp_error($b3), 'druhý prenájom v presne rovnakom čase odmietnutý');
    $s = Slots::make($sid);
    $s->load('2026-11-20', '2026-11-20');
    $times = array_keys($s->day('2026-11-20'));
    $ok(!in_array('10:00', $times, true) && !in_array('11:00', $times, true) && !in_array('12:15', $times, true) && in_array('12:30', $times, true), 'po prenájme 10–12 + 30 min upratanie je voľno od 12:30');
    $s2 = Slots::make(2, [], 1);
    $s2->load('2026-11-20', '2026-11-20');
    $ok(isset($s2->day('2026-11-20')['10:00']), 'pracovník má počas prenájmu voľno na inú službu');
    $ok(strpos(\Rzv\Mailer::detail($b), 'Pracovník') === false, 'e-mail prenájmu bez riadku Pracovník');

    /* ---------- automatické dokončenie + hodnotenia ---------- */
    echo "Hodnotenia\n";
    Settings::set('ratings_enabled', 1);
    Settings::set('ratings_mode', 'both');
    Settings::set('ratings_external_url', 'https://g.page/r/test/review');
    Settings::set('auto_complete', 1);
    $lp = ['first_name' => 'Verna', 'last_name' => 'Zakaznicka', 'email' => 'verna@example.test', 'phone' => '0905666777'];
    $past = [];
    foreach (['2026-11-02 09:00', '2026-11-03 09:00', '2026-11-04 09:00'] as $dt) {
        $x = Booking::create($lp + ['service_id' => 2, 'worker_id' => 1, 'date' => substr($dt, 0, 10), 'time' => substr($dt, 11, 5), 'status' => 'confirmed'], true);
        $past[] = $x;
    }
    $wpdb->query('UPDATE ' . Db::t('bookings') . ' SET rating_sent = 1 WHERE id IN (' . (int) $past[0]['id'] . ',' . (int) $past[1]['id'] . ')'); // staršie neposielať
    $wpdb->query('UPDATE ' . Db::t('bookings') . " SET end_at = '" . esc_sql(Db::fmt(time() - 3 * HOUR_IN_SECONDS)) . "', start_at = '" . esc_sql(Db::fmt(time() - 4 * HOUR_IN_SECONDS)) . "' WHERE id = " . (int) $past[2]['id']);
    $count = fn() => (int) (json_decode(wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/search?query=to:verna@example.test%20subject:spokojn')), true)['messages_count'] ?? 0);
    $before = $count();
    Ratings::cron();
    $st = $wpdb->get_col('SELECT status FROM ' . Db::t('bookings') . ' WHERE id IN (' . implode(',', array_map(fn($x) => (int) $x['id'], $past)) . ')');
    $ok(array_unique($st) === ['completed'], 'prebehnuté rezervácie automaticky zrealizované');
    $sent = (int) $wpdb->get_var('SELECT rating_sent FROM ' . Db::t('bookings') . ' WHERE id = ' . (int) $past[2]['id']);
    $ok($sent === 2, 'žiadosť o hodnotenie odoslaná (rating_sent=2)');
    sleep(1);
    $ok($count() - $before === 1, 'e-mail so žiadosťou o hodnotenie doručený (práve 1)');

    $bk = Booking::get((int) $past[2]['id']);
    $r = Ratings::save($bk, 5, 'Super, ďakujem!');
    $ok(!is_wp_error($r) && $r['status'] === 'published', '5★ hodnotenie zverejnené automaticky');
    $ok(is_wp_error(Ratings::save($bk, 4, 'znova')), 'druhé hodnotenie tej istej návštevy odmietnuté');
    $r2 = Ratings::save(Booking::get((int) $past[1]['id']), 2, 'Čakala som dlho');
    $ok(!is_wp_error($r2) && $r2['status'] === 'pending', '2★ hodnotenie čaká na schválenie');
    $ok(is_wp_error(Ratings::save($b, 5, '')), 'budúcu rezerváciu nie je možné hodnotiť');
    $sum = Ratings::summary();
    $ok($sum['count'] >= 1 && $sum['avg'] > 0, 'priemer zverejnených: ' . $sum['avg'] . ' (' . $sum['count'] . ')');
    $html = do_shortcode('[rezervacie_hodnotenia]');
    $ok(strpos($html, 'Super, ďakujem!') !== false && strpos($html, 'Čakala') === false && strpos($html, 'aggregateRating') !== false, 'shortcode zobrazí len zverejnené + štruktúrované dáta');

    /* ---------- vernostný program ---------- */
    echo "Vernostný program\n";
    Settings::set('loyalty_enabled', 1);
    Settings::set('loyalty_basis', 'visits');
    Settings::set('loyalty_levels', [['name' => 'Bronze', 'min' => 2, 'discount' => 5], ['name' => 'Silver', 'min' => 3, 'discount' => 10], ['name' => 'Gold', 'min' => 10, 'discount' => 20]]);
    $l = Loyalty::for_email('verna@example.test');
    $ok($l && $l['name'] === 'Silver' && (int) $l['visits'] === 3 && $l['next']['name'] === 'Gold', 'úroveň: ' . ($l['name'] ?? '-') . ', návštev ' . ($l['visits'] ?? '-') . '; ' . Loyalty::describe($l));
    $q = Pricing::quote(1, [], [], 'verna@example.test');
    $ok($q['discount'] === 4.5 && $q['total'] === 40.5 && $q['loyalty_level'] === 'Silver', 'cena 45 € − 10 % = ' . $q['total']);
    $q = Pricing::quote(1, [], [], 'nikto@example.test');
    $ok($q['discount'] === 0.0, 'neznámy e-mail bez zľavy');
    $wpdb->update(Db::t('coupons'), ['per_customer' => null], ['code' => 'LETO20']);
    $q = Pricing::quote(1, [], ['LETO20'], 'verna@example.test');
    $ok($q['discount'] === 9.0 && !$q['loyalty_level'], 'so zľavovým kódom sa vernostná nesčíta (zľava ' . $q['discount'] . ')');
    Settings::set('loyalty_combine', 1);
    $q = Pricing::quote(1, [], ['LETO20'], 'verna@example.test');
    $ok($q['discount'] === 12.6, 'pri povolenom sčítaní: 9 + 10 % z 36 = ' . $q['discount']);
    Settings::set('loyalty_combine', 0);
    $nb = Booking::create($lp + ['service_id' => 1, 'worker_id' => 0, 'date' => '2026-11-23', 'time' => '09:00', 'terms' => 1]);
    $ok(!is_wp_error($nb) && $nb['loyalty_level'] === 'Silver' && (float) $nb['discount'] === 4.5, 'rezervácia uložila vernostnú zľavu');
    $ok(strpos(\Rzv\Mailer::detail($nb), 'vernostná Silver') !== false, 'e-mail uvádza vernostnú zľavu');
} finally {
    Settings::save($orig);
    $wpdb->update(Db::t('coupons'), ['per_customer' => 1], ['code' => 'LETO20']);
}
