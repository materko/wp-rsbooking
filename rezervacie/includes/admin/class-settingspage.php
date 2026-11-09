<?php
namespace Rzv\Admin;

use Rzv\Mailer;
use Rzv\Settings;

defined('ABSPATH') || exit;

class SettingsPage
{
    public static function tabs(): array
    {
        $pages = ['0' => '— žiadna —'];
        foreach (get_pages(['post_status' => 'publish,private']) as $p) {
            $pages[$p->ID] = $p->post_title;
        }
        $tpl = function (string $key, string $label) {
            return [
                ['key' => "tpl_{$key}_subject", 'label' => $label . ' – predmet'],
                ['key' => "tpl_{$key}_body", 'label' => $label . ' – text', 'type' => 'textarea', 'rows' => 7],
            ];
        };
        $tabs = [
            'general' => ['Prevádzka', [
                ['key' => 'business_name', 'label' => 'Názov prevádzky'],
                ['key' => 'business_address', 'label' => 'Adresa'],
                ['key' => 'business_phone', 'label' => 'Telefón'],
                ['key' => 'business_email', 'label' => 'E-mail prevádzky', 'type' => 'email'],
                ['key' => 'booking_page', 'label' => 'Stránka s rezerváciou', 'type' => 'select', 'options' => $pages, 'help' => 'Stránka so shortcode <code>[rezervacie]</code>. Sem vedú odkazy z e-mailov.'],
                ['key' => 'currency', 'label' => 'Mena', 'class' => 'small-text'],
            ]],
            'booking' => ['Rezervácie', [
                ['key' => 'hours_before', 'label' => 'Vytvorenie rezervácie najneskôr', 'type' => 'number', 'step' => '0.5', 'suffix' => 'hodín pred termínom'],
                ['key' => 'days_ahead', 'label' => 'Rezervácie dopredu', 'type' => 'number', 'suffix' => 'dní'],
                ['key' => 'interval', 'label' => 'Časový interval', 'type' => 'select', 'options' => [5 => '5 min', 10 => '10 min', 15 => '15 min', 20 => '20 min', 30 => '30 min', 45 => '45 min', 60 => '60 min'], 'help' => 'Krok, po ktorom sa zákazníkom ponúkajú časy.'],
                ['key' => 'allow_worker_choice', 'label' => 'Výber pracovníka', 'type' => 'checkbox', 'cb_label' => 'Zákazník si môže vybrať pracovníka'],
                ['key' => 'require_phone', 'label' => 'Telefón povinný', 'type' => 'checkbox'],
                ['key' => 'waitlist', 'label' => 'Čakacia listina (náhradníci)', 'type' => 'checkbox', 'cb_label' => 'Pri plnom dni sa zákazník môže prihlásiť ako náhradník'],
                ['key' => 'auto_complete', 'label' => 'Automaticky zrealizované', 'type' => 'checkbox', 'cb_label' => 'Potvrdené rezervácie hodinu po skončení označiť ako zrealizované (neprišiel označíte ručne)'],
                ['key' => 'waitlist_auto', 'label' => 'Upozorniť náhradníkov', 'type' => 'checkbox', 'cb_label' => 'Pri zrušení rezervácie automaticky poslať e-mail náhradníkom'],
                ['key' => 'terms_url', 'label' => 'Obchodné podmienky (URL)', 'type' => 'url'],
                ['key' => 'privacy_url', 'label' => 'Ochrana osobných údajov (URL)', 'type' => 'url'],
            ]],
            'hours' => ['Pracovný čas', [
                ['key' => 'hours', 'label' => 'Pracovný čas prevádzky', 'type' => 'hours', 'help' => 'Určuje dostupné časy pre online rezervácie. Pracovník môže mať vlastný čas. Prestávky a dovolenky zadajte v Blokovaných časoch.'],
            ]],
            'emails' => ['E-maily', array_merge([
                ['key' => 'email_from_name', 'label' => 'Odosielateľ – meno'],
                ['key' => 'email_from', 'label' => 'Odosielateľ – e-mail', 'type' => 'email'],
                ['key' => 'notify_admin', 'label' => 'Upozornenia prevádzke', 'type' => 'checkbox', 'cb_label' => 'Posielať e-mail o nových / zrušených rezerváciách'],
                ['key' => 'notify_emails', 'label' => 'Kópia na e-maily', 'help' => 'Oddelené čiarkou. Prázdne = e-mail prevádzky.'],
                ['key' => 'reminder', 'label' => 'Pripomienka zákazníkovi', 'type' => 'checkbox'],
                ['key' => 'reminder_hours', 'label' => 'Pripomienka vopred', 'type' => 'number', 'suffix' => 'hodín'],
                ['key' => 'email_footer', 'label' => 'Pätička e-mailov', 'type' => 'textarea', 'rows' => 2],
            ], $tpl('pending', 'Prijatá (čaká)'), $tpl('confirmed', 'Potvrdená'), $tpl('changed', 'Zmenená'), $tpl('cancelled', 'Zrušená'), $tpl('reminder', 'Pripomienka'), $tpl('waitlist', 'Náhradník – uvoľnený termín'))],
            'cancel' => ['Storno', [
                ['key' => 'cancel_allowed', 'label' => 'Online zrušenie', 'type' => 'checkbox', 'cb_label' => 'Zákazník môže zrušiť rezerváciu cez odkaz v e-maile'],
                ['key' => 'cancel_hours_before', 'label' => 'Zrušenie najneskôr', 'type' => 'number', 'suffix' => 'hodín pred termínom'],
                ['key' => 'cancel_policy', 'label' => 'Storno podmienky (text vo formulári)', 'type' => 'textarea', 'rows' => 3],
            ]],
            'payments' => ['Platby', [
                ['key' => 'qr_enabled', 'label' => 'QR platby', 'type' => 'checkbox', 'cb_label' => 'Zobrazovať QR kód (PAY by square) a údaje na prevod v potvrdení a e-mailoch'],
                ['key' => 'iban', 'label' => 'IBAN', 'help' => 'Peniaze idú priamo na váš účet. Pripísanie platby si overujete v banke a potvrdíte v zozname Platby.'],
                ['key' => 'bic', 'label' => 'BIC/SWIFT (nepovinné)', 'class' => 'small-text'],
                ['key' => 'beneficiary', 'label' => 'Príjemca (meno na účte)'],
                ['key' => 'qr_due_days', 'label' => 'Splatnosť', 'type' => 'number', 'suffix' => 'dní od vytvorenia (0 = deň termínu)'],
                ['key' => 'online_enabled', 'label' => 'Online platby kartou', 'type' => 'checkbox', 'cb_label' => 'Stripe Checkout (karta, Apple Pay, Google Pay)'],
                ['key' => 'stripe_secret', 'label' => 'Stripe Secret key', 'class' => 'regular-text', 'help' => 'sk_test_… na skúšanie, sk_live_… naostro. Stripe → Developers → API keys.'],
                ['key' => 'stripe_webhook_secret', 'label' => 'Stripe Webhook secret', 'class' => 'regular-text', 'help' => 'whsec_… Webhook URL: <code>' . esc_html(rest_url('rzv/v1/stripe/webhook')) . '</code>, udalosti <code>checkout.session.completed</code> a <code>checkout.session.async_payment_succeeded</code>. Bez webhooku sa platba overí pri návrate zákazníka zo Stripe.'],
                ['key' => 'payment_mode', 'label' => 'Kedy platiť online', 'type' => 'select', 'options' => [
                    'deposit'  => 'Len služby s nastavenou „platbou vopred“ (záloha)',
                    'full'     => 'Vždy celú sumu pri rezervácii',
                    'optional' => 'Dobrovoľne – zálohy povinné, ostatné môže zaplatiť online',
                ]],
                ['key' => 'payment_timeout', 'label' => 'Čas na zaplatenie', 'type' => 'number', 'suffix' => 'minút (potom sa termín uvoľní; Stripe min. 30)'],
                ['key' => 'tpl_payment_text', 'label' => 'Text platby v e-maile', 'type' => 'textarea', 'rows' => 5, 'help' => 'Premenné: {iban} {suma} {vs} {qr_odkaz} {prijemca}. Do šablón e-mailov sa vkladá ako {platba}.'],
            ]],
            'vouchers' => ['Poukážky', [
                ['key' => 'voucher_page', 'label' => 'Predajná stránka', 'type' => 'select', 'options' => $pages, 'help' => 'Stránka so shortcode <code>[rezervacie_poukazky]</code>.'],
                ['key' => 'voucher_prefix', 'label' => 'Predpona kódu', 'class' => 'small-text', 'help' => 'Napr. DAR → DAR-7KQ2-M9XA'],
                ['key' => 'voucher_intro', 'label' => 'Úvodný text na predajnej stránke', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'voucher_terms', 'label' => 'Podmienky na poukážke', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'voucher_pay_days', 'label' => 'Nezaplatené objednávky zrušiť po', 'type' => 'number', 'suffix' => 'dňoch'],
                ['key' => 'tpl_voucher_order_subject', 'label' => 'Objednávka – predmet'],
                ['key' => 'tpl_voucher_order_body', 'label' => 'Objednávka – text', 'type' => 'textarea', 'rows' => 6, 'help' => 'Premenné: {meno} {poukazka} {cena} {kod} {platba} {prevadzka}'],
                ['key' => 'tpl_voucher_subject', 'label' => 'Poukážka – predmet'],
                ['key' => 'tpl_voucher_body', 'label' => 'Poukážka – text', 'type' => 'textarea', 'rows' => 6, 'help' => 'Premenné: {meno} {poukazka} {kod} {platnost} {odkaz} {prevadzka}'],
            ]],
            'ratings' => ['Hodnotenia', [
                ['key' => 'ratings_enabled', 'label' => 'Zber hodnotení', 'type' => 'checkbox', 'cb_label' => 'Po návšteve poslať zákazníkovi e-mail so žiadosťou o hodnotenie'],
                ['key' => 'ratings_mode', 'label' => 'Kde hodnotiť', 'type' => 'select', 'options' => [
                    'internal' => 'U nás (hviezdičky + komentár)',
                    'both'     => 'U nás a pri dobrom hodnotení pozvať aj na verejný portál',
                    'external' => 'Priamo na verejnom portáli (napr. Google)',
                ]],
                ['key' => 'ratings_external_url', 'label' => 'Odkaz na verejné recenzie', 'type' => 'url', 'help' => 'Napr. odkaz „Napísať recenziu“ z Google profilu firmy.'],
                ['key' => 'ratings_external_name', 'label' => 'Názov portálu', 'class' => 'small-text'],
                ['key' => 'ratings_threshold', 'label' => 'Pozitívne od', 'type' => 'number', 'suffix' => 'hviezdičiek'],
                ['key' => 'ratings_autopublish', 'label' => 'Zverejňovanie', 'type' => 'checkbox', 'cb_label' => 'Pozitívne hodnotenia zverejniť automaticky (ostatné čakajú na schválenie)'],
                ['key' => 'ratings_delay_hours', 'label' => 'Poslať po', 'type' => 'number', 'step' => '0.5', 'suffix' => 'hodinách od konca návštevy'],
                ['key' => 'tpl_rating_subject', 'label' => 'E-mail – predmet'],
                ['key' => 'tpl_rating_body', 'label' => 'E-mail – text', 'type' => 'textarea', 'rows' => 6, 'help' => 'Premenné ako pri rezerváciách + {hodnotenie} (odkaz na hodnotenie).'],
            ]],
            'loyalty' => ['Vernostný program', [
                ['key' => 'loyalty_enabled', 'label' => 'Vernostný program', 'type' => 'checkbox', 'cb_label' => 'Zapnúť – zákazník dostane zľavu podľa úrovne automaticky (rozpozná sa podľa e-mailu)'],
                ['key' => 'loyalty_basis', 'label' => 'Úroveň podľa', 'type' => 'select', 'options' => ['visits' => 'Počtu návštev', 'spend' => 'Útraty (€)']],
                ['key' => 'loyalty_levels', 'label' => 'Úrovne', 'type' => 'levels', 'help' => 'Návšteva = zrealizovaná rezervácia. Prázdny názov = úroveň sa nepoužije.'],
                ['key' => 'loyalty_combine', 'label' => 'So zľavovým kódom', 'type' => 'checkbox', 'cb_label' => 'Vernostnú zľavu sčítať so zľavovým kódom (inak sa pri kóde nepoužije)'],
            ]],
            'look' => ['Vzhľad formulára', [
                ['key' => 'color_primary', 'label' => 'Hlavná farba', 'type' => 'color'],
                ['key' => 'show_prices', 'label' => 'Zobrazovať ceny', 'type' => 'checkbox'],
                ['key' => 'show_duration', 'label' => 'Zobrazovať trvanie', 'type' => 'checkbox'],
            ]],
        ];
        return apply_filters('rzv_settings_tabs', $tabs);
    }

    public static function save(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['rzv_settings_tab'])) {
            return;
        }
        check_admin_referer('rzv_settings');
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatočné oprávnenia.');
        }
        $tab = sanitize_key($_POST['rzv_settings_tab']);
        $tabs = self::tabs();
        if (!isset($tabs[$tab])) {
            return;
        }
        $in = wp_unslash($_POST['s'] ?? []);
        $all = Settings::all();
        foreach ($tabs[$tab][1] as $f) {
            $k = $f['key'];
            $v = $in[$k] ?? null;
            switch ($f['type'] ?? 'text') {
                case 'checkbox': $all[$k] = $v ? 1 : 0; break;
                case 'number': $all[$k] = (float) $v == (int) $v ? (int) $v : (float) $v; break;
                case 'textarea': $all[$k] = sanitize_textarea_field((string) $v); break;
                case 'email': $all[$k] = sanitize_email((string) $v); break;
                case 'url': $all[$k] = esc_url_raw((string) $v); break;
                case 'color': $all[$k] = sanitize_hex_color((string) $v) ?: '#c2185b'; break;
                case 'hours': $all[$k] = Crud::parse_days((array) ($v['days'] ?? [])); break;
                case 'levels':
                    $all[$k] = [];
                    foreach ((array) $v as $l) {
                        if (trim((string) ($l['name'] ?? '')) !== '' && (float) ($l['min'] ?? 0) > 0) {
                            $all[$k][] = ['name' => sanitize_text_field($l['name']), 'min' => (float) $l['min'], 'discount' => min(100, max(0, (float) ($l['discount'] ?? 0)))];
                        }
                    }
                    break;
                default: $all[$k] = sanitize_text_field((string) $v);
            }
        }
        if ($tab === 'payments') {
            $all['iban'] = strtoupper(preg_replace('/\s+/', '', (string) $all['iban']));
            if ($all['iban'] !== '' && !\Rzv\PayBySquare::iban_valid($all['iban'])) {
                set_transient('rzv_settings_err', 'IBAN nie je platný – skontrolujte ho.', 60);
                $all['qr_enabled'] = 0;
            }
            if ($all['online_enabled'] && !preg_match('/^(sk|rk)_(test|live)_/', (string) $all['stripe_secret'])) {
                set_transient('rzv_settings_err', 'Stripe Secret key musí začínať sk_test_ alebo sk_live_.', 60);
                $all['online_enabled'] = 0;
            }
        }
        Settings::save($all);
        do_action('rzv_settings_saved', $tab, $in);
        wp_safe_redirect(admin_url('admin.php?page=rzv-settings&tab=' . $tab . '&saved=1'));
        exit;
    }

    public static function render(): void
    {
        $tabs = self::tabs();
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }
        $s = Settings::all();
        echo '<div class="wrap rzv-admin"><h1>Nastavenia rezervácií</h1>';
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Nastavenia uložené.</p></div>';
        }
        if ($err = get_transient('rzv_settings_err')) {
            delete_transient('rzv_settings_err');
            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $k => [$label]) {
            printf('<a href="%s" class="nav-tab%s">%s</a>', esc_url(admin_url('admin.php?page=rzv-settings&tab=' . $k)), $k === $tab ? ' nav-tab-active' : '', esc_html($label));
        }
        echo '</nav><form method="post">';
        wp_nonce_field('rzv_settings');
        echo '<input type="hidden" name="rzv_settings_tab" value="' . esc_attr($tab) . '"><table class="form-table" role="presentation"><tbody>';
        foreach ($tabs[$tab][1] as $f) {
            $k = $f['key'];
            $name = 's[' . $k . ']';
            $v = $s[$k] ?? '';
            echo '<tr><th scope="row">' . esc_html($f['label']) . '</th><td>';
            switch ($f['type'] ?? 'text') {
                case 'checkbox':
                    printf('<label><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr($name), checked((bool) $v, true, false), esc_html($f['cb_label'] ?? 'Áno'));
                    break;
                case 'number':
                    printf('<input type="number" name="%s" value="%s" class="small-text" step="%s" min="0"> %s', esc_attr($name), esc_attr((string) $v), esc_attr($f['step'] ?? '1'), esc_html($f['suffix'] ?? ''));
                    break;
                case 'textarea':
                    printf('<textarea name="%s" rows="%d" class="large-text">%s</textarea>', esc_attr($name), (int) ($f['rows'] ?? 4), esc_textarea((string) $v));
                    break;
                case 'select':
                    printf('<select name="%s">', esc_attr($name));
                    foreach ($f['options'] as $ok => $ol) {
                        printf('<option value="%s"%s>%s</option>', esc_attr((string) $ok), selected((string) $v, (string) $ok, false), esc_html($ol));
                    }
                    echo '</select>';
                    break;
                case 'color':
                    printf('<input type="color" name="%s" value="%s">', esc_attr($name), esc_attr((string) $v));
                    break;
                case 'hours':
                    Crud::hours_editor($name, ['custom' => true, 'days' => $v], false);
                    break;
                case 'levels':
                    echo '<table class="rzv-levels"><thead><tr><th>Názov</th><th>Od (návštev / €)</th><th>Zľava %</th></tr></thead><tbody>';
                    $levels = array_values((array) $v);
                    for ($i = 0; $i < 3; $i++) {
                        $l = $levels[$i] ?? ['name' => '', 'min' => '', 'discount' => ''];
                        printf('<tr><td><input type="text" name="%1$s[%2$d][name]" value="%3$s"></td><td><input type="number" step="0.01" min="0" class="small-text" name="%1$s[%2$d][min]" value="%4$s"></td><td><input type="number" step="0.5" min="0" max="100" class="small-text" name="%1$s[%2$d][discount]" value="%5$s"></td></tr>',
                            esc_attr($name), $i, esc_attr($l['name']), esc_attr((string) $l['min']), esc_attr((string) $l['discount']));
                    }
                    echo '</tbody></table>';
                    break;
                default:
                    $type = in_array($f['type'] ?? '', ['email', 'url'], true) ? $f['type'] : 'text';
                    printf('<input type="%s" name="%s" value="%s" class="%s">', $type, esc_attr($name), esc_attr((string) $v), esc_attr($f['class'] ?? 'regular-text'));
            }
            if (!empty($f['help'])) {
                echo '<p class="description">' . wp_kses_post($f['help']) . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if ($tab === 'emails') {
            echo '<h2>Premenné v šablónach</h2><ul class="rzv-placeholders">';
            foreach (Mailer::PLACEHOLDERS as $k => $l) {
                echo '<li><code>' . esc_html($k) . '</code> ' . esc_html($l) . '</li>';
            }
            echo '</ul>';
        }
        if ($tab === 'general') {
            echo '<h2>Vloženie na web</h2><p>Shortcode: <code>[rezervacie]</code>, len kategória: <code>[rezervacie category="ID"]</code>, len služba: <code>[rezervacie service="ID"]</code>, konkrétny pracovník: <code>[rezervacie worker="ID"]</code>.</p>';
        }
        submit_button('Uložiť');
        echo '</form></div>';
    }
}
