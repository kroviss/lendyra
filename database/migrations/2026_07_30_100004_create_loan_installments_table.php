<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date')->index();

            $table->unsignedBigInteger('opening_minor');
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedBigInteger('interest_minor');
            $table->unsignedBigInteger('penalty_minor')->default(0);

            $table->unsignedBigInteger('principal_paid_minor')->default(0);
            $table->unsignedBigInteger('interest_paid_minor')->default(0);
            $table->unsignedBigInteger('penalty_paid_minor')->default(0);

            $table->date('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
