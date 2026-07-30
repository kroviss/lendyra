<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_installment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // upcoming | overdue
            $table->date('sent_for');   // the due-date context, prevents duplicates
            $table->string('to', 32);
            $table->text('message');
            $table->string('status', 16)->default('sent');
            $table->timestamps();

            $table->unique(['loan_installment_id', 'kind', 'sent_for'], 'sms_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
