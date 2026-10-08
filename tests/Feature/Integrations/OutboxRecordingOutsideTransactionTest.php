<?php

namespace Tests\Feature\Integrations;

use App\Support\Outbox\Outbox;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class OutboxRecordingOutsideTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_integration_recording_refuses_calls_outside_an_active_transaction(): void
    {
        [, , $property] = \organizationWithOwner();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Outbox messages must be recorded inside the caller transaction.');

        \inTenant($property, fn () => app(Outbox::class)->record(
            'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
            'reservation.changed', ['guest_count' => 2], 'reservation-1',
        ));
    }
}
