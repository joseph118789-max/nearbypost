<?php

namespace Database\Seeders;

use App\Models\LocationAlias;
use Illuminate\Database\Seeder;

class LocationAliasSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LocationAlias::seedMalaysianPlaces() as $alias) {
            LocationAlias::updateOrCreate(
                ['alias_text' => $alias[0]],
                [
                    'canonical_name' => $alias[1],
                    'alias_type'    => $alias[2],
                    'is_active'     => true,
                ]
            );
        }
        echo('Seeded ' . LocationAlias::count() . ' location aliases.');
    }
}
