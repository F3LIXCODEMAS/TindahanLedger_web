<?php

use App\Http\Controllers\Portal\PortalAccessController;
use App\Models\Customer;
use App\Models\CustomerAccessCredential;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerAccessService;
use App\Services\CustomerLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

function issuePortalToken(Customer $customer): string
{
    return app(CustomerAccessService::class)->issue($customer)->plainToken();
}

function scanPortalToken(string $token): TestResponse
{
    return test()->post(route('portal.access'), ['token' => $token]);
}

describe('signing in with a QR code', function () {
    it('opens the read-only ledger with only the QR token and no password', function () {
        $customer = Customer::factory()->create(['full_name' => 'Maria Santos']);

        scanPortalToken(issuePortalToken($customer))->assertRedirect(route('portal.dashboard'));

        expect(Auth::guard('customer')->id())->toBe($customer->id);
        $this->assertGuest('web');
        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Hello, Maria Santos')
            ->assertSee($customer->store->store_name);
    });

    it('rotates the session id when the QR code is accepted', function () {
        $token = issuePortalToken(Customer::factory()->create());
        $this->get(route('portal.entry'));
        $sessionIdBeforeScan = session()->getId();

        scanPortalToken($token);

        expect(session()->getId())->not->toBe($sessionIdBeforeScan);
    });

    it('rejects unknown, malformed, and revoked tokens with the same message', function (Closure $makeToken) {
        $customer = Customer::factory()->create(['full_name' => 'Hidden Customer']);

        scanPortalToken($makeToken($customer))
            ->assertRedirect(route('portal.entry'))
            ->assertSessionHas('portal_message', 'This QR code is not valid. It may have been replaced or revoked. Please ask your store for a new one.');

        expect(Auth::guard('customer')->check())->toBeFalse();
        $this->get(route('portal.entry'))->assertDontSee('Hidden Customer');
    })->with([
        'unknown token' => fn () => CustomerAccessService::generateToken(),
        'malformed token' => fn () => "' OR 1=1 --",
        'customer id instead of token' => fn (Customer $customer) => (string) $customer->id,
        'revoked token' => function (Customer $customer): string {
            $token = issuePortalToken($customer);
            app(CustomerAccessService::class)->revoke($customer);

            return $token;
        },
    ]);

    it('refuses to open the portal for a suspended store', function () {
        $customer = Customer::factory()->for(Store::factory()->suspended())->create();

        scanPortalToken(issuePortalToken($customer))
            ->assertRedirect(route('portal.entry'))
            ->assertSessionHas('portal_message', fn (string $message) => str_contains($message, 'temporarily unavailable'));

        expect(Auth::guard('customer')->check())->toBeFalse();
    });

    it('limits repeated QR scan attempts', function () {
        RateLimiter::clear('customer-portal-access');

        foreach (range(1, 10) as $attempt) {
            scanPortalToken(CustomerAccessService::generateToken())->assertRedirect();
        }

        scanPortalToken(CustomerAccessService::generateToken())->assertTooManyRequests();
    });

    it('serves portal pages without caching or a referrer', function () {
        $this->get(route('portal.entry'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('data-portal-access-form', false);
    });
});

describe('portal sessions', function () {
    it('ends the session after the configured inactivity timeout', function () {
        config(['customer_portal.idle_minutes' => 15]);
        scanPortalToken(issuePortalToken(Customer::factory()->create()));

        $this->travel(14)->minutes();
        $this->get(route('portal.dashboard'))->assertOk();

        $this->travel(16)->minutes();
        $this->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.entry'))
            ->assertSessionHas('portal_message', 'Your session ended after 15 minutes of inactivity. Scan your QR code to continue.');

        expect(Auth::guard('customer')->check())->toBeFalse();
    });

    it('ends the session when the customer exits', function () {
        scanPortalToken(issuePortalToken(Customer::factory()->create()));

        $this->post(route('portal.exit'))->assertRedirect(route('portal.entry'));

        expect(Auth::guard('customer')->check())->toBeFalse();
        expect(session()->has(PortalAccessController::SESSION_CREDENTIAL_KEY))->toBeFalse();
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.entry'));
    });

    it('ends open sessions once the QR code is revoked or replaced', function (string $ownerAction) {
        $customer = Customer::factory()->create();
        scanPortalToken(issuePortalToken($customer));
        $this->get(route('portal.dashboard'))->assertOk();

        $ownerAction === 'revoke'
            ? app(CustomerAccessService::class)->revoke($customer)
            : app(CustomerAccessService::class)->issue($customer);

        $this->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.entry'))
            ->assertSessionHas('portal_message', fn (string $message) => str_contains($message, 'no longer valid'));
        expect(Auth::guard('customer')->check())->toBeFalse();
    })->with(['revoke', 'replace']);

    it('ends open sessions once the store is suspended', function () {
        $customer = Customer::factory()->create();
        scanPortalToken(issuePortalToken($customer));

        $customer->store->forceFill(['status' => Store::STATUS_SUSPENDED])->save();

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.entry'));
    });

    it('requires a QR session for the ledger page', function () {
        $this->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.entry'))
            ->assertSessionHas('portal_message', 'Scan your QR code to view your ledger.');
    });

    it('rejects a customer guard session that was not opened with a QR credential', function () {
        $customer = Customer::factory()->create();
        CustomerAccessCredential::factory()->for($customer)->create();

        $this->actingAs($customer, 'customer')
            ->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.entry'));
    });
});

describe('isolation and permissions', function () {
    it('shows only the signed-in customer even when another customer id is supplied', function () {
        $customer = Customer::factory()->create(['full_name' => 'Signed In Customer']);
        $otherCustomer = Customer::factory()->for($customer->store)->create(['full_name' => 'Other Customer']);
        scanPortalToken(issuePortalToken($customer));

        $this->get(route('portal.dashboard', ['customer_id' => $otherCustomer->id, 'store_id' => 999]))
            ->assertOk()
            ->assertSee('Signed In Customer')
            ->assertDontSee('Other Customer');
    });

    it('does not let a customer session open owner or admin pages', function (string $routeName) {
        $customer = Customer::factory()->create();
        scanPortalToken(issuePortalToken($customer));

        $this->get(route($routeName))->assertRedirect(route('login'));
    })->with(['dashboard', 'customers.index', 'admin.dashboard', 'admin.stores.index']);

    it('does not let a customer session open an owner customer page by id', function () {
        $customer = Customer::factory()->create();
        scanPortalToken(issuePortalToken($customer));

        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
        $this->get(route('customers.access-code.show', $customer))->assertRedirect(route('login'));
    });

    it('does not let an owner session open the customer portal', function () {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)->get(route('portal.dashboard'))->assertRedirect(route('portal.entry'));
    });

    it('offers no way to change records from the portal', function (string $method) {
        scanPortalToken(issuePortalToken(Customer::factory()->create()));

        $this->call($method, route('portal.dashboard'))->assertMethodNotAllowed();
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);
});

describe('financial figures', function () {
    it('matches the owner ledger for a partially paid overdue customer', function () {
        $this->travelTo('2026-10-16 09:00:00');
        $customer = Customer::factory()->create(['credit_limit' => '5000.00']);
        $overdueEntry = LedgerEntry::factory()->for($customer)->create([
            'amount' => '1000.00', 'transaction_date' => '2026-09-01', 'repayment_deadline' => '2026-10-01',
        ]);
        LedgerEntry::factory()->for($customer)->create([
            'amount' => '300.00', 'transaction_date' => '2026-10-10', 'repayment_deadline' => '2026-11-01',
        ]);
        Payment::factory()->for($customer)->for($overdueEntry)->create(['amount' => '400.00', 'payment_date' => '2026-10-05']);
        $ownerLedger = app(CustomerLedgerService::class)->forCustomer($customer);
        scanPortalToken(issuePortalToken($customer));

        $response = $this->get(route('portal.dashboard'))->assertOk();

        expect($response->viewData('ledger')['outstanding_balance'])->toBe($ownerLedger['outstanding_balance'])->toBe('900.00');
        expect($response->viewData('ledger')['available_credit'])->toBe('4100.00');
        $response->assertSee('₱900.00')
            ->assertSee('Overdue: 1 credit is past the repayment deadline.')
            ->assertSee('Partially paid · ₱600.00 left')
            ->assertSee('Due Oct 1, 2026')
            ->assertSee('Balance ₱900.00');
    });

    it('shows a fully paid customer as ₱0.00 and not overdue', function () {
        $this->travelTo('2026-10-16 09:00:00');
        $customer = Customer::factory()->create();
        $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '250.00', 'repayment_deadline' => '2026-10-01']);
        Payment::factory()->for($customer)->for($entry)->create(['amount' => '250.00']);
        scanPortalToken(issuePortalToken($customer));

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('₱0.00')
            ->assertSee('Fully paid. You have no outstanding balance.')
            ->assertDontSee('Overdue');
    });

    it('shows an empty history for a customer without transactions', function () {
        $customer = Customer::factory()->create(['credit_limit' => '3000.00']);
        scanPortalToken(issuePortalToken($customer));

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('No credits or payments have been recorded yet.')
            ->assertSee('₱3000.00');
    });

    it('paginates long transaction histories newest first', function () {
        $customer = Customer::factory()->create(['credit_limit' => '100000.00']);
        foreach (range(1, 20) as $day) {
            LedgerEntry::factory()->for($customer)->create([
                'amount' => $day.'.00',
                'transaction_date' => sprintf('2026-09-%02d', $day),
            ]);
        }
        scanPortalToken(issuePortalToken($customer));

        $firstPage = $this->get(route('portal.dashboard'))->assertOk();
        $secondPage = $this->get(route('portal.dashboard', ['page' => 2]))->assertOk();

        expect($firstPage->viewData('transactions')->first()['amount'])->toBe('20.00');
        expect($firstPage->viewData('transactions'))->toHaveCount(15);
        expect($secondPage->viewData('transactions'))->toHaveCount(5);
    });

    it('does not change balances, points, or records when the portal is viewed', function () {
        $customer = Customer::factory()->create();
        $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '500.00']);
        Payment::factory()->for($customer)->for($entry)->create(['amount' => '100.00']);
        $countsBefore = [LedgerEntry::query()->count(), Payment::query()->count(), $customer->fresh()->current_balance];
        scanPortalToken(issuePortalToken($customer));

        $this->get(route('portal.dashboard'))->assertOk();

        expect([LedgerEntry::query()->count(), Payment::query()->count(), $customer->fresh()->current_balance])->toBe($countsBefore);
    });
});
