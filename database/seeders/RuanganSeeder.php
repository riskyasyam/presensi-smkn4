<?php

namespace Database\Seeders;

use App\Models\Ruangan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RuanganSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Ruangan::insert([
            [
                'nama_ruangan' => 'Lab_TKK',
                'id_kelas' => 1, // Referencing Kelas ID
                'id_jurusan' => 1, // Referencing Jurusan ID
                'relay_state' => 'off',
            ],
            [
                'nama_ruangan' => 'Lab_TIF',
                'id_kelas' => 2, // Referencing Kelas ID
                'id_jurusan' => 2, // Referencing Jurusan ID
                'relay_state' => 'off',
            ],
        ]);
    }
}
