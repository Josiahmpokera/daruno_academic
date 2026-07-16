<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schools declare which TAMISEMI stages they operate
 * (pre_primary, primary, ordinary_level, advanced_level) — multi-select.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'education_stages')) {
                $table->json('education_stages')->nullable()->after('education_level');
            }
        });

        // Backfill: if empty, offer all stages so existing tenants keep full catalogue
        $all = json_encode([
            'pre_primary',
            'primary',
            'ordinary_level',
            'advanced_level',
        ]);

        try {
            DB::table('schools')
                ->whereNull('education_stages')
                ->orWhere('education_stages', '')
                ->orWhere('education_stages', '[]')
                ->update(['education_stages' => $all]);
        } catch (\Throwable) {
            // sqlite / driver differences — ignore
        }
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (Schema::hasColumn('schools', 'education_stages')) {
                $table->dropColumn('education_stages');
            }
        });
    }
};
