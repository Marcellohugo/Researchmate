<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep existing vectors as JSON text so Qdrant can be rebuilt without calling the gateway.
        DB::statement('ALTER TABLE paper_chunks ALTER COLUMN embedding TYPE text USING embedding::text');
    }

    public function down(): void
    {
        // Text is also the original schema type; retain data without requiring an extension.
    }
};
