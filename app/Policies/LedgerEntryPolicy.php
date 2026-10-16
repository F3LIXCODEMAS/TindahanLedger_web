<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LedgerEntryPolicy
{
    /**
     * A ledger entry inherits its store from the customer that owns it.
     */
    public function view(User $user, LedgerEntry $ledgerEntry): Response
    {
        return $user->can('view', $ledgerEntry->customer)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
