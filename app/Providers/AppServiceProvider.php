<?php

namespace App\Providers;

use App\Domains\Billing\PreventArchivingWithOpenBalances;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\VfmsTranslator;
use App\Domains\Reports\Policies\ReportPolicy;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Contracts\LifecycleTranslator;
use App\Domains\Reservations\PreventArchivingWithFutureReservations;
use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Support\Jobs\CallQueuedTenantHandler;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\PreventArchivingWithUnresolvedMessages;
use App\Support\Tenancy\Origin;
use App\Support\Tenancy\TenancyManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenancyManager::class);
        $this->app->singleton(Origin::class);
        $this->app->singleton(DeliveryAdapters::class, function () {
            $adapters = new DeliveryAdapters;
            $adapters->register(VfmsAdapter::INTEGRATION, $this->app->make(VfmsAdapter::class));

            return $adapters;
        });
        $this->app->tag([VfmsTranslator::class], LifecycleTranslator::TAG);
        $this->app->tag([PreventArchivingWithUnresolvedMessages::class, PreventArchivingWithFutureReservations::class, PreventArchivingWithOpenBalances::class], PreArchiveCheck::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::policy(PropertyKpis::class, ReportPolicy::class);

        // Queued work runs in the tenant context it was dispatched from.
        Queue::createPayloadUsing(CallQueuedTenantHandler::capture(...));

        // Entering the platform context is logged with the running job or command.
        $this->app->make(Origin::class)->listen($this->app->make('events'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
