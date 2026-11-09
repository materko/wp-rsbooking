<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Settings
{
    const OPTION = 'rzv_settings';
    private static ?array $cache = null;

    public static function defaults(): array
    {
        $day = ['open' => true, 'intervals' => [['09:00', '17:00']]];
        $off = ['open' => false, 'intervals' => [['09:00', '17:00']]];
        return [
            'business_name'        => get_bloginfo('name'),
            'business_email'       => get_option('admin_email'),
            'business_phone'       => '',
            'business_address'     => '',
            'booking_page'         => 0,
            'currency'             => '€',
            // Rezervačný systém
            'hours_before'         => 1,     // najneskôr X hodín vopred
            'days_ahead'           => 30,    // koľko dní do budúcnosti
            'interval'             => 15,    // krok časov v minútach
            'allow_worker_choice'  => 1,     // zákazník si vyberá pracovníka
            'waitlist'             => 0,     // čakacia listina (náhradníci)
            'waitlist_auto'        => 1,     // pri uvoľnení automaticky upozorniť
            'require_phone'        => 1,
            'terms_url'            => '',
            'privacy_url'          => get_privacy_policy_url(),
            // Pracovný čas prevádzky: 1 = pondelok … 7 = nedeľa
            'hours'                => [1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => $off, 7 => $off],
            // Storno
            'cancel_allowed'       => 1,
            'cancel_hours_before'  => 24,
            'cancel_policy'        => '',
            // E-maily
            'email_from_name'      => get_bloginfo('name'),
            'email_from'           => get_option('admin_email'),
            'notify_admin'         => 1,
            'notify_emails'        => '',
            'reminder'             => 1,
            'reminder_hours'       => 24,
            'email_footer'         => '',
            'tpl_pending_subject'   => 'Rezervácia prijatá – čaká na potvrdenie',
            'tpl_pending_body'      => "Dobrý deň {meno},\n\nďakujeme za rezerváciu. Vaša požiadavka čaká na potvrdenie.\n\n{detail}\n\nSpravovať / zrušiť: {odkaz}\n\n{prevadzka}",
            'tpl_confirmed_subject' => 'Rezervácia potvrdená – {sluzba} {datum} {cas}',
            'tpl_confirmed_body'    => "Dobrý deň {meno},\n\nvaša rezervácia je potvrdená.\n\n{detail}\n\nSpravovať / zrušiť: {odkaz}\n\n{prevadzka}",
            'tpl_cancelled_subject' => 'Rezervácia zrušená – {sluzba} {datum} {cas}',
            'tpl_cancelled_body'    => "Dobrý deň {meno},\n\nvaša rezervácia bola zrušená.\n\n{detail}\n\n{prevadzka}",
            'tpl_changed_subject'   => 'Zmena rezervácie – {sluzba} {datum} {cas}',
            'tpl_changed_body'      => "Dobrý deň {meno},\n\nvaša rezervácia bola zmenená.\n\n{detail}\n\nSpravovať / zrušiť: {odkaz}\n\n{prevadzka}",
            'tpl_reminder_subject'  => 'Pripomienka: {sluzba} {datum} o {cas}',
            'tpl_reminder_body'     => "Dobrý deň {meno},\n\npripomíname vašu rezerváciu.\n\n{detail}\n\nAk nemôžete prísť, zrušte ju prosím: {odkaz}\n\n{prevadzka}",
            'tpl_waitlist_subject'  => 'Uvoľnil sa termín – {sluzba} {datum}',
            'tpl_waitlist_body'     => "Dobrý deň {meno},\n\nuvoľnil sa termín, o ktorý ste mali záujem ({sluzba}, {datum}).\nRezervujte si ho čo najskôr: {rezervacia}\n\n{prevadzka}",
            // Platby
            'qr_enabled'           => 0,
            'iban'                 => '',
            'bic'                  => '',
            'beneficiary'          => get_bloginfo('name'),
            'qr_due_days'          => 0,     // splatnosť: 0 = deň termínu
            'online_enabled'       => 0,     // Stripe Checkout
            'stripe_secret'        => '',
            'stripe_webhook_secret'=> '',
            'payment_mode'         => 'deposit', // deposit = len zálohy pri službách, full = vždy celá suma, optional = dobrovoľne
            'payment_timeout'      => 30,    // minút na zaplatenie, potom sa termín uvoľní
            'tpl_payment_text'     => "Platba prevodom:\nIBAN: {iban}\nSuma: {suma}\nVariabilný symbol: {vs}\nQR platba: {qr_odkaz}",
            // Poukážky
            'voucher_page'         => 0,
            'voucher_prefix'       => 'DAR',
            'voucher_intro'        => 'Darujte zážitok – poukážku pošleme e-mailom hneď po zaplatení.',
            'voucher_terms'        => 'Poukážku je možné uplatniť pri online rezervácii zadaním kódu alebo na prevádzke.',
            'voucher_pay_days'     => 7,     // nezaplatené objednávky po X dňoch zrušiť
            'tpl_voucher_order_subject' => 'Objednávka poukážky {kod}',
            'tpl_voucher_order_body'    => "Dobrý deň {meno},\n\nďakujeme za objednávku poukážky „{poukazka}“ ({cena}).\nPo pripísaní platby vám ju pošleme e-mailom.\n\n{platba}\n\n{prevadzka}",
            'tpl_voucher_subject'  => 'Vaša darčeková poukážka {kod}',
            'tpl_voucher_body'     => "Dobrý deň {meno},\n\nposielame vašu poukážku „{poukazka}“.\nKód: {kod}\nPlatnosť do: {platnost}\n\nPoukážku si môžete vytlačiť alebo preposlať: {odkaz}\n\n{prevadzka}",
            // Hodnotenia
            'ratings_enabled'      => 0,
            'ratings_delay_hours'  => 2,     // koľko hodín po skončení poslať žiadosť
            'ratings_mode'         => 'internal', // internal = u nás, external = priamo na portál, both = u nás + pri dobrom hodnotení pozvánka na portál
            'ratings_external_url' => '',    // napr. odkaz na Google recenzie
            'ratings_external_name'=> 'Google',
            'ratings_threshold'    => 4,     // od koľkých hviezdičiek je hodnotenie pozitívne
            'ratings_autopublish'  => 1,     // pozitívne hodnotenia zverejniť hneď
            'tpl_rating_subject'   => 'Ako ste boli spokojní? – {sluzba}',
            'tpl_rating_body'      => "Dobrý deň {meno},\n\nďakujeme za návštevu. Budeme radi, ak nám dáte vedieť, ako ste boli spokojní – zaberie to pár sekúnd:\n{hodnotenie}\n\n{prevadzka}",
            // Vernostný program
            'loyalty_enabled'      => 0,
            'loyalty_basis'        => 'visits', // visits = počet návštev, spend = útrata v €
            'loyalty_levels'       => [
                ['name' => 'Bronze', 'min' => 3, 'discount' => 5],
                ['name' => 'Silver', 'min' => 8, 'discount' => 10],
                ['name' => 'Gold', 'min' => 15, 'discount' => 15],
            ],
            'loyalty_combine'      => 0,     // 1 = vernostná zľava sa sčíta so zľavovým kódom
            // Automatické dokončenie
            'auto_complete'        => 1,     // potvrdené rezervácie po skončení označiť ako zrealizované
            // Vzhľad formulára
            'color_primary'        => '#c2185b',
            'show_prices'          => 1,
            'show_duration'        => 1,
        ];
    }

    public static function ensure_defaults(): void
    {
        $cur = get_option(self::OPTION, []);
        $merged = array_replace(self::defaults(), is_array($cur) ? $cur : []);
        update_option(self::OPTION, $merged, false);
        self::$cache = null;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $cur = get_option(self::OPTION, []);
            self::$cache = array_replace(self::defaults(), is_array($cur) ? $cur : []);
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $all = self::all();
        $all[$key] = $value;
        self::save($all);
    }

    public static function save(array $all): void
    {
        update_option(self::OPTION, $all, false);
        self::$cache = null;
    }

    public static function booking_url(array $args = []): string
    {
        $id  = (int) self::get('booking_page');
        $url = $id ? get_permalink($id) : home_url('/');
        return $args ? add_query_arg($args, $url) : $url;
    }

    public static function money($v): string
    {
        return number_format_i18n((float) $v, 2) . ' ' . self::get('currency', '€');
    }
}
