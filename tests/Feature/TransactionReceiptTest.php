<?php

use App\Mail\CreditEntryReceipt;
use App\Mail\PaymentReceipt;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\StoreEmailSetting;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MailDeliveryService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function expectReceiptDelivery(
    User $owner,
    string $recipient,
    string $expectedClass,
    string $notificationType,
    ?Mailable &$capturedMailable,
): void {
    $deliveryService = Mockery::mock(MailDeliveryService::class);
    $deliveryService->shouldReceive('sendAsOwner')
        ->once()
        ->with(
            $owner,
            Mockery::on(function (Mailable $mailable) use ($expectedClass, &$capturedMailable): bool {
                if (! $mailable instanceof $expectedClass) {
                    return false;
                }

                $capturedMailable = $mailable;

                return true;
            }),
            $recipient,
            $notificationType,
        )
        ->andReturn(true);
    app()->instance(MailDeliveryService::class, $deliveryService);
}

it('sends an itemized credit receipt with its saved amount and resulting balance', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create(['store_name' => 'Aling Nena Store']);
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    $this->travelTo(Carbon::parse('2026-10-15 09:30:00'));
    $sentReceipt = null;
    expectReceiptDelivery($owner, 'customer@example.test', CreditEntryReceipt::class, 'credit_receipt', $sentReceipt);

    $response = $this->actingAs($owner)->post('/credits', [
        'customer_id' => $customer->id,
        'amount' => '250.00',
        'itemized_list' => 'rice, coffee',
        'note' => 'Purchase for Friday',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $entry = LedgerEntry::query()->sole();
    $response->assertRedirect(route('credits.confirmation', $entry));
    expect($entry->running_balance)->toBe('250.00');

    expect($sentReceipt)->toBeInstanceOf(CreditEntryReceipt::class);
    $sentReceipt->assertSeeInHtml('Credit Entry Receipt');
    $sentReceipt->assertSeeInHtml('₱250.00');
    $sentReceipt->assertSeeInHtml('October 15, 2026 9:30 AM');
    $sentReceipt->assertSeeInHtml('rice');
    $sentReceipt->assertSeeInHtml('coffee');
    $sentReceipt->assertSeeInHtml('Purchase for Friday');
    expect($sentReceipt->envelope()->subject)->toBe('TindahanLedger – Credit Entry Receipt');
});

it('sends a credit receipt without an empty items section when items were not provided', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    $sentReceipt = null;
    expectReceiptDelivery($owner, 'customer@example.test', CreditEntryReceipt::class, 'credit_receipt', $sentReceipt);

    $this->actingAs($owner)->post('/credits', [
        'customer_id' => $customer->id,
        'amount' => '75.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect($sentReceipt)->toBeInstanceOf(CreditEntryReceipt::class);
    $sentReceipt->assertSeeInHtml('₱75.00');
    $sentReceipt->assertDontSeeInHtml('Items');
});

it('sends partial and full payment receipts with the resulting balance', function (
    string $paymentAmount,
    string $remainingBalance,
    bool $debtCycleCompleted,
) {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create(['store_name' => 'Aling Nena Store']);
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    app(LedgerService::class)->recordCredit($customer, ['amount' => '300.00']);
    $this->travelTo(Carbon::parse('2026-10-15 09:30:00'));
    $sentReceipt = null;
    expectReceiptDelivery($owner, 'customer@example.test', PaymentReceipt::class, 'payment_receipt', $sentReceipt);

    $this->actingAs($owner)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => $paymentAmount,
        'note' => 'Cash received',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect($customer->fresh()->current_balance)->toBe($remainingBalance);
    expect($sentReceipt)->toBeInstanceOf(PaymentReceipt::class);
    $sentReceipt->assertSeeInHtml('Payment Receipt');
    $sentReceipt->assertSeeInHtml('October 15, 2026 9:30 AM');
    $sentReceipt->assertSeeInHtml('₱'.$paymentAmount);
    $sentReceipt->assertSeeInHtml('₱'.$remainingBalance);

    if ($debtCycleCompleted) {
        $sentReceipt->assertSeeInHtml('Fully paid');
        $sentReceipt->assertSeeInHtml('₱0.00');
    } else {
        $sentReceipt->assertDontSeeInHtml('Fully paid');
    }

    $sentReceipt->assertSeeInHtml('Cash received');
    expect($sentReceipt->envelope()->subject)->toBe('TindahanLedger – Payment Receipt');
})->with([
    'partial payment' => ['100.00', '200.00', false],
    'full payment' => ['300.00', '0.00', true],
]);

it('skips receipts for customers with missing or legacy invalid email addresses', function (?string $email) {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => $email,
        'credit_limit' => '1000.00',
    ]);
    $this->actingAs($owner)->post('/credits', [
        'customer_id' => $customer->id,
        'amount' => '50.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expect($customer->fresh()->current_balance)->toBe('50.00');
})->with([
    'missing' => [null],
    'legacy placeholder' => ['legacy-customer@example.invalid'],
]);

it('does not send duplicate credit receipts when an idempotent request is repeated', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    $sentReceipt = null;
    expectReceiptDelivery($owner, 'customer@example.test', CreditEntryReceipt::class, 'credit_receipt', $sentReceipt);
    $payload = [
        'customer_id' => $customer->id,
        'amount' => '50.00',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($owner)->post('/credits', $payload)->assertSessionHasNoErrors();
    $this->post('/credits', $payload)->assertSessionHasNoErrors();

    $this->assertDatabaseCount('ledger_entries', 1);
    expect($sentReceipt)->toBeInstanceOf(CreditEntryReceipt::class);
});

it('does not send duplicate payment receipts when an idempotent request is repeated', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    app(LedgerService::class)->recordCredit($customer, ['amount' => '300.00']);
    $sentReceipt = null;
    expectReceiptDelivery($owner, 'customer@example.test', PaymentReceipt::class, 'payment_receipt', $sentReceipt);
    $payload = [
        'customer_id' => $customer->id,
        'amount' => '100.00',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($owner)->post('/payments', $payload)->assertSessionHasNoErrors();
    $this->post('/payments', $payload)->assertSessionHasNoErrors();

    $this->assertDatabaseCount('payments', 1);
    expect($sentReceipt)->toBeInstanceOf(PaymentReceipt::class);
});

it('keeps a saved credit when the receipt mail transport fails', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => 'customer@example.test',
        'credit_limit' => '1000.00',
    ]);
    $deliveryService = Mockery::mock(MailDeliveryService::class);
    $deliveryService->shouldReceive('sendAsOwner')->once()->andReturn(false);
    app()->instance(MailDeliveryService::class, $deliveryService);

    $this->actingAs($owner)->post('/credits', [
        'customer_id' => $customer->id,
        'amount' => '50.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('ledger_entries', 1);
    expect($customer->fresh()->current_balance)->toBe('50.00');
});

it('renders an optional credit receipt note only when provided', function () {
    $customer = Customer::factory()->make(['full_name' => 'Juan Customer']);
    $entry = LedgerEntry::factory()->make([
        'amount' => '20.00',
        'running_balance' => '20.00',
        'itemized_list' => null,
        'note' => null,
    ]);

    $email = new CreditEntryReceipt($customer, $entry, 'Store Name');
    $email->assertSeeInHtml('Juan Customer');
    $email->assertDontSeeInHtml('Note:');
});

it('uses the store identity and saved Gmail account for credit receipts', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create([
        'store_name' => 'Aling Nena Store',
        'sender_email' => 'nena@gmail.com',
        'smtp_app_password' => 'private-app-password',
    ]);
    $customer = Customer::factory()->create(['email' => 'customer@example.test']);
    $entry = app(LedgerService::class)->recordCredit($customer, ['amount' => '25.00']);
    $sentReceipt = null;
    $mailer = Mockery::mock();
    $mailer->shouldReceive('to')->once()->with('customer@example.test')->andReturnSelf();
    $mailer->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (CreditEntryReceipt $email) use (&$sentReceipt): bool {
            $sentReceipt = $email;

            return true;
        }));
    Mail::shouldReceive('build')->once()->andReturn($mailer);

    $sent = app(MailDeliveryService::class)->sendAsOwner(
        $owner,
        new CreditEntryReceipt($customer, $entry),
        $customer->email,
        'credit_receipt',
    );

    expect($sent)->toBeTrue();
    expect($sentReceipt)->toBeInstanceOf(CreditEntryReceipt::class);
    expect($sentReceipt->storeName)->toBe('Aling Nena Store');
    expect($sentReceipt->from)->toBe([[
        'name' => 'Aling Nena Store',
        'address' => 'nena@gmail.com',
    ]]);
    expect($sentReceipt->content()->with['appName'])->toBe('Aling Nena Store');
});

it('skips missing or malformed recipients before creating an owner mailer', function (?string $recipient) {
    $owner = User::factory()->create();
    Mail::shouldReceive('build')->never();

    $sent = app(MailDeliveryService::class)->sendAsOwner(
        $owner,
        new CreditEntryReceipt(Customer::factory()->make(), LedgerEntry::factory()->make()),
        $recipient,
        'credit_receipt',
    );

    expect($sent)->toBeFalse();
})->with([
    'missing' => [null],
    'malformed' => ['not-an-email'],
    'legacy invalid placeholder' => ['legacy-customer@example.invalid'],
]);

it('logs receipt delivery failures and returns false without exposing recipient details', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create(['email' => 'customer@example.test']);
    $entry = app(LedgerService::class)->recordCredit($customer, ['amount' => '25.00']);
    Mail::shouldReceive('build')
        ->once()
        ->andThrow(new RuntimeException('SMTP unavailable for customer@example.test.'));
    Log::shouldReceive('error')
        ->once()
        ->with('Email delivery failed.', Mockery::on(
            fn (array $context): bool => $context['notification_type'] === 'credit_receipt'
                && $context['recipient'] === 'c***@example.test'
                && $context['exception'] === RuntimeException::class
                && ! str_contains($context['error'], 'customer@example.test'),
        ));

    $sent = app(MailDeliveryService::class)->sendAsOwner(
        $owner,
        new CreditEntryReceipt($customer, $entry),
        $customer->email,
        'credit_receipt',
    );

    expect($sent)->toBeFalse();
    expect($customer->fresh()->current_balance)->toBe('25.00');
});

it('records a payment when the customer has no email address', function () {
    $owner = User::factory()->create();
    StoreEmailSetting::factory()->for($owner)->create();
    $customer = Customer::factory()->create([
        'email' => null,
        'credit_limit' => '1000.00',
    ]);
    app(LedgerService::class)->recordCredit($customer, ['amount' => '100.00']);

    $this->actingAs($owner)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '40.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('payments', 1);
    expect($customer->fresh()->current_balance)->toBe('60.00');
});
