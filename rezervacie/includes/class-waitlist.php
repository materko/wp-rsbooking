<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Čakacia listina (náhradníci) – zákazník sa prihlási na deň, pri uvoľnení termínu dostane e-mail. */
class Waitlist
{
    public static function add(array $d)
    {
        global $wpdb;
        if (!Settings::get('waitlist')) {
            return new \WP_Error('rzv_off', 'Čakacia listina nie je zapnutá.');
        }
        $service_id = (int) ($d['service_id'] ?? 0);
        $date = (string) ($d['date'] ?? '');
        $svc = Db::row('services', $service_id);
        if (!$svc || !$svc['active'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < wp_date('Y-m-d')) {
            return new \WP_Error('rzv_input', 'Neplatná služba alebo dátum.');
        }
        $c = Customers::validate($d, false);
        if (is_wp_error($c)) {
            return $c;
        }
        $cid = Customers::upsert($c);
        $tf = preg_match('/^\d{2}:\d{2}$/', $d['time_from'] ?? '') ? $d['time_from'] . ':00' : null;
        $tt = preg_match('/^\d{2}:\d{2}$/', $d['time_to'] ?? '') ? $d['time_to'] . ':00' : null;
        $wpdb->insert(Db::t('waitlist'), [
            'customer_id' => $cid,
            'service_id'  => $service_id,
            'worker_id'   => !empty($d['worker_id']) ? (int) $d['worker_id'] : null,
            'date'        => $date,
            'time_from'   => $tf,
            'time_to'     => $tt,
            'status'      => 'waiting',
            'created_at'  => Db::now(),
        ]);
        Mailer::admin_text('Nový náhradník – ' . $svc['name'] . ' ' . $date, trim($c['first_name'] . ' ' . $c['last_name']) . "\n" . $c['email'] . ' ' . $c['phone']);
        return (int) $wpdb->insert_id;
    }

    /** Volá sa po zrušení / presune rezervácie. */
    public static function on_slot_freed(array $old_booking): void
    {
        global $wpdb;
        if (!Settings::get('waitlist') || !Settings::get('waitlist_auto')) {
            return;
        }
        $date = substr($old_booking['start_at'], 0, 10);
        if ($date < wp_date('Y-m-d')) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Db::t('waitlist') . " WHERE date = %s AND status = 'waiting'", $date
        ), ARRAY_A) ?: [];
        foreach ($rows as $w) {
            $slots = Slots::make((int) $w['service_id'], [], (int) $w['worker_id']);
            if (!$slots) {
                continue;
            }
            $slots->load($date, $date);
            $times = array_keys($slots->day($date));
            if ($w['time_from'] || $w['time_to']) {
                $from = substr($w['time_from'] ?: '00:00', 0, 5);
                $to = substr($w['time_to'] ?: '23:59', 0, 5);
                $times = array_filter($times, fn($t) => $t >= $from && $t <= $to);
            }
            if (!$times) {
                continue;
            }
            $c = Db::row('customers', (int) $w['customer_id']);
            $svc = Db::row('services', (int) $w['service_id']);
            if ($c && $svc) {
                Mailer::waitlist($c, $svc, $date, array_values($times));
                $wpdb->update(Db::t('waitlist'), ['status' => 'notified', 'notified_at' => Db::now()], ['id' => (int) $w['id']]);
            }
        }
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . Db::t('waitlist') . " SET status = 'expired' WHERE status = 'waiting' AND date < %s", wp_date('Y-m-d')));
    }
}
