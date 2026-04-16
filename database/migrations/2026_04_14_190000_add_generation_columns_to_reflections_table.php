<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reflections', function (Blueprint $table) {
            $table->date('week_start_date')->nullable()->after('reflection_date');
            $table->date('week_end_date')->nullable()->after('week_start_date');
            $table->boolean('is_generated')->default(false)->after('week_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reflections', function (Blueprint $table) {
            $table->dropColumn(['week_start_date', 'week_end_date', 'is_generated']);
        });
    }
};
