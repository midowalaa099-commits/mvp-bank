<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        if (Currency::query()->where('code', 'EGP')->exists()) {
            return;
        }

        $currency = new Currency;
        $currency->code = 'EGP';
        $currency->name = 'Egyptian Pound';
        $currency->decimal_places = 2;
        $currency->save();
    }
}
