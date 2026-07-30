<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('scale')->default(2);

            $table->string('method', 40)->default('declining_equal_principal');
            $table->string('frequency', 16)->default('monthly');
            $table->string('basis', 16)->default('equal_periods');
            $table->decimal('annual_rate', 8, 4);
            $table->unsignedSmallInteger('term_count');

            $table->unsignedBigInteger('min_principal_minor')->nullable();
            $table->unsignedBigInteger('max_principal_minor')->nullable();
            $table->unsignedSmallInteger('min_term_count')->nullable();
            $table->unsignedSmallInteger('max_term_count')->nullable();

            $table->decimal('processing_fee_percent', 8, 4)->default(0);
            $table->unsignedBigInteger('processing_fee_flat_minor')->default(0);

            $table->decimal('penalty_daily_rate', 8, 5)->default(0);
            $table->unsignedSmallInteger('penalty_grace_days')->default(0);
            $table->string('penalty_base', 32)->default('overdue_principal');
            $table->decimal('penalty_cap_percent', 8, 4)->nullable();

            $table->json('allocation_order')->nullable();
            $table->string('allocation_mode', 40)->default('oldest_installment_first');
            $table->string('payoff_interest_mode', 20)->default('prorated');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
