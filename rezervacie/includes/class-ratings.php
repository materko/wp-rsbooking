<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Hodnotenia po návšteve – e-mail so žiadosťou, formulár (?rzv_rate=token), zverejnenie, shortcode. */
class Ratings
{
    const STATUSES = ['pending' => 'Čaká na schválenie', 'published' => 'Zverejnené', 'hidden' => 'Skryté'];

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'page']);
        add_shortcode('rezervacie_hodnotenia', [self::class, 'shortcode']);
    }

    public static function url(array $b): string
    {
        return add_query_arg('rzv_rate', $b['token'], home_url('/'));
    }

    /** Cron: dokončenie prebehnutých rezervácií a rozoslanie žiadostí o hodnotenie. */
    public static function cron(): void
    {
        global $wpdb;
        $t = Db::t('bookings');
        if (Settings::get('auto_complete')) {
            $wpdb->query($wpdb->prepare("UPDATE $t SET status = 'completed', updated_at = %s WHERE status = 'confirmed' AND end_at < %s", Db::now(), Db::fmt(time() - HOUR_IN_SECONDS)));
        }
        if (!Settings::get('ratings_enabled')) {
            return;
        }
        $mode = Settings::get('ratings_mode', 'internal');
        if ($mode === 'external' && !Settings::get('ratings_external_url')) {
            return;
        }
        $delay = max(0, (float) Settings::get('ratings_delay_hours', 2)) * HOUR_IN_SECONDS;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT b.id FROM $t b WHERE b.rating_sent = 0 AND b.status IN ('completed','confirmed')
             AND b.end_at <= %s AND b.end_at >= %s ORDER BY b.end_at LIMIT 30",
            Db::fmt(time() - (int) $delay), Db::fmt(time() - 7 * DAY_IN_SECONDS)
        ));
        foreach ($ids as $id) {
            $wpdb->update($t, ['rating_sent' => 1], ['id' => (int) $id]);
            $b = Booking::get((int) $id);
            if (!$b || !$b['email']) {
                continue;
            }
            // jeden zákazník max. jedna žiadosť za 14 dní
            $recent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND rating_sent = 2 AND end_at > %s", (int) $b['customer_id'], Db::fmt(time() - 14 * DAY_IN_SECONDS)
            ));
            if ($recent) {
                continue;
            }
            $wpdb->update($t, ['rating_sent' => 2], ['id' => (int) $id]); // 2 = e-mail odoslaný
            self::send_request($b);
        }
    }

    public static function send_request(array $b): void
    {
        $mode = Settings::get('ratings_mode', 'internal');
        $link = $mode === 'external' ? (string) Settings::get('ratings_external_url') : self::url($b);
        $vars = Mailer::vars($b);
        $vars['{hodnotenie}'] = $link;
        $vars['{platba}'] = '';
        Mailer::send($b['email'], strtr(Settings::get('tpl_rating_subject'), $vars), strtr(Settings::get('tpl_rating_body'), $vars));
    }

    public static function for_booking(int $booking_id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::t('ratings') . ' WHERE booking_id = %d', $booking_id), ARRAY_A);
        return $r ?: null;
    }

    /** Uloží hodnotenie. @return array|\WP_Error */
    public static function save(array $b, int $stars, string $comment)
    {
        global $wpdb;
        if ($stars < 1 || $stars > 5) {
            return new \WP_Error('rzv_input', 'Vyberte počet hviezdičiek.');
        }
        if (self::for_booking((int) $b['id'])) {
            return new \WP_Error('rzv_input', 'Túto návštevu ste už hodnotili. Ďakujeme!');
        }
        if (!in_array($b['status'], ['completed', 'confirmed'], true) || Db::ts($b['start_at']) > time()) {
            return new \WP_Error('rzv_input', 'Hodnotiť je možné až po návšteve.');
        }
        $positive = $stars >= (int) Settings::get('ratings_threshold', 4);
        $wpdb->insert(Db::t('ratings'), [
            'booking_id'  => (int) $b['id'],
            'customer_id' => (int) $b['customer_id'],
            'service_id'  => (int) $b['service_id'],
            'worker_id'   => (int) $b['worker_id'] ?: null,
            'stars'       => $stars,
            'comment'     => mb_substr(sanitize_textarea_field($comment), 0, 2000),
            'status'      => $positive && Settings::get('ratings_autopublish') ? 'published' : 'pending',
            'created_at'  => Db::now(),
        ]);
        $r = self::for_booking((int) $b['id']);
        Mailer::admin_text(
            'Nové hodnotenie ' . str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) . ' – ' . $b['service_name'],
            $b['customer_name'] . ' · ' . $b['service_name'] . ' · ' . $b['worker_name'] . "\n\n" . ($comment ?: '(bez komentára)') . "\n\n" . admin_url('admin.php?page=rzv-ratings')
        );
        do_action('rzv_rating_saved', $r, $b);
        return $r;
    }

    /** Priemer a počet zverejnených hodnotení. */
    public static function summary(array $where = []): array
    {
        global $wpdb;
        $w = "status = 'published'";
        foreach (['worker_id', 'service_id'] as $k) {
            if (!empty($where[$k])) {
                $w .= $wpdb->prepare(" AND $k = %d", (int) $where[$k]);
            }
        }
        $r = $wpdb->get_row('SELECT COUNT(*) AS n, AVG(stars) AS avg FROM ' . Db::t('ratings') . " WHERE $w", ARRAY_A);
        return ['count' => (int) $r['n'], 'avg' => $r['n'] ? round((float) $r['avg'], 1) : 0.0];
    }

    /* ---------- verejná stránka hodnotenia ---------- */

    public static function page(): void
    {
        if (empty($_GET['rzv_rate'])) {
            return;
        }
        $b = Booking::by_token(sanitize_text_field(wp_unslash($_GET['rzv_rate'])));
        if (!$b) {
            return;
        }
        $msg = '';
        $err = '';
        $done = self::for_booking((int) $b['id']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
            $res = self::save($b, (int) ($_POST['stars'] ?? 0), (string) wp_unslash($_POST['comment'] ?? ''));
            if (is_wp_error($res)) {
                $err = $res->get_error_message();
            } else {
                $done = $res;
                $msg = 'Ďakujeme za hodnotenie!';
            }
        }
        $s = Settings::all();
        $ext = $s['ratings_external_url'] && in_array($s['ratings_mode'], ['both', 'external'], true)
            && $done && (int) $done['stars'] >= (int) $s['ratings_threshold'];
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        ?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html('Hodnotenie – ' . $s['business_name']); ?></title>
<style>
:root{--c:<?php echo esc_attr($s['color_primary']); ?>}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;color:#222;padding:32px 16px}
.box{max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:28px 24px;box-shadow:0 6px 24px rgba(0,0,0,.08);text-align:center}
h1{font-size:22px;margin:0 0 6px}p{color:#555}
.stars{display:flex;flex-direction:row-reverse;justify-content:center;gap:6px;margin:18px 0}
.stars input{position:absolute;opacity:0;width:1px;height:1px}
.stars label{font-size:44px;line-height:1;color:#d0d0d0;cursor:pointer;transition:transform .1s}
.stars label:hover,.stars label:hover~label,.stars input:checked~label{color:#f5a623}
.stars input:focus-visible+label{outline:2px solid var(--c);border-radius:4px}
textarea{width:100%;min-height:110px;font:inherit;font-size:16px;padding:10px 12px;border:1px solid #ddd;border-radius:8px}
.btn{display:inline-block;margin-top:14px;padding:12px 22px;border-radius:8px;border:0;background:var(--c);color:#fff;font-size:16px;cursor:pointer;text-decoration:none}
.err{background:#fdecea;color:#b71c1c;padding:10px;border-radius:8px}.ok{font-size:40px}
.given{font-size:30px;color:#f5a623;letter-spacing:4px}
</style></head><body><div class="box">
<h1><?php echo esc_html($s['business_name']); ?></h1>
<p><?php echo esc_html($b['service_name'] . ' · ' . wp_date('j. n. Y', Db::ts($b['start_at']), Db::tz()) . ($b['worker_name'] ? ' · ' . $b['worker_name'] : '')); ?></p>
<?php if ($done) : ?>
  <div class="ok">✓</div>
  <p><strong><?php echo esc_html($msg ?: 'Túto návštevu ste už hodnotili. Ďakujeme!'); ?></strong></p>
  <div class="given"><?php echo esc_html(str_repeat('★', (int) $done['stars']) . str_repeat('☆', 5 - (int) $done['stars'])); ?></div>
  <?php if ($ext) : ?>
    <p>Pomôžete nám aj verejnou recenziou? Veľmi si to ceníme.</p>
    <a class="btn" href="<?php echo esc_url($s['ratings_external_url']); ?>" rel="noopener" target="_blank"><?php echo esc_html('Ohodnotiť na ' . $s['ratings_external_name']); ?></a>
  <?php endif; ?>
  <p><a href="<?php echo esc_url(Settings::booking_url()); ?>">Nová rezervácia</a></p>
<?php else : ?>
  <p>Ako ste boli spokojní?</p>
  <?php if ($err) : ?><div class="err"><?php echo esc_html($err); ?></div><?php endif; ?>
  <form method="post">
    <div class="stars" role="radiogroup" aria-label="Počet hviezdičiek">
      <?php for ($i = 5; $i >= 1; $i--) : ?>
        <input type="radio" id="s<?php echo $i; ?>" name="stars" value="<?php echo $i; ?>" required>
        <label for="s<?php echo $i; ?>" title="<?php echo $i; ?> z 5">★</label>
      <?php endfor; ?>
    </div>
    <textarea name="comment" maxlength="2000" placeholder="Čo sa vám páčilo, čo môžeme zlepšiť? (nepovinné)"></textarea>
    <button class="btn" type="submit">Odoslať hodnotenie</button>
  </form>
<?php endif; ?>
</div></body></html>
        <?php
        exit;
    }

    /** [rezervacie_hodnotenia limit="10" min="4" worker="ID" service="ID"] */
    public static function shortcode($atts): string
    {
        global $wpdb;
        $a = shortcode_atts(['limit' => 10, 'min' => 1, 'worker' => 0, 'service' => 0], $atts, 'rezervacie_hodnotenia');
        $w = $wpdb->prepare("r.status = 'published' AND r.stars >= %d", (int) $a['min']);
        if ((int) $a['worker']) {
            $w .= $wpdb->prepare(' AND r.worker_id = %d', (int) $a['worker']);
        }
        if ((int) $a['service']) {
            $w .= $wpdb->prepare(' AND r.service_id = %d', (int) $a['service']);
        }
        $rows = $wpdb->get_results(
            'SELECT r.*, c.first_name, c.last_name, s.name AS service_name FROM ' . Db::t('ratings') . ' r
             LEFT JOIN ' . Db::t('customers') . ' c ON c.id = r.customer_id LEFT JOIN ' . Db::t('services') . " s ON s.id = r.service_id
             WHERE $w ORDER BY r.created_at DESC LIMIT " . max(1, min(100, (int) $a['limit'])), ARRAY_A
        ) ?: [];
        $sum = self::summary(['worker_id' => (int) $a['worker'], 'service_id' => (int) $a['service']]);
        if (!$sum['count']) {
            return '';
        }
        wp_enqueue_style('rzv-widget', RZV_URL . 'assets/widget.css', [], RZV_VERSION);
        $h = '<div class="rzv-ratings" style="--rzv-primary:' . esc_attr(Settings::get('color_primary')) . '">';
        $h .= '<div class="rzv-ratings__sum"><span class="rzv-ratings__avg">' . esc_html(number_format_i18n($sum['avg'], 1)) . '</span> '
            . self::stars_html((int) round($sum['avg'])) . ' <span class="rzv-muted">(' . (int) $sum['count'] . ' hodnotení)</span></div>';
        foreach ($rows as $r) {
            $name = trim($r['first_name'] . ' ' . mb_substr((string) $r['last_name'], 0, 1) . ($r['last_name'] ? '.' : ''));
            $h .= '<div class="rzv-rating">' . self::stars_html((int) $r['stars'])
                . ($r['comment'] ? '<p>' . nl2br(esc_html($r['comment'])) . '</p>' : '')
                . '<small class="rzv-muted">' . esc_html($name . ' · ' . $r['service_name'] . ' · ' . wp_date('j. n. Y', Db::ts($r['created_at']), Db::tz())) . '</small>'
                . ($r['reply'] ? '<div class="rzv-rating__reply"><strong>' . esc_html(Settings::get('business_name')) . ':</strong> ' . nl2br(esc_html($r['reply'])) . '</div>' : '')
                . '</div>';
        }
        // štruktúrované dáta pre vyhľadávače
        $ld = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => Settings::get('business_name'),
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => $sum['avg'], 'reviewCount' => $sum['count'], 'bestRating' => 5]];
        $h .= '<script type="application/ld+json">' . wp_json_encode($ld) . '</script>';
        return $h . '</div>';
    }

    public static function stars_html(int $n): string
    {
        return '<span class="rzv-stars" aria-label="' . $n . ' z 5">' . str_repeat('★', $n) . '<span class="rzv-stars__off">' . str_repeat('★', 5 - $n) . '</span></span>';
    }
}
