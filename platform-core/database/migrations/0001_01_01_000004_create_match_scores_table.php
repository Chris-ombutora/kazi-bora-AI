<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match scores table — stores AI matching results from Developer 2's engine.
 * Each row represents one candidate scored against one job posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->onDelete('cascade');
            $table->unsignedBigInteger('job_id');
            $table->foreign('job_id')->references('id')->on('job_postings')->onDelete('cascade');

            // Score components (0.0 to 1.0 scale)
            $table->decimal('overall_score', 6, 4)->default(0);
            $table->decimal('semantic_score', 6, 4)->default(0);
            $table->decimal('skills_score', 6, 4)->default(0);
            $table->decimal('experience_score', 6, 4)->default(0);
            $table->decimal('education_score', 6, 4)->default(0);

            // Breakdown details
            $table->json('matched_skills')->nullable();
            $table->json('missing_skills')->nullable();
            $table->json('explanation')->nullable();

            $table->timestamps();

            // Each candidate can only have one score per job
            $table->unique(['candidate_id', 'job_id']);
            
            // Index for fast ranked queries
            $table->index(['job_id', 'overall_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_scores');
    }
};
