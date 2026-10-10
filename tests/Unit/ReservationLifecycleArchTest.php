<?php

arch('core domains never reference integration interfaces (ADR 0013)')
    ->expect(['App\Domains\Reservations', 'App\Domains\Guests', 'App\Domains\FrontDesk', 'App\Domains\Billing'])
    ->not->toUse(['App\Domains\ZanReporting', 'App\Domains\FiscalReceipting', 'App\Domains\Distribution', 'App\Support\Outbox']);

test('every reservation lifecycle action records an event (ADR 0013)', function () {
    $actions = glob(dirname(__DIR__, 2).'/app/Domains/{Reservations,FrontDesk,Billing}/Actions/*.php', GLOB_BRACE);
    $lifecycle = '/^(CreateReservation|ModifyReservation|AmendReservation|CancelReservation|VoidReservation|ConfirmNoShow|CheckIn|MoveRoom|AddGuest|ChangeNightlyPrice|CheckOut)$/';
    $checked = [];
    foreach ($actions as $path) {
        if (! preg_match($lifecycle, basename($path, '.php'))) {
            continue;
        }
        $checked[] = $path;
        expect(file_get_contents($path))->toContain('RecordLifecycleEvent::class');
    }
    expect($checked)->not->toBeEmpty();
});
