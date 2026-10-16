<?php

/** @return list<array{session: int, due: float}> */
function qualificationSchedule(int $sessions, int $seconds, int $period): array
{
    if ($sessions < 1 || $period < 1 || $seconds < $period || $seconds % $period !== 0) {
        throw new InvalidArgumentException('Schedule requires positive sessions and complete periods.');
    }
    $schedule = [];
    for ($cycle = 0; $cycle < intdiv($seconds, $period); $cycle++) {
        for ($session = 0; $session < $sessions; $session++) {
            $jitter = (crc32("107:$cycle:$session") % 10000) / 10000;
            $schedule[] = ['session' => $session, 'due' => $cycle * $period + ($session + 0.1 + 0.8 * $jitter) * $period / $sessions];
        }
    }

    return $schedule;
}
