<?php
namespace Rzv;

defined('ABSPATH') || exit;

/**
 * Výpočet voľných termínov.
 *
 * Termín pre pracovníka je voľný, ak:
 *  - leží celý v pracovnom čase (vlastný čas pracovníka, inak čas prevádzky),
 *  - neprekrýva blokovaný čas (celoprevádzkový alebo pracovníka),
 *  - neprekrýva inú rezerváciu pracovníka (vrátane prestávok pred/po),
 *    okrem skupinovej služby v tom istom čase, kým je voľná kapacita,
 *  - pre každú miestnosť/prístroj služby je voľná aspoň 1 jednotka,
 *  - je najskôr "hours_before" hodín od teraz a najneskôr "days_ahead" dní dopredu.
 */
class Slots
{
    private array $service;
    private int $duration;
    private int $bb;
    private int $ba;
    private int $capacity;
    /** @var array<int,array> */
    private array $workers = [];
    private array $resource_ids = [];
    private array $resources = [];
    private bool $admin;
    private int $exclude;

    private array $blocked = [];
    /** @var array<int,array> rezervácie podľa pracovníka */
    private array $bookings = [];
    /** @var array<int,array> rezervácie podľa miestnosti/prístroja */
    private array $res_bookings = [];
    private array $svc_meta = [];

    public static function make(int $service_id, array $addon_ids = [], int $worker_id = 0, int $exclude_booking = 0, bool $admin = false): ?self
    {
        $svc = Db::row('services', $service_id);
        if (!$svc || (!$admin && (!$svc['active'] || !$svc['visible']))) {
            return null;
        }
        $s = new self();
        $s->service  = $svc;
        $s->admin    = $admin;
        $s->exclude  = $exclude_booking;
        $s->bb       = (int) $svc['buffer_before'];
        $s->ba       = (int) $svc['buffer_after'];
        $s->capacity = max(1, (int) $svc['capacity']);
        $s->duration = (int) $svc['duration'] + self::addons_duration($service_id, $addon_ids);

        if (($svc['kind'] ?? 'service') === 'rental') {
            // prenájom: bez pracovníka (ID 0), čas prevádzky, kapacitu určujú predmety
            $s->workers[0] = ['id' => 0, 'priority' => 0, 'active' => 1, '_hours' => Settings::get('hours', [])];
            $ids = [];
        } else {
            $ids = Db::pivot('service_worker', 'service_id', $service_id, 'worker_id');
        }
        if ($worker_id && empty($s->workers)) { // pri prenájme sa pracovník ignoruje
            $ids = $admin ? [$worker_id] : array_values(array_intersect($ids, [$worker_id]));
        }
        foreach ($ids as $wid) {
            $w = Db::row('workers', $wid);
            if ($w && ($admin || $w['active'])) {
                $w['_hours'] = self::worker_hours($w);
                $s->workers[$wid] = $w;
            }
        }
        uasort($s->workers, fn($a, $b) => (int) $b['priority'] <=> (int) $a['priority'] ?: (int) $a['id'] <=> (int) $b['id']);

        foreach (Db::pivot('service_resource', 'service_id', $service_id, 'resource_id') as $rid) {
            $r = Db::row('resources', $rid);
            if ($r && $r['active']) {
                $s->resources[$rid] = max(1, (int) $r['quantity']);
            }
        }
        $s->resource_ids = array_keys($s->resources);
        return $s;
    }

    public static function addons_duration(int $service_id, array $addon_ids): int
    {
        if (!$addon_ids) {
            return 0;
        }
        $allowed = Db::pivot('service_addon', 'service_id', $service_id, 'addon_id');
        $sum = 0;
        foreach (array_intersect(array_map('intval', $addon_ids), $allowed) as $aid) {
            $a = Db::row('addons', $aid);
            if ($a && $a['active']) {
                $sum += (int) $a['duration'];
            }
        }
        return $sum;
    }

    public static function worker_hours(array $w): array
    {
        $h = $w['hours'] ? json_decode($w['hours'], true) : null;
        if (is_array($h) && !empty($h['custom'])) {
            return $h['days'] ?? [];
        }
        return Settings::get('hours', []);
    }

    public function duration(): int
    {
        return $this->duration;
    }

    public function worker_ids(): array
    {
        return array_keys($this->workers);
    }

    /** Prednačítanie blokácií a rezervácií pre rozsah dátumov (Y-m-d, vrátane). */
    public function load(string $from, string $to): void
    {
        global $wpdb;
        $start = $from . ' 00:00:00';
        $end   = Db::dt($to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        $this->blocked = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Db::t('blocked') . ' WHERE (start_at < %s AND end_at > %s) OR (repeat_weekly = 1 AND start_at < %s AND (repeat_until IS NULL OR repeat_until >= %s))',
            $end, $start, $end, $from
        ), ARRAY_A) ?: [];

        $this->bookings = [];
        $this->res_bookings = [];
        $this->svc_meta = [];
        $wids = $this->worker_ids();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT b.id, b.worker_id, b.service_id, b.start_at, b.end_at, s.buffer_before, s.buffer_after, s.capacity
             FROM ' . Db::t('bookings') . ' b JOIN ' . Db::t('services') . " s ON s.id = b.service_id
             WHERE b.status IN ('pending','confirmed','awaiting_payment') AND b.start_at < %s AND b.end_at > %s AND b.id <> %d",
            $end, $start, $this->exclude
        ), ARRAY_A) ?: [];

        $res_map = $this->resource_ids ? Db::pivot_map('service_resource', 'service_id', 'resource_id') : [];
        foreach ($rows as $r) {
            $r['_s'] = Db::ts($r['start_at']);
            $r['_e'] = Db::ts($r['end_at']);
            if (in_array((int) $r['worker_id'], $wids, true)) {
                $this->bookings[(int) $r['worker_id']][] = $r;
            }
            foreach ($res_map[(int) $r['service_id']] ?? [] as $rid) {
                if (isset($this->resources[$rid])) {
                    $this->res_bookings[$rid][] = $r;
                }
            }
        }
    }

    /**
     * Voľné časy v daný deň.
     * @return array<string,int[]> 'H:i' => [worker_id, …] (zoradené podľa priority)
     */
    public function day(string $date): array
    {
        $tz  = Db::tz();
        $d   = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $dow = (int) $d->format('N');
        $step = max(5, (int) Settings::get('interval', 15));

        $now = time();
        $min = $this->admin ? 0 : $now + (int) round((float) Settings::get('hours_before', 0) * HOUR_IN_SECONDS);
        $max = $this->admin ? PHP_INT_MAX : (new \DateTimeImmutable('today', $tz))->modify('+' . ((int) Settings::get('days_ahead', 30) + 1) . ' days')->getTimestamp();

        $day_blocks = $this->blocks_for_day($d);
        $out = [];

        foreach ($this->workers as $wid => $w) {
            $hours = $w['_hours'][$dow] ?? $w['_hours'][(string) $dow] ?? null;
            if (!$hours || empty($hours['open'])) {
                continue;
            }
            foreach ($hours['intervals'] ?? [] as $iv) {
                $open  = self::ts($d, $iv[0] ?? '');
                $close = self::ts($d, $iv[1] ?? '');
                if ($open === null || $close === null || $close <= $open) {
                    continue;
                }
                for ($t = $open; $t + $this->duration * 60 <= $close; $t += $step * 60) {
                    if ($t < $min || $t >= $max) {
                        continue;
                    }
                    if ($this->is_free($wid, $t, $day_blocks)) {
                        $out[wp_date('H:i', $t, $tz)][] = $wid;
                    }
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** Admin: je konkrétny čas voľný pre pracovníka? (ignoruje pracovný čas, ak $ignore_hours) */
    public function check(int $worker_id, string $start_at, bool $ignore_hours = false): bool
    {
        $t = Db::ts($start_at);
        $d = Db::dt(substr($start_at, 0, 10) . ' 00:00:00');
        if (!array_key_exists($worker_id, $this->workers)) {
            return false;
        }
        if (!$ignore_hours) {
            $times = $this->day($d->format('Y-m-d'));
            return in_array($worker_id, $times[wp_date('H:i', $t, Db::tz())] ?? [], true);
        }
        return $this->is_free($worker_id, $t, $this->blocks_for_day($d));
    }

    /** Dátumy (Y-m-d) v rozsahu, v ktorých je aspoň jeden voľný čas. */
    public function days(string $from, string $to): array
    {
        $this->load($from, $to);
        $out = [];
        $d = Db::dt($from);
        $end = Db::dt($to);
        while ($d <= $end) {
            if ($this->day($d->format('Y-m-d'))) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
        }
        return $out;
    }

    private function is_free(int $wid, int $t, array $day_blocks): bool
    {
        $s = $t;
        $e = $t + $this->duration * 60;

        foreach ($day_blocks as $b) {
            if ($b['resource_id'] && !in_array((int) $b['resource_id'], $this->resource_ids, true)) {
                continue;
            }
            if ($b['worker_id'] && (int) $b['worker_id'] !== $wid) {
                continue;
            }
            if ($s < $b['_e'] && $e > $b['_s']) {
                return false;
            }
        }

        $group = 0;
        foreach ($wid === 0 ? [] : ($this->bookings[$wid] ?? []) as $b) {
            $bs = $b['_s'] - (int) $b['buffer_before'] * 60;
            $be = $b['_e'] + (int) $b['buffer_after'] * 60;
            if (($s - $this->bb * 60) < $be && ($e + $this->ba * 60) > $bs) {
                if ($this->capacity > 1 && (int) $b['service_id'] === (int) $this->service['id'] && $b['_s'] === $t) {
                    $group++;
                    continue;
                }
                return false;
            }
        }
        if ($group >= $this->capacity) {
            return false;
        }

        foreach ($this->resources as $rid => $qty) {
            $slots = [];
            foreach ($this->res_bookings[$rid] ?? [] as $b) {
                // príprava a upratanie blokujú aj miestnosť / predmet
                $bs = $b['_s'] - (int) $b['buffer_before'] * 60;
                $be = $b['_e'] + (int) $b['buffer_after'] * 60;
                if (($s - $this->bb * 60) < $be && ($e + $this->ba * 60) > $bs) {
                    // skupinová rezervácia v rovnakom čase zaberá jednu jednotku
                    $slots[$b['service_id'] . '-' . $b['worker_id'] . '-' . $b['_s']] = 1;
                }
            }
            // pripojiť sa k existujúcemu termínu môže len skupinová služba (kapacita > 1)
            $own = $this->service['id'] . '-' . $wid . '-' . $t;
            $joins_group = $this->capacity > 1 && isset($slots[$own]);
            if (count($slots) >= $qty && !$joins_group) {
                return false;
            }
        }
        return true;
    }

    private function blocks_for_day(\DateTimeImmutable $d): array
    {
        $out = [];
        $day_start = $d->getTimestamp();
        $day_end   = $d->modify('+1 day')->getTimestamp();
        foreach ($this->blocked as $b) {
            $bs = Db::ts($b['start_at']);
            $be = Db::ts($b['end_at']);
            if ($b['repeat_weekly']) {
                $first = Db::dt($b['start_at']);
                if ($first->format('N') !== $d->format('N') || $first->getTimestamp() > $day_end) {
                    continue;
                }
                if ($b['repeat_until'] && $d->format('Y-m-d') > $b['repeat_until']) {
                    continue;
                }
                $len = $be - $bs;
                $bs  = self::ts($d, $first->format('H:i'));
                $be  = $bs + $len;
            }
            if ($bs < $day_end && $be > $day_start) {
                $b['_s'] = $bs;
                $b['_e'] = $be;
                $out[] = $b;
            }
        }
        return $out;
    }

    private static function ts(\DateTimeImmutable $d, string $hm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        if ($h === 24) {
            return $d->modify('+1 day')->getTimestamp();
        }
        return $d->setTime($h, (int) $m[2])->getTimestamp();
    }
}
