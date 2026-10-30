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
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable()->after('resolved_at');
            $table->timestamp('reopened_at')->nullable()->after('closed_at');
            $table->unsignedInteger('reopened_count')->default(0)->after('reopened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['first_responded_at', 'reopened_at', 'reopened_count']);
        });
    }
};
