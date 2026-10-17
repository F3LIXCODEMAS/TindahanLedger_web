<?php

namespace App\Http\Controllers;

use App\Exceptions\StoreSuspended;
use App\Exceptions\SukiPointRedemptionRejected;
use App\Http\Requests\RedeemSukiPointsRequest;
use App\Models\Customer;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class SukiPointRedemptionController extends Controller
{
    /**
     * Redeem Suki Points as a non-cash credit against the customer's debt.
     * The points value and resulting balance are always calculated on the
     * server by LedgerService::redeemSukiPoints.
     */
    public function store(RedeemSukiPointsRequest $request, Customer $customer, LedgerService $ledgerService): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $redemption = $ledgerService->redeemSukiPoints(
                $customer,
                (int) $validated['points'],
                $validated['idempotency_key'],
                $request->user(),
            );
        } catch (SukiPointRedemptionRejected|StoreSuspended $exception) {
            return back()->withInput()->withErrors(['points' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['points' => 'Unable to redeem the points. Please try again.']);
        }

        return redirect()->route('customers.show', $customer)->with('status', sprintf(
            '%s Suki %s redeemed for ₱%s. New balance: ₱%s.',
            number_format($redemption['points_redeemed']),
            $redemption['points_redeemed'] === 1 ? 'Point' : 'Points',
            $redemption['redeemed_amount'],
            $redemption['remaining_balance'],
        ));
    }
}
