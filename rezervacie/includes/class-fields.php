<?php
namespace Rzv;

defined('ABSPATH') || exit;

/** Doplňujúce otázky vo formulári (otvorené: text/textarea, výberové: select/radio/checkbox). */
class Fields
{
    const TYPES = [
        'text'     => 'Krátky text',
        'textarea' => 'Dlhý text',
        'select'   => 'Výber z možností',
        'radio'    => 'Jedna možnosť (prepínač)',
        'checkbox' => 'Viac možností (zaškrtávacie)',
        'yesno'    => 'Áno / Nie',
    ];

    public static function for_service(int $service_id): array
    {
        $linked = Db::pivot('service_field', 'service_id', $service_id, 'field_id');
        $out = [];
        foreach (Db::all('fields', 'active = 1') as $f) {
            if ($f['all_services'] || in_array((int) $f['id'], $linked, true)) {
                $out[] = self::public($f);
            }
        }
        return $out;
    }

    public static function public(array $f): array
    {
        return [
            'id'       => (int) $f['id'],
            'label'    => $f['label'],
            'type'     => $f['type'],
            'required' => (bool) $f['required'],
            'options'  => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $f['options'])))),
        ];
    }

    /** @return array|\WP_Error [{id,label,value}] */
    public static function validate(int $service_id, array $answers, bool $admin)
    {
        $out = [];
        foreach (self::for_service($service_id) as $f) {
            $raw = $answers[$f['id']] ?? $answers[(string) $f['id']] ?? '';
            if ($f['type'] === 'checkbox') {
                $val = array_values(array_intersect(array_map('sanitize_text_field', (array) $raw), $f['options']));
                $empty = !$val;
                $val = implode(', ', $val);
            } else {
                $val = $f['type'] === 'textarea' ? sanitize_textarea_field((string) $raw) : sanitize_text_field((string) $raw);
                if (in_array($f['type'], ['select', 'radio'], true) && $val !== '' && !in_array($val, $f['options'], true)) {
                    $val = '';
                }
                if ($f['type'] === 'yesno' && $val !== '') {
                    $val = $val === '1' || $val === 'Áno' ? 'Áno' : 'Nie';
                }
                $empty = $val === '';
            }
            if ($f['required'] && $empty && !$admin) {
                return new \WP_Error('rzv_input', sprintf('Vyplňte prosím: %s', $f['label']));
            }
            if (!$empty) {
                $out[] = ['id' => $f['id'], 'label' => $f['label'], 'value' => $val];
            }
        }
        return $out;
    }
}
