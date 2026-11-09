<?php
namespace Rzv\Admin;

use Rzv\Db;
use Rzv\Settings;

defined('ABSPATH') || exit;

/**
 * Jednoduchý CRUD pre tabuľky pluginu, riadený definíciou polí.
 *
 * def: slug, table, title, singular, fields[], columns[], order, where, search[], cap, after_save(callable), row_actions(callable)
 * pole: key, label, type (text|textarea|number|money|checkbox|select|multi|color|datetime|date|email|tel|url|hours|media|html),
 *       options (array|callable), pivot [table, key, other], default, help, required, wide
 */
class Crud
{
    public array $d;

    public function __construct(array $def)
    {
        $this->d = $def + ['order' => 'id DESC', 'where' => '1=1', 'search' => [], 'per_page' => 50, 'cap' => 'rzv_manage', 'can_add' => true];
    }

    public function url(array $args = []): string
    {
        return add_query_arg($args, admin_url('admin.php?page=' . $this->d['slug']));
    }

    /** Spracovanie POST / zmazania – volá sa na load-{page} (pred výstupom). */
    public function handle(): void
    {
        global $wpdb;
        if (!current_user_can($this->d['cap'])) {
            return;
        }
        $t = Db::t($this->d['table']);

        if (isset($_GET['delete']) && check_admin_referer('rzv_del_' . $this->d['slug'] . '_' . (int) $_GET['delete'])) {
            $id = (int) $_GET['delete'];
            $wpdb->delete($t, ['id' => $id]);
            foreach ($this->d['fields'] as $f) {
                if (!empty($f['pivot'])) {
                    $wpdb->delete(Db::t($f['pivot'][0]), [$f['pivot'][1] => $id]);
                }
            }
            if (!empty($this->d['after_delete'])) {
                ($this->d['after_delete'])($id);
            }
            wp_safe_redirect($this->url(['msg' => 'deleted']));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['rzv_crud']) || $_POST['rzv_crud'] !== $this->d['slug']) {
            return;
        }
        check_admin_referer('rzv_save_' . $this->d['slug']);
        $id = (int) ($_POST['id'] ?? 0);
        $data = [];
        $pivots = [];
        $in = wp_unslash($_POST['f'] ?? []);
        foreach ($this->d['fields'] as $f) {
            if (($f['type'] ?? '') === 'html' || !empty($f['readonly'])) {
                continue;
            }
            $v = $this->sanitize($f, $in[$f['key']] ?? null);
            if (!empty($f['pivot'])) {
                $pivots[] = [$f['pivot'], (array) $v];
                continue;
            }
            $data[$f['key']] = $v;
        }
        if (!empty($this->d['before_save'])) {
            $data = ($this->d['before_save'])($data, $id, $in);
            if (is_wp_error($data)) {
                set_transient('rzv_crud_err_' . get_current_user_id(), $data->get_error_message(), 60);
                wp_safe_redirect($this->url($id ? ['edit' => $id] : ['new' => 1]));
                exit;
            }
        }
        if ($id) {
            $wpdb->update($t, $data, ['id' => $id]);
        } else {
            $wpdb->insert($t, $data);
            $id = (int) $wpdb->insert_id;
        }
        foreach ($pivots as [[$pt, $pk, $po], $ids]) {
            Db::set_pivot($pt, $pk, $id, $po, $ids);
        }
        if (!empty($this->d['after_save'])) {
            ($this->d['after_save'])($id, $data, $in);
        }
        wp_safe_redirect($this->url(['msg' => 'saved'] + (!empty($_POST['stay']) ? ['edit' => $id] : [])));
        exit;
    }

    private function sanitize(array $f, $v)
    {
        switch ($f['type'] ?? 'text') {
            case 'textarea':
                return sanitize_textarea_field((string) $v);
            case 'number':
                return $v === '' || $v === null ? ($f['nullable'] ?? false ? null : 0) : (int) $v;
            case 'money':
            case 'decimal':
                return round((float) str_replace(',', '.', (string) $v), 2);
            case 'checkbox':
                return $v ? 1 : 0;
            case 'select':
                return $v === '' || $v === null ? ($f['nullable'] ?? false ? null : '') : sanitize_text_field((string) $v);
            case 'multi':
                return array_map('intval', (array) $v);
            case 'color':
                return sanitize_hex_color((string) $v) ?: null;
            case 'email':
                return sanitize_email((string) $v);
            case 'url':
            case 'media':
                return esc_url_raw((string) $v);
            case 'datetime':
                $v = str_replace('T', ' ', (string) $v);
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v) ? substr($v, 0, 16) . ':00' : null;
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
            case 'hours':
                return self::parse_hours((array) $v);
            default:
                return sanitize_text_field((string) $v);
        }
    }

    public function render(): void
    {
        if (!current_user_can($this->d['cap'])) {
            wp_die('Nedostatočné oprávnenia.');
        }
        echo '<div class="wrap rzv-admin">';
        if (isset($_GET['edit']) || isset($_GET['new'])) {
            $this->form(isset($_GET['edit']) ? (int) $_GET['edit'] : 0);
        } else {
            $this->table();
        }
        echo '</div>';
    }

    private function notices(): void
    {
        $msg = $_GET['msg'] ?? '';
        if ($msg === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Uložené.</p></div>';
        } elseif ($msg === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>Zmazané.</p></div>';
        }
        $err = get_transient('rzv_crud_err_' . get_current_user_id());
        if ($err) {
            delete_transient('rzv_crud_err_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
        }
    }

    private function table(): void
    {
        global $wpdb;
        $t = Db::t($this->d['table']);
        $where = $this->d['where'];
        $args = [];
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        if ($q !== '' && $this->d['search']) {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where .= ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE %s", $this->d['search'])) . ')';
            $args = array_fill(0, count($this->d['search']), $like);
        }
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $per = (int) $this->d['per_page'];
        $sql = "SELECT * FROM $t WHERE $where ORDER BY {$this->d['order']} LIMIT $per OFFSET " . (($page - 1) * $per);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
        $csql = "SELECT COUNT(*) FROM $t WHERE $where";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($csql, $args) : $csql);

        echo '<h1 class="wp-heading-inline">' . esc_html($this->d['title']) . '</h1>';
        if ($this->d['can_add']) {
            echo ' <a href="' . esc_url($this->url(['new' => 1])) . '" class="page-title-action">Pridať</a>';
        }
        if (!empty($this->d['header_html'])) {
            echo ($this->d['header_html'])();
        }
        echo '<hr class="wp-header-end">';
        $this->notices();
        if (!empty($this->d['intro'])) {
            echo '<p class="description">' . wp_kses_post($this->d['intro']) . '</p>';
        }
        if ($this->d['search']) {
            echo '<form method="get" class="rzv-search"><input type="hidden" name="page" value="' . esc_attr($this->d['slug']) . '">';
            echo '<input type="search" name="s" value="' . esc_attr($q) . '" placeholder="Hľadať…"> <button class="button">Hľadať</button></form>';
        }
        echo '<table class="widefat striped rzv-table"><thead><tr>';
        foreach ($this->d['columns'] as $label) {
            echo '<th>' . esc_html(is_array($label) ? $label[0] : $label) . '</th>';
        }
        echo '<th class="rzv-actions-col"></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . (count($this->d['columns']) + 1) . '">Zatiaľ nič.</td></tr>';
        }
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($this->d['columns'] as $key => $label) {
                $val = is_array($label) && isset($label[1]) ? ($label[1])($r) : esc_html((string) ($r[$key] ?? ''));
                echo '<td>' . $val . '</td>';
            }
            echo '<td class="rzv-actions-col">';
            if (!empty($this->d['row_actions'])) {
                echo ($this->d['row_actions'])($r);
            }
            echo '<a class="button button-small" href="' . esc_url($this->url(['edit' => $r['id']])) . '">Upraviť</a> ';
            echo '<a class="button button-small button-link-delete" onclick="return confirm(\'Naozaj zmazať?\')" href="' . esc_url(wp_nonce_url($this->url(['delete' => $r['id']]), 'rzv_del_' . $this->d['slug'] . '_' . $r['id'])) . '">Zmazať</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $pages = (int) ceil($total / $per);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
                'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages,
            ]) . '</div></div>';
        }
    }

    private function form(int $id): void
    {
        $row = $id ? Db::row($this->d['table'], $id) : [];
        if ($id && !$row) {
            echo '<p>Záznam neexistuje.</p>';
            return;
        }
        echo '<h1>' . esc_html(($id ? 'Upraviť: ' : 'Pridať: ') . $this->d['singular']) . '</h1>';
        $this->notices();
        echo '<p><a href="' . esc_url($this->url()) . '">&larr; Späť na zoznam</a></p>';
        echo '<form method="post" class="rzv-form">';
        wp_nonce_field('rzv_save_' . $this->d['slug']);
        echo '<input type="hidden" name="rzv_crud" value="' . esc_attr($this->d['slug']) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($this->d['fields'] as $f) {
            $val = $row[$f['key']] ?? ($f['default'] ?? '');
            if (!empty($f['pivot'])) {
                $val = $id ? Db::pivot($f['pivot'][0], $f['pivot'][1], $id, $f['pivot'][2]) : ($f['default'] ?? []);
            }
            if (($f['type'] ?? '') === 'html') {
                echo '<tr><th></th><td>' . ($f['html'])($row) . '</td></tr>';
                continue;
            }
            echo '<tr><th scope="row"><label for="rzv-f-' . esc_attr($f['key']) . '">' . esc_html($f['label']) . (!empty($f['required']) ? ' *' : '') . '</label></th><td>';
            $this->field($f, $val);
            if (!empty($f['help'])) {
                echo '<p class="description">' . wp_kses_post($f['help']) . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($this->d['form_after'])) {
            echo ($this->d['form_after'])($row);
        }
        echo '<p class="submit"><button class="button button-primary">Uložiť</button> <button class="button" name="stay" value="1">Uložiť a pokračovať</button></p>';
        echo '</form>';
    }

    private function field(array $f, $val): void
    {
        $name = 'f[' . $f['key'] . ']';
        $id = 'rzv-f-' . $f['key'];
        $req = !empty($f['required']) ? ' required' : '';
        $opts = $f['options'] ?? [];
        if (is_callable($opts)) {
            $opts = $opts();
        }
        switch ($f['type'] ?? 'text') {
            case 'textarea':
                printf('<textarea id="%s" name="%s" rows="%d" class="large-text"%s>%s</textarea>', esc_attr($id), esc_attr($name), (int) ($f['rows'] ?? 4), $req, esc_textarea((string) $val));
                break;
            case 'number':
                printf('<input type="number" id="%s" name="%s" value="%s" class="small-text" step="%s" min="%s"%s>%s', esc_attr($id), esc_attr($name), esc_attr((string) $val), esc_attr($f['step'] ?? '1'), esc_attr($f['min'] ?? ''), $req, isset($f['suffix']) ? ' ' . esc_html($f['suffix']) : '');
                break;
            case 'decimal':
                printf('<input type="number" id="%s" name="%s" value="%s" class="small-text" step="0.01" min="0"%s>', esc_attr($id), esc_attr($name), esc_attr((string) $val), $req);
                break;
            case 'money':
                printf('<input type="number" id="%s" name="%s" value="%s" class="small-text" step="0.01" min="0"%s> %s', esc_attr($id), esc_attr($name), esc_attr((string) $val), $req, esc_html(Settings::get('currency')));
                break;
            case 'checkbox':
                printf('<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr($id), esc_attr($name), checked((bool) $val, true, false), esc_html($f['cb_label'] ?? 'Áno'));
                break;
            case 'select':
                printf('<select id="%s" name="%s"%s>', esc_attr($id), esc_attr($name), $req);
                if (!empty($f['nullable'])) {
                    echo '<option value="">— nevybrané —</option>';
                }
                foreach ($opts as $k => $l) {
                    printf('<option value="%s"%s>%s</option>', esc_attr((string) $k), selected((string) $val, (string) $k, false), esc_html($l));
                }
                echo '</select>';
                break;
            case 'multi':
                echo '<div class="rzv-multi">';
                if (!$opts) {
                    echo '<em>Zatiaľ nič na výber.</em>';
                }
                foreach ($opts as $k => $l) {
                    printf('<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label>', esc_attr($name), esc_attr((string) $k), checked(in_array((int) $k, (array) $val, true), true, false), esc_html($l));
                }
                echo '</div>';
                break;
            case 'color':
                printf('<input type="color" id="%s" name="%s" value="%s">', esc_attr($id), esc_attr($name), esc_attr($val ?: '#c2185b'));
                break;
            case 'datetime':
                printf('<input type="datetime-local" id="%s" name="%s" value="%s"%s>', esc_attr($id), esc_attr($name), esc_attr($val ? str_replace(' ', 'T', substr((string) $val, 0, 16)) : ''), $req);
                break;
            case 'date':
                printf('<input type="date" id="%s" name="%s" value="%s"%s>', esc_attr($id), esc_attr($name), esc_attr((string) $val), $req);
                break;
            case 'hours':
                self::hours_editor($name, $val);
                break;
            case 'media':
                printf('<input type="url" id="%s" name="%s" value="%s" class="regular-text rzv-media-input"> <button type="button" class="button rzv-media-btn" data-target="%s">Vybrať</button>', esc_attr($id), esc_attr($name), esc_attr((string) $val), esc_attr($id));
                break;
            default:
                $type = in_array($f['type'] ?? 'text', ['email', 'tel', 'url'], true) ? $f['type'] : 'text';
                printf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s>', $type, esc_attr($id), esc_attr($name), esc_attr((string) $val), $req);
        }
    }

    /* ---------- pracovný čas ---------- */

    const DAYS = [1 => 'Pondelok', 2 => 'Utorok', 3 => 'Streda', 4 => 'Štvrtok', 5 => 'Piatok', 6 => 'Sobota', 7 => 'Nedeľa'];

    /**
     * $val: JSON {custom:bool, days:{1:{open,intervals}}} pri pracovníkovi, alebo pole dní pri prevádzke.
     */
    public static function hours_editor(string $name, $val, bool $with_custom = true): void
    {
        $data = is_array($val) ? $val : (json_decode((string) $val, true) ?: []);
        $custom = $with_custom ? !empty($data['custom']) : true;
        $days = $data['days'] ?? ($with_custom ? Settings::get('hours') : $data);
        echo '<div class="rzv-hours">';
        if ($with_custom) {
            printf('<p><label><input type="checkbox" name="%s[custom]" value="1" class="rzv-hours-custom"%s> Vlastný pracovný čas (inak platí pracovný čas prevádzky)</label></p>', esc_attr($name), checked($custom, true, false));
        }
        echo '<table class="rzv-hours-table' . ($custom ? '' : ' is-disabled') . '">';
        foreach (self::DAYS as $n => $label) {
            $d = $days[$n] ?? $days[(string) $n] ?? ['open' => false, 'intervals' => []];
            $iv = implode(', ', array_map(fn($i) => $i[0] . '-' . $i[1], $d['intervals'] ?? []));
            printf(
                '<tr><th>%s</th><td><label><input type="checkbox" name="%s[days][%d][open]" value="1"%s> otvorené</label></td><td><input type="text" name="%s[days][%d][intervals]" value="%s" placeholder="09:00-12:00, 13:00-17:00" class="regular-text"></td></tr>',
                esc_html($label), esc_attr($name), $n, checked(!empty($d['open']), true, false), esc_attr($name), $n, esc_attr($iv ?: '09:00-17:00')
            );
        }
        echo '</table><p class="description">Viac intervalov oddeľte čiarkou (napr. obedňajšia prestávka). Formát HH:MM-HH:MM.</p></div>';
    }

    /** Vstup z hours_editor → JSON (pracovník) */
    public static function parse_hours(array $v): string
    {
        return wp_json_encode(['custom' => !empty($v['custom']), 'days' => self::parse_days($v['days'] ?? [])]);
    }

    public static function parse_days(array $days): array
    {
        $out = [];
        foreach (self::DAYS as $n => $_) {
            $d = $days[$n] ?? [];
            $intervals = [];
            foreach (preg_split('/[,;]+/', (string) ($d['intervals'] ?? '')) as $part) {
                if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $part, $m)) {
                    $a = sprintf('%02d:%s', $m[1], $m[2]);
                    $b = sprintf('%02d:%s', $m[3], $m[4]);
                    if ($b > $a) {
                        $intervals[] = [$a, $b];
                    }
                }
            }
            $out[$n] = ['open' => !empty($d['open']) && $intervals, 'intervals' => $intervals];
        }
        return $out;
    }
}
