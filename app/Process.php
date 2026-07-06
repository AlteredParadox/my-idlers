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
        if ($term === 1) {
            return "p/m";
        } elseif ($term === 2) {
            return "p/qtr";
        } elseif ($term === 3) {
            return "p/hy";
        } elseif ($term === 4) {
            return "p/y";
        } elseif ($term === 5) {
            return "p/2y";
        } elseif ($term === 6) {
            return "p/3y";
        } elseif ($term === 7) {
            return "once";
        } else {
            return "unknown";
        }
    }
}
