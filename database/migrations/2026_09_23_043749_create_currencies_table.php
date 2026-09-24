<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->char('code', 3)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('decimal_places');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
