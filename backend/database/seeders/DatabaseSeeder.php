<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create([
            'name' => 'Sam Okafor',
            'email' => 'ops@fellwise.co.uk',
            'password' => 'fellwise',
        ]);

        $this->call([CatalogueSeeder::class, TrailDeskSeeder::class]);
    }
}
