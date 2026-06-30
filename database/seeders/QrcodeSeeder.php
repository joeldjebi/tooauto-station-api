<?php

namespace Database\Seeders;

use App\Models\QrcodeGenerate;
use Illuminate\Database\Seeder;

class QrcodeSeeder extends Seeder
{
    public function run(): void
    {
        // Générer 10 QR codes de test
        for ($i = 1; $i <= 10; $i++) {
            QrcodeGenerate::create([
                'qrcode' => 'QR' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'is_assigned' => false,
                'assigned_at' => null
            ]);
        }
    }
}
