<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Mailer
{
    const PLACEHOLDERS = [
        '{meno}'       => 'Krstné meno zákazníka',
        '{priezvisko}' => 'Priezvisko zákazníka',
        '{sluzba}'     => 'Názov služby',
        '{pracovnik}'  => 'Pracovník',
        '{datum}'      => 'Dátum (d. m. Y)',
        '{cas}'        => 'Čas začiatku',
        '{trvanie}'    => 'Trvanie v minútach',
        '{cena}'       => 'Cena',
        '{detail}'     => 'Zhrnutie rezervácie',
        '{odkaz}'      => 'Odkaz na správu / zrušenie',
        '{prevadzka}'  => 'Názov a kontakt prevádzky',
        '{rezervacia}' => 'Odkaz na rezervačný formulár',
        '{platba}'     => 'Platobné údaje (QR / online), ak je čo platiť',
        '{kod}'        => 'Kód poukážky (e-maily poukážok)',
        '{poukazka}'   => 'Názov poukážky',
        '{platnost}'   => 'Platnosť poukážky',
    ];

    /** Typy: pending, confirmed, cancelled, changed, reminder */
    public static function booking(array $b, string $type): bool
    {
        if (!$b['email']) {
            return false;
        }
        $subject = Settings::get("tpl_{$type}_subject", '');
        $body    = Settings::get("tpl_{$type}_body", '');
        if (!$subject || !$body) {
            return false;
        }
        $vars = self::vars($b);
        if ($vars['{platba}'] !== '' && strpos($body, '{platba}') === false && in_array($type, ['confirmed', 'pending', 'reminder'], true)) {
            $body = rtrim($body) . "\n\n{platba}";
        }
        $attach = [];
        if (in_array($type, ['confirmed', 'changed'], true)) {
            $attach[] = self::ics($b);
        }
        $ok = self::send($b['email'], strtr($subject, $vars), strtr($body, $vars), $attach);
        foreach ($attach as $f) {
            @unlink($f);
        }
        if (in_array($type, ['confirmed', 'pending', 'changed', 'cancelled'], true)) {
            self::worker_notice($b, $type);
        }
        return $ok;
    }

    public static function admin_new(array $b): void
    {
        $label = $b['status'] === 'pending' ? 'Nová rezervácia (čaká na potvrdenie)' : 'Nová rezervácia';
        $body = self::detail($b) . "\n\nZákazník: {$b['customer_name']}\nE-mail: {$b['email']}\nTelefón: {$b['phone']}"
            . ($b['note'] ? "\nPoznámka: {$b['note']}" : '')
            . "\n\nAdministrácia: " . admin_url('admin.php?page=rzv-bookings&edit=' . $b['id']);
        self::admin_text($label . ' – ' . $b['service_name'] . ' ' . self::date($b['start_at']), $body);
    }

    public static function admin_cancelled(array $b): void
    {
        self::admin_text('Zákazník zrušil rezerváciu – ' . $b['service_name'] . ' ' . self::date($b['start_at']),
            self::detail($b) . "\n\nZákazník: {$b['customer_name']} {$b['phone']}");
    }

    public static function admin_text(string $subject, string $body): void
    {
        if (!Settings::get('notify_admin')) {
            return;
        }
        $to = array_filter(array_map('trim', explode(',', Settings::get('notify_emails') ?: Settings::get('business_email'))));
        if ($to) {
            self::send($to, $subject, $body);
        }
    }

    public static function waitlist(array $c, array $svc, string $date, array $times): void
    {
        $vars = [
            '{meno}'       => $c['first_name'],
            '{priezvisko}' => $c['last_name'],
            '{sluzba}'     => $svc['name'],
            '{datum}'      => wp_date('j. n. Y', Db::ts($date . ' 12:00:00'), Db::tz()),
            '{cas}'        => implode(', ', array_slice($times, 0, 8)),
            '{rezervacia}' => Settings::booking_url(),
            '{prevadzka}'  => self::business(),
        ];
        self::send($c['email'], strtr(Settings::get('tpl_waitlist_subject'), $vars), strtr(Settings::get('tpl_waitlist_body'), $vars));
    }

    private static function worker_notice(array $b, string $type): void
    {
        $w = Db::row('workers', (int) $b['worker_id']);
        if (!$w || !$w['notify'] || !is_email($w['email'])) {
            return;
        }
        $labels = ['pending' => 'Nová rezervácia', 'confirmed' => 'Potvrdená rezervácia', 'changed' => 'Zmenená rezervácia', 'cancelled' => 'Zrušená rezervácia'];
        self::send($w['email'], ($labels[$type] ?? 'Rezervácia') . ' – ' . $b['service_name'] . ' ' . self::date($b['start_at']),
            self::detail($b) . "\n\nZákazník: {$b['customer_name']} {$b['phone']}" . ($b['note'] ? "\nPoznámka: {$b['note']}" : ''));
    }

    public static function vars(array $b): array
    {
        $ts = Db::ts($b['start_at']);
        return [
            '{meno}'       => $b['first_name'],
            '{priezvisko}' => $b['last_name'],
            '{sluzba}'     => $b['service_name'],
            '{pracovnik}'  => $b['worker_name'],
            '{datum}'      => wp_date('j. n. Y', $ts, Db::tz()),
            '{cas}'        => wp_date('H:i', $ts, Db::tz()),
            '{trvanie}'    => (string) round((Db::ts($b['end_at']) - $ts) / 60),
            '{cena}'       => Settings::money(Pricing::total($b)),
            '{detail}'     => self::detail($b),
            '{odkaz}'      => Booking::manage_url($b),
            '{prevadzka}'  => self::business(),
            '{rezervacia}' => Settings::booking_url(),
            '{platba}'     => in_array($b['status'], ['confirmed', 'pending'], true) && Settings::get('qr_enabled') ? Payments::email_text(Payments::for_booking_due($b)) : '',
        ];
    }

    public static function voucher_order(array $v): void
    {
        $ins = Payments::pending_for_voucher((int) $v['id']);
        self::voucher_mail($v, 'tpl_voucher_order', ['{platba}' => Payments::email_text($ins) . "

Poukážka a platba: " . Vouchers::url($v)]);
    }

    public static function voucher(array $v): void
    {
        self::voucher_mail($v, 'tpl_voucher', []);
    }

    private static function voucher_mail(array $v, string $tpl, array $extra): void
    {
        if (!is_email($v['buyer_email'])) {
            return;
        }
        $first = explode(' ', trim((string) $v['buyer_name']))[0] ?? '';
        $vars = $extra + [
            '{meno}'      => $first,
            '{kod}'       => $v['code'],
            '{poukazka}'  => $v['name'],
            '{cena}'      => Settings::money((float) $v['price'] - (float) $v['discount']),
            '{platnost}'  => Vouchers::date($v['valid_to']),
            '{odkaz}'     => Vouchers::url($v),
            '{prevadzka}' => self::business(),
            '{platba}'    => '',
        ];
        self::send($v['buyer_email'], strtr(Settings::get($tpl . '_subject'), $vars), strtr(Settings::get($tpl . '_body'), $vars));
    }

    public static function detail(array $b): string
    {
        $ts = Db::ts($b['start_at']);
        $lines = [
            'Služba: ' . $b['service_name'],
            'Termín: ' . wp_date('l j. n. Y, H:i', $ts, Db::tz()) . ' – ' . wp_date('H:i', Db::ts($b['end_at']), Db::tz()),
        ];
        if ($b['worker_name']) {
            $lines[] = 'Pracovník: ' . $b['worker_name'];
        }
        foreach ($b['addons_list'] as $a) {
            $lines[] = '+ ' . $a['name'];
        }
        if ((float) $b['price'] > 0) {
            if ((float) $b['discount'] > 0) {
                $why = implode(', ', array_filter([$b['coupon_code'], $b['loyalty_level'] ? 'vernostná ' . $b['loyalty_level'] : '']));
                $lines[] = 'Zľava' . ($why ? ' (' . $why . ')' : '') . ': −' . Settings::money($b['discount']);
            }
            if ((float) $b['voucher_amount'] > 0) {
                $lines[] = 'Uhradené poukážkou: −' . Settings::money($b['voucher_amount']);
            }
            $lines[] = 'Cena: ' . Settings::money(Pricing::total($b));
            if ((float) $b['paid'] > 0) {
                $lines[] = 'Zaplatené: ' . Settings::money($b['paid']) . ', zostáva: ' . Settings::money(Pricing::due($b));
            }
        }
        foreach ($b['answers_list'] as $a) {
            $lines[] = $a['label'] . ': ' . $a['value'];
        }
        return implode("\n", $lines);
    }

    public static function business(): string
    {
        return trim(implode("\n", array_filter([
            Settings::get('business_name'),
            Settings::get('business_address'),
            Settings::get('business_phone'),
            Settings::get('business_email'),
            Settings::get('email_footer'),
        ])));
    }

    public static function date(string $dt): string
    {
        return wp_date('j. n. Y H:i', Db::ts($dt), Db::tz());
    }

    public static function send($to, string $subject, string $body, array $attachments = []): bool
    {
        $headers = [];
        $from = Settings::get('email_from');
        if (is_email($from)) {
            $headers[] = sprintf('From: %s <%s>', wp_specialchars_decode(Settings::get('email_from_name'), ENT_QUOTES), $from);
        }
        $reply = Settings::get('business_email');
        if (is_email($reply)) {
            $headers[] = 'Reply-To: ' . $reply;
        }
        $body = apply_filters('rzv_mail_body', $body, $subject, $to);
        return (bool) wp_mail($to, $subject, $body, $headers, $attachments);
    }

    /** Pripomienky – volá cron každých 15 minút. */
    public static function cron(): void
    {
        global $wpdb;
        if (Settings::get('reminder')) {
            $now = Db::now();
            $until = Db::fmt(time() + (int) Settings::get('reminder_hours', 24) * HOUR_IN_SECONDS);
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT id FROM ' . Db::t('bookings') . " WHERE status IN ('confirmed','pending') AND reminder_sent = 0 AND start_at > %s AND start_at <= %s LIMIT 50",
                $now, $until
            ));
            foreach ($ids as $id) {
                $wpdb->update(Db::t('bookings'), ['reminder_sent' => 1], ['id' => (int) $id]);
                $b = Booking::get((int) $id);
                // nepripomínať rezervácie vytvorené tesne pred termínom
                if ($b && Db::ts($b['created_at']) < time() - HOUR_IN_SECONDS) {
                    self::booking($b, 'reminder');
                }
            }
        }
        Waitlist::cleanup();
        Payments::cron();
        Vouchers::cron();
        Ratings::cron();
        // rozšírenia sa napájajú priamo na akciu 'rzv_cron' (nevolať ju odtiaľto – rekurzia)
    }

    private static function ics(array $b): string
    {
        $fmt = fn($s) => gmdate('Ymd\THis\Z', Db::ts($s));
        $esc = fn($s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], (string) $s);
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Rezervacie//WP//SK', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:rzv-' . $b['id'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $fmt($b['start_at']),
            'DTEND:' . $fmt($b['end_at']),
            'SUMMARY:' . $esc($b['service_name'] . ' – ' . Settings::get('business_name')),
            'LOCATION:' . $esc(Settings::get('business_address')),
            'DESCRIPTION:' . $esc(self::detail($b)),
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
        $file = trailingslashit(get_temp_dir()) . 'rezervacia-' . $b['id'] . '.ics';
        file_put_contents($file, $ics);
        return $file;
    }
}
