<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->bigInteger('balance_minor')->default(0);
            $table->enum('status', ['active', 'frozen'])->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'currency_id']);
            $table->unique(['id', 'currency_id']);
        });

        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_balance_nonnegative CHECK (balance_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
