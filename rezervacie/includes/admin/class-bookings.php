<?php
namespace Rzv\Admin;

use Rzv\Booking;
use Rzv\Db;
use Rzv\Mailer;
use Rzv\Settings;

defined('ABSPATH') || exit;

class Bookings
{
    public static function filters(): array
    {
        $g = wp_unslash($_GET);
        $f = [
            'from'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['from'] ?? '') ? $g['from'] : wp_date('Y-m-d'),
            'to'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['to'] ?? '') ? $g['to'] : '',
            'status'      => isset($g['status']) && $g['status'] !== '' ? sanitize_key($g['status']) : '',
            'worker_id'   => (int) ($g['worker_id'] ?? 0),
            'service_id'  => (int) ($g['service_id'] ?? 0),
            'customer_id' => (int) ($g['customer_id'] ?? 0),
            'q'           => sanitize_text_field($g['q'] ?? ''),
        ];
        return array_filter($f);
    }

    public static function render(): void
    {
        $f = self::filters();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $per = 50;
        $rows = Booking::query($f + ['limit' => $per, 'offset' => ($page - 1) * $per]);
        $total = Booking::count($f);

        echo '<div class="wrap rzv-admin">';
        echo '<h1 class="wp-heading-inline">Rezervácie</h1> <a href="#" class="page-title-action" data-rzv-new>Nová rezervácia</a>';
        echo ' <a class="page-title-action" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'rzv_export', 'what' => 'bookings'] + $f, admin_url('admin-post.php')), 'rzv_export')) . '">Export CSV</a>';
        echo '<hr class="wp-header-end">';

        // filtre
        echo '<form method="get" class="rzv-filters"><input type="hidden" name="page" value="rzv-bookings">';
        if (!empty($f['customer_id'])) {
            echo '<input type="hidden" name="customer_id" value="' . (int) $f['customer_id'] . '">';
            $c = Db::row('customers', (int) $f['customer_id']);
            if ($c) {
                echo '<strong>Zákazník: ' . esc_html(trim($c['first_name'] . ' ' . $c['last_name'])) . '</strong> ';
            }
        }
        printf('<label>Od <input type="date" name="from" value="%s"></label> ', esc_attr($f['from'] ?? ''));
        printf('<label>Do <input type="date" name="to" value="%s"></label> ', esc_attr($f['to'] ?? ''));
        echo '<select name="status"><option value="">Všetky stavy</option>';
        foreach (Booking::STATUSES as $k => $l) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($f['status'] ?? '', $k, false), esc_html($l));
        }
        echo '</select> <select name="worker_id"><option value="">Všetci pracovníci</option>';
        foreach (Db::all('workers') as $w) {
            printf('<option value="%d"%s>%s</option>', $w['id'], selected($f['worker_id'] ?? 0, (int) $w['id'], false), esc_html($w['name']));
        }
        echo '</select> <select name="service_id"><option value="">Všetky služby</option>';
        foreach (Db::all('services') as $s) {
            printf('<option value="%d"%s>%s</option>', $s['id'], selected($f['service_id'] ?? 0, (int) $s['id'], false), esc_html($s['name']));
        }
        echo '</select> ';
        printf('<input type="search" name="q" value="%s" placeholder="Meno, e-mail, telefón"> ', esc_attr($f['q'] ?? ''));
        echo '<button class="button">Filtrovať</button> <a class="button-link" href="' . esc_url(admin_url('admin.php?page=rzv-bookings')) . '">Zrušiť filter</a></form>';

        echo '<table class="widefat striped rzv-table"><thead><tr><th>Termín</th><th>Zákazník</th><th>Služba</th><th>Pracovník</th><th>Cena</th><th>Platba</th><th>Stav</th><th>Vytvorená</th><th></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="9">Žiadne rezervácie pre zvolený filter.</td></tr>';
        }
        foreach ($rows as $b) {
            $actions = [];
            $st = fn($s, $l, $notify = 1) => '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=rzv_status&id=' . $b['id'] . '&status=' . $s . '&notify=' . $notify), 'rzv_status_' . $b['id'])) . '">' . esc_html($l) . '</a>';
            if ($b['status'] === 'pending') {
                $actions[] = $st('confirmed', 'Potvrdiť');
            }
            if (in_array($b['status'], ['pending', 'confirmed'], true)) {
                if (Db::ts($b['start_at']) < time()) {
                    $actions[] = $st('completed', 'Prišiel', 0);
                    $actions[] = $st('no_show', 'Neprišiel', 0);
                }
            }
            $actions[] = '<a href="#" class="button button-small" data-rzv-edit="' . (int) $b['id'] . '">Upraviť</a>';
            $extra = $b['addons_list'] ? '<br><small>+ ' . esc_html(implode(', ', array_column($b['addons_list'], 'name'))) . '</small>' : '';
            $note = $b['note'] ? '<br><small class="rzv-note">' . esc_html($b['note']) . '</small>' : '';
            printf(
                '<tr><td><strong>%s</strong><br>%s – %s</td><td><a href="%s">%s</a><br><small>%s %s</small>%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="rzv-badge rzv-badge--%s">%s</span></td><td><small>%s<br>%s</small></td><td class="rzv-actions-col">%s</td></tr>',
                esc_html(wp_date('D j. n. Y', Db::ts($b['start_at']), Db::tz())),
                esc_html(substr($b['start_at'], 11, 5)), esc_html(substr($b['end_at'], 11, 5)),
                esc_url(admin_url('admin.php?page=rzv-customers&edit=' . $b['customer_id'])), esc_html($b['customer_name']),
                esc_html($b['phone']), esc_html($b['email']), $note,
                esc_html($b['service_name']), $extra, esc_html($b['worker_name']),
                esc_html(Settings::money(\Rzv\Pricing::total($b))) . ($b['coupon_code'] ? '<br><small>' . esc_html($b['coupon_code']) . '</small>' : '') . ((float) $b['voucher_amount'] > 0 ? '<br><small>poukážka −' . esc_html(Settings::money($b['voucher_amount'])) . '</small>' : ''),
                self::pay_label($b),
                esc_attr($b['status']), esc_html($b['status_label']),
                esc_html(Mailer::date($b['created_at'])), esc_html($b['source'] === 'web' ? 'online' : 'admin'),
                implode(' ', $actions)
            );
        }
        echo '</tbody></table>';
        $pages = (int) ceil($total / $per);
        echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' položiek</span> ';
        if ($pages > 1) {
            echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages]);
        }
        echo '</div></div></div>';
    }

    private static function pay_label(array $b): string
    {
        $total = \Rzv\Pricing::total($b);
        $due = \Rzv\Pricing::due($b);
        if ($total <= 0) {
            return '<span class="rzv-badge rzv-badge--confirmed">—</span>';
        }
        if ($due <= 0) {
            return '<span class="rzv-badge rzv-badge--confirmed">zaplatené</span>';
        }
        if ((float) $b['paid'] > 0) {
            return '<span class="rzv-badge rzv-badge--pending">zostáva ' . esc_html(Settings::money($due)) . '</span>';
        }
        return '<small>na mieste</small>';
    }

    public static function render_waitlist(): void
    {
        global $wpdb;
        if (isset($_GET['del']) && check_admin_referer('rzv_wl_' . (int) $_GET['del'])) {
            $wpdb->delete(Db::t('waitlist'), ['id' => (int) $_GET['del']]);
        }
        $rows = $wpdb->get_results(
            'SELECT w.*, c.first_name, c.last_name, c.email, c.phone, s.name AS service_name, k.name AS worker_name FROM ' . Db::t('waitlist') . ' w
             LEFT JOIN ' . Db::t('customers') . ' c ON c.id = w.customer_id
             LEFT JOIN ' . Db::t('services') . ' s ON s.id = w.service_id
             LEFT JOIN ' . Db::t('workers') . ' k ON k.id = w.worker_id
             WHERE w.date >= CURDATE() - INTERVAL 7 DAY ORDER BY w.date ASC, w.id ASC', ARRAY_A
        ) ?: [];
        $labels = ['waiting' => 'Čaká', 'notified' => 'Upozornený', 'expired' => 'Expirované', 'booked' => 'Rezervoval'];
        echo '<div class="wrap rzv-admin"><h1>Čakacia listina (náhradníci)</h1>';
        if (!Settings::get('waitlist')) {
            echo '<div class="notice notice-warning"><p>Čakacia listina je vypnutá. Zapnete ju v <a href="' . esc_url(admin_url('admin.php?page=rzv-settings&tab=booking')) . '">Nastaveniach</a>.</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Deň</th><th>Služba</th><th>Pracovník</th><th>Zákazník</th><th>Kontakt</th><th>Stav</th><th>Prihlásený</th><th></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="8">Nikto nečaká.</td></tr>';
        }
        foreach ($rows as $r) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s<br>%s</td><td>%s</td><td>%s</td><td><a class="button button-small button-link-delete" href="%s">Zmazať</a></td></tr>',
                esc_html(wp_date('D j. n. Y', Db::ts($r['date'] . ' 12:00:00'), Db::tz())), esc_html($r['service_name']), esc_html($r['worker_name'] ?: 'ktokoľvek'),
                esc_html(trim($r['first_name'] . ' ' . $r['last_name'])), esc_html($r['email']), esc_html($r['phone']),
                esc_html($labels[$r['status']] ?? $r['status']), esc_html(Mailer::date($r['created_at'])),
                esc_url(wp_nonce_url(admin_url('admin.php?page=rzv-waitlist&del=' . $r['id']), 'rzv_wl_' . $r['id'])));
        }
        echo '</tbody></table></div>';
    }
}
