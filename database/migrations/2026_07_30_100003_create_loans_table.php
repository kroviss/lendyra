<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('borrower_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained()->restrictOnDelete();

            $table->string('currency', 3);
            $table->unsignedTinyInteger('scale')->default(2);
            $table->unsignedBigInteger('principal_minor');
            $table->decimal('annual_rate', 8, 4);
            $table->unsignedSmallInteger('term_count');
            $table->string('method', 40);
            $table->string('frequency', 16);
            $table->string('basis', 16);

            $table->date('application_date')->nullable();
            $table->date('disbursed_at')->nullable();
            $table->date('first_due_date')->nullable();
            $table->date('closed_at')->nullable();
            $table->date('written_off_at')->nullable();

            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('id_number', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('relationship', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('estimated_value_minor')->default(0);
            $table->string('status', 16)->default('held');
            $table->date('released_at')->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaterals');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('loans');
    }
};
