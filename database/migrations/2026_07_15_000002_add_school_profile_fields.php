<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'registration_number')) {
                $table->string('registration_number')->nullable()->after('code');
            }
            if (! Schema::hasColumn('schools', 'emis_code')) {
                $table->string('emis_code')->nullable()->after('registration_number');
            }
            if (! Schema::hasColumn('schools', 'school_type')) {
                $table->string('school_type', 50)->nullable()->after('emis_code');
            }
            if (! Schema::hasColumn('schools', 'ownership')) {
                $table->string('ownership', 50)->nullable()->after('school_type');
            }
            if (! Schema::hasColumn('schools', 'education_level')) {
                $table->string('education_level', 80)->nullable()->after('ownership');
            }
            if (! Schema::hasColumn('schools', 'address')) {
                $table->string('address')->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('schools', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
            if (! Schema::hasColumn('schools', 'region')) {
                $table->string('region')->nullable()->after('city');
            }
            if (! Schema::hasColumn('schools', 'country')) {
                $table->string('country')->nullable()->default('Tanzania')->after('region');
            }
            if (! Schema::hasColumn('schools', 'website')) {
                $table->string('website')->nullable()->after('country');
            }
            if (! Schema::hasColumn('schools', 'motto')) {
                $table->string('motto')->nullable()->after('website');
            }
            if (! Schema::hasColumn('schools', 'vision')) {
                $table->text('vision')->nullable()->after('motto');
            }
            if (! Schema::hasColumn('schools', 'mission')) {
                $table->text('mission')->nullable()->after('vision');
            }
            if (! Schema::hasColumn('schools', 'logo_url')) {
                $table->string('logo_url')->nullable()->after('mission');
            }
            if (! Schema::hasColumn('schools', 'working_days')) {
                $table->json('working_days')->nullable()->after('logo_url');
            }
            if (! Schema::hasColumn('schools', 'calendar_notes')) {
                $table->text('calendar_notes')->nullable()->after('working_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $cols = [
                'registration_number',
                'emis_code',
                'school_type',
                'ownership',
                'education_level',
                'address',
                'city',
                'region',
                'country',
                'website',
                'motto',
                'vision',
                'mission',
                'logo_url',
                'working_days',
                'calendar_notes',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('schools', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
