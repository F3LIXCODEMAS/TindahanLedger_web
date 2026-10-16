<?php

use App\Models\Customer;
use App\Models\CustomerAccessCredential;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerAccessService;
use Illuminate\Database\QueryException;

function newCustomerPayload(array $overrides = []): array
{
    return [
        'full_name' => 'Maria Santos',
        'contact_number' => '09171234567',
        'email' => 'maria@example.test',
        'residential_landmark' => 'Near the market',
        'credit_limit' => '500.00',
        ...$overrides,
    ];
}

describe('issuing credentials', function () {
    it('issues one active QR credential when an owner adds a customer', function () {
        $owner = User::factory()->withStore()->create();

        $this->actingAs($owner)->post(route('customers.store'), newCustomerPayload())
            ->assertRedirect(route('customers.index'));

        $customer = Customer::query()->sole();
        $credential = CustomerAccessCredential::query()->sole();
        expect($credential->customer_id)->toBe($customer->id);
        expect($credential->isActive())->toBeTrue();
        expect($credential->created_by_user_id)->toBe($owner->id);
        expect($customer->store_id)->toBe($owner->store->id);
    });

    it('does not keep the customer when issuing the QR credential fails', function () {
        $owner = User::factory()->withStore()->create();
        $this->mock(CustomerAccessService::class)
            ->shouldReceive('issue')
            ->once()
            ->andThrow(new RuntimeException('QR issuing failed'));

        $this->actingAs($owner)->post(route('customers.store'), newCustomerPayload());

        expect(Customer::query()->count())->toBe(0);
        expect(CustomerAccessCredential::query()->count())->toBe(0);
    });

    it('stores only a hash and an encrypted copy of a random opaque token', function () {
        $credential = app(CustomerAccessService::class)->issue(Customer::factory()->create());
        $token = $credential->plainToken();

        expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/');
        expect($credential->token_hash)->toBe(hash('sha256', $token));
        expect($credential->getRawOriginal('encrypted_token'))->not->toContain($token);
        expect($credential->toArray())->not->toHaveKeys(['token_hash', 'encrypted_token']);
    });

    it('gives different customers different tokens', function () {
        $service = app(CustomerAccessService::class);

        $tokens = Customer::factory()->count(5)->create()
            ->map(fn (Customer $customer) => $service->issue($customer)->plainToken());

        expect($tokens->unique())->toHaveCount(5);
    });

    it('encodes only the portal URL and token, never customer details or ids', function () {
        $customer = Customer::factory()->create(['full_name' => 'Maria Santos', 'email' => 'maria@example.test']);
        $service = app(CustomerAccessService::class);
        $credential = $service->issue($customer);

        expect($service->portalUrl($credential))->toBe(route('portal.entry').'#'.$credential->plainToken());
        expect($service->qrSvg($credential))
            ->toContain('<svg')
            ->not->toContain('Maria')
            ->not->toContain('maria@example.test');
    });

    it('allows only one active credential per customer at the database level', function () {
        $customer = Customer::factory()->create();
        CustomerAccessCredential::factory()->for($customer)->create();

        expect(fn () => CustomerAccessCredential::factory()->for($customer)->create())
            ->toThrow(QueryException::class);
    });

    it('issues codes only to customers without an active one from the artisan command', function () {
        $withCode = Customer::factory()->create();
        $existingCredential = app(CustomerAccessService::class)->issue($withCode);
        Customer::factory()->count(2)->create();

        $this->artisan('customers:issue-access-codes')
            ->expectsOutput('Issued 2 customer access codes.')
            ->assertSuccessful();

        expect(CustomerAccessCredential::query()->where('status', 'active')->count())->toBe(3);
        expect($existingCredential->fresh()->isActive())->toBeTrue();
    });
});

describe('owner management', function () {
    it('shows the owner their customer QR code with print and download options', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create();
        app(CustomerAccessService::class)->issue($customer);

        $this->actingAs($store->owner)
            ->get(route('customers.access-code.show', $customer))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('<svg', false)
            ->assertSee('Print access card')
            ->assertSee('Download QR (SVG)');

        $this->get(route('customers.access-code.download', $customer))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertDownload();

        $this->get(route('customers.access-code.card', $customer))
            ->assertOk()
            ->assertSee($store->store_name)
            ->assertSee('Keep this card private.');
    });

    it('responds as not found for every QR action on another store customer', function (string $method, string $routeName) {
        $owner = User::factory()->withStore()->create();
        $otherCustomer = Customer::factory()->create();
        $otherCredential = app(CustomerAccessService::class)->issue($otherCustomer);

        $this->actingAs($owner)
            ->call($method, route($routeName, $otherCustomer))
            ->assertNotFound();

        expect($otherCredential->fresh()->isActive())->toBeTrue();
        expect(CustomerAccessCredential::query()->count())->toBe(1);
    })->with([
        'view' => ['GET', 'customers.access-code.show'],
        'download' => ['GET', 'customers.access-code.download'],
        'print card' => ['GET', 'customers.access-code.card'],
        'replace' => ['POST', 'customers.access-code.store'],
        'revoke' => ['DELETE', 'customers.access-code.destroy'],
    ]);

    it('forbids the super admin from managing customer QR codes', function () {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('customers.access-code.show', $customer))
            ->assertForbidden();
    });

    it('replaces a QR code so the previous token stops resolving', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create();
        $service = app(CustomerAccessService::class);
        $previousToken = $service->issue($customer)->plainToken();

        $this->actingAs($store->owner)
            ->post(route('customers.access-code.store', $customer))
            ->assertRedirect(route('customers.access-code.show', $customer));

        $newCredential = $customer->fresh()->activeAccessCredential;
        expect($service->resolve($previousToken))->toBeNull();
        expect($service->resolve($newCredential->plainToken())?->is($newCredential))->toBeTrue();
        expect(CustomerAccessCredential::query()->where('status', 'revoked')->sole())
            ->encrypted_token->toBeNull()
            ->revoked_by_user_id->toBe($store->owner_user_id);
    });

    it('revokes a QR code without touching customer or financial records', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['full_name' => 'Kept Customer']);
        $entry = LedgerEntry::factory()->for($customer)->create(['amount' => '500.00']);
        Payment::factory()->for($customer)->for($entry)->create(['amount' => '200.00']);
        $service = app(CustomerAccessService::class);
        $token = $service->issue($customer)->plainToken();

        $this->actingAs($store->owner)
            ->delete(route('customers.access-code.destroy', $customer))
            ->assertRedirect(route('customers.access-code.show', $customer));

        expect($service->resolve($token))->toBeNull();
        expect($customer->fresh()->activeAccessCredential)->toBeNull();
        expect($customer->fresh()->full_name)->toBe('Kept Customer');
        expect($entry->fresh()->amount)->toBe('500.00');
        expect(Payment::query()->sum('amount'))->toEqual(200);
    });

    it('lets a suspended store revoke but not create QR codes', function () {
        $store = Store::factory()->suspended()->create();
        $customer = Customer::factory()->for($store)->create();
        app(CustomerAccessService::class)->issue($customer);

        $this->actingAs($store->owner)
            ->post(route('customers.access-code.store', $customer))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('store');
        expect(CustomerAccessCredential::query()->count())->toBe(1);

        $this->delete(route('customers.access-code.destroy', $customer));
        expect($customer->fresh()->activeAccessCredential)->toBeNull();
    });

    it('offers to create a code for a customer without one', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create();

        $this->actingAs($store->owner)
            ->get(route('customers.access-code.show', $customer))
            ->assertOk()
            ->assertSee('does not have an active QR code')
            ->assertSee('Create QR code');

        $this->post(route('customers.access-code.store', $customer));

        expect($customer->fresh()->activeAccessCredential)->not->toBeNull();
    });
});
