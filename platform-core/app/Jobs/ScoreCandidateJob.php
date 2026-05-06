<?php

namespace App\Jobs;

use App\Models\Candidate;
use App\Models\Job;
use App\Models\MatchScore;
use App\Services\MatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job: Score a candidate against a job posting using Developer 2's AI Matching Service.
 * 
 * Formats data to match Developer 2's Pydantic models exactly, then persists results.
 */
class ScoreCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(
        private int $candidateId,
        private int $jobPostingId
    ) {}

    public function handle(MatcherService $matcherService): void
    {
        $candidate = Candidate::with(['skills', 'education', 'experience'])
            ->find($this->candidateId);

        $job = Job::find($this->jobPostingId);

        if (!$candidate || !$job) {
            Log::error("ScoreCandidateJob: Missing candidate ({$this->candidateId}) or job ({$this->jobPostingId})");
            return;
        }

        try {
            // Format job data to match Developer 2's JobDescription Pydantic model
            $jobPayload = [
                'id' => (string) $job->id,
                'title' => $job->title,
                'required_skills' => $job->required_skills ?? [],
                'preferred_skills' => $job->preferred_skills ?? [],
                'minimum_years_experience' => (float) $job->min_years_experience,
                'description_text' => $job->description,
            ];

            // Format candidate data using the model's toMatcherPayload()
            // This ensures field names match Developer 2's Candidate Pydantic model
            $candidatePayload = $candidate->toMatcherPayload();

            // Submit to matcher and wait for results
            $result = $matcherService->matchAndWait($jobPayload, [$candidatePayload], 120);

            if (!$result || empty($result['results'])) {
                Log::warning("No scoring results returned for candidate {$this->candidateId}");
                return;
            }

            // Persist the first (and only) score card
            $scoreCard = $result['results'][0];
            $this->persistScore($scoreCard);

            Log::info("Scoring complete", [
                'candidate_id' => $this->candidateId,
                'job_id' => $this->jobPostingId,
                'overall_score' => $scoreCard['overall_score'],
            ]);

        } catch (\Exception $e) {
            Log::error("ScoreCandidateJob failed: {$e->getMessage()}", [
                'candidate_id' => $this->candidateId,
                'job_id' => $this->jobPostingId,
            ]);
            throw $e;
        }
    }

    /**
     * Persist a scoring card from the AI Matching Service.
     * Maps Developer 2's ScoringCard fields to our match_scores table.
     */
    private function persistScore(array $scoreCard): void
    {
        $explanation = $scoreCard['explanation'] ?? [];

        MatchScore::updateOrCreate(
            [
                'candidate_id' => $this->candidateId,
                'job_id' => $this->jobPostingId,
            ],
            [
                'overall_score' => $scoreCard['overall_score'],
                'semantic_score' => $scoreCard['semantic_score'],
                'skills_score' => $scoreCard['skills_score'],
                'experience_score' => $scoreCard['experience_score'],
                'education_score' => $scoreCard['education_score'],
                'matched_skills' => $explanation['matched_skills'] ?? [],
                'missing_skills' => $explanation['missing_skills'] ?? [],
                'explanation' => $explanation,
            ]
        );
    }
}
