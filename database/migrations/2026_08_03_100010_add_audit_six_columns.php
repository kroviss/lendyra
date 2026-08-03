<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            // Lifetime total of penalty forgiven on this installment.
            // Accrual recomputes from scratch, so without this marker a
            // waiver would be silently re-billed by the next accrual run.
            $table->unsignedBigInteger('penalty_waived_minor')->default(0)->after('penalty_paid_minor');
        });

        Schema::table('loan_payments', function (Blueprint $table) {
            // A payoff settlement rewrites future interest; its reversal
            // must restore the schedule. The free-text reference field is
            // user input and cannot be trusted to identify payoffs.
            $table->boolean('is_payoff')->default(false)->after('reference');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->string('reject_reason', 500)->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->after('reject_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropColumn('penalty_waived_minor');
        });

        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn('is_payoff');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['reject_reason', 'rejected_at']);
        });
    }
};
