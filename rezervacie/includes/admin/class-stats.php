<?php
namespace Rzv\Admin;

use Rzv\Booking;
use Rzv\Db;
use Rzv\Settings;
use Rzv\Slots;

defined('ABSPATH') || exit;

/** Štatistiky: rezervácie, tržby, pracovníci, služby, zákazníci, vyťaženosť. */
class Stats
{
    private static function range(): array
    {
        $tz = Db::tz();
        $today = new \DateTimeImmutable('today', $tz);
        $presets = [
            'month'      => ['Tento mesiac', $today->modify('first day of this month'), $today->modify('last day of this month')],
            'last_month' => ['Minulý mesiac', $today->modify('first day of last month'), $today->modify('last day of last month')],
            '30'         => ['Posledných 30 dní', $today->modify('-29 days'), $today],
            'next30'     => ['Nasledujúcich 30 dní', $today, $today->modify('+29 days')],
            'year'       => ['Tento rok', $today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)],
        ];
        $p = sanitize_key($_GET['preset'] ?? '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : null;
        if (!$from || !$to) {
            $p = isset($presets[$p]) ? $p : 'month';
            $from = $presets[$p][1]->format('Y-m-d');
            $to = $presets[$p][2]->format('Y-m-d');
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to, $p, $presets];
    }

    public static function render(): void
    {
        global $wpdb;
        [$from, $to, $preset, $presets] = self::range();
        $b = Db::t('bookings');
        $start = $from . ' 00:00:00';
        $end = Db::dt($to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $in = $wpdb->prepare('start_at >= %s AND start_at < %s', $start, $end);
        $valid = "status IN ('confirmed','completed','pending')";
        $net = '(price - discount)'; // tržba po zľave (poukážka je predplatená, teda tržba)

        $by_status = [];
        foreach ($wpdb->get_results("SELECT status, COUNT(*) n FROM $b WHERE $in GROUP BY status", ARRAY_A) ?: [] as $r) {
            $by_status[$r['status']] = (int) $r['n'];
        }
        $total = array_sum($by_status);
        $kpi = $wpdb->get_row("SELECT COUNT(*) n, COALESCE(SUM($net),0) rev, COALESCE(SUM(TIMESTAMPDIFF(MINUTE,start_at,end_at)),0) mins,
            COUNT(DISTINCT customer_id) cust, SUM(source='web') web FROM $b WHERE $in AND $valid", ARRAY_A);
        $paid = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . Db::t('payments') . " WHERE status='paid' AND paid_at >= %s AND paid_at < %s", $start, $end));
        $new_cust = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Db::t('customers') . ' WHERE created_at >= %s AND created_at < %s', $start, $end));
        $returning = (int) $wpdb->get_var("SELECT COUNT(DISTINCT x.customer_id) FROM $b x WHERE " . str_replace('start_at', 'x.start_at', $in) . " AND x.status IN ('confirmed','completed')
            AND EXISTS (SELECT 1 FROM $b y WHERE y.customer_id = x.customer_id AND y.start_at < x.start_at AND y.status IN ('confirmed','completed'))");
        $vouchers = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, COALESCE(SUM(price - discount),0) s FROM ' . Db::t('vouchers') . " WHERE activated_at >= %s AND activated_at < %s", $start, $end), ARRAY_A);
        $coupons = (int) $wpdb->get_var("SELECT COUNT(*) FROM $b WHERE $in AND coupon_code IS NOT NULL AND status <> 'cancelled'");
        $rating = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, AVG(stars) a FROM ' . Db::t('ratings') . ' WHERE created_at >= %s AND created_at < %s', $start, $end), ARRAY_A);
        $cancel_rate = $total ? round(100 * ($by_status['cancelled'] ?? 0) / $total, 1) : 0;
        $noshow_rate = $total ? round(100 * ($by_status['no_show'] ?? 0) / max(1, ($by_status['no_show'] ?? 0) + ($by_status['completed'] ?? 0) + ($by_status['confirmed'] ?? 0)), 1) : 0;

        // pracovný čas pracovníkov v období (na vyťaženosť)
        $bnet = '(b.price - b.discount)';
        $workers = [];
        foreach ($wpdb->get_results("SELECT w.id, w.name, COUNT(b.id) n, COALESCE(SUM($bnet),0) rev, COALESCE(SUM(TIMESTAMPDIFF(MINUTE,b.start_at,b.end_at)),0) mins
            FROM " . Db::t('workers') . " w LEFT JOIN $b b ON b.worker_id = w.id AND " . str_replace(['start_at', 'status'], ['b.start_at', 'b.status'], $in . ' AND ' . $valid) . '
            GROUP BY w.id ORDER BY rev DESC', ARRAY_A) ?: [] as $w) {
            $w['avail'] = self::available_minutes((int) $w['id'], $from, $to);
            $w['util'] = $w['avail'] ? min(100, round(100 * $w['mins'] / $w['avail'])) : 0;
            $w['rating'] = $wpdb->get_var($wpdb->prepare('SELECT AVG(stars) FROM ' . Db::t('ratings') . ' WHERE worker_id = %d', (int) $w['id']));
            $workers[] = $w;
        }
        $services = $wpdb->get_results("SELECT s.name, s.kind, COUNT(b.id) n, COALESCE(SUM($bnet),0) rev, COALESCE(AVG($bnet),0) avg
            FROM $b b JOIN " . Db::t('services') . " s ON s.id = b.service_id WHERE " . str_replace(['start_at', 'status'], ['b.start_at', 'b.status'], $in . ' AND ' . $valid) . '
            GROUP BY s.id ORDER BY rev DESC', ARRAY_A) ?: [];
        $per_day = [];
        foreach ($wpdb->get_results("SELECT DATE(start_at) d, COUNT(*) n, COALESCE(SUM($net),0) rev FROM $b WHERE $in AND $valid GROUP BY DATE(start_at)", ARRAY_A) ?: [] as $r) {
            $per_day[$r['d']] = $r;
        }
        $heat = [];
        foreach ($wpdb->get_results("SELECT WEEKDAY(start_at) wd, HOUR(start_at) h, COUNT(*) n FROM $b WHERE $in AND $valid GROUP BY wd, h", ARRAY_A) ?: [] as $r) {
            $heat[(int) $r['wd']][(int) $r['h']] = (int) $r['n'];
        }
        $top_customers = $wpdb->get_results("SELECT c.id, c.first_name, c.last_name, COUNT(*) n, COALESCE(SUM($bnet),0) rev FROM $b b JOIN " . Db::t('customers') . " c ON c.id = b.customer_id
            WHERE " . str_replace(['start_at', 'status'], ['b.start_at', 'b.status'], $in . ' AND ' . $valid) . ' GROUP BY c.id ORDER BY rev DESC LIMIT 10', ARRAY_A) ?: [];

        $m = fn($v) => esc_html(Settings::money($v));
        echo '<div class="wrap rzv-admin rzv-stats"><h1>Štatistiky</h1>';
        echo '<form method="get" class="rzv-filters"><input type="hidden" name="page" value="rzv-stats">';
        foreach ($presets as $k => [$label]) {
            printf('<a class="button%s" href="%s">%s</a> ', $k === $preset && empty($_GET['from']) ? ' button-primary' : '', esc_url(admin_url('admin.php?page=rzv-stats&preset=' . $k)), esc_html($label));
        }
        printf(' <label>Od <input type="date" name="from" value="%s"></label> <label>Do <input type="date" name="to" value="%s"></label> <button class="button">Zobraziť</button></form>', esc_attr($from), esc_attr($to));

        $card = fn($label, $value, $sub = '') => '<div class="rzv-kpi"><span>' . esc_html($label) . '</span><strong>' . $value . '</strong>' . ($sub !== '' ? '<small>' . esc_html($sub) . '</small>' : '') . '</div>';
        echo '<div class="rzv-kpis">';
        echo $card('Rezervácie', (string) (int) $kpi['n'], $total . ' vrátane zrušených');
        echo $card('Tržba (po zľavách)', $m($kpi['rev']), 'priemer ' . Settings::money($kpi['n'] ? $kpi['rev'] / $kpi['n'] : 0));
        echo $card('Prijaté platby', $m($paid), 'online, prevodom a na mieste');
        echo $card('Zákazníci', (string) (int) $kpi['cust'], $new_cust . ' nových, ' . $returning . ' vracajúcich sa');
        echo $card('Zrušené', esc_html(number_format_i18n($cancel_rate, 1) . ' %'), ($by_status['cancelled'] ?? 0) . ' rezervácií');
        echo $card('Neprišli', esc_html(number_format_i18n($noshow_rate, 1) . ' %'), ($by_status['no_show'] ?? 0) . ' rezervácií');
        echo $card('Online', esc_html(($kpi['n'] ? round(100 * $kpi['web'] / $kpi['n']) : 0) . ' %'), 'rezervácií cez web');
        echo $card('Poukážky', $m($vouchers['s']), (int) $vouchers['n'] . ' predaných');
        echo $card('Zľavové kódy', (string) $coupons, 'použité v rezerváciách');
        echo $card('Hodnotenie', $rating['n'] ? esc_html(number_format_i18n((float) $rating['a'], 1)) . ' ★' : '—', (int) $rating['n'] . ' hodnotení');
        echo '</div>';

        // rezervácie po dňoch
        $days = [];
        $d = Db::dt($from);
        $last = Db::dt($to);
        while ($d <= $last && count($days) < 400) {
            $k = $d->format('Y-m-d');
            $days[$k] = $per_day[$k] ?? ['n' => 0, 'rev' => 0];
            $d = $d->modify('+1 day');
        }
        $max = max(1, ...array_values(array_map(fn($x) => (int) $x['n'], $days ?: [['n' => 1]])));
        echo '<h2>Rezervácie po dňoch</h2><div class="rzv-bars" role="img" aria-label="Počet rezervácií po dňoch">';
        foreach ($days as $k => $x) {
            $ts = Db::ts($k . ' 12:00:00');
            printf('<div class="rzv-bar%s" title="%s: %d rez., %s"><i style="height:%d%%"></i><span>%s</span></div>',
                in_array((int) wp_date('N', $ts, Db::tz()), [6, 7], true) ? ' is-weekend' : '',
                esc_attr(wp_date('D j. n.', $ts, Db::tz())), (int) $x['n'], esc_attr(Settings::money($x['rev'])),
                (int) round(100 * (int) $x['n'] / $max), count($days) <= 45 ? esc_html(wp_date('j.', $ts, Db::tz())) : '');
        }
        echo '</div>';

        echo '<div class="rzv-stats-grid"><div>';
        echo '<h2>Pracovníci</h2><table class="widefat striped"><thead><tr><th>Pracovník</th><th>Rezervácie</th><th>Tržba</th><th>Vyťaženosť</th><th>Hodnotenie</th></tr></thead><tbody>';
        foreach ($workers as $w) {
            printf('<tr><td>%s</td><td>%d</td><td>%s</td><td><div class="rzv-meter"><i style="width:%d%%"></i></div> %d %% <small class="rzv-muted">(%s h / %s h)</small></td><td>%s</td></tr>',
                esc_html($w['name']), (int) $w['n'], $m($w['rev']), (int) $w['util'], (int) $w['util'],
                esc_html(number_format_i18n($w['mins'] / 60, 1)), esc_html(number_format_i18n($w['avail'] / 60, 0)),
                $w['rating'] ? esc_html(number_format_i18n((float) $w['rating'], 1)) . ' ★' : '—');
        }
        echo '</tbody></table>';
        echo '<h2>Služby a prenájmy</h2><table class="widefat striped"><thead><tr><th>Služba</th><th>Počet</th><th>Tržba</th><th>Priemer</th></tr></thead><tbody>';
        if (!$services) {
            echo '<tr><td colspan="4">—</td></tr>';
        }
        foreach ($services as $s) {
            printf('<tr><td>%s%s</td><td>%d</td><td>%s</td><td>%s</td></tr>', esc_html($s['name']), $s['kind'] === 'rental' ? ' <small class="rzv-muted">(prenájom)</small>' : '', (int) $s['n'], $m($s['rev']), $m($s['avg']));
        }
        echo '</tbody></table></div><div>';

        // heatmapa
        $hmax = 1;
        foreach ($heat as $row) {
            $hmax = max($hmax, ...array_values($row));
        }
        $hours = range(7, 20);
        echo '<h2>Kedy si zákazníci rezervujú</h2><table class="rzv-heat"><thead><tr><th></th>';
        foreach ($hours as $hh) {
            echo '<th>' . $hh . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'] as $wd => $label) {
            echo '<tr><th>' . esc_html($label) . '</th>';
            foreach ($hours as $hh) {
                $n = $heat[$wd][$hh] ?? 0;
                printf('<td style="--a:%.2f" title="%s %d:00 – %d rez.">%s</td>', $n / $hmax, esc_attr($label), $hh, $n, $n ?: '');
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Stav rezervácií</h2><table class="widefat striped"><tbody>';
        foreach (Booking::STATUSES as $k => $l) {
            printf('<tr><td><span class="rzv-badge rzv-badge--%s">%s</span></td><td>%d</td></tr>', esc_attr($k), esc_html($l), $by_status[$k] ?? 0);
        }
        echo '</tbody></table>';
        echo '<h2>Najlepší zákazníci</h2><table class="widefat striped"><tbody>';
        if (!$top_customers) {
            echo '<tr><td>—</td></tr>';
        }
        foreach ($top_customers as $c) {
            printf('<tr><td><a href="%s">%s</a></td><td>%d×</td><td>%s</td></tr>', esc_url(admin_url('admin.php?page=rzv-customers&edit=' . $c['id'])), esc_html(trim($c['first_name'] . ' ' . $c['last_name'])), (int) $c['n'], $m($c['rev']));
        }
        echo '</tbody></table></div></div></div>';
    }

    /** Minúty pracovného času pracovníka v období (bez blokácií – orientačne). */
    private static function available_minutes(int $worker_id, string $from, string $to): int
    {
        $w = Db::row('workers', $worker_id);
        if (!$w) {
            return 0;
        }
        $hours = Slots::worker_hours($w);
        $sum = 0;
        $d = Db::dt($from);
        $end = Db::dt($to);
        while ($d <= $end) {
            $day = $hours[(int) $d->format('N')] ?? null;
            if (!empty($day['open'])) {
                foreach ($day['intervals'] ?? [] as $iv) {
                    [$h1, $m1] = array_map('intval', explode(':', $iv[0]));
                    [$h2, $m2] = array_map('intval', explode(':', $iv[1]));
                    $sum += max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
                }
            }
            $d = $d->modify('+1 day');
        }
        return $sum;
    }
}
