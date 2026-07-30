<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the actual filter/sort patterns of the app.
 * Single-column FK indexes already exist from ->constrained().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->index(['status', 'branch_id', 'deleted_at'], 'loans_status_branch_idx');
            $table->index(['branch_id', 'status', 'deleted_at'], 'loans_branch_status_idx');
            $table->index('disbursed_at', 'loans_disbursed_at_idx');
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            $table->index(['loan_id', 'settled_at', 'due_date'], 'li_loan_settled_due_idx');
            $table->index(['settled_at', 'due_date'], 'li_settled_due_idx');
        });

        Schema::table('loan_payments', function (Blueprint $table) {
            $table->index(['reversed_at', 'paid_at'], 'lp_reversed_paid_idx');
            $table->index(['loan_id', 'paid_at'], 'lp_loan_paid_idx');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index(['ledger_account_id', 'currency'], 'jl_account_currency_idx');
            $table->index('currency', 'jl_currency_idx');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index(['kind', 'status', 'created_at'], 'sms_kind_status_created_idx');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->index(['branch_id', 'deleted_at'], 'borrowers_branch_deleted_idx');
            $table->index(['last_name', 'first_name'], 'borrowers_name_idx');
        });

        Schema::table('collaterals', function (Blueprint $table) {
            $table->index(['status', 'type'], 'collaterals_status_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex('loans_status_branch_idx');
            $table->dropIndex('loans_branch_status_idx');
            $table->dropIndex('loans_disbursed_at_idx');
        });
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropIndex('li_loan_settled_due_idx');
            $table->dropIndex('li_settled_due_idx');
        });
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropIndex('lp_reversed_paid_idx');
            $table->dropIndex('lp_loan_paid_idx');
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('jl_account_currency_idx');
            $table->dropIndex('jl_currency_idx');
        });
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex('sms_kind_status_created_idx');
        });
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropIndex('borrowers_branch_deleted_idx');
            $table->dropIndex('borrowers_name_idx');
        });
        Schema::table('collaterals', function (Blueprint $table) {
            $table->dropIndex('collaterals_status_type_idx');
        });
    }
};
