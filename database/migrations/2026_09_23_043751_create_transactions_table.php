<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'transfer']);
            $table->bigInteger('amount_minor');
            $table->foreignId('source_account_id')->nullable();
            $table->foreignId('destination_account_id')->nullable();
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('request_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['initiated_by', 'idempotency_key']);
            $table->index(['source_account_id', 'created_at', 'id'], 'transactions_outgoing_history');
            $table->index(['destination_account_id', 'created_at', 'id'], 'transactions_incoming_history');

            $table->foreign(['source_account_id', 'currency_id'], 'transactions_source_currency_fk')
                ->references(['id', 'currency_id'])->on('accounts');
            $table->foreign(['destination_account_id', 'currency_id'], 'transactions_destination_currency_fk')
                ->references(['id', 'currency_id'])->on('accounts');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount_minor > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE transactions ADD CONSTRAINT transactions_valid_accounts CHECK (
                (type = 'deposit' AND source_account_id IS NULL AND destination_account_id IS NOT NULL)
                OR (type = 'withdrawal' AND source_account_id IS NOT NULL AND destination_account_id IS NULL)
                OR (type = 'transfer' AND source_account_id IS NOT NULL AND destination_account_id IS NOT NULL
                    AND source_account_id <> destination_account_id)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
