<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CargoCompany;
use Illuminate\Database\Seeder;

class CargoCompanySeeder extends Seeder
{
    /**
     * Seed default cargo companies.
     */
    public function run(): void
    {
        $companies = [
            [
                'name' => 'Aras Kargo',
                'provider_type' => 'aras',
                'is_active' => false,
                'is_default' => true,
            ],
            [
                'name' => 'MNG Kargo',
                'provider_type' => 'mng',
                'is_active' => false,
                'is_default' => false,
            ],
            [
                'name' => 'Yurtiçi Kargo',
                'provider_type' => 'yurtici',
                'is_active' => false,
                'is_default' => false,
            ],
            [
                'name' => 'PTT',
                'provider_type' => 'ptt',
                'is_active' => false,
                'is_default' => false,
            ],
        ];

        foreach ($companies as $company) {
            CargoCompany::query()->firstOrCreate(
                ['provider_type' => $company['provider_type']],
                $company
            );
        }
    }
}
