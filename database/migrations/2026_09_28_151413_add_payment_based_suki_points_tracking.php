<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suki_points', function (Blueprint $table) {
            $table->unsignedSmallInteger('repayment_remainder_cents')->default(0)->after('points_balance');
        });

        Schema::create('suki_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paid_amount_cents');
            $table->unsignedInteger('points_awarded')->default(0);
            $table->unsignedSmallInteger('remainder_before_cents');
            $table->unsignedSmallInteger('remainder_after_cents');
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        DB::table('suki_points')->update([
            'points_balance' => 0,
            'repayment_remainder_cents' => 0,
        ]);

        $customerBalances = [];
        $toCents = static function (string $amount): int {
            [$wholeUnits, $fractionalUnits] = array_pad(explode('.', $amount, 2), 2, '');

            return ((int) $wholeUnits * 100) + (int) str_pad($fractionalUnits, 2, '0');
        };

        foreach (DB::table('payments')
            ->select(['id', 'customer_id', 'amount', 'created_at', 'updated_at'])
            ->orderBy('customer_id')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->cursor() as $payment) {
            $customerId = (int) $payment->customer_id;
            $previousRemainder = $customerBalances[$customerId]['remainder'] ?? 0;
            $paidAmountInCents = $toCents((string) $payment->amount);
            $repaymentTotalInCents = $previousRemainder + $paidAmountInCents;
            $pointsAwarded = intdiv($repaymentTotalInCents, 10000);
            $newRemainder = $repaymentTotalInCents % 10000;
            $timestamp = $payment->created_at ?? now()->toDateTimeString();

            DB::table('suki_point_transactions')->insert([
                'customer_id' => $customerId,
                'payment_id' => $payment->id,
                'paid_amount_cents' => $paidAmountInCents,
                'points_awarded' => $pointsAwarded,
                'remainder_before_cents' => $previousRemainder,
                'remainder_after_cents' => $newRemainder,
                'created_at' => $timestamp,
                'updated_at' => $payment->updated_at ?? $timestamp,
            ]);

            $customerBalances[$customerId] = [
                'points' => ($customerBalances[$customerId]['points'] ?? 0) + $pointsAwarded,
                'remainder' => $newRemainder,
            ];
        }

        foreach ($customerBalances as $customerId => $balance) {
            DB::table('suki_points')->updateOrInsert(
                ['customer_id' => $customerId],
                [
                    'points_balance' => $balance['points'],
                    'repayment_remainder_cents' => $balance['remainder'],
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suki_point_transactions');

        Schema::table('suki_points', function (Blueprint $table) {
            $table->dropColumn('repayment_remainder_cents');
        });
    }
};
