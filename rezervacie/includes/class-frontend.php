<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Frontend
{
    public static function init(): void
    {
        add_shortcode('rezervacie', [self::class, 'shortcode']);
        add_shortcode('rezervacie_poukazky', [self::class, 'shortcode_vouchers']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('template_redirect', [self::class, 'voucher_page']);
    }

    public static function register_assets(): void
    {
        wp_register_style('rzv-widget', RZV_URL . 'assets/widget.css', [], RZV_VERSION);
        wp_register_script('rzv-qrcode', RZV_URL . 'assets/vendor/qrcode.js', [], '1.4.4', true);
        wp_register_script('rzv-widget', RZV_URL . 'assets/widget.js', ['rzv-qrcode'], RZV_VERSION, true);
    }

    /**
     * [rezervacie category="ID" service="ID" worker="ID"]
     */
    public static function shortcode($atts): string
    {
        $atts = shortcode_atts(['category' => '', 'service' => '', 'worker' => ''], $atts, 'rezervacie');
        if (!wp_script_is('rzv-widget', 'registered')) {
            self::register_assets();
        }
        wp_enqueue_style('rzv-widget');
        wp_enqueue_script('rzv-widget');

        $cfg = [
            'api'     => esc_url_raw(rest_url(Rest::NS)),
            'nonce'   => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'color'   => Settings::get('color_primary'),
            'preset'  => array_map('intval', $atts),
            'manage'  => isset($_GET['rzv_manage']) ? sanitize_text_field(wp_unslash($_GET['rzv_manage'])) : '',
            'paid'    => isset($_GET['rzv_paid']) ? sanitize_key($_GET['rzv_paid']) : (isset($_GET['rzv_pay']) ? 'cancel' : ''),
            'locale'  => get_locale(),
            'i18n'    => self::i18n(),
            'modules' => apply_filters('rzv_widget_modules', []),
        ];
        $id = 'rzv-' . wp_generate_password(6, false, false);
        return sprintf(
            '<div id="%1$s" class="rzv-widget" style="--rzv-primary:%2$s" data-config="%3$s"><noscript>Rezervácia vyžaduje JavaScript.</noscript><div class="rzv-loading">Načítavam…</div></div>',
            esc_attr($id),
            esc_attr(Settings::get('color_primary')),
            esc_attr(wp_json_encode($cfg))
        );
    }

    /** [rezervacie_poukazky] – predaj darčekových poukážok a permanentiek. */
    public static function shortcode_vouchers($atts): string
    {
        if (!wp_script_is('rzv-widget', 'registered')) {
            self::register_assets();
        }
        wp_enqueue_style('rzv-widget');
        wp_enqueue_script('rzv-widget');
        $cfg = [
            'api'   => esc_url_raw(rest_url(Rest::NS)),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'mode'  => 'vouchers',
            'i18n'  => self::i18n(),
        ];
        return sprintf(
            '<div class="rzv-widget rzv-shop" style="--rzv-primary:%1$s" data-config="%2$s"><div class="rzv-loading">Načítavam…</div></div>',
            esc_attr(Settings::get('color_primary')),
            esc_attr(wp_json_encode($cfg))
        );
    }

    /** ?rzv_voucher=token – samostatná stránka s poukážkou na tlač. */
    public static function voucher_page(): void
    {
        if (empty($_GET['rzv_voucher'])) {
            return;
        }
        $v = Vouchers::by_token(sanitize_text_field(wp_unslash($_GET['rzv_voucher'])));
        if (!$v) {
            return;
        }
        Vouchers::render_print($v);
        exit;
    }

    private static function i18n(): array
    {
        return [
            'category'   => 'Vyberte kategóriu',
            'service'    => 'Vyberte službu',
            'addons'     => 'Doplnkové služby',
            'worker'     => 'Vyberte pracovníka',
            'anyWorker'  => 'Ktokoľvek voľný',
            'date'       => 'Vyberte dátum',
            'time'       => 'Vyberte čas',
            'details'    => 'Vyplňte vaše údaje',
            'firstName'  => 'Meno',
            'lastName'   => 'Priezvisko',
            'email'      => 'E-mail',
            'phone'      => 'Telefón',
            'note'       => 'Poznámka',
            'terms'      => 'Súhlasím so všeobecnými obchodnými podmienkami',
            'privacy'    => 'Zásady ochrany osobných údajov',
            'marketing'  => 'Chcem dostávať novinky a ponuky',
            'book'       => 'Rezervovať',
            'noTimes'    => 'V tento deň nie sú voľné termíny.',
            'waitlist'   => 'Prihlásiť sa ako náhradník na tento deň',
            'waitlistOk' => 'Ste na zozname náhradníkov. Ak sa termín uvoľní, pošleme vám e-mail.',
            'confirmed'  => 'Rezervácia je potvrdená',
            'pending'    => 'Rezervácia bola prijatá a čaká na potvrdenie',
            'emailSent'  => 'Potvrdenie sme vám poslali na e-mail.',
            'newBooking' => 'Nová rezervácia',
            'cancel'     => 'Zrušiť rezerváciu',
            'cancelAsk'  => 'Naozaj chcete zrušiť túto rezerváciu?',
            'cancelled'  => 'Rezervácia bola zrušená.',
            'yourBooking'=> 'Vaša rezervácia',
            'months'     => ['január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'],
            'days'       => ['PO', 'UT', 'ST', 'ŠT', 'PI', 'SO', 'NE'],
            'morning'    => 'Dopoludnia',
            'afternoon'  => 'Popoludní',
            'summary'    => 'Zhrnutie',
            'total'      => 'Spolu',
            'min'        => 'min.',
            'error'      => 'Nastala chyba. Skúste to prosím znova.',
            'yes'        => 'Áno',
            'no'         => 'Nie',
            'choose'     => '— vyberte —',
            'codeLabel'  => 'Zľavový kód alebo poukážka',
            'codeApply'  => 'Uplatniť',
            'codeRemove' => 'odstrániť',
            'payTitle'   => 'Platba',
            'payOnline'  => 'Zaplatiť kartou online',
            'payQr'      => 'Zaplatiť prevodom / QR kódom',
            'payQrHint'  => 'Naskenujte QR kód v aplikácii banky.',
            'payRequired'=> 'Na dokončenie rezervácie je potrebná platba',
            'payUntil'   => 'Termín držíme do',
            'paidOk'     => 'Platba prebehla úspešne. Ďakujeme!',
            'paidFail'   => 'Platba nebola dokončená.',
            'payOnlineNow' => 'Zaplatiť online teraz',
            'redirecting'=> 'Presmerovávam na platobnú bránu…',
            'vouchersTitle' => 'Darčekové poukážky',
            'voucherBuy' => 'Objednať poukážku',
            'recipient'  => 'Pre koho je poukážka (meno na poukážke)',
            'message'    => 'Venovanie',
            'buyer'      => 'Vaše údaje',
            'validity'   => 'Platnosť',
            'daysUnit'   => 'dní',
            'voucherOk'  => 'Ďakujeme za objednávku!',
            'voucherPaid'=> 'Poukážka je aktívna – poslali sme ju na váš e-mail.',
            'voucherWait'=> 'Poukážku vám pošleme e-mailom hneď po prijatí platby.',
            'voucherOpen'=> 'Zobraziť poukážku',
        ];
    }
}
