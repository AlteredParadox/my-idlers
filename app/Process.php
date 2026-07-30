<?php

namespace App;

class Process
{
    public string $startTime;
    public string $endTime;

    public function startTimer(): void
    {
        $this->startTime = microtime(true);
    }

    public function stopTimer(): void
    {
        $this->endTime = microtime(true);
    }

    public function getTimeTaken(): float
    {//In seconds (microtime(true) deltas are already seconds)
        return round($this->endTime - $this->startTime, 3);
    }

    // Guards the per-USD compare rows: usd_per_month is 0 for one-time/lifetime
    // terms and for free ($0) services, and dividing by it would fatal (PHP 8
    // DivisionByZeroError).
    public static function safeDivide(float $numerator, float $denominator): float
    {
        return $denominator != 0.0 ? $numerator / $denominator : 0.0;
    }

    public static function paymentTermIntToString(int $term): string
    {
        return match ($term) {
            1 => "p/m",
            2 => "p/qtr",
            3 => "p/hy",
            4 => "p/y",
            5 => "p/2y",
            6 => "p/3y",
            7 => "once",
            default => "unknown",
        };
    }

    /**
     * A sort key that puts addresses in address order rather than text order.
     *
     * Sorting an address column as text reads wrong to anyone who knows what
     * the values mean: 45.77.120.80 lands after 205.185.120.45 because '4' >
     * '2'. inet_pton gives the packed bytes, and hex of a fixed-width packed
     * address sorts lexicographically in exactly numeric order -- 4 bytes for
     * IPv4, 16 for IPv6 -- so no 128-bit arithmetic (which a JS float cannot
     * hold anyway) is needed on either side.
     *
     * The leading family digit groups v4 before v6 before anything unparseable,
     * which is what the DNS address column needs: it also holds hostnames and
     * "10 mail.example.com" MX values, and those fall back to text order among
     * themselves instead of interleaving with the addresses.
     */
    public static function addressSortKey(?string $address): string
    {
        $address = trim((string) $address);
        $packed = $address === '' ? false : @inet_pton($address);

        if ($packed === false) {
            return '9' . $address;
        }

        return (strlen($packed) === 4 ? '4' : '6') . bin2hex($packed);
    }

    public static function tableRowCompare(string $val1, string $val2, string $value_type = '', bool $is_int = true): string
    {
        //<td class="td-nowrap plus-td">+303<span class="data-type">MBps</span></td>
        $str = '<td class="td-nowrap ';
        $value_append = '<span class="data-type">' . $value_type . '</span>';
        if ($is_int) {
            $val1 = (int)$val1;
            $val2 = (int)$val2;
        } else {
            // Float mode: int-casting 4.50 vs 4.90 rendered "0 equal".
            $val1 = (float)$val1;
            $val2 = (float)$val2;
        }
        $diff = $is_int ? abs($val1 - $val2) : round(abs($val1 - $val2), 2);
        if ($val1 > $val2) {//val1 is greater than val2
            $result = '+' . $diff;
            if (!empty($value_type)) {
                $result = '+' . $diff . $value_append;

            }
            $str .= 'plus-td">' . $result . '</td>';
        } elseif ($val1 < $val2) {//val1 is less than val2
            $result = '-' . $diff;
            if (!empty($value_type)) {
                $result = '-' . $diff . $value_append;
            }
            $str .= 'neg-td">' . $result . '</td>';
        } else {//Equal
            $result = 0;
            if (!empty($value_type)) {
                $result = '0' . $value_append;
            }
            $str .= 'equal-td">' . $result . '</td>';
        }
        return $str;
    }
}
