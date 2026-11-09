<?php
namespace Rzv\Admin;

use Rzv\Db;
use Rzv\Mailer;
use Rzv\Ratings;
use Rzv\Settings;

defined('ABSPATH') || exit;

/** Admin F3: prenájmy a hodnotenia. */
class Extras
{
    private static function opts(string $table, string $label = 'name', string $where = '1=1'): array
    {
        $out = [];
        foreach (Db::all($table, $where, $label . ' ASC') as $r) {
            $out[$r['id']] = $r[$label];
        }
        return $out;
    }

    public static function cruds(callable $yes, callable $money): array
    {
        return [
            'rzv-rentals' => [
                'table' => 'services', 'title' => 'Prenájmy', 'singular' => 'prenájom', 'order' => 'priority DESC, name ASC', 'search' => ['name'],
                'where' => "kind = 'rental'",
                'intro' => 'Prenájom miestnosti alebo predmetu bez pracovníka. Koľko prenájmov môže prebiehať naraz, určuje počet kusov predmetu (Miestnosti, prístroje a predmety). Dostupný je v pracovnom čase prevádzky.',
                'columns' => [
                    'name' => 'Názov',
                    'duration' => ['Trvanie', fn($r) => ((int) $r['buffer_before'] ? (int) $r['buffer_before'] . ' + ' : '') . (int) $r['duration'] . ((int) $r['buffer_after'] ? ' + ' . (int) $r['buffer_after'] : '') . ' min'],
                    'price' => ['Cena', $money('price')],
                    'items' => ['Predmety', fn($r) => esc_html(implode(', ', array_intersect_key(self::opts('resources'), array_flip(Db::pivot('service_resource', 'service_id', (int) $r['id'], 'resource_id'))))) ?: '<span style="color:#b32d2e">žiadny – nedá sa rezervovať</span>'],
                    'auto_confirm' => ['Autom. potvrdenie', $yes('auto_confirm')],
                    'active' => ['Aktívny', $yes('active')],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Názov', 'required' => true],
                    ['key' => 'category_id', 'label' => 'Kategória', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('categories')],
                    ['key' => 'description', 'label' => 'Popis', 'type' => 'textarea', 'rows' => 3],
                    ['key' => 'duration', 'label' => 'Trvanie', 'type' => 'number', 'default' => 60, 'min' => 5, 'step' => 5, 'suffix' => 'min'],
                    ['key' => 'buffer_before', 'label' => 'Príprava pred', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 5, 'suffix' => 'min'],
                    ['key' => 'buffer_after', 'label' => 'Upratanie po', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 5, 'suffix' => 'min'],
                    ['key' => 'price', 'label' => 'Cena', 'type' => 'money', 'default' => 0],
                    ['key' => 'deposit', 'label' => 'Platba vopred', 'type' => 'money', 'default' => 0],
                    ['key' => 'resources', 'label' => 'Predmety prenájmu', 'type' => 'multi', 'options' => fn() => self::opts('resources'), 'pivot' => ['service_resource', 'service_id', 'resource_id'], 'help' => 'Povinné. Napr. „Salónik“ s počtom kusov 1 = jeden prenájom naraz.'],
                    ['key' => 'addons', 'label' => 'Doplnkové služby', 'type' => 'multi', 'options' => fn() => self::opts('addons'), 'pivot' => ['service_addon', 'service_id', 'addon_id']],
                    ['key' => 'fields', 'label' => 'Otázky vo formulári', 'type' => 'multi', 'options' => fn() => self::opts('fields', 'label', 'all_services = 0'), 'pivot' => ['service_field', 'service_id', 'field_id']],
                    ['key' => 'auto_confirm', 'label' => 'Automatické potvrdenie', 'type' => 'checkbox', 'default' => 1],
                    ['key' => 'color', 'label' => 'Farba v kalendári', 'type' => 'color'],
                    ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0],
                    ['key' => 'visible', 'label' => 'Zobraziť v online formulári', 'type' => 'checkbox', 'default' => 1],
                    ['key' => 'active', 'label' => 'Aktívny', 'type' => 'checkbox', 'default' => 1],
                ],
                'before_save' => function ($data, $id, $in) {
                    if (empty(array_filter((array) ($in['resources'] ?? [])))) {
                        return new \WP_Error('rzv', 'Vyberte aspoň jeden predmet prenájmu (najprv ho pridajte v Miestnosti, prístroje a predmety).');
                    }
                    $data['kind'] = 'rental';
                    $data['capacity'] = 1;
                    return $data;
                },
            ],
        ];
    }

    /* ---------- hodnotenia ---------- */

    public static function handle_ratings(): void
    {
        global $wpdb;
        if (!current_user_can('rzv_manage')) {
            return;
        }
        $t = Db::t('ratings');
        if (isset($_GET['rate_do'], $_GET['id']) && check_admin_referer('rzv_rating_' . (int) $_GET['id'])) {
            $id = (int) $_GET['id'];
            $do = sanitize_key($_GET['rate_do']);
            if ($do === 'delete') {
                $wpdb->delete($t, ['id' => $id]);
            } elseif (isset(Ratings::STATUSES[$do])) {
                $wpdb->update($t, ['status' => $do], ['id' => $id]);
            }
            wp_safe_redirect(remove_query_arg(['rate_do', 'id', '_wpnonce']));
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_id'])) {
            $id = (int) $_POST['reply_id'];
            check_admin_referer('rzv_reply_' . $id);
            $wpdb->update($t, ['reply' => sanitize_textarea_field(wp_unslash($_POST['reply'] ?? ''))], ['id' => $id]);
            wp_safe_redirect(add_query_arg('msg', 'saved'));
            exit;
        }
    }

    public static function render_ratings(): void
    {
        global $wpdb;
        $status = sanitize_key($_GET['status'] ?? '');
        $worker = (int) ($_GET['worker_id'] ?? 0);
        $where = '1=1';
        if (isset(Ratings::STATUSES[$status])) {
            $where .= $wpdb->prepare(' AND r.status = %s', $status);
        }
        if ($worker) {
            $where .= $wpdb->prepare(' AND r.worker_id = %d', $worker);
        }
        $rows = $wpdb->get_results(
            'SELECT r.*, c.first_name, c.last_name, c.email, s.name AS service_name, w.name AS worker_name, b.start_at FROM ' . Db::t('ratings') . ' r
             LEFT JOIN ' . Db::t('customers') . ' c ON c.id = r.customer_id
             LEFT JOIN ' . Db::t('services') . ' s ON s.id = r.service_id
             LEFT JOIN ' . Db::t('workers') . ' w ON w.id = r.worker_id
             LEFT JOIN ' . Db::t('bookings') . " b ON b.id = r.booking_id
             WHERE $where ORDER BY r.id DESC LIMIT 200", ARRAY_A
        ) ?: [];
        $all = $wpdb->get_row('SELECT COUNT(*) n, AVG(stars) a FROM ' . Db::t('ratings'), ARRAY_A);
        $per_worker = $wpdb->get_results('SELECT w.name, COUNT(*) n, AVG(r.stars) a FROM ' . Db::t('ratings') . ' r JOIN ' . Db::t('workers') . ' w ON w.id = r.worker_id GROUP BY r.worker_id ORDER BY a DESC', ARRAY_A) ?: [];

        echo '<div class="wrap rzv-admin"><h1>Hodnotenia</h1>';
        if (!Settings::get('ratings_enabled')) {
            echo '<div class="notice notice-warning"><p>Zber hodnotení je vypnutý. Zapnete ho v <a href="' . esc_url(admin_url('admin.php?page=rzv-settings&tab=ratings')) . '">Nastaveniach</a>.</p></div>';
        }
        if (($_GET['msg'] ?? '') === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Odpoveď uložená.</p></div>';
        }
        echo '<div class="rzv-kpis">';
        echo '<div class="rzv-kpi"><span>Priemer</span><strong>' . ($all['n'] ? esc_html(number_format_i18n((float) $all['a'], 1)) . ' ★' : '—') . '</strong><small>' . (int) $all['n'] . ' hodnotení</small></div>';
        foreach ($per_worker as $p) {
            echo '<div class="rzv-kpi"><span>' . esc_html($p['name']) . '</span><strong>' . esc_html(number_format_i18n((float) $p['a'], 1)) . ' ★</strong><small>' . (int) $p['n'] . ' hodnotení</small></div>';
        }
        echo '</div><p class="description">Na web ich vložíte shortcode <code>[rezervacie_hodnotenia]</code> (voliteľne <code>limit="10" min="4" worker="ID"</code>).</p>';

        echo '<ul class="subsubsub">';
        $links = ['' => 'Všetky'] + Ratings::STATUSES;
        $i = 0;
        foreach ($links as $k => $l) {
            echo '<li><a href="' . esc_url(admin_url('admin.php?page=rzv-ratings' . ($k ? '&status=' . $k : ''))) . '"' . ($status === $k ? ' class="current"' : '') . '>' . esc_html($l) . '</a>' . (++$i < count($links) ? ' | ' : '') . '</li>';
        }
        echo '</ul><table class="widefat striped rzv-table"><thead><tr><th>Hodnotenie</th><th>Zákazník</th><th>Návšteva</th><th>Stav</th><th></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="5">Zatiaľ žiadne hodnotenia.</td></tr>';
        }
        foreach ($rows as $r) {
            $act = function ($do, $label, $confirm = '') use ($r) {
                return '<a class="button button-small"' . ($confirm ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '') . ' href="'
                    . esc_url(wp_nonce_url(add_query_arg(['rate_do' => $do, 'id' => $r['id']]), 'rzv_rating_' . $r['id'])) . '">' . esc_html($label) . '</a> ';
            };
            $actions = ($r['status'] !== 'published' ? $act('published', 'Zverejniť') : '') . ($r['status'] !== 'hidden' ? $act('hidden', 'Skryť') : '') . $act('delete', 'Zmazať', 'Zmazať hodnotenie?');
            $reply = '<details><summary>' . ($r['reply'] ? 'Upraviť odpoveď' : 'Odpovedať') . '</summary><form method="post">' . wp_nonce_field('rzv_reply_' . $r['id'], '_wpnonce', true, false)
                . '<input type="hidden" name="reply_id" value="' . (int) $r['id'] . '"><textarea name="reply" rows="2" class="large-text">' . esc_textarea((string) $r['reply']) . '</textarea><button class="button button-small">Uložiť</button></form></details>';
            printf('<tr><td><span class="rzv-stars-admin">%s</span>%s%s</td><td>%s<br><small>%s</small></td><td>%s<br><small>%s %s</small></td><td><span class="rzv-badge rzv-badge--r-%s">%s</span></td><td class="rzv-actions-col">%s</td></tr>',
                esc_html(str_repeat('★', (int) $r['stars']) . str_repeat('☆', 5 - (int) $r['stars'])),
                $r['comment'] ? '<p>' . nl2br(esc_html($r['comment'])) . '</p>' : '',
                $reply . ($r['reply'] ? '<p class="rzv-muted"><em>Odpoveď: ' . esc_html($r['reply']) . '</em></p>' : ''),
                esc_html(trim($r['first_name'] . ' ' . $r['last_name'])), esc_html((string) $r['email']),
                esc_html((string) $r['service_name']), esc_html((string) $r['worker_name']), esc_html($r['start_at'] ? Mailer::date($r['start_at']) : ''),
                esc_attr($r['status']), esc_html(Ratings::STATUSES[$r['status']] ?? $r['status']), $actions);
        }
        echo '</tbody></table></div>';
    }
}
