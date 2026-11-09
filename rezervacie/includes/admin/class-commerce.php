<?php
namespace Rzv\Admin;

use Rzv\Coupons;
use Rzv\Db;
use Rzv\Mailer;
use Rzv\Payments;
use Rzv\Settings;
use Rzv\Vouchers;

defined('ABSPATH') || exit;

/** Admin F2: zľavové kódy, poukážky, platby. */
class Commerce
{
    public static function init(): void
    {
        add_action('admin_post_rzv_voucher_action', [self::class, 'voucher_action']);
        add_action('admin_post_rzv_payment_paid', [self::class, 'payment_paid']);
    }

    private static function opts(string $table, string $label = 'name'): array
    {
        $out = [];
        foreach (Db::all($table, '1=1', $label . ' ASC') as $r) {
            $out[$r['id']] = $r[$label];
        }
        return $out;
    }

    public static function cruds(callable $yes, callable $money): array
    {
        $defs = [];

        $defs['rzv-coupons'] = [
            'table' => 'coupons', 'title' => 'Zľavové kódy', 'singular' => 'zľavový kód', 'order' => 'id DESC', 'search' => ['code', 'note'],
            'intro' => 'Kódy, ktoré zákazníci zadajú pri rezervácii alebo nákupe poukážky.',
            'columns' => [
                'code'  => ['Kód', fn($r) => '<code>' . esc_html($r['code']) . '</code>' . ($r['note'] ? '<br><small>' . esc_html($r['note']) . '</small>' : '')],
                'value' => ['Zľava', fn($r) => esc_html(Coupons::label($r))],
                'scope' => ['Platí pre', fn($r) => esc_html(Coupons::SCOPES[$r['scope']] ?? $r['scope'])],
                'valid' => ['Platnosť', fn($r) => esc_html(($r['valid_from'] ? Vouchers::date($r['valid_from']) : '…') . ' – ' . ($r['valid_to'] ? Vouchers::date($r['valid_to']) : '…'))],
                'uses'  => ['Použitie', fn($r) => (int) $r['uses'] . ($r['max_uses'] ? ' / ' . (int) $r['max_uses'] : '')],
                'limits'=> ['Obmedzenia', fn($r) => esc_html(implode(', ', array_filter([
                    $r['per_customer'] ? $r['per_customer'] . '× na zákazníka' : '',
                    (float) $r['min_amount'] > 0 ? 'od ' . Settings::money($r['min_amount']) : '',
                    Db::pivot('coupon_service', 'coupon_id', (int) $r['id'], 'service_id') ? 'vybrané služby' : '',
                ])) ?: '—')],
                'active' => ['Aktívny', $yes('active')],
            ],
            'fields' => [
                ['key' => 'code', 'label' => 'Kód', 'required' => true, 'help' => 'Napr. LETO20. Veľké/malé písmená sa nerozlišujú.'],
                ['key' => 'note', 'label' => 'Interná poznámka'],
                ['key' => 'type', 'label' => 'Typ zľavy', 'type' => 'select', 'options' => Coupons::TYPES],
                ['key' => 'value', 'label' => 'Hodnota', 'type' => 'decimal', 'help' => 'Percentá alebo suma v €.'],
                ['key' => 'scope', 'label' => 'Platí pre', 'type' => 'select', 'options' => Coupons::SCOPES],
                ['key' => 'services', 'label' => 'Len pre služby', 'type' => 'multi', 'options' => fn() => self::opts('services'), 'pivot' => ['coupon_service', 'coupon_id', 'service_id'], 'help' => 'Nič nevybrané = všetky služby.'],
                ['key' => 'valid_from', 'label' => 'Platí od', 'type' => 'date'],
                ['key' => 'valid_to', 'label' => 'Platí do', 'type' => 'date'],
                ['key' => 'max_uses', 'label' => 'Max. počet použití', 'type' => 'number', 'nullable' => true, 'min' => '1', 'help' => 'Prázdne = neobmedzene.'],
                ['key' => 'per_customer', 'label' => 'Max. na zákazníka', 'type' => 'number', 'nullable' => true, 'min' => '1', 'help' => 'Podľa e-mailu. Prázdne = neobmedzene.'],
                ['key' => 'min_amount', 'label' => 'Minimálna suma', 'type' => 'money', 'default' => 0],
                ['key' => 'active', 'label' => 'Aktívny', 'type' => 'checkbox', 'default' => 1],
            ],
            'before_save' => function ($data, $id) {
                global $wpdb;
                $data['code'] = Coupons::normalize($data['code']);
                if ($data['code'] === '') {
                    return new \WP_Error('rzv', 'Zadajte kód.');
                }
                $dup = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::t('coupons') . ' WHERE code = %s AND id <> %d', $data['code'], $id));
                $dup = $dup ?: $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::t('vouchers') . ' WHERE code = %s', $data['code']));
                if ($dup) {
                    return new \WP_Error('rzv', 'Kód už existuje.');
                }
                $data['value'] = round((float) $data['value'], 2);
                if ($data['type'] === 'percent' && $data['value'] > 100) {
                    return new \WP_Error('rzv', 'Percentuálna zľava môže byť najviac 100 %.');
                }
                foreach (['max_uses', 'per_customer'] as $k) {
                    $data[$k] = $data[$k] ? (int) $data[$k] : null;
                }
                if (!$id) {
                    $data['created_at'] = Db::now();
                }
                return $data;
            },
        ];

        $defs['rzv-voucher-types'] = [
            'table' => 'voucher_types', 'title' => 'Poukážky a permanentky – ponuka', 'singular' => 'poukážka', 'order' => 'priority DESC, name ASC',
            'intro' => 'Čo predávate. Predajná stránka: shortcode <code>[rezervacie_poukazky]</code>.',
            'header_html' => fn() => ($u = self::voucher_page_url()) ? ' <a class="page-title-action" target="_blank" href="' . esc_url($u) . '">Predajná stránka</a>' : '',
            'columns' => [
                'name'  => 'Názov',
                'kind'  => ['Druh', fn($r) => esc_html(Vouchers::KINDS[$r['kind']] ?? $r['kind'])],
                'value' => ['Obsah', fn($r) => esc_html($r['kind'] === 'value' ? Settings::money($r['value']) : (($r['kind'] === 'pass' ? $r['entries'] . '× ' : '') . (self::opts('services')[$r['service_id']] ?? '?')))],
                'price' => ['Cena', $money('price')],
                'validity_days' => ['Platnosť', fn($r) => (int) $r['validity_days'] . ' dní'],
                'sell_online' => ['Online predaj', $yes('sell_online')],
                'active' => ['Aktívna', $yes('active')],
            ],
            'fields' => [
                ['key' => 'name', 'label' => 'Názov', 'required' => true],
                ['key' => 'description', 'label' => 'Popis', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'kind', 'label' => 'Druh', 'type' => 'select', 'options' => Vouchers::KINDS],
                ['key' => 'value', 'label' => 'Hodnota poukážky', 'type' => 'money', 'default' => 0, 'help' => 'Pri hodnotovej poukážke – suma, ktorú zákazník čerpá.'],
                ['key' => 'service_id', 'label' => 'Služba', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('services'), 'help' => 'Pri poukážke na službu a permanentke.'],
                ['key' => 'entries', 'label' => 'Počet vstupov', 'type' => 'number', 'default' => 10, 'min' => 1, 'help' => 'Len permanentka.'],
                ['key' => 'price', 'label' => 'Predajná cena', 'type' => 'money', 'default' => 0, 'help' => 'Napr. permanentka 10 vstupov za cenu 9.'],
                ['key' => 'validity_days', 'label' => 'Platnosť', 'type' => 'number', 'default' => 365, 'min' => 1, 'suffix' => 'dní od zaplatenia'],
                ['key' => 'image', 'label' => 'Obrázok', 'type' => 'media'],
                ['key' => 'color', 'label' => 'Farba', 'type' => 'color'],
                ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0],
                ['key' => 'sell_online', 'label' => 'Predávať online', 'type' => 'checkbox', 'default' => 1],
                ['key' => 'active', 'label' => 'Aktívna', 'type' => 'checkbox', 'default' => 1],
            ],
            'before_save' => function ($data) {
                if ($data['kind'] !== 'value' && empty($data['service_id'])) {
                    return new \WP_Error('rzv', 'Vyberte službu.');
                }
                if ($data['kind'] === 'value' && (float) $data['value'] <= 0) {
                    return new \WP_Error('rzv', 'Zadajte hodnotu poukážky.');
                }
                if ($data['kind'] === 'value') {
                    $data['service_id'] = null;
                }
                if ((float) $data['price'] <= 0) {
                    $data['price'] = $data['kind'] === 'value' ? $data['value'] : $data['price'];
                }
                return $data;
            },
        ];

        $defs['rzv-vouchers'] = [
            'table' => 'vouchers', 'title' => 'Predané poukážky', 'singular' => 'poukážka', 'order' => 'id DESC',
            'search' => ['code', 'buyer_name', 'buyer_email', 'recipient'],
            'intro' => 'Pridaním vystavíte poukážku ručne (napr. predaj na prevádzke). Nezaplatené online objednávky sa zrušia po ' . (int) Settings::get('voucher_pay_days') . ' dňoch.',
            'columns' => [
                'code'   => ['Kód', fn($r) => '<code>' . esc_html($r['code']) . '</code><br><small>' . esc_html($r['name']) . '</small>'],
                'value'  => ['Hodnota', fn($r) => esc_html(Vouchers::value_label($r))],
                'left'   => ['Zostáva', fn($r) => esc_html(Vouchers::remaining_label($r))],
                'buyer'  => ['Kupujúci', fn($r) => esc_html((string) $r['buyer_name']) . '<br><small>' . esc_html((string) $r['buyer_email']) . '</small>' . ($r['recipient'] ? '<br><small>pre: ' . esc_html($r['recipient']) . '</small>' : '')],
                'price'  => ['Cena', fn($r) => esc_html(Settings::money((float) $r['price'] - (float) $r['discount'])) . ($r['coupon_code'] ? '<br><small>' . esc_html($r['coupon_code']) . '</small>' : '')],
                'valid_to' => ['Platí do', fn($r) => esc_html(Vouchers::date($r['valid_to']))],
                'status' => ['Stav', fn($r) => '<span class="rzv-badge rzv-badge--v-' . esc_attr($r['status']) . '">' . esc_html(Vouchers::STATUSES[$r['status']] ?? $r['status']) . '</span>'],
            ],
            'row_actions' => function ($r) {
                $a = '';
                $act = fn($do, $label, $confirm = '') => '<a class="button button-small"' . ($confirm ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '') . ' href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=rzv_voucher_action&do=' . $do . '&id=' . $r['id']), 'rzv_voucher_' . $r['id'])) . '">' . esc_html($label) . '</a> ';
                if ($r['status'] === 'unpaid') {
                    $a .= $act('paid', 'Zaplatené', 'Označiť ako zaplatenú a poslať zákazníkovi?');
                }
                if ($r['status'] === 'active' && $r['buyer_email']) {
                    $a .= $act('send', 'Poslať e-mail');
                }
                $a .= '<a class="button button-small" target="_blank" href="' . esc_url(Vouchers::url($r)) . '">Zobraziť</a> ';
                return $a;
            },
            'fields' => [
                ['key' => 'type_id', 'label' => 'Z ponuky', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('voucher_types'), 'help' => 'Pri novej poukážke doplní druh, hodnotu, službu a platnosť.'],
                ['key' => 'code', 'label' => 'Kód', 'help' => 'Prázdne = vygeneruje sa.'],
                ['key' => 'name', 'label' => 'Názov'],
                ['key' => 'kind', 'label' => 'Druh', 'type' => 'select', 'options' => Vouchers::KINDS],
                ['key' => 'service_id', 'label' => 'Služba', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('services')],
                ['key' => 'initial_value', 'label' => 'Hodnota', 'type' => 'money', 'default' => 0],
                ['key' => 'balance', 'label' => 'Zostatok', 'type' => 'money', 'default' => 0, 'help' => 'Hodnotová poukážka.'],
                ['key' => 'entries_total', 'label' => 'Vstupov spolu', 'type' => 'number', 'default' => 0, 'min' => 0],
                ['key' => 'entries_left', 'label' => 'Zostáva vstupov', 'type' => 'number', 'default' => 0, 'min' => 0],
                ['key' => 'price', 'label' => 'Predajná cena', 'type' => 'money', 'default' => 0],
                ['key' => 'valid_to', 'label' => 'Platí do', 'type' => 'date'],
                ['key' => 'status', 'label' => 'Stav', 'type' => 'select', 'options' => Vouchers::STATUSES, 'default' => 'active'],
                ['key' => 'buyer_name', 'label' => 'Kupujúci'],
                ['key' => 'buyer_email', 'label' => 'E-mail kupujúceho', 'type' => 'email'],
                ['key' => 'buyer_phone', 'label' => 'Telefón', 'type' => 'tel'],
                ['key' => 'recipient', 'label' => 'Pre koho'],
                ['key' => 'message', 'label' => 'Venovanie', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'history', 'type' => 'html', 'html' => fn($row) => self::voucher_history($row)],
            ],
            'before_save' => function ($data, $id) {
                global $wpdb;
                $data['code'] = Coupons::normalize((string) $data['code']);
                if (!$id) {
                    $type = $data['type_id'] ? Db::row('voucher_types', (int) $data['type_id']) : null;
                    if ($type) {
                        $data['kind'] = $type['kind'];
                        $data['name'] = $data['name'] ?: $type['name'];
                        $data['service_id'] = $type['kind'] === 'value' ? null : (int) $type['service_id'];
                        if ((float) $data['initial_value'] <= 0) {
                            $data['initial_value'] = $type['kind'] === 'value' ? (float) $type['value'] : (float) $type['price'];
                        }
                        if ((int) $data['entries_total'] <= 0 && $type['kind'] !== 'value') {
                            $data['entries_total'] = $type['kind'] === 'pass' ? (int) $type['entries'] : 1;
                        }
                        if ((float) $data['price'] <= 0) {
                            $data['price'] = (float) $type['price'];
                        }
                        if (!$data['valid_to']) {
                            $data['valid_to'] = wp_date('Y-m-d', time() + (int) $type['validity_days'] * DAY_IN_SECONDS);
                        }
                    }
                    if ($data['kind'] === 'value' && (float) $data['balance'] <= 0) {
                        $data['balance'] = $data['initial_value'];
                    }
                    if ($data['kind'] !== 'value' && (int) $data['entries_left'] <= 0) {
                        $data['entries_left'] = max(1, (int) $data['entries_total']);
                        $data['entries_total'] = max(1, (int) $data['entries_total']);
                    }
                    $data['code'] = $data['code'] ?: Vouchers::generate_code();
                    $data['token'] = \Rzv\Booking::token();
                    $data['created_at'] = Db::now();
                    $data['source'] = 'admin';
                    if ($data['status'] === 'active') {
                        $data['activated_at'] = Db::now();
                    }
                    $data['name'] = $data['name'] ?: 'Darčeková poukážka';
                }
                if ($data['code'] === '') {
                    return new \WP_Error('rzv', 'Zadajte kód.');
                }
                $dup = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::t('vouchers') . ' WHERE code = %s AND id <> %d', $data['code'], $id))
                    ?: $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::t('coupons') . ' WHERE code = %s', $data['code']));
                if ($dup) {
                    return new \WP_Error('rzv', 'Kód už existuje.');
                }
                if ($data['kind'] !== 'value' && empty($data['service_id'])) {
                    return new \WP_Error('rzv', 'Vyberte službu.');
                }
                return $data;
            },
        ];

        return $defs;
    }

    private static function voucher_history(array $row): string
    {
        global $wpdb;
        if (empty($row['id'])) {
            return '';
        }
        $uses = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Db::t('voucher_uses') . ' WHERE voucher_id = %d ORDER BY id', (int) $row['id']), ARRAY_A) ?: [];
        $pays = Payments::for_voucher((int) $row['id']);
        $h = '<h3>Čerpanie</h3>';
        if (!$uses) {
            $h .= '<p>Zatiaľ nečerpaná.</p>';
        } else {
            $h .= '<table class="widefat striped" style="max-width:640px"><tbody>';
            foreach ($uses as $u) {
                $link = $u['booking_id'] ? ' <a href="' . esc_url(admin_url('admin.php?page=rzv-bookings&edit=' . $u['booking_id'])) . '">#' . (int) $u['booking_id'] . '</a>' : '';
                $h .= '<tr><td>' . esc_html(Mailer::date($u['created_at'])) . '</td><td>' . esc_html($u['note']) . $link . '</td><td>'
                    . esc_html((float) $u['amount'] > 0 ? Settings::money($u['amount']) : '') . ((int) $u['entries'] ? ' ' . (int) $u['entries'] . ' vstup' : '') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        if ($pays) {
            $h .= '<h3>Platby</h3><ul>';
            foreach ($pays as $p) {
                $h .= '<li>' . esc_html(Mailer::date($p['created_at']) . ' – ' . Settings::money($p['amount']) . ' – ' . (Payments::METHODS[$p['method']] ?? $p['method']) . ' – ' . (Payments::STATUSES[$p['status']] ?? $p['status']) . ' (VS ' . $p['reference'] . ')') . '</li>';
            }
            $h .= '</ul>';
        }
        return $h;
    }

    public static function voucher_page_url(): string
    {
        $id = (int) Settings::get('voucher_page');
        return $id ? (string) get_permalink($id) : '';
    }

    public static function voucher_action(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        check_admin_referer('rzv_voucher_' . $id);
        if (!current_user_can('rzv_manage')) {
            wp_die('Nedostatočné oprávnenia.');
        }
        $v = Vouchers::get($id);
        $do = sanitize_key($_GET['do'] ?? '');
        if ($v && $do === 'paid') {
            $pending = array_values(array_filter(Payments::for_voucher($id), fn($p) => $p['status'] === 'pending'));
            if ($pending) {
                Payments::mark_paid((int) $pending[0]['id'], 'transfer');
            } else {
                Vouchers::activate($id);
            }
        } elseif ($v && $do === 'send') {
            Mailer::voucher($v);
        }
        wp_safe_redirect(add_query_arg('msg', 'saved', wp_get_referer() ?: admin_url('admin.php?page=rzv-vouchers')));
        exit;
    }

    public static function payment_paid(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        check_admin_referer('rzv_payment_' . $id);
        if (!current_user_can('rzv_manage')) {
            wp_die('Nedostatočné oprávnenia.');
        }
        Payments::mark_paid($id, 'transfer');
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=rzv-payments'));
        exit;
    }

    /** Zoznam platieb. */
    public static function render_payments(): void
    {
        global $wpdb;
        $status = sanitize_key($_GET['status'] ?? '');
        $where = '1=1';
        if (isset(Payments::STATUSES[$status])) {
            $where .= $wpdb->prepare(' AND p.status = %s', $status);
        } else {
            $where .= " AND p.status <> 'cancelled'";
        }
        $rows = $wpdb->get_results(
            'SELECT p.*, b.start_at, s.name AS service_name, CONCAT(c.first_name, " ", c.last_name) AS customer, v.code AS voucher_code, v.buyer_name
             FROM ' . Db::t('payments') . ' p
             LEFT JOIN ' . Db::t('bookings') . ' b ON b.id = p.booking_id
             LEFT JOIN ' . Db::t('services') . ' s ON s.id = b.service_id
             LEFT JOIN ' . Db::t('customers') . ' c ON c.id = b.customer_id
             LEFT JOIN ' . Db::t('vouchers') . " v ON v.id = p.voucher_id
             WHERE $where ORDER BY p.id DESC LIMIT 200", ARRAY_A
        ) ?: [];
        $sum = (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM " . Db::t('payments') . " WHERE status = 'paid' AND paid_at >= '" . esc_sql(wp_date('Y-m-01')) . "'");
        echo '<div class="wrap rzv-admin"><h1>Platby</h1>';
        echo '<p>Prijaté tento mesiac: <strong>' . esc_html(Settings::money($sum)) . '</strong></p>';
        echo '<ul class="subsubsub">';
        $links = ['' => 'Všetky', 'pending' => 'Čakajúce', 'paid' => 'Zaplatené', 'cancelled' => 'Zrušené'];
        $i = 0;
        foreach ($links as $k => $l) {
            echo '<li><a href="' . esc_url(admin_url('admin.php?page=rzv-payments' . ($k ? '&status=' . $k : ''))) . '"' . ($status === $k ? ' class="current"' : '') . '>' . esc_html($l) . '</a>' . (++$i < count($links) ? ' | ' : '') . '</li>';
        }
        echo '</ul><table class="widefat striped rzv-table"><thead><tr><th>Vytvorená</th><th>Za čo</th><th>Suma</th><th>VS</th><th>Spôsob</th><th>Stav</th><th>Zaplatená</th><th></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="8">Žiadne platby.</td></tr>';
        }
        foreach ($rows as $p) {
            if ($p['booking_id']) {
                $what = '<a href="' . esc_url(admin_url('admin.php?page=rzv-bookings&edit=' . $p['booking_id'])) . '">Rezervácia #' . (int) $p['booking_id'] . '</a><br><small>'
                    . esc_html(trim((string) $p['customer']) . ' · ' . $p['service_name'] . ' · ' . ($p['start_at'] ? Mailer::date($p['start_at']) : '')) . '</small>';
            } else {
                $what = '<a href="' . esc_url(admin_url('admin.php?page=rzv-vouchers&edit=' . $p['voucher_id'])) . '">Poukážka ' . esc_html((string) $p['voucher_code']) . '</a><br><small>' . esc_html((string) $p['buyer_name']) . '</small>';
            }
            $action = $p['status'] === 'pending'
                ? '<a class="button button-small" onclick="return confirm(\'Označiť platbu ako prijatú?\')" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=rzv_payment_paid&id=' . $p['id']), 'rzv_payment_' . $p['id'])) . '">Prijatá</a>'
                : '';
            printf('<tr><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td><span class="rzv-badge rzv-badge--p-%s">%s</span></td><td>%s</td><td>%s</td></tr>',
                esc_html(Mailer::date($p['created_at'])), $what, esc_html(Settings::money($p['amount'])), esc_html((string) $p['reference']),
                esc_html(Payments::METHODS[$p['method']] ?? $p['method']), esc_attr($p['status']), esc_html(Payments::STATUSES[$p['status']] ?? $p['status']),
                esc_html($p['paid_at'] ? Mailer::date($p['paid_at']) : '—'), $action);
        }
        echo '</tbody></table></div>';
    }
}
