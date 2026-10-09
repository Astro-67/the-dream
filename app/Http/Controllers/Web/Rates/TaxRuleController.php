<?php

namespace App\Http\Controllers\Web\Rates;

use App\Domains\Rates\Actions\ChangeTaxRule;
use App\Domains\Rates\Actions\CreateTaxRule;
use App\Domains\Rates\Actions\EndTaxRule;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\TaxRuleRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaxRuleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', TaxRule::class);
        $property = Tenancy::property();

        return Inertia::render('rates/taxes', [
            'taxRules' => TaxRule::query()->orderBy('name')->orderBy('effective_from')->get()->map(fn (TaxRule $rule) => [
                ...$rule->only(['id', 'name', 'rate', 'amount', 'inclusive']),
                'kind' => $rule->kind->value, 'currency' => $rule->currency?->value, 'applies_to' => $rule->applies_to->value, 'rounding' => $rule->rounding->value,
                'effective_from' => $rule->effective_from->format('Y-m-d'), 'effective_to' => $rule->effective_to?->format('Y-m-d'),
            ]),
            'currencies' => $property ? JurisdictionRules::allowedCurrencies($property) : [],
            'canManage' => Gate::allows('create', TaxRule::class),
        ]);
    }

    public function store(TaxRuleRequest $request, CreateTaxRule $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $create->handle($actor, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule added.')]);

        return redirect()->route('tax-rules.index');
    }

    public function change(TaxRuleRequest $request, TaxRule $taxRule, ChangeTaxRule $change): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $change->handle($actor, $taxRule, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':tax changed.', ['tax' => $taxRule->name])]);

        return redirect()->route('tax-rules.index');
    }

    public function end(Request $request, TaxRule $taxRule, EndTaxRule $end): RedirectResponse
    {
        Gate::authorize('update', $taxRule);
        /** @var array{effective_to: string} $valid */
        $valid = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        /** @var User $actor */
        $actor = $request->user();
        $end->handle($actor, $taxRule, $valid['effective_to']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':tax ends on :date.', ['tax' => $taxRule->name, 'date' => $valid['effective_to']])]);

        return redirect()->route('tax-rules.index');
    }
}
