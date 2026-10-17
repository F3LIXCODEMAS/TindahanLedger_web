<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLatePenaltyPolicyRequest;
use App\Models\StorePenaltyPolicy;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LatePenaltyPolicyController extends Controller
{
    public function edit(Request $request): View
    {
        $policy = $request->user()->store->penaltyPolicy;

        return view('settings.late-penalties', [
            'policy' => $policy,
            'fee' => $policy ? $policy->fee() : Money::fromCents((int) config('penalties.default_fee_cents')),
            'graceDays' => $policy?->grace_days ?? (int) config('penalties.default_grace_days'),
            'maxFee' => Money::fromCents((int) config('penalties.max_fee_cents')),
            'maxGraceDays' => (int) config('penalties.max_grace_days'),
        ]);
    }

    /**
     * Save the store's policy. Each change raises the version; credits keep
     * the values they were accepted under, so existing penalties and credits
     * are never changed by a new policy.
     */
    public function update(UpdateLatePenaltyPolicyRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $store = $request->user()->store;

        DB::transaction(function () use ($store, $validated, $request): void {
            $policy = StorePenaltyPolicy::query()->where('store_id', $store->getKey())->lockForUpdate()->first()
                ?? (new StorePenaltyPolicy)->forceFill(['store_id' => $store->getKey(), 'version' => 0]);

            $policy->fill([
                'is_enabled' => $validated['is_enabled'],
                'fee_cents' => Money::toCents((string) $validated['fee']),
                'grace_days' => (int) $validated['grace_days'],
                'effective_from' => $validated['effective_from'],
                'disclosure_text' => $validated['disclosure_text'],
            ])->forceFill([
                'version' => $policy->version + 1,
                'updated_by_user_id' => $request->user()->getKey(),
            ])->save();
        });

        return redirect()->route('late-penalties.edit')->with('status', $validated['is_enabled']
            ? 'Late payment policy saved. It applies to new credits with a deadline from the effective date; existing credits are not affected.'
            : 'Late payment penalties are turned off for new credits. Credits already accepted under the policy keep their terms.');
    }
}
