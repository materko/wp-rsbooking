<?php
namespace Rzv;

defined('ABSPATH') || exit;

/**
 * Darčekové poukážky a permanentky.
 *  value   – hodnotová poukážka (zostatok v €, dá sa čerpať po častiach)
 *  service – poukážka na konkrétnu službu (1 vstup)
 *  pass    – permanentka (N vstupov na službu)
 */
class Vouchers
{
    const KINDS = ['value' => 'Hodnotová (suma)', 'service' => 'Na službu', 'pass' => 'Permanentka (počet vstupov)'];
    const STATUSES = ['unpaid' => 'Nezaplatená', 'active' => 'Aktívna', 'used' => 'Vyčerpaná', 'expired' => 'Expirovaná', 'cancelled' => 'Zrušená'];

    public static function find(string $code): ?array
    {
        global $wpdb;
        $code = Coupons::normalize($code);
        if ($code === '') {
            return null;
        }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::t('vouchers') . ' WHERE code = %s', $code), ARRAY_A);
        return $r ?: null;
    }

    public static function by_token(string $token): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-zA-Z0-9]{20,64}$/', $token)) {
            return null;
        }
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::t('vouchers') . ' WHERE token = %s', $token), ARRAY_A);
        return $r ?: null;
    }

    /**
     * Koľko pokryje poukážka z rezervácie.
     * @return array|\WP_Error ['amount' => float, 'entries' => int]
     */
    public static function check_for_booking(array $v, int $service_id, float $service_price, float $remaining)
    {
        if ($v['status'] === 'unpaid') {
            return new \WP_Error('rzv_voucher', 'Poukážka ešte nebola zaplatená.');
        }
        if ($v['status'] !== 'active') {
            return new \WP_Error('rzv_voucher', 'Poukážka už nie je platná (' . (self::STATUSES[$v['status']] ?? $v['status']) . ').');
        }
        if ($v['valid_to'] && wp_date('Y-m-d') > $v['valid_to']) {
            return new \WP_Error('rzv_voucher', 'Platnosť poukážky vypršala ' . self::date($v['valid_to']) . '.');
        }
        if ($v['kind'] === 'value') {
            if ((float) $v['balance'] <= 0) {
                return new \WP_Error('rzv_voucher', 'Poukážka je vyčerpaná.');
            }
            return ['amount' => round(min((float) $v['balance'], $remaining), 2), 'entries' => 0];
        }
        if ((int) $v['service_id'] !== $service_id) {
            $s = Db::row('services', (int) $v['service_id']);
            return new \WP_Error('rzv_voucher', 'Poukážka platí len na službu: ' . ($s['name'] ?? '?') . '.');
        }
        if ((int) $v['entries_left'] <= 0) {
            return new \WP_Error('rzv_voucher', 'Poukážka je vyčerpaná.');
        }
        return ['amount' => round(min($service_price, $remaining), 2), 'entries' => 1];
    }

    public static function redeem(int $voucher_id, ?int $booking_id, float $amount, int $entries, string $note = ''): void
    {
        global $wpdb;
        $t = Db::t('vouchers');
        $wpdb->insert(Db::t('voucher_uses'), [
            'voucher_id' => $voucher_id, 'booking_id' => $booking_id, 'amount' => $amount, 'entries' => $entries,
            'note' => $note, 'created_at' => Db::now(),
        ]);
        $wpdb->query($wpdb->prepare("UPDATE $t SET balance = GREATEST(0, balance - %f), entries_left = GREATEST(0, entries_left - %d) WHERE id = %d", $amount, $entries, $voucher_id));
        $wpdb->query($wpdb->prepare("UPDATE $t SET status = 'used' WHERE id = %d AND status = 'active' AND ((kind = 'value' AND balance <= 0) OR (kind <> 'value' AND entries_left <= 0))", $voucher_id));
    }

    /** Vráti čerpanie pri zrušení rezervácie. */
    public static function refund_booking(int $booking_id): void
    {
        global $wpdb;
        $uses = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Db::t('voucher_uses') . ' WHERE booking_id = %d', $booking_id), ARRAY_A) ?: [];
        foreach ($uses as $u) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Db::t('vouchers') . " SET balance = balance + %f, entries_left = entries_left + %d,
                 status = IF(status = 'used', 'active', status) WHERE id = %d",
                (float) $u['amount'], (int) $u['entries'], (int) $u['voucher_id']
            ));
            $wpdb->delete(Db::t('voucher_uses'), ['id' => (int) $u['id']]);
        }
    }

    /**
     * Objednávka poukážky zákazníkom (stav nezaplatená).
     * $d: type_id, buyer_name, buyer_email, buyer_phone, recipient, message, coupon
     * @return array|\WP_Error
     */
    public static function order(array $d)
    {
        $type = Db::row('voucher_types', (int) ($d['type_id'] ?? 0));
        if (!$type || !$type['active'] || !$type['sell_online']) {
            return new \WP_Error('rzv_input', 'Poukážka nie je v ponuke.');
        }
        $name = sanitize_text_field($d['buyer_name'] ?? '');
        $email = sanitize_email($d['buyer_email'] ?? '');
        if ($name === '' || !is_email($email)) {
            return new \WP_Error('rzv_input', 'Zadajte meno a platný e-mail.');
        }
        $price = (float) $type['price'];
        $discount = 0.0;
        $coupon_code = null;
        if (!empty($d['coupon'])) {
            $c = Coupons::find((string) $d['coupon']);
            if (!$c) {
                return new \WP_Error('rzv_coupon', 'Zľavový kód neexistuje.');
            }
            $disc = Coupons::discount($c, 'vouchers', $price, 0, $email);
            if (is_wp_error($disc)) {
                return $disc;
            }
            $discount = $disc;
            $coupon_code = $c['code'];
        }
        $v = self::create_from_type($type, [
            'price'       => $price,
            'discount'    => $discount,
            'coupon_code' => $coupon_code,
            'buyer_name'  => $name,
            'buyer_email' => $email,
            'buyer_phone' => Customers::phone($d['buyer_phone'] ?? ''),
            'recipient'   => sanitize_text_field($d['recipient'] ?? ''),
            'message'     => sanitize_textarea_field($d['message'] ?? ''),
            'status'      => 'unpaid',
            'source'      => 'web',
        ]);
        if ($coupon_code) {
            Coupons::count_use($coupon_code);
        }
        $to_pay = round($price - $discount, 2);
        if ($to_pay <= 0) {
            self::activate((int) $v['id']);
            return self::get((int) $v['id']);
        }
        Payments::create(['voucher_id' => (int) $v['id'], 'method' => 'pending', 'amount' => $to_pay]);
        $v = self::get((int) $v['id']);
        Mailer::voucher_order($v);
        Mailer::admin_text('Objednávka poukážky ' . $v['code'], $v['name'] . ' – ' . Settings::money($to_pay) . "\n" . $name . ' ' . $email
            . "\n\n" . admin_url('admin.php?page=rzv-vouchers&edit=' . $v['id']));
        return $v;
    }

    public static function create_from_type(array $type, array $extra): array
    {
        global $wpdb;
        $row = [
            'code'          => self::generate_code(),
            'token'         => Booking::token(),
            'type_id'       => (int) $type['id'],
            'kind'          => $type['kind'],
            'name'          => $type['name'],
            'service_id'    => $type['kind'] === 'value' ? null : (int) $type['service_id'],
            'initial_value' => $type['kind'] === 'value' ? (float) $type['value'] : (float) $type['price'],
            'balance'       => $type['kind'] === 'value' ? (float) $type['value'] : 0,
            'entries_total' => $type['kind'] === 'pass' ? max(1, (int) $type['entries']) : ($type['kind'] === 'service' ? 1 : 0),
            'price'         => (float) $type['price'],
            'created_at'    => Db::now(),
        ];
        $row['entries_left'] = $row['entries_total'];
        $row = array_merge($row, $extra);
        $wpdb->insert(Db::t('vouchers'), $row);
        return self::get((int) $wpdb->insert_id);
    }

    public static function activate(int $id, bool $send = true): ?array
    {
        global $wpdb;
        $v = self::get($id);
        if (!$v || $v['status'] !== 'unpaid') {
            return $v;
        }
        $type = $v['type_id'] ? Db::row('voucher_types', (int) $v['type_id']) : null;
        $days = $type ? (int) $type['validity_days'] : 365;
        $wpdb->update(Db::t('vouchers'), [
            'status'       => 'active',
            'activated_at' => Db::now(),
            'valid_to'     => $v['valid_to'] ?: wp_date('Y-m-d', time() + max(1, $days) * DAY_IN_SECONDS),
        ], ['id' => $id]);
        $v = self::get($id);
        do_action('rzv_voucher_activated', $v);
        if ($send && $v['buyer_email']) {
            Mailer::voucher($v);
        }
        return $v;
    }

    public static function get(int $id): ?array
    {
        return Db::row('vouchers', $id);
    }

    public static function generate_code(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = Coupons::normalize((string) Settings::get('voucher_prefix', 'DAR'));
        do {
            $s = '';
            for ($i = 0; $i < 8; $i++) {
                $s .= $alpha[random_int(0, strlen($alpha) - 1)];
            }
            $code = ($prefix ? $prefix . '-' : '') . substr($s, 0, 4) . '-' . substr($s, 4);
        } while (self::find($code));
        return $code;
    }

    public static function url(array $v): string
    {
        return add_query_arg('rzv_voucher', $v['token'], home_url('/'));
    }

    public static function value_label(array $v): string
    {
        if ($v['kind'] === 'value') {
            return Settings::money($v['initial_value']);
        }
        $s = Db::row('services', (int) $v['service_id']);
        $name = $s['name'] ?? 'služba';
        return $v['kind'] === 'pass' ? $v['entries_total'] . '× ' . $name : $name;
    }

    public static function remaining_label(array $v): string
    {
        return $v['kind'] === 'value' ? Settings::money($v['balance']) : (int) $v['entries_left'] . ' / ' . (int) $v['entries_total'] . ' vstupov';
    }

    public static function date(?string $d): string
    {
        return $d ? wp_date('j. n. Y', Db::ts($d . ' 12:00:00'), Db::tz()) : '—';
    }

    /** Zruší nezaplatené objednávky staršie ako voucher_pay_days a expiruje staré poukážky. */
    public static function cron(): void
    {
        global $wpdb;
        $t = Db::t('vouchers');
        $days = max(1, (int) Settings::get('voucher_pay_days', 7));
        $old = $wpdb->get_results($wpdb->prepare("SELECT id, coupon_code FROM $t WHERE status = 'unpaid' AND source = 'web' AND created_at < %s", Db::fmt(time() - $days * DAY_IN_SECONDS)), ARRAY_A) ?: [];
        foreach ($old as $v) {
            $wpdb->update($t, ['status' => 'cancelled'], ['id' => (int) $v['id']]);
            $wpdb->update(Db::t('payments'), ['status' => 'cancelled'], ['voucher_id' => (int) $v['id'], 'status' => 'pending']);
            if ($v['coupon_code']) {
                Coupons::count_use($v['coupon_code'], -1);
            }
        }
        $wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired' WHERE status = 'active' AND valid_to IS NOT NULL AND valid_to < %s", wp_date('Y-m-d')));
    }

    /** Samostatná stránka s poukážkou na tlač (?rzv_voucher=token). */
    public static function render_print(array $v): void
    {
        $s = Settings::all();
        $type = $v['type_id'] ? Db::row('voucher_types', (int) $v['type_id']) : null;
        $color = $type['color'] ?? '' ?: $s['color_primary'];
        $image = $type['image'] ?? '';
        $active = $v['status'] === 'active';
        $pay = null;
        if ($v['status'] === 'unpaid') {
            $pay = Payments::pending_for_voucher((int) $v['id']);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        ?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html('Poukážka ' . $v['code'] . ' – ' . $s['business_name']); ?></title>
<style>
:root{--c:<?php echo esc_attr($color); ?>}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f3f3f3;color:#222;padding:24px 16px}
.card{max-width:720px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.12)}
.top{background:var(--c);color:#fff;padding:28px 32px;position:relative}
.top h1{margin:0;font-size:30px;letter-spacing:.5px}.top p{margin:6px 0 0;opacity:.9}
.img{width:100%;max-height:260px;object-fit:cover;display:block}
.body{padding:28px 32px;display:grid;gap:14px}
.value{font-size:34px;font-weight:800;color:var(--c)}
.code{font-family:ui-monospace,Consolas,monospace;font-size:26px;letter-spacing:2px;border:2px dashed var(--c);padding:10px 16px;border-radius:10px;display:inline-block}
.msg{font-style:italic;background:#faf7f8;border-left:4px solid var(--c);padding:10px 14px;white-space:pre-line}
.meta{display:grid;grid-template-columns:auto 1fr;gap:4px 16px;font-size:15px}.meta dt{color:#777}.meta dd{margin:0}
.foot{padding:16px 32px;background:#fafafa;font-size:13px;color:#666;white-space:pre-line}
.btn{display:inline-block;margin:20px auto 0;padding:12px 22px;border-radius:8px;border:0;background:var(--c);color:#fff;font-size:16px;cursor:pointer}
.center{text-align:center}.warn{background:#fff3cd;color:#7a5b00;padding:12px 16px;border-radius:8px}
.pay{max-width:720px;margin:20px auto 0;background:#fff;border-radius:12px;padding:20px 24px}
@media print{body{background:#fff;padding:0}.card{box-shadow:none}.noprint{display:none}}
</style></head><body>
<?php if (!$active) : ?>
<div class="card" style="margin-bottom:16px"><div class="body"><div class="warn"><?php
    echo esc_html($v['status'] === 'unpaid' ? 'Poukážka čaká na zaplatenie. Po pripísaní platby bude aktívna a pošleme vám ju e-mailom.' : 'Poukážka nie je aktívna: ' . (self::STATUSES[$v['status']] ?? $v['status']) . '.');
?></div></div></div>
<?php endif; ?>
<div class="card">
  <div class="top"><h1>Darčeková poukážka</h1><p><?php echo esc_html($s['business_name']); ?></p></div>
  <?php if ($image) : ?><img class="img" src="<?php echo esc_url($image); ?>" alt=""><?php endif; ?>
  <div class="body">
    <?php if ($v['recipient']) : ?><div>Pre: <strong><?php echo esc_html($v['recipient']); ?></strong></div><?php endif; ?>
    <div><strong><?php echo esc_html($v['name']); ?></strong></div>
    <div class="value"><?php echo esc_html(self::value_label($v)); ?></div>
    <?php if ($v['message']) : ?><div class="msg"><?php echo esc_html($v['message']); ?></div><?php endif; ?>
    <div><?php if ($active) : ?><span class="code"><?php echo esc_html($v['code']); ?></span><?php endif; ?></div>
    <dl class="meta">
      <?php if ($active) : ?><dt>Platnosť do</dt><dd><?php echo esc_html(self::date($v['valid_to'])); ?></dd>
      <?php if ($v['kind'] !== 'service') : ?><dt>Zostáva</dt><dd><?php echo esc_html(self::remaining_label($v)); ?></dd><?php endif; endif; ?>
      <dt>Rezervácia</dt><dd><?php echo esc_html(Settings::booking_url()); ?></dd>
    </dl>
  </div>
  <div class="foot"><?php echo esc_html(trim($s['voucher_terms'] . "\n" . Mailer::business())); ?></div>
</div>
<?php if ($pay) : ?>
<div class="pay noprint" id="rzv-pay" data-qr="<?php echo esc_attr($pay['qr'] ?? ''); ?>">
  <h2 style="margin-top:0">Platba <?php echo esc_html(Settings::money($pay['amount'])); ?></h2>
  <?php if (!empty($pay['online_url'])) : ?><p><a class="btn" href="<?php echo esc_url($pay['online_url']); ?>">Zaplatiť kartou online</a></p><?php endif; ?>
  <?php if (!empty($pay['qr'])) : ?>
  <p>Prevodom / QR platbou:<br>IBAN: <strong><?php echo esc_html($pay['iban']); ?></strong><br>Variabilný symbol: <strong><?php echo esc_html($pay['vs']); ?></strong></p>
  <div id="rzv-qr" style="width:220px"></div>
  <script src="<?php echo esc_url(RZV_URL . 'assets/vendor/qrcode.js?ver=' . RZV_VERSION); ?>"></script>
  <script>(function(){var q=qrcode(0,'M');q.addData(document.getElementById('rzv-pay').getAttribute('data-qr'));q.make();document.getElementById('rzv-qr').innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true});})();</script>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($active) : ?><div class="center noprint"><button class="btn" onclick="window.print()">Vytlačiť / uložiť ako PDF</button></div><?php endif; ?>
</body></html>
        <?php
    }
}
