<?php

// protects=core domains stay unaware of integration implementations (ADR 0013, #159); fails_when=a core domain imports an integration; no_claim=delivery correctness.
arch('core domains never reference integration interfaces (ADR 0013)')
    ->expect(['App\Domains\Reservations', 'App\Domains\Guests', 'App\Domains\FrontDesk', 'App\Domains\Billing'])
    ->not->toUse(['App\Domains\ZanReporting', 'App\Domains\FiscalReceipting', 'App\Domains\Distribution', 'App\Support\Outbox']);

test('every reservation lifecycle action records an event (ADR 0013)', function () {
    $actions = glob(dirname(__DIR__, 2).'/app/Domains/{Reservations,FrontDesk,Billing}/Actions/*.php', GLOB_BRACE);
    $lifecycle = '/^(CreateReservation|AssignRoom|ModifyReservation|AmendReservation|CancelReservation|VoidReservation|TerminateReservation|ConfirmNoShow|CheckIn|ExtendStay|DepartEarly|MoveRoom|AddGuest|ChangeNightlyPrice|CheckOut|PostExtraCharge)$/';
    $checked = [];
    foreach ($actions as $path) {
        if (! preg_match($lifecycle, basename($path, '.php'))) {
            continue;
        }
        $checked[] = $path;
        $source = file_get_contents($path);
        expect(str_contains($source, 'RecordLifecycleEvent::class') || str_contains($source, 'TerminateReservation::class'))->toBeTrue();
    }
    expect($checked)->not->toBeEmpty();
});
