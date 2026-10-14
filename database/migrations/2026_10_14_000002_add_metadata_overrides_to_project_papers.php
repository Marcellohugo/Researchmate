<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_papers', function (Blueprint $table) {
            $table->jsonb('metadata_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_papers', function (Blueprint $table) {
            $table->dropColumn('metadata_overrides');
        });
    }
};
