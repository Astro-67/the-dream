<?php

/**
 * Qualification harness for group reports (#107): PRD capacity workload, p95 < 800 ms.
 * Run through scripts/qualify-group-reports.sh, which owns the disposable HTTP/MySQL stack.
 *
 *   php scripts/qualify-group-reports.php seed
 *   php scripts/qualify-group-reports.php query [repeats]
 *   php scripts/qualify-group-reports.php http <base-url> <requests> <concurrency>...
 *   php scripts/qualify-group-reports.php steady http://nginx <seconds> <distinct-sessions>
 *
 * Refuses any database but ticket107_qualification, so it never touches dev data.
 */

use App\Domains\Reports\GroupReports;
use App\Domains\Tenancy\Actions\AddOrganizationMember;
use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/qualification/schedule.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error."\n");
    exit(1);
});
if (getenv('QUALIFY_DISPOSABLE') !== '1' || config('database.connections.mysql.host') !== 'mysql' || DB::connection()->getDatabaseName() !== 'ticket107_qualification') {
    throw new RuntimeException('This harness requires its disposable ticket107_qualification database.');
}

// QUALIFY_ORGANIZATIONS lowers the count for a smoke run only; anything below 100 is not the qualification workload.
define('ORGANIZATIONS', (int) (getenv('QUALIFY_ORGANIZATIONS') ?: 100));
const PROPERTIES_PER_ORGANIZATION = 5;
const ROOMS_PER_PROPERTY = 100;
const STAFF_PER_ORGANIZATION = 10;
const WINDOW_DAYS = 21; // nights on each side of the business date carrying inventory and bookings

$mode = $argv[1] ?? '';
match ($mode) {
    'seed' => seed(),
    'query' => measureQueries((int) ($argv[2] ?? 5)),
    'http' => measureHttp($argv[2] ?? '', (int) ($argv[3] ?? 200), array_map('intval', array_slice($argv, 4)) ?: [1]),
    'steady' => measureSteady($argv[2] ?? '', (int) ($argv[3] ?? 300), (int) ($argv[4] ?? 1000)),
    default => throw new InvalidArgumentException('Mode must be seed, query, http or steady.'),
};

function ulid(): string
{
    return strtolower((string) Str::ulid());
}

/** @param list<array<string, mixed>> $rows */
function insertRows(string $table, array &$rows, bool $flush = false): void
{
    if ($rows !== [] && ($flush || count($rows) >= 1000)) {
        DB::table($table)->insert($rows);
        $rows = [];
    }
}

function seed(): void
{
    $started = microtime(true);
    $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfDay();
    $now = now();
    $session = [];

    for ($o = 1; $o <= ORGANIZATIONS; $o++) {
        $owner = User::factory()->create(['name' => "Owner {$o}", 'email' => "owner{$o}@qualification.test"]);
        $organization = app(CreateOrganization::class)->handle($owner, OrganizationSetup::fromArray([
            'organization_name' => "Qualification Group {$o}", 'country' => 'TZ', 'property_name' => "Group {$o} Property 1",
            'property_type' => 'hotel', 'address_line' => 'Road 1', 'city' => 'Zanzibar City', 'region' => 'Mjini Magharibi',
            'postal_code' => '71101', 'phone' => '+255 24 223 0000', 'email' => "p{$o}@qualification.test",
            'timezone' => 'Africa/Dar_es_Salaam', 'currency' => 'USD', 'jurisdiction_profile' => 'zanzibar', 'zra_hotel_code' => "ZH-Q{$o}",
        ]));
        $first = Tenancy::within(TenantContext::forOrganization($organization), fn () => Property::query()->sole());
        DB::table('properties')->where('id', $first->id)->update(['status' => 'live']);
        $propertyIds = [$first->id];
        for ($p = 2; $p <= PROPERTIES_PER_ORGANIZATION; $p++) {
            $id = ulid();
            DB::table('properties')->insert([
                ...collect((array) DB::table('properties')->find($first->id))->except(['id', 'name', 'currency', 'zra_hotel_code'])->all(),
                'id' => $id, 'name' => "Group {$o} Property {$p}", 'currency' => $p % 2 === 0 ? 'TZS' : 'USD', 'zra_hotel_code' => null,
            ]);
            $propertyIds[] = $id;
        }
        // One group operations manager, and property staff with reports access spread over the properties.
        Tenancy::within(TenantContext::forOrganization($organization), function () use ($o, $propertyIds, &$session, $organization, $owner) {
            $session[] = [$owner->id, $organization->id, $propertyIds[0], 'owner'];
            for ($s = 1; $s < STAFF_PER_ORGANIZATION; $s++) {
                $role = $s === 1 ? OrganizationRole::GroupOperationsManager : [OrganizationRole::PropertyManager, OrganizationRole::Finance][$s % 2];
                $user = User::factory()->create(['name' => "Staff {$o}-{$s}", 'email' => "staff{$o}-{$s}@qualification.test"]);
                $property = $propertyIds[$s % PROPERTIES_PER_ORGANIZATION];
                app(AddOrganizationMember::class)->handle($user, $role->mayHaveAllProperties() ? MembershipGrant::allProperties($role) : MembershipGrant::properties($role, [$property]));
                $session[] = [$user->id, $organization->id, $role->mayHaveAllProperties() ? $propertyIds[0] : $property, $role->value];
            }
        });
        foreach ($propertyIds as $index => $propertyId) {
            seedProperty($organization->id, $propertyId, $index % 2 === 1 ? 'TZS' : 'USD', $today, $now);
        }
        fwrite(STDERR, sprintf("organization %d/%d seeded (%.0fs)\n", $o, ORGANIZATIONS, microtime(true) - $started));
    }

    $rows = array_map(fn (array $s) => ['id' => Str::random(40), 'user_id' => $s[0], 'ip_address' => '127.0.0.1', 'user_agent' => 'qualification',
        'payload' => base64_encode(json_encode([
            '_token' => Str::random(40), 'login_web_'.sha1(SessionGuard::class) => $s[0],
            'tenancy' => ['organization_id' => $s[1], 'property_id' => $s[2]], '_flash' => ['old' => [], 'new' => []],
        ], JSON_THROW_ON_ERROR)), 'last_activity' => time() + 86400, 'role' => $s[3]], $session);
    DB::table('sessions')->insert(array_map(fn (array $row) => collect($row)->except('role')->all(), $rows));
    file_put_contents(dirname(__DIR__).'/.scratch/qualification-sessions.json', json_encode(array_map(fn (array $row) => ['id' => $row['id'], 'role' => $row['role']], $rows), JSON_PRETTY_PRINT));
    chmod(dirname(__DIR__).'/.scratch/qualification-sessions.json', 0600);

    $counts = collect(['organizations', 'properties', 'rooms', 'users', 'sessions', 'inventory_records', 'reservations', 'reservation_price_lines', 'folios', 'postings'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
    echo json_encode(['mode' => 'seed', 'organizations' => ORGANIZATIONS, 'seconds' => round(microtime(true) - $started), 'rows' => $counts], JSON_PRETTY_PRINT)."\n";
}

function seedProperty(string $organizationId, string $propertyId, string $currency, CarbonImmutable $today, mixed $now): void
{
    $base = ['organization_id' => $organizationId, 'property_id' => $propertyId, 'created_at' => $now, 'updated_at' => $now];
    $planId = ulid();
    DB::table('rate_plans')->insert([...$base, 'id' => $planId, 'name' => 'Room only', 'code' => 'RO', 'currency' => $currency]);
    $bookerId = ulid();
    DB::table('bookers')->insert([...$base, 'id' => $bookerId, 'name' => 'Qualification Booker']);
    $price = $currency === 'TZS' ? 25000000 : 12000;
    $statuses = $inventory = $reservations = $lines = $folios = $postings = [];
    $number = 100;

    foreach (['STD' => 60, 'DLX' => 40] as $code => $count) {
        $typeId = ulid();
        DB::table('room_types')->insert([...$base, 'id' => $typeId, 'name' => $code, 'code' => $code, 'base_occupancy' => 2, 'max_adults' => 2, 'max_children' => 1, 'max_occupancy' => 3]);
        $sold = [];
        for ($r = 0; $r < $count; $r++) {
            $roomId = ulid();
            // Each room is inserted before its reservations, which reference it.
            DB::table('rooms')->insert([...$base, 'id' => $roomId, 'room_type_id' => $typeId, 'number' => (string) ++$number]);
            $statuses[] = [...$base, 'id' => ulid(), 'room_id' => $roomId, 'status' => ['dirty', 'clean', 'occupied', 'inspection_required', 'unavailable'][$r % 5]];
            // Back-to-back stays of 1-5 nights with gaps, about two thirds occupied.
            $night = -WINDOW_DAYS + ($r % 3);
            while ($night < WINDOW_DAYS) {
                $length = 1 + (($r + $night) % 5 + 5) % 5;
                $arrival = $today->addDays($night);
                $departure = $arrival->addDays($length);
                $status = match (true) {
                    ($r + $night) % 17 === 0 => 'cancelled',
                    $departure <= $today => 'checked_out',
                    $arrival <= $today => 'checked_in',
                    default => 'confirmed',
                };
                $reservationId = ulid();
                $folioId = ulid();
                $reservations[] = [...$base, 'id' => $reservationId, 'reservation_number' => 'Q-'.substr($reservationId, -10), 'room_type_id' => $typeId,
                    'rate_plan_id' => $planId, 'booker_id' => $bookerId, 'arrival' => $arrival->toDateString(), 'departure' => $departure->toDateString(),
                    'adults' => 2, 'children' => 0, 'source' => 'phone', 'status' => $status, 'room_id' => $status === 'confirmed' ? null : $roomId];
                $folios[] = [...$base, 'id' => $folioId, 'reservation_id' => $reservationId, 'currency' => $currency];
                $charged = 0;
                for ($n = 0; $n < $length; $n++) {
                    $date = $arrival->addDays($n);
                    $lines[] = [...$base, 'id' => ulid(), 'reservation_id' => $reservationId, 'rate_plan_id' => $planId, 'date' => $date->toDateString(), 'amount' => $price, 'currency' => $currency];
                    if ($status !== 'cancelled') {
                        $sold[$date->toDateString()] = ($sold[$date->toDateString()] ?? 0) + 1;
                    }
                    if (in_array($status, ['checked_in', 'checked_out'], true) && $date < $today) {
                        $postings[] = [...posting($base, $folioId, 'charge', 'room', $price, $currency, $date, null, "room-{$n}"), 'reservation_id' => $reservationId];
                        $charged += $price;
                    }
                }
                if ($charged > 0) {
                    // Most departed guests settle in full; every tenth leaves an open balance.
                    $paid = $status === 'checked_out' && ($r + $night) % 10 !== 0 ? $charged : intdiv($charged, 2);
                    $postings[] = posting($base, $folioId, 'payment', 'payment', -$paid, $currency, $departure->min($today), ['card', 'cash', 'mobile_money'][$r % 3], 'payment');
                }
                if (count($lines) >= 1000) {
                    // Parents first, so foreign keys hold.
                    insertRows('reservations', $reservations, true);
                    insertRows('folios', $folios, true);
                    insertRows('reservation_price_lines', $lines, true);
                    insertRows('postings', $postings, true);
                }
                $night += $length + ($r % 2);
            }
        }
        for ($d = -WINDOW_DAYS; $d < WINDOW_DAYS; $d++) {
            $date = $today->addDays($d)->toDateString();
            $inventory[] = [...$base, 'id' => ulid(), 'room_type_id' => $typeId, 'date' => $date, 'sellable_count' => $count - 1, 'sold_count' => min($count - 1, $sold[$date] ?? 0)];
        }
    }
    foreach (['reservations' => $reservations, 'housekeeping_room_statuses' => $statuses, 'inventory_records' => $inventory,
        'folios' => $folios, 'reservation_price_lines' => $lines, 'postings' => $postings] as $table => $batch) {
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}

/** @param array<string, mixed> $base */
function posting(array $base, string $folioId, string $type, string $category, int $amount, string $currency, CarbonImmutable $date, ?string $method, string $key): array
{
    return [...collect($base)->except(['created_at', 'updated_at'])->all(), 'id' => ulid(), 'folio_id' => $folioId, 'type' => $type, 'category' => $category,
        'quantity' => 1, 'unit_price' => abs($amount), 'net' => abs($amount), 'amount' => $amount, 'currency' => $currency, 'tax_lines' => '[]',
        'tax_rule_ids' => '[]', 'idempotency_key' => $key, 'business_date' => $date->toDateString(), 'posted_at' => $date->setTime(12, 0), 'method' => $method,
        'reservation_id' => null];
}

/** @param list<float> $milliseconds */
function summary(array $milliseconds): array
{
    sort($milliseconds);
    $at = fn (float $q) => round($milliseconds[(int) max(0, ceil($q * count($milliseconds)) - 1)], 1);

    return ['samples' => count($milliseconds), 'p50' => $at(0.5), 'p95' => $at(0.95), 'max' => round(end($milliseconds), 1)];
}

/** In-process GroupReports for every organization's real properties: query and PHP time, no HTTP. */
function measureQueries(int $repeats): void
{
    $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->toDateString();
    $ranges = ['1 night' => [$today, $today], '31 nights' => [CarbonImmutable::parse($today)->subDays(30)->toDateString(), $today]];
    $organizations = DB::table('organizations')->orderBy('id')->pluck('id');
    $results = [];
    foreach ($ranges as $label => [$from, $to]) {
        $times = $queries = [];
        for ($repeat = 0; $repeat < $repeats; $repeat++) {
            foreach ($organizations as $organizationId) {
                Tenancy::within(TenantContext::forOrganization($organizationId), function () use ($from, $to, &$times, &$queries) {
                    $properties = Property::query()->real()->get();
                    $count = 0;
                    DB::listen(function () use (&$count) {
                        $count++;
                    });
                    $started = hrtime(true);
                    app(GroupReports::class)->forProperties($properties, $from, $to);
                    $times[] = (hrtime(true) - $started) / 1e6;
                    $queries[] = $count;
                });
                DB::flushQueryLog();
                app('events')->forget('Illuminate\Database\Events\QueryExecuted');
            }
        }
        $results[$label] = summary($times) + ['queriesPerReport' => max($queries)];
    }
    echo json_encode(['mode' => 'query (in-process, serial, no HTTP)', 'propertiesPerReport' => PROPERTIES_PER_ORGANIZATION, 'results' => $results], JSON_PRETTY_PRINT)."\n";
}

/**
 * GET /reports/group over HTTP with the seeded staff sessions, $requests per concurrency level.
 * Concurrency 1 is the serial measurement.
 *
 * @param  list<int>  $levels
 */
function measureHttp(string $baseUrl, int $requests, array $levels): void
{
    if (! in_array($baseUrl, ['http://nginx'], true) || $requests < 1 || min($levels) < 1) {
        throw new InvalidArgumentException('HTTP measurements require the private qualification nginx and positive counts.');
    }
    $sessions = collect(json_decode((string) file_get_contents(dirname(__DIR__).'/.scratch/qualification-sessions.json'), true))
        ->whereIn('role', ['owner', 'group_operations_manager', 'property_manager', 'finance'])->values();
    $name = (string) config('session.cookie');
    $key = app('encrypter')->getKey();
    $cookies = $sessions->map(fn (array $s) => $name.'='.urlencode(encrypt(CookieValuePrefix::create($name, $key).$s['id'], false)))->all();
    $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->toDateString();
    $ranges = ['1 night' => "from={$today}&to={$today}", '31 nights' => 'from='.CarbonImmutable::parse($today)->subDays(30)->toDateString()."&to={$today}"];
    $results = [];
    foreach ($ranges as $label => $query) {
        foreach ($levels as $concurrency) {
            $multi = curl_multi_init();
            $times = [];
            $statuses = [];
            $sent = 0;
            $started = microtime(true);
            $add = function () use (&$sent, $multi, $cookies, $baseUrl, $query) {
                $handle = curl_init(rtrim($baseUrl, '/').'/reports/group?'.$query);
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                    CURLOPT_HTTPHEADER => ['Cookie: '.$cookies[$sent % count($cookies)], 'Accept: text/html']]);
                curl_multi_add_handle($multi, $handle);
                $sent++;
            };
            for ($i = 0; $i < min($concurrency, $requests); $i++) {
                $add();
            }
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.05);
                while ($done = curl_multi_info_read($multi)) {
                    $handle = $done['handle'];
                    $times[] = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
                    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    // Only a rendered group report counts as success, not a redirect or an error page.
                    if ($status === 200 && ! str_contains((string) curl_multi_getcontent($handle), 'component&quot;:&quot;reports\\/group') && ! str_contains((string) curl_multi_getcontent($handle), 'component":"reports\\/group')) {
                        $status = 'not a group report';
                    }
                    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                    curl_multi_remove_handle($multi, $handle);
                    if ($sent < $requests) {
                        $add();
                    }
                }
            } while ($running > 0 || $sent < $requests || count($times) < $requests);
            $results[$label]["concurrency {$concurrency}"] = summary($times) + [
                'statuses' => $statuses, 'requestsPerSecond' => round($requests / (microtime(true) - $started), 1),
            ];
        }
    }
    echo json_encode(['mode' => 'http GET reports.group (full HTML page load)', 'eligibleSessions' => count($cookies), 'distinctSessions' => min($requests, count($cookies)), 'results' => $results], JSON_PRETTY_PRINT)."\n";
    foreach ($results as $ranges) {
        foreach ($ranges as $result) {
            if (array_keys($result['statuses']) !== [200]) {
                throw new RuntimeException('Burst contained failed report requests.');
            }
        }
    }
}

function measureSteady(string $baseUrl, int $seconds, int $expectedSessions): void
{
    if ($baseUrl !== 'http://nginx' || ! (($seconds === 2 && $expectedSessions === 20) || ($seconds >= 300 && $expectedSessions === 1000))) {
        throw new InvalidArgumentException('Use private nginx: smoke 2s/20 sessions, qualification >=300s/1,000 sessions.');
    }
    $sessions = json_decode((string) file_get_contents(dirname(__DIR__).'/.scratch/qualification-sessions.json'), true, flags: JSON_THROW_ON_ERROR);
    if (count($sessions) !== $expectedSessions || count(array_unique(array_column($sessions, 'id'))) !== $expectedSessions) {
        throw new RuntimeException('Distinct seeded session count does not match workload.');
    }
    if ($expectedSessions === 1000) {
        foreach (['organizations' => 100, 'properties' => 500, 'rooms' => 50000, 'reservations' => 640000, 'folios' => 640000, 'postings' => 1139000] as $table => $expected) {
            if (DB::table($table)->count() !== $expected) {
                throw new RuntimeException("Capacity mismatch: {$table}");
            }
        }
    }
    $name = (string) config('session.cookie');
    $key = app('encrypter')->getKey();
    $cookies = array_map(fn (array $session) => $name.'='.urlencode(encrypt(CookieValuePrefix::create($name, $key).$session['id'], false)), $sessions);
    $schedule = qualificationSchedule($expectedSessions, $seconds, $expectedSessions === 20 ? 2 : 60);
    $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->toDateString();
    $results = [];
    foreach (['1 night' => $today, '31 nights' => CarbonImmutable::parse($today)->subDays(30)->toDateString()] as $label => $from) {
        $multi = curl_multi_init();
        $active = $times = $queues = $wire = $statuses = $issued = [];
        $next = $peak = 0;
        $started = hrtime(true) / 1e9;
        do {
            $elapsed = hrtime(true) / 1e9 - $started;
            while ($next < count($schedule) && $schedule[$next]['due'] <= $elapsed && count($active) < 256) {
                $request = $schedule[$next++];
                $handle = curl_init($baseUrl."/reports/group?from={$from}&to={$today}");
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => ['Cookie: '.$cookies[$request['session']], 'Accept: text/html'],
                ]);
                $active[spl_object_id($handle)] = $request;
                $queues[] = (hrtime(true) / 1e9 - $started - $request['due']) * 1000;
                $issued[$request['session']] = true;
                curl_multi_add_handle($multi, $handle);
            }
            $peak = max($peak, count($active));
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) {
                throw new RuntimeException('curl_multi_exec failed: '.curl_multi_strerror($code));
            }
            while ($done = curl_multi_info_read($multi)) {
                $handle = $done['handle'];
                $request = $active[spl_object_id($handle)];
                $times[] = (hrtime(true) / 1e9 - $started - $request['due']) * 1000;
                $wire[] = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
                $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = (string) curl_multi_getcontent($handle);
                if ($done['result'] !== CURLE_OK) {
                    $status = 'curl:'.$done['result'];
                } elseif ($status === 200 && ! str_contains($body, 'component&quot;:&quot;reports\\/group') && ! str_contains($body, 'component":"reports\\/group')) {
                    $status = 'not a group report';
                }
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                unset($active[spl_object_id($handle)]);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            $untilNext = $next < count($schedule) ? max(0.001, $schedule[$next]['due'] - (hrtime(true) / 1e9 - $started)) : 0.01;
            if ($active !== []) {
                if (curl_multi_select($multi, min(0.01, $untilNext)) === -1) {
                    usleep(1000);
                }
            } elseif ($next < count($schedule)) {
                usleep((int) (min(0.01, $untilNext) * 1e6));
            }
        } while ($next < count($schedule) || $active !== [] || hrtime(true) / 1e9 - $started < $seconds);
        curl_multi_close($multi);
        $results[$label] = summary($times) + [
            'schedulingQueueMs' => summary($queues), 'wireMs' => summary($wire),
            'statuses' => $statuses, 'distinctSessionsIssued' => count($issued),
            'offeredRequestsPerSecond' => count($schedule) / $seconds, 'steadySeconds' => $seconds,
            'elapsedSecondsIncludingDrain' => round(hrtime(true) / 1e9 - $started, 3), 'peakInFlight' => $peak,
        ];
    }
    echo json_encode(['mode' => 'open-loop, latency from scheduled arrival through completion INCLUDING errors and client queue', 'qualification' => $expectedSessions === 1000, 'results' => $results], JSON_PRETTY_PRINT)."\n";
    foreach ($results as $result) {
        if (array_keys($result['statuses']) !== [200] || $result['distinctSessionsIssued'] !== $expectedSessions || ($expectedSessions === 1000 && $result['p95'] >= 800)) {
            throw new RuntimeException('Steady workload failed response/session/p95 acceptance; see JSON evidence.');
        }
    }
}
