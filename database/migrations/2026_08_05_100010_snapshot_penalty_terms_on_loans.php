<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the penalty and early-payoff terms onto each loan, exactly as
 * annual_rate/method/frequency/basis are already snapshotted. Without this a
 * later edit to a product's penalty rate, grace, base or cap silently
 * re-prices the entire penalty history of every live loan the next time the
 * nightly accrual runs — a retroactive rewrite of contractual amounts with
 * no audit trail. After this migration the services read the loan's own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('penalty_daily_rate', 8, 5)->nullable()->after('basis');
            $table->unsignedSmallInteger('penalty_grace_days')->nullable()->after('penalty_daily_rate');
            $table->string('penalty_base', 32)->nullable()->after('penalty_grace_days');
            $table->decimal('penalty_cap_percent', 8, 4)->nullable()->after('penalty_base');
            $table->string('payoff_interest_mode', 20)->nullable()->after('penalty_cap_percent');
        });

        // Backfill existing loans from their product. Kept DB-agnostic (no
        // UPDATE ... JOIN) — products are few, so load them once and stamp
        // loans in chunks.
        $products = DB::table('loan_products')->get()->keyBy('id');

        DB::table('loans')->orderBy('id')->select('id', 'loan_product_id')
            ->chunkById(1000, function ($rows) use ($products) {
                foreach ($rows as $row) {
                    $product = $products->get($row->loan_product_id);

                    if ($product === null) {
                        continue;
                    }

                    DB::table('loans')->where('id', $row->id)->update([
                        'penalty_daily_rate' => $product->penalty_daily_rate,
                        'penalty_grace_days' => $product->penalty_grace_days,
                        'penalty_base' => $product->penalty_base,
                        'penalty_cap_percent' => $product->penalty_cap_percent,
                        'payoff_interest_mode' => $product->payoff_interest_mode,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'penalty_daily_rate',
                'penalty_grace_days',
                'penalty_base',
                'penalty_cap_percent',
                'payoff_interest_mode',
            ]);
        });
    }
};
