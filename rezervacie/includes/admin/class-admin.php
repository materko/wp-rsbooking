<?php
namespace Rzv\Admin;

use Rzv\Booking;
use Rzv\Customers;
use Rzv\Db;
use Rzv\Fields;
use Rzv\Settings;

defined('ABSPATH') || exit;

class Admin
{
    /** @var Crud[] */
    private static array $cruds = [];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_rzv_status', [self::class, 'post_status']);
        add_action('admin_post_rzv_export', [self::class, 'export_csv']);
        Commerce::init();
    }

    private static function opts(string $table, string $label = 'name', string $where = '1=1'): array
    {
        $out = [];
        foreach (Db::all($table, $where, $label . ' ASC, id ASC') as $r) {
            $out[$r['id']] = $r[$label];
        }
        return $out;
    }

    public static function cruds(): array
    {
        if (self::$cruds) {
            return self::$cruds;
        }
        $yes = fn($k) => fn($r) => $r[$k] ? '✓' : '—';
        $money = fn($k) => fn($r) => esc_html(Settings::money($r[$k]));

        $defs = [];

        $defs['rzv-services'] = [
            'table' => 'services', 'title' => 'Služby', 'singular' => 'služba', 'order' => 'priority DESC, name ASC', 'search' => ['name'],
            'where' => "kind = 'service'",
            'before_save' => function ($data) { $data['kind'] = 'service'; return $data; },
            'columns' => [
                'name' => 'Názov',
                'category_id' => ['Kategória', fn($r) => esc_html(self::opts('categories')[$r['category_id']] ?? '—')],
                'duration' => ['Trvanie', fn($r) => (int) $r['duration'] . ' min'],
                'price' => ['Cena', $money('price')],
                'capacity' => 'Kapacita',
                'workers' => ['Pracovníci', fn($r) => esc_html(implode(', ', array_intersect_key(self::opts('workers'), array_flip(Db::pivot('service_worker', 'service_id', (int) $r['id'], 'worker_id'))))) ?: '<span style="color:#b32d2e">žiadny – nedá sa rezervovať</span>'],
                'auto_confirm' => ['Autom. potvrdenie', $yes('auto_confirm')],
                'active' => ['Aktívna', $yes('active')],
            ],
            'fields' => [
                ['key' => 'name', 'label' => 'Názov', 'required' => true],
                ['key' => 'category_id', 'label' => 'Kategória', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('categories')],
                ['key' => 'description', 'label' => 'Popis', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'duration', 'label' => 'Trvanie', 'type' => 'number', 'default' => 60, 'min' => 5, 'step' => 5, 'suffix' => 'min'],
                ['key' => 'buffer_before', 'label' => 'Prestávka pred', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 5, 'suffix' => 'min', 'help' => 'Príprava – v tomto čase nemôže mať pracovník inú rezerváciu.'],
                ['key' => 'buffer_after', 'label' => 'Prestávka po', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 5, 'suffix' => 'min', 'help' => 'Upratanie / dezinfekcia po službe.'],
                ['key' => 'price', 'label' => 'Cena', 'type' => 'money', 'default' => 0],
                ['key' => 'deposit', 'label' => 'Požadovaná platba vopred', 'type' => 'money', 'default' => 0, 'help' => 'Záloha pri online rezervácii (použije modul platieb).'],
                ['key' => 'capacity', 'label' => 'Kapacita', 'type' => 'number', 'default' => 1, 'min' => 1, 'help' => 'Koľko zákazníkov naraz (skupinové lekcie, kurzy). 1 = individuálna služba.'],
                ['key' => 'workers', 'label' => 'Pracovníci', 'type' => 'multi', 'options' => fn() => self::opts('workers'), 'pivot' => ['service_worker', 'service_id', 'worker_id']],
                ['key' => 'addons', 'label' => 'Doplnkové služby', 'type' => 'multi', 'options' => fn() => self::opts('addons'), 'pivot' => ['service_addon', 'service_id', 'addon_id']],
                ['key' => 'resources', 'label' => 'Miestnosti / prístroje', 'type' => 'multi', 'options' => fn() => self::opts('resources'), 'pivot' => ['service_resource', 'service_id', 'resource_id'], 'help' => 'Služba obsadí jednu jednotku každého zvoleného zdroja.'],
                ['key' => 'fields', 'label' => 'Otázky vo formulári', 'type' => 'multi', 'options' => fn() => self::opts('fields', 'label', 'all_services = 0'), 'pivot' => ['service_field', 'service_id', 'field_id'], 'help' => 'Otázky označené "pre všetky služby" sa zobrazia vždy.'],
                ['key' => 'auto_confirm', 'label' => 'Automatické potvrdenie', 'type' => 'checkbox', 'default' => 1, 'cb_label' => 'Rezervácia sa potvrdí hneď (inak čaká na schválenie)'],
                ['key' => 'color', 'label' => 'Farba v kalendári', 'type' => 'color'],
                ['key' => 'priority', 'label' => 'Priorita (poradie)', 'type' => 'number', 'default' => 0, 'help' => 'Vyššie číslo = vyššie v zozname.'],
                ['key' => 'visible', 'label' => 'Zobraziť v online formulári', 'type' => 'checkbox', 'default' => 1],
                ['key' => 'active', 'label' => 'Aktívna', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-categories'] = [
            'table' => 'categories', 'title' => 'Kategórie', 'singular' => 'kategória', 'order' => 'priority DESC, name ASC',
            'columns' => [
                'name' => 'Názov',
                'priority' => 'Priorita',
                'visible' => ['Zobraziť vo formulári', $yes('visible')],
                'services' => ['Služby', fn($r) => esc_html(implode(', ', array_column(Db::all('services', 'category_id = ' . (int) $r['id']), 'name')))],
            ],
            'fields' => [
                ['key' => 'name', 'label' => 'Názov', 'required' => true],
                ['key' => 'description', 'label' => 'Popis', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0],
                ['key' => 'visible', 'label' => 'Zobraziť v online formulári', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-workers'] = [
            'table' => 'workers', 'title' => 'Pracovníci', 'singular' => 'pracovník', 'order' => 'priority DESC, name ASC', 'search' => ['name', 'email', 'phone'],
            'columns' => [
                'name' => 'Meno',
                'email' => 'E-mail',
                'phone' => 'Telefón',
                'services' => ['Služby', fn($r) => esc_html(implode(', ', array_intersect_key(self::opts('services'), array_flip(Db::pivot('service_worker', 'worker_id', (int) $r['id'], 'service_id')))))],
                'hours' => ['Pracovný čas', fn($r) => !empty(json_decode((string) $r['hours'], true)['custom']) ? 'vlastný' : 'prevádzky'],
                'active' => ['Aktívny', $yes('active')],
            ],
            'fields' => [
                ['key' => 'name', 'label' => 'Meno a priezvisko', 'required' => true],
                ['key' => 'email', 'label' => 'E-mail', 'type' => 'email'],
                ['key' => 'phone', 'label' => 'Telefón', 'type' => 'tel'],
                ['key' => 'description', 'label' => 'Popis (zobrazí sa zákazníkom)', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'photo', 'label' => 'Fotka', 'type' => 'media'],
                ['key' => 'user_id', 'label' => 'WP používateľ', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::users(), 'help' => 'Prepojenie s účtom – pracovník uvidí svoj kalendár.'],
                ['key' => 'services', 'label' => 'Služby', 'type' => 'multi', 'options' => fn() => self::opts('services'), 'pivot' => ['service_worker', 'worker_id', 'service_id']],
                ['key' => 'hours', 'label' => 'Pracovný čas', 'type' => 'hours'],
                ['key' => 'color', 'label' => 'Farba v kalendári', 'type' => 'color'],
                ['key' => 'notify', 'label' => 'E-mail pri novej rezervácii', 'type' => 'checkbox', 'default' => 1],
                ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0, 'help' => 'Pri voľbe "ktokoľvek" dostane rezerváciu pracovník s vyššou prioritou.'],
                ['key' => 'visible', 'label' => 'Zobraziť v online formulári', 'type' => 'checkbox', 'default' => 1],
                ['key' => 'active', 'label' => 'Aktívny', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-addons'] = [
            'table' => 'addons', 'title' => 'Doplnkové služby', 'singular' => 'doplnková služba', 'order' => 'priority DESC, name ASC',
            'intro' => 'Doplnky si zákazník pridá k hlavnej službe (predĺžia trvanie a zvýšia cenu). Priraďte ich v nastavení služby.',
            'columns' => ['name' => 'Názov', 'duration' => ['Trvanie', fn($r) => '+' . (int) $r['duration'] . ' min'], 'price' => ['Cena', $money('price')], 'active' => ['Aktívna', $yes('active')]],
            'fields' => [
                ['key' => 'name', 'label' => 'Názov', 'required' => true],
                ['key' => 'description', 'label' => 'Popis', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'duration', 'label' => 'Predĺženie', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 5, 'suffix' => 'min'],
                ['key' => 'price', 'label' => 'Cena', 'type' => 'money', 'default' => 0],
                ['key' => 'services', 'label' => 'Pri službách', 'type' => 'multi', 'options' => fn() => self::opts('services'), 'pivot' => ['service_addon', 'addon_id', 'service_id']],
                ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0],
                ['key' => 'active', 'label' => 'Aktívna', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-resources'] = [
            'table' => 'resources', 'title' => 'Miestnosti, prístroje a predmety prenájmu', 'singular' => 'miestnosť / prístroj / predmet', 'order' => 'name ASC',
            'intro' => 'Blokácia služieb: ak dve služby používajú ten istý prístroj (napr. 1 laser), nedajú sa rezervovať naraz, ani keď ich robia rôzni pracovníci.',
            'columns' => [
                'name' => 'Názov', 'quantity' => 'Počet kusov',
                'services' => ['Služby', fn($r) => esc_html(implode(', ', array_intersect_key(self::opts('services'), array_flip(Db::pivot('service_resource', 'resource_id', (int) $r['id'], 'service_id')))))],
                'active' => ['Aktívna', $yes('active')],
            ],
            'fields' => [
                ['key' => 'name', 'label' => 'Názov', 'required' => true],
                ['key' => 'quantity', 'label' => 'Počet kusov', 'type' => 'number', 'default' => 1, 'min' => 1],
                ['key' => 'services', 'label' => 'Služby', 'type' => 'multi', 'options' => fn() => self::opts('services'), 'pivot' => ['service_resource', 'resource_id', 'service_id']],
                ['key' => 'active', 'label' => 'Aktívna', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-fields'] = [
            'table' => 'fields', 'title' => 'Otázky vo formulári', 'singular' => 'otázka', 'order' => 'priority DESC, id ASC',
            'intro' => 'Otvorené (text) a výberové otázky, ktoré zákazník vyplní pri rezervácii.',
            'columns' => [
                'label' => 'Otázka', 'type' => ['Typ', fn($r) => esc_html(Fields::TYPES[$r['type']] ?? $r['type'])],
                'required' => ['Povinná', $yes('required')], 'all_services' => ['Všetky služby', $yes('all_services')], 'active' => ['Aktívna', $yes('active')],
            ],
            'fields' => [
                ['key' => 'label', 'label' => 'Otázka', 'required' => true],
                ['key' => 'type', 'label' => 'Typ', 'type' => 'select', 'options' => Fields::TYPES],
                ['key' => 'options', 'label' => 'Možnosti', 'type' => 'textarea', 'rows' => 4, 'help' => 'Pri výberových otázkach – jedna možnosť na riadok.'],
                ['key' => 'required', 'label' => 'Povinná', 'type' => 'checkbox'],
                ['key' => 'all_services', 'label' => 'Pre všetky služby', 'type' => 'checkbox', 'default' => 1, 'cb_label' => 'Áno (inak ju priraďte pri konkrétnych službách)'],
                ['key' => 'services', 'label' => 'Len pri službách', 'type' => 'multi', 'options' => fn() => self::opts('services'), 'pivot' => ['service_field', 'field_id', 'service_id']],
                ['key' => 'priority', 'label' => 'Priorita', 'type' => 'number', 'default' => 0],
                ['key' => 'active', 'label' => 'Aktívna', 'type' => 'checkbox', 'default' => 1],
            ],
        ];

        $defs['rzv-blocked'] = [
            'table' => 'blocked', 'title' => 'Blokované časy', 'singular' => 'blokovaný čas', 'order' => 'start_at DESC',
            'where' => "(end_at >= NOW() - INTERVAL 30 DAY OR repeat_weekly = 1)",
            'intro' => 'Dovolenky, sviatky, obedy, školenia. Bez pracovníka = blokuje celú prevádzku. Týždenné opakovanie zopakuje časový úsek každý týždeň v rovnaký deň.',
            'columns' => [
                'start_at' => ['Od', fn($r) => esc_html(\Rzv\Mailer::date($r['start_at']))],
                'end_at' => ['Do', fn($r) => esc_html(\Rzv\Mailer::date($r['end_at']))],
                'worker_id' => ['Pracovník', fn($r) => esc_html($r['worker_id'] ? (self::opts('workers')[$r['worker_id']] ?? '?') : 'Celá prevádzka')],
                'resource_id' => ['Miestnosť/prístroj', fn($r) => esc_html($r['resource_id'] ? (self::opts('resources')[$r['resource_id']] ?? '?') : '—')],
                'repeat_weekly' => ['Opakovanie', fn($r) => $r['repeat_weekly'] ? 'každý týždeň' . ($r['repeat_until'] ? ' do ' . esc_html($r['repeat_until']) : '') : '—'],
                'note' => 'Poznámka',
            ],
            'fields' => [
                ['key' => 'worker_id', 'label' => 'Pracovník', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('workers'), 'help' => 'Prázdne = celá prevádzka.'],
                ['key' => 'resource_id', 'label' => 'Miestnosť / prístroj', 'type' => 'select', 'nullable' => true, 'options' => fn() => self::opts('resources'), 'help' => 'Napr. servis prístroja – zablokuje len služby, ktoré ho používajú.'],
                ['key' => 'start_at', 'label' => 'Od', 'type' => 'datetime', 'required' => true],
                ['key' => 'end_at', 'label' => 'Do', 'type' => 'datetime', 'required' => true],
                ['key' => 'repeat_weekly', 'label' => 'Opakovať každý týždeň', 'type' => 'checkbox'],
                ['key' => 'repeat_until', 'label' => 'Opakovať do', 'type' => 'date'],
                ['key' => 'note', 'label' => 'Poznámka'],
            ],
            'before_save' => function ($data) {
                if (!$data['start_at'] || !$data['end_at'] || $data['end_at'] <= $data['start_at']) {
                    return new \WP_Error('rzv', 'Koniec musí byť po začiatku.');
                }
                if ($data['resource_id'] === '' || $data['resource_id'] === null) { $data['resource_id'] = null; }
                if ($data['worker_id'] === '' || $data['worker_id'] === null) { $data['worker_id'] = null; }
                return $data;
            },
        ];

        $defs['rzv-customers'] = [
            'table' => 'customers', 'title' => 'Zákazníci', 'singular' => 'zákazník', 'order' => 'last_name ASC, first_name ASC',
            'search' => ['first_name', 'last_name', 'email', 'phone', 'tags'],
            'header_html' => fn() => ' <a class="page-title-action" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=rzv_export&what=customers'), 'rzv_export')) . '">Export CSV</a>',
            'columns' => [
                'name' => ['Meno', fn($r) => esc_html(trim($r['first_name'] . ' ' . $r['last_name'])) . ($r['blocked'] ? ' <span class="rzv-badge rzv-badge--cancelled">blokovaný</span>' : '')],
                'email' => 'E-mail', 'phone' => 'Telefón', 'tags' => 'Štítky',
                'visits' => ['Rezervácie', function ($r) { $s = Customers::stats((int) $r['id']); return (int) $s['total'] . ($s['no_show'] ? ' <span class="rzv-badge rzv-badge--no_show">' . (int) $s['no_show'] . '× neprišiel</span>' : ''); }],
                'marketing' => ['Marketing', $yes('marketing')],
            ],
            'row_actions' => fn($r) => '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=rzv-bookings&customer_id=' . $r['id'] . '&from=2000-01-01')) . '">História</a> ',
            'fields' => [
                ['key' => 'first_name', 'label' => 'Meno', 'required' => true],
                ['key' => 'last_name', 'label' => 'Priezvisko'],
                ['key' => 'email', 'label' => 'E-mail', 'type' => 'email'],
                ['key' => 'phone', 'label' => 'Telefón', 'type' => 'tel'],
                ['key' => 'tags', 'label' => 'Štítky', 'help' => 'Oddelené čiarkou (napr. VIP, alergia).'],
                ['key' => 'note', 'label' => 'Interná poznámka', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'marketing', 'label' => 'Súhlas s marketingom', 'type' => 'checkbox'],
                ['key' => 'blocked', 'label' => 'Blokovať online rezervácie', 'type' => 'checkbox'],
                ['key' => 'stats', 'type' => 'html', 'html' => function ($row) {
                    if (empty($row['id'])) { return ''; }
                    $s = Customers::stats((int) $row['id']);
                    return sprintf('<p><strong>Rezervácie:</strong> %d · zrealizované %d · neprišiel %d · zrušené %d · tržba %s · posledná %s</p>',
                        $s['total'], $s['completed'], $s['no_show'], $s['cancelled'], esc_html(Settings::money($s['revenue'] ?? 0)), esc_html($s['last_visit'] ? \Rzv\Mailer::date($s['last_visit']) : '—'))
                        . (\Rzv\Loyalty::enabled() ? '<p><strong>Vernostný program:</strong> ' . esc_html(\Rzv\Loyalty::describe(\Rzv\Loyalty::for_customer((int) $row['id']))) . '</p>' : '');
                }],
            ],
            'before_save' => function ($data, $id) {
                $data['phone'] = Customers::phone($data['phone']);
                if (!$id) { $data['created_at'] = Db::now(); }
                return $data;
            },
        ];

        $defs += Commerce::cruds($yes, $money);
        $defs += Extras::cruds($yes, $money);
        $defs = apply_filters('rzv_admin_cruds', $defs);
        foreach ($defs as $slug => $def) {
            self::$cruds[$slug] = new Crud($def + ['slug' => $slug]);
        }
        return self::$cruds;
    }

    private static function users(): array
    {
        $out = [];
        foreach (get_users(['fields' => ['ID', 'display_name', 'user_email'], 'number' => 300]) as $u) {
            $out[$u->ID] = $u->display_name . ' (' . $u->user_email . ')';
        }
        return $out;
    }

    public static function menu(): void
    {
        $cap = 'rzv_manage';
        add_menu_page('Rezervácie', 'Rezervácie', $cap, 'rzv-calendar', [Calendar::class, 'render'], 'dashicons-calendar-alt', 26);
        add_submenu_page('rzv-calendar', 'Kalendár', 'Kalendár', $cap, 'rzv-calendar', [Calendar::class, 'render']);
        add_submenu_page('rzv-calendar', 'Rezervácie', 'Zoznam rezervácií', $cap, 'rzv-bookings', [Bookings::class, 'render']);

        $labels = [
            'rzv-customers' => 'Zákazníci', 'rzv-services' => 'Služby', 'rzv-categories' => 'Kategórie', 'rzv-addons' => 'Doplnkové služby',
            'rzv-workers' => 'Pracovníci', 'rzv-blocked' => 'Blokované časy', 'rzv-resources' => 'Miestnosti, prístroje, predmety', 'rzv-fields' => 'Otázky vo formulári',
            'rzv-rentals' => 'Prenájmy', 'rzv-coupons' => 'Zľavové kódy', 'rzv-voucher-types' => 'Poukážky – ponuka', 'rzv-vouchers' => 'Predané poukážky',
        ];
        foreach (self::cruds() as $slug => $crud) {
            $hook = add_submenu_page('rzv-calendar', $crud->d['title'], $labels[$slug] ?? $crud->d['title'], $crud->d['cap'], $slug, [$crud, 'render']);
            add_action('load-' . $hook, [$crud, 'handle']);
        }
        add_submenu_page('rzv-calendar', 'Čakacia listina', 'Čakacia listina', $cap, 'rzv-waitlist', [Bookings::class, 'render_waitlist']);
        add_submenu_page('rzv-calendar', 'Platby', 'Platby', $cap, 'rzv-payments', [Commerce::class, 'render_payments']);
        $hook = add_submenu_page('rzv-calendar', 'Hodnotenia', 'Hodnotenia', $cap, 'rzv-ratings', [Extras::class, 'render_ratings']);
        add_action('load-' . $hook, [Extras::class, 'handle_ratings']);
        add_submenu_page('rzv-calendar', 'Štatistiky', 'Štatistiky', $cap, 'rzv-stats', [Stats::class, 'render']);
        do_action('rzv_admin_menu', 'rzv-calendar');
        $hook = add_submenu_page('rzv-calendar', 'Nastavenia', 'Nastavenia', 'manage_options', 'rzv-settings', [SettingsPage::class, 'render']);
        add_action('load-' . $hook, [SettingsPage::class, 'save']);
    }

    public static function assets($hook): void
    {
        if (strpos((string) ($_GET['page'] ?? ''), 'rzv-') !== 0) {
            return;
        }
        wp_enqueue_style('rzv-admin', RZV_URL . 'assets/admin.css', [], RZV_VERSION);
        wp_enqueue_media();
        wp_enqueue_script('rzv-admin', RZV_URL . 'assets/admin.js', [], RZV_VERSION, true);
        $workers = array_map(fn($w) => ['id' => (int) $w['id'], 'name' => $w['name'], 'color' => $w['color'] ?: '', 'active' => (int) $w['active']], Db::all('workers'));
        $services = array_map(fn($s) => [
            'id' => (int) $s['id'], 'name' => $s['name'], 'duration' => (int) $s['duration'], 'price' => (float) $s['price'], 'active' => (int) $s['active'], 'kind' => $s['kind'],
            'workers' => Db::pivot('service_worker', 'service_id', (int) $s['id'], 'worker_id'),
            'addons' => Db::pivot('service_addon', 'service_id', (int) $s['id'], 'addon_id'),
        ], Db::all('services'));
        $addons = array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'price' => (float) $a['price'], 'duration' => (int) $a['duration']], Db::all('addons'));
        wp_localize_script('rzv-admin', 'RZV', [
            'api'      => esc_url_raw(rest_url('rzv/v1')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'workers'  => $workers,
            'services' => $services,
            'addons'   => $addons,
            'statuses' => Booking::STATUSES,
            'hours'    => Settings::get('hours'),
            'interval' => (int) Settings::get('interval'),
            'currency' => Settings::get('currency'),
            'methods'  => array_diff_key(\Rzv\Payments::METHODS, ['pending' => 1, 'stripe' => 1, 'voucher' => 1]),
        ]);
    }

    public static function post_status(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        check_admin_referer('rzv_status_' . $id);
        if (!current_user_can('rzv_manage')) {
            wp_die('Nedostatočné oprávnenia.');
        }
        Booking::set_status($id, sanitize_key($_GET['status'] ?? ''), !empty($_GET['notify']));
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=rzv-bookings'));
        exit;
    }

    public static function export_csv(): void
    {
        check_admin_referer('rzv_export');
        if (!current_user_can('rzv_manage')) {
            wp_die('Nedostatočné oprávnenia.');
        }
        $what = $_GET['what'] ?? 'bookings';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=rezervacie-' . sanitize_key($what) . '-' . wp_date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if ($what === 'customers') {
            fputcsv($out, ['ID', 'Meno', 'Priezvisko', 'E-mail', 'Telefón', 'Štítky', 'Marketing', 'Vytvorený'], ';');
            foreach (Db::all('customers', '1=1', 'id ASC') as $c) {
                fputcsv($out, [$c['id'], $c['first_name'], $c['last_name'], $c['email'], $c['phone'], $c['tags'], $c['marketing'] ? 'áno' : 'nie', $c['created_at']], ';');
            }
        } else {
            $f = Bookings::filters();
            fputcsv($out, ['ID', 'Začiatok', 'Koniec', 'Služba', 'Doplnky', 'Pracovník', 'Zákazník', 'E-mail', 'Telefón', 'Stav', 'Cena', 'Zľava', 'Zaplatené', 'Poznámka', 'Odpovede', 'Zdroj', 'Vytvorená'], ';');
            foreach (Booking::query($f) as $b) {
                fputcsv($out, [
                    $b['id'], $b['start_at'], $b['end_at'], $b['service_name'], implode(', ', array_column($b['addons_list'], 'name')), $b['worker_name'],
                    $b['customer_name'], $b['email'], $b['phone'], $b['status_label'],
                    number_format((float) $b['price'], 2, ',', ''), number_format((float) $b['discount'], 2, ',', ''), number_format((float) $b['paid'], 2, ',', ''), $b['note'],
                    implode(' | ', array_map(fn($a) => $a['label'] . ': ' . $a['value'], $b['answers_list'])), $b['source'], $b['created_at'],
                ], ';');
            }
        }
        fclose($out);
        exit;
    }
}
