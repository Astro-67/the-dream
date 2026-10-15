<?php

use App\Domains\Reports\GroupReports;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function qualificationDate(): CarbonImmutable
{
    return CarbonImmutable::parse(DB::table('properties')->value('business_date') ?? CarbonImmutable::now('Africa/Dar_es_Salaam'), 'Africa/Dar_es_Salaam')->startOfDay();
}

function verifySeed(): void
{
    $today = qualificationDate();
    $properties = ORGANIZATIONS * PROPERTIES_PER_ORGANIZATION;
    $coverage = DB::table('inventory_records')->whereBetween('date', [$today->subDays(30)->toDateString(), $today->toDateString()])
        ->selectRaw('property_id, COUNT(DISTINCT date) as dates')->groupBy('property_id')->get();
    if ($coverage->count() !== $properties || $coverage->contains(fn ($row) => (int) $row->dates !== 31)) {
        throw new RuntimeException('Every qualifying property requires inventory on all 31 measured dates.');
    }
    foreach (['reservation_price_lines' => 'date', 'postings' => 'business_date'] as $table => $column) {
        $dates = DB::table($table)->whereBetween($column, [$today->subDays(30)->toDateString(), $today->toDateString()])
            ->selectRaw("property_id, COUNT(DISTINCT {$column}) as dates")->groupBy('property_id')->get();
        if ($dates->count() !== $properties || $dates->contains(fn ($row) => (int) $row->dates !== 31)) {
            throw new RuntimeException("Every property requires {$table} on all measured dates.");
        }
    }
    $counts = collect(['organizations', 'properties', 'rooms', 'users', 'sessions', 'inventory_records', 'reservations', 'reservation_price_lines', 'folios', 'postings', 'reservation_lifecycle_events'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
    foreach (['organizations' => ORGANIZATIONS, 'properties' => $properties, 'rooms' => $properties * 100,
        'users' => ORGANIZATIONS * 10, 'sessions' => ORGANIZATIONS * 10, 'inventory_records' => $properties * 120,
        'reservations' => $properties * 1824, 'folios' => $properties * 1824,
        'reservation_price_lines' => $properties * 5230, 'postings' => $properties * 3271] as $table => $expected) {
        if ($counts[$table] !== $expected) {
            throw new RuntimeException("Capacity mismatch: {$table}: {$counts[$table]} != {$expected}");
        }
    }
    $types = DB::table('reservation_lifecycle_events')->selectRaw('type, COUNT(*) as events')->groupBy('type')->pluck('events', 'type')->map(fn ($count) => (int) $count)->all();
    foreach (['created' => 1823, 'checked_in' => 894, 'checked_out' => 814, 'extended' => 54, 'departed_early' => 32, 'cancelled' => 105] as $type => $expected) {
        if (($types[$type] ?? 0) !== $expected * $properties) {
            throw new RuntimeException("Lifecycle distribution mismatch: {$type}");
        }
    }
    $boundaries = DB::table('reservation_lifecycle_events')->selectRaw("reservation_id, MAX(type = 'checked_in') as arrived, MAX(type = 'checked_out') as departed")->groupBy('reservation_id');
    $missing = DB::table('reservations as r')->leftJoinSub($boundaries, 'h', 'h.reservation_id', '=', 'r.id')
        ->whereIn('r.status', ['checked_in', 'checked_out'])->whereRaw("(COALESCE(h.arrived, 0) = 0 OR (r.status = 'checked_out' AND COALESCE(h.departed, 0) = 0))")
        ->selectRaw('r.property_id, r.status, COUNT(*) as missing')->groupBy('r.property_id', 'r.status')->get();
    if ($missing->count() !== $properties || $missing->contains(fn ($row) => $row->status !== 'checked_out' || (int) $row->missing !== 2)) {
        throw new RuntimeException('Only the two explicit legacy/partial stays per property may lack boundaries.');
    }
    $invalid = DB::table('reservation_lifecycle_events as e')->join('reservations as r', 'r.id', '=', 'e.reservation_id')
        ->where(fn ($query) => $query->whereColumn('e.organization_id', '!=', 'r.organization_id')->orWhereColumn('e.property_id', '!=', 'r.property_id')
            ->orWhere('e.test', true)->orWhereRaw('JSON_LENGTH(e.snapshot) < 10')->orWhereRaw('e.business_date > ?', [$today->toDateString()])
            ->orWhere('e.occurred_at', '>', now()))->count();
    $confirmedBoundaries = DB::table('reservation_lifecycle_events as e')->join('reservations as r', 'r.id', '=', 'e.reservation_id')
        ->where('r.status', 'confirmed')->whereIn('e.type', ['checked_in', 'checked_out'])->count();
    if ($invalid !== 0 || $confirmedBoundaries !== 0) {
        throw new RuntimeException('Invalid lifecycle identity, snapshot, date or confirmed-stay boundary.');
    }
    $sequences = DB::table('reservation_lifecycle_events')->select('reservation_id')->groupBy('reservation_id')
        ->havingRaw('MIN(sequence) != 1 OR MAX(sequence) != COUNT(*)')->exists();
    if ($sequences) {
        throw new RuntimeException('Lifecycle sequences must start at one and increase without gaps.');
    }
    $organization = DB::table('organizations')->orderBy('id')->value('id');
    $fixtures = Tenancy::within(TenantContext::forOrganization($organization), function () use ($today): array {
        $properties = Property::query()->real()->get();
        $property = $properties->firstOrFail();
        $fixtures = [];
        foreach (['1 night' => [$today->toDateString(), [41, 38, 79], 0],
            '31 nights' => [$today->subDays(30)->toDateString(), [893, 813, 2491], 2],
            'delayed December 12' => ['2025-12-12', [0, 0, 1], 0],
            'delayed December 13' => ['2025-12-13', [0, 1, 0], 0]] as $label => [$from, $expected, $missing]) {
            $to = str_starts_with($label, 'delayed') ? $from : $today->toDateString();
            $actual = app(PropertyOperations::class)->forProperty($property, $from, $to);
            $group = app(GroupReports::class)->forProperties($properties, $from, $to)['operations'];
            foreach (['property' => [$actual, 1], 'group' => [$group, 5]] as $scope => [$report, $factor]) {
                $observed = array_map(fn ($key) => array_sum(array_column($report['daily'], $key)), ['arrivals', 'departures', 'inHouse']);
                if ($observed !== array_map(fn ($count) => $count * $factor, $expected) || $report['historyUnavailable'] !== $missing * $factor) {
                    throw new RuntimeException("{$scope} {$label} fixture mismatch: ".json_encode([$observed, $report['historyUnavailable']]));
                }
                $fixtures[$label][$scope] = ['expected' => array_map(fn ($count) => $count * $factor, $expected), 'actual' => $observed, 'historyUnavailable' => $report['historyUnavailable']];
            }
        }
        $future = app(PropertyOperations::class)->forProperty($property, $today->addDay()->toDateString(), $today->addDays(30)->toDateString());
        foreach (['arrivals', 'departures', 'inHouse'] as $key) {
            if (array_sum(array_column($future['daily'], $key)) !== 0) {
                throw new RuntimeException('Future plans must never become recorded operational history.');
            }
        }
        if (array_sum(array_column($future['daily'], 'plannedArrivals')) < 1 || array_sum(array_column($future['daily'], 'plannedDepartures')) < 1) {
            throw new RuntimeException('Future planned arrivals and departures must be populated.');
        }
        $fixtures['futurePlansSeparate'] = true;

        return $fixtures;
    });
    echo json_encode(['rows' => $counts, 'eventTypes' => $types, 'measuredDatesPerProperty' => 31,
        'reservationStatuses' => DB::table('reservations')->selectRaw('status, COUNT(*) as reservations')->groupBy('status')->pluck('reservations', 'status'),
        'missingBoundaries' => $missing->sum('missing'), 'missingFraction' => $missing->sum('missing') / $counts['reservations'],
        'invalidEvents' => $invalid, 'confirmedBoundaries' => $confirmedBoundaries, 'fixtures' => $fixtures], JSON_PRETTY_PRINT)."\n";
}
