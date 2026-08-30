<?php

namespace Database\Seeders;

use App\Models\AutoReplyRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => null,
            'password' => null,
        ]);

        Setting::singleton();

        collect([
            ['harga', 'Untuk info harga, silakan DM kami ya! 🙌'],
            ['dm', 'Siap, cek DM-nya ya! 🚀'],
            ['info', 'Terima kasih sudah bertanya! Detail lengkap ada di bio kami.'],
            ['terima kasih', 'Sama-sama! Semoga harimu menyenangkan ✨'],
        ])->each(fn (array $rule) => AutoReplyRule::updateOrCreate(
            ['keyword' => $rule[0]],
            ['reply_text' => $rule[1], 'is_active' => true],
        ));
    }
}
