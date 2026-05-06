<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job postings table.
 * Named 'job_postings' to avoid collision with Laravel's built-in 'jobs' queue table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->json('required_skills');
            $table->json('preferred_skills')->nullable();
            $table->decimal('min_years_experience', 4, 2)->default(0);
            $table->string('location')->nullable();
            $table->enum('status', ['draft', 'active', 'closed'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
