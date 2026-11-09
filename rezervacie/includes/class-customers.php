<?php
namespace Rzv;

defined('ABSPATH') || exit;

class Customers
{
    /** Overí údaje zákazníka. Admin môže zadať existujúce customer_id. */
    public static function validate(array $d, bool $admin)
    {
        if ($admin && !empty($d['customer_id'])) {
            $c = Db::row('customers', (int) $d['customer_id']);
            return $c ?: new \WP_Error('rzv_input', 'Zákazník neexistuje.');
        }
        $c = [
            'first_name' => sanitize_text_field($d['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($d['last_name'] ?? ''),
            'email'      => sanitize_email($d['email'] ?? ''),
            'phone'      => self::phone($d['phone'] ?? ''),
            'marketing'  => !empty($d['marketing']) ? 1 : 0,
        ];
        if ($c['first_name'] === '' || ($c['last_name'] === '' && !$admin)) {
            return new \WP_Error('rzv_input', 'Zadajte meno a priezvisko.');
        }
        if (!$admin) {
            if (!is_email($c['email'])) {
                return new \WP_Error('rzv_input', 'Zadajte platný e-mail.');
            }
            if (Settings::get('require_phone') && strlen(preg_replace('/\D/', '', $c['phone'])) < 9) {
                return new \WP_Error('rzv_input', 'Zadajte platné telefónne číslo.');
            }
        }
        $existing = self::find($c['email'], $c['phone']);
        if ($existing) {
            $c['id'] = (int) $existing['id'];
            $c['blocked'] = (int) $existing['blocked'];
        }
        return $c;
    }

    public static function find(string $email, string $phone): ?array
    {
        global $wpdb;
        $t = Db::t('customers');
        if ($email) {
            $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE email = %s ORDER BY id LIMIT 1", $email), ARRAY_A);
            if ($r) {
                return $r;
            }
        }
        if ($phone && !$email) {
            $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE phone = %s ORDER BY id LIMIT 1", $phone), ARRAY_A);
            if ($r) {
                return $r;
            }
        }
        return null;
    }

    /** Vloží alebo aktualizuje zákazníka, vráti ID. */
    public static function upsert(array $c): int
    {
        global $wpdb;
        $t = Db::t('customers');
        $data = array_intersect_key($c, array_flip(['first_name', 'last_name', 'email', 'phone']));
        if (!empty($c['id'])) {
            $data = array_filter($data, fn($v) => $v !== '');
            if (!empty($c['marketing'])) {
                $data['marketing'] = 1;
            }
            if ($data) {
                $wpdb->update($t, $data, ['id' => (int) $c['id']]);
            }
            return (int) $c['id'];
        }
        $data['marketing'] = (int) ($c['marketing'] ?? 0);
        $data['created_at'] = Db::now();
        if (is_user_logged_in() && !current_user_can('rzv_manage')) {
            $data['user_id'] = get_current_user_id();
        }
        $wpdb->insert($t, $data);
        return (int) $wpdb->insert_id;
    }

    public static function phone(string $p): string
    {
        $p = preg_replace('/[^\d+]/', '', $p);
        if (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        } elseif (str_starts_with($p, '0') && strlen($p) === 10) {
            $p = '+421' . substr($p, 1);
        }
        return $p;
    }

    public static function stats(int $id): array
    {
        global $wpdb;
        $t = Db::t('bookings');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'no_show') AS no_show,
                SUM(status = 'cancelled') AS cancelled,
                SUM(CASE WHEN status IN ('completed','confirmed') THEN price - discount ELSE 0 END) AS revenue,
                MAX(start_at) AS last_visit
             FROM $t WHERE customer_id = %d", $id
        ), ARRAY_A) ?: [];
    }
}
