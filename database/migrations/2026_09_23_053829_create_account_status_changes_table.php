<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_status_changes', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->enum('from_status', ['active', 'frozen']);
            $table->enum('to_status', ['active', 'frozen']);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'id']);
        });

        DB::statement('ALTER TABLE account_status_changes ADD CONSTRAINT account_status_changes_different CHECK (from_status <> to_status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_status_changes');
    }
};
