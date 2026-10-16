<?php

require __DIR__.'/schedule.php';

$schedule = qualificationSchedule(1000, 300, 60);
if (count($schedule) !== 5000 || count(array_unique(array_column($schedule, 'session'))) !== 1000) {
    throw new RuntimeException('All 1,000 sessions must issue five requests.');
}
foreach ($schedule as $index => $request) {
    if ($request['due'] < 0 || $request['due'] >= 300 || ($index > 0 && $request['due'] <= $schedule[$index - 1]['due'])) {
        throw new RuntimeException('Schedule must remain ordered inside the steady window.');
    }
}
foreach (array_count_values(array_column($schedule, 'session')) as $count) {
    if ($count !== 5) {
        throw new RuntimeException('Session cadence differs from once per minute.');
    }
}
try {
    qualificationSchedule(1000, 299, 60);
    throw new RuntimeException('Partial periods must be rejected.');
} catch (InvalidArgumentException) {
}
echo "schedule: 5,000 ordered arrivals, 1,000 distinct sessions, five complete minutes OK\n";
