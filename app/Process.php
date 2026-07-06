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

    public static function tableRowCompare(string $val1, string $val2, string $value_type = '', bool $is_int = true): string
    {
        //<td class="td-nowrap plus-td">+303<span class="data-type">MBps</span></td>
        $str = '<td class="td-nowrap ';
        $value_append = '<span class="data-type">' . $value_type . '</span>';
        if ($is_int) {
            $val1 = (int)$val1;
            $val2 = (int)$val2;
        }
        if ($val1 > $val2) {//val1 is greater than val2
            $result = '+' . ($val1 - $val2);
            if (!empty($value_type)) {
                $result = '+' . ($val1 - $val2) . $value_append;

            }
            $str .= 'plus-td">' . $result . '</td>';
        } elseif ($val1 < $val2) {//val1 is less than val2
            $result = '-' . ($val2 - $val1);
            if (!empty($value_type)) {
                $result = '-' . ($val2 - $val1) . $value_append;
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
