<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DrainOutboxMessage;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([DrainOutboxMessage::class]);
    Http::preventStrayRequests();
});

test('document register folio and reprints annotate every correction state without changing evidence', function (string $type, string $delivery, ?string $decision) {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, $type);
    $before = DB::table('issued_documents')->orderBy('id')->get()->toJson();
    $postings = DB::table('postings')->get()->toJson();
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id]);
    config(['services.vfms.live_url' => 'https://vfms-live.example/vfms/api/']);
    // Provisional section 2.12/2.13 shapes, not observed provider conformance.
    Http::fake([
        'https://vfms-live.example/vfms/error-management/new' => Http::response(
            $delivery === 'unknown' ? [] : ['status' => $delivery === 'rejected' ? 400 : 200],
        ),
        'https://vfms-live.example/vfms/error-management/errors' => Http::response([[
            'old_receipt_number' => 'OLD-232', 'new_receipt_number' => 'NEW-232', 'unitId' => 232,
            'status' => ucfirst($decision ?? 'Pending'),
        ]]),
    ]);
    submitCorrection($this, $tenant, $old, $new)->assertRedirect();
    $correction = inTenant($tenant->property, fn () => DocumentCorrection::query()->sole());
    if ($delivery !== 'pending') {
        inTenant($tenant->property, fn () => app(DeliveryEngine::class)->deliver($correction->outbox_message_id));
    }
    if ($decision !== null) {
        $this->post(route('document-corrections.refresh', $correction), ['_property' => $tenant->property->id])->assertRedirect();
    }
    $expected = $decision ?? ($delivery === 'delivered' ? 'awaiting' : null);
    $this->get(route('reservations.fiscal-report', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', $type === 'checkout_report' ? 'live' : 'waiting')
            ->where("documentCorrections.{$old->id}.annotations.0.delivery", $delivery)
            ->where("documentCorrections.{$old->id}.annotations.0.decision", $expected)
            ->where("documentCorrections.{$old->id}.annotations.0.number", 'NEW-232')
            ->where("documentCorrections.{$old->id}.annotations.0.label", $decision === 'approved' ? 'Replaced by' : 'Proposed to be replaced by')
            ->where("documentCorrections.{$new->id}.annotations.0.label", $decision === 'approved' ? 'Replacement for' : 'Proposed replacement for')
            ->where("documentCorrections.{$old->id}.submitUrl", null));
    $this->get(route('receipts.index', ['tab' => $type === 'checkout_report' ? 'checkout_reports' : 'zra_receipts']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('correctionRefresh.refreshing', false)
        ->where('documents.data', function ($rows) use ($old, $expected) {
            expect(collect($rows)->firstWhere('id', $old->id)['annotations'][0]['decision'])->toBe($expected);

            return true;
        }));
    $this->get(route('reservations.show', $stay))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('correctionRefresh.refreshing', false)
        ->where("documentCorrections.{$old->id}.annotations.0.decision", $expected));
    $this->get(route('reservations.fiscal-report.print', [$stay, 'document' => $old->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('documents', 1)
            ->where('documents.0.annotations.0.delivery', $delivery)
            ->where('documents.0.annotations.0.decision', $expected)
            ->where('documents.0.annotations.0.number', 'NEW-232')
            ->where('documents.0.annotations.0.label', $decision === 'approved' ? 'Replaced by' : 'Proposed to be replaced by'));
    expect(DB::table('issued_documents')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('postings')->get()->toJson())->toBe($postings);
})->with(['services_receipt', 'checkout_report'])->with([
    ['pending', null], ['unknown', null], ['rejected', null], ['delivered', null],
    ['delivered', 'pending'], ['delivered', 'verification'], ['delivered', 'approved'], ['delivered', 'rejected'],
]);

test('a correction without an already issued replacement shows its prerequisite and never sends traffic', function () {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant);
    inTenant($tenant->property, fn () => $new->delete());

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.replacements", [])
            ->where("documentCorrections.{$old->id}.refusal", null));
    Http::assertNothingSent();
});

test('a Sandbox issued document offers only Sandbox replacements under ZRA\'s test business unit', function () {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, 'services_receipt', 'sandbox');

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.refusal", null)
            ->where("documentCorrections.{$old->id}.replacements.0.id", $new->id)
            ->where("documentCorrections.{$old->id}.business.unitId", 5940));
    Http::assertNothingSent();
});

test('a document filed in the other mode is refused without hiding its evidence', function () {
    $tenant = twoTenants()->a;
    [$stay, $old] = correctionDocuments($tenant);
    inTenant($tenant->property, function () use ($tenant) {
        VfmsCredential::for($tenant->property)
            ->forceFill(['mode' => VfmsMode::Sandbox])->save();
    });

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.document.number", 'OLD-232')
            ->where("documentCorrections.{$old->id}.refusal", 'Both documents must have been filed in the property\'s current VFMS mode.')
            ->where("documentCorrections.{$old->id}.replacements", []));
    Http::assertNothingSent();
});

test('the issued document opens correction with its own number and only verified replacements', function (string $type) {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, $type);

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.document.number", 'OLD-232')
            ->where("documentCorrections.{$old->id}.document.type", $type)
            ->where("documentCorrections.{$old->id}.submitUrl", route('issued-documents.corrections.store', $old))
            ->where("documentCorrections.{$old->id}.replacements.0.number", 'NEW-232')
            ->where("documentCorrections.{$old->id}.replacements.0.id", $new->id)
            ->where("documentCorrections.{$old->id}.business.name", 'Accepted business')
            ->where("documentCorrections.{$old->id}.business.email", 'desk@example.test')
            ->where($type === 'services_receipt' ? 'serviceReceipts' : 'guestReports', function ($rows) {
                expect(collect($rows)->pluck('number')->all())->toBe(['OLD-232', 'NEW-232']);

                return true;
            }))
        ->assertDontSee('PRIVATE-GUEST-ID')
        ->assertDontSee('P1234567');
    Http::assertNothingSent();
})->with(['services_receipt', 'checkout_report']);

test('non managers can read documents but cannot receive correction controls', function () {
    $tenant = twoTenants()->a;
    [$stay, $old] = correctionDocuments($tenant);

    $this->actingAs($tenant->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.submitUrl", null)
            ->where("documentCorrections.{$old->id}.replacements", []));
});

test('a different guests issued report is never offered as a replacement', function () {
    $tenant = twoTenants()->a;
    [$stay, $old, $new] = correctionDocuments($tenant, 'checkout_report');
    inTenant($tenant->property, function () use ($new) {
        $message = OutboxMessage::query()->findOrFail($new->getAttribute('outbox_message_id'));
        $payload = $message->payload;
        $payload['body']['documentNumberList'] = [['documentNumber' => 'OTHER-PRIVATE-IDENTITY']];
        $message->forceFill(['payload' => $payload])->save();
        $attempt = $message->attempts()->sole();
        $response = json_decode($attempt->response_body, true);
        $response['body'][0]['guest']['documentNo'] = 'OTHER-PRIVATE-IDENTITY';
        $message->attempts()->whereKey($attempt->id)->update(['response_body' => Crypt::encryptString(json_encode($response))]);
    });

    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.fiscal-report', $stay))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("documentCorrections.{$old->id}.replacements", []))
        ->assertDontSee('OTHER-PRIVATE-IDENTITY');
    Http::assertNothingSent();
});
