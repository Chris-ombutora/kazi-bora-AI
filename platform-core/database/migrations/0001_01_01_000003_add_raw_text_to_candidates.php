<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds raw_resume_text column to the existing candidates table (created by Developer 1's schema).
 * This column stores the extracted text for semantic matching in the AI engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only add the column if the table exists (created by Developer 1's 01_schema.sql)
        // and the column doesn't already exist
        if (Schema::hasTable('candidates') && !Schema::hasColumn('candidates', 'raw_resume_text')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->longText('raw_resume_text')->nullable()->after('original_file_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('candidates') && Schema::hasColumn('candidates', 'raw_resume_text')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->dropColumn('raw_resume_text');
            });
        }
    }
};
