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
    {//In seconds
        return ($this->endTime - $this->startTime) * 100;
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
}
