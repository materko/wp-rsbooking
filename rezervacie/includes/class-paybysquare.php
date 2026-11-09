<?php
namespace Rzv;

defined('ABSPATH') || exit;

/**
 * PAY by square (slovenský štandard QR platieb), verzia 1.2.0.
 *
 * Reťazec = base32hex( hlavička(2 B) + dĺžka(2 B LE) + LZMA1_raw( CRC32_LE + polia oddelené TAB ) )
 * LZMA: lc=3, lp=0, pb=2, slovník 2^17. Kódovač nižšie zapisuje len literály – je to platný
 * LZMA prúd (bez kompresie), pre krátke platobné údaje úplne postačuje.
 */
class PayBySquare
{
    /**
     * @param array $p iban, amount, vs, ks, ss, note, due (Y-m-d), name (príjemca), bic, currency
     */
    public static function encode(array $p): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($p['iban'] ?? '')));
        $fields = [
            '',                                     // invoice id
            '1',                                    // počet platieb
            '1',                                    // typ: platobný príkaz
            self::amount((float) ($p['amount'] ?? 0)),
            strtoupper($p['currency'] ?? 'EUR'),
            !empty($p['due']) ? str_replace('-', '', $p['due']) : '',
            self::digits($p['vs'] ?? '', 10),
            self::digits($p['ks'] ?? '', 4),
            self::digits($p['ss'] ?? '', 10),
            '',                                     // originators reference
            self::clean($p['note'] ?? '', 140),
            '1',                                    // počet účtov
            $iban,
            strtoupper(trim((string) ($p['bic'] ?? ''))),
            '0',                                    // trvalý príkaz – nie
            '0',                                    // inkaso – nie
            self::clean($p['name'] ?? '', 70),      // príjemca (povinné od 1.2.0)
            self::clean($p['street'] ?? '', 70),
            self::clean($p['city'] ?? '', 70),
        ];
        $payload = implode("\t", $fields);
        $checked = pack('V', crc32($payload)) . $payload;
        $body = self::lzma_literals($checked);
        $bin = chr((0 << 4) | 0x02) . chr(0x00) . pack('v', strlen($checked)) . $body;
        return self::base32hex($bin);
    }

    public static function amount(float $a): string
    {
        $s = rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private static function digits($v, int $max): string
    {
        return substr(preg_replace('/\D/', '', (string) $v), 0, $max);
    }

    /** Bez diakritiky a tabulátorov (odporúčanie knižníc – staršie bankové aplikácie). */
    public static function clean(string $s, int $max): string
    {
        $s = remove_accents($s);
        $s = preg_replace('/[\t\r\n]+/', ' ', $s);
        $s = preg_replace('/[^\x20-\x7E]/', '-', $s);
        return mb_substr(trim($s), 0, $max);
    }

    public static function iban_valid(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $r = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($r) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($num, 7) as $part) {
            $mod = (int) (($mod . $part) % 97);
        }
        return $mod === 1;
    }

    /* ---------- LZMA1 (iba literály) ---------- */

    private static function lzma_literals(string $data): string
    {
        $lc = 3;
        $pbMask = 3; // pb = 2
        $isMatch = array_fill(0, 12 << 4, 1024);
        $lit = array_fill(0, 0x300 << $lc, 1024);

        $rc = ['low' => 0, 'range' => 0xFFFFFFFF, 'cache' => 0, 'cacheSize' => 1, 'out' => ''];
        $prev = 0;
        $state = 0;
        $len = strlen($data);
        for ($pos = 0; $pos < $len; $pos++) {
            $byte = ord($data[$pos]);
            self::bit($rc, $isMatch[($state << 4) + ($pos & $pbMask)], 0);
            $base = 0x300 * ($prev >> (8 - $lc));
            $ctx = 1;
            for ($i = 7; $i >= 0; $i--) {
                $b = ($byte >> $i) & 1;
                self::bit($rc, $lit[$base + $ctx], $b);
                $ctx = ($ctx << 1) | $b;
            }
            $prev = $byte;
            // po literáli zo stavu 0 zostáva stav 0
        }
        for ($i = 0; $i < 5; $i++) {
            self::shift_low($rc);
        }
        return $rc['out'];
    }

    private static function bit(array &$rc, int &$prob, int $bit): void
    {
        $bound = ($rc['range'] >> 11) * $prob;
        if ($bit === 0) {
            $rc['range'] = $bound;
            $prob += (2048 - $prob) >> 5;
        } else {
            $rc['low'] += $bound;
            $rc['range'] -= $bound;
            $prob -= $prob >> 5;
        }
        while ($rc['range'] < 0x01000000) {
            $rc['range'] = ($rc['range'] << 8) & 0xFFFFFFFF;
            self::shift_low($rc);
        }
    }

    private static function shift_low(array &$rc): void
    {
        if ($rc['low'] < 0xFF000000 || $rc['low'] > 0xFFFFFFFF) {
            $carry = $rc['low'] > 0xFFFFFFFF ? 1 : 0;
            $temp = $rc['cache'];
            do {
                $rc['out'] .= chr(($temp + $carry) & 0xFF);
                $temp = 0xFF;
            } while (--$rc['cacheSize'] !== 0);
            $rc['cache'] = ($rc['low'] >> 24) & 0xFF;
        }
        $rc['cacheSize']++;
        $rc['low'] = ($rc['low'] & 0x00FFFFFF) << 8;
    }

    private static function base32hex(string $bin): string
    {
        $alpha = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $rem = strlen($bits) % 5;
        if ($rem) {
            $bits .= str_repeat('0', 5 - $rem);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alpha[bindec($chunk)];
        }
        return $out;
    }
}
