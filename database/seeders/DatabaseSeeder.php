<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Curated legal-compliance rulebook — idempotent, safe to run on every
        // deploy (upserts + preserves operator overrides). Production-safe.
        $this->call(ComplianceLegalRuleSeeder::class);

        // The demo user is local-only — never create it in production.
        if (! app()->isProduction()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
