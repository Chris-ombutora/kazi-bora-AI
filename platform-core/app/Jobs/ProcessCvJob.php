<?php

namespace App\Jobs;

use App\Models\Candidate;
use App\Models\Skill;
use App\Models\Education;
use App\Models\Experience;
use App\Services\TextExtractorService;
use App\Services\NlpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job: Extract text from a CV file and send it to the NLP service for parsing.
 * Stores the extracted structured data (skills, education, experience) in the database.
 * 
 * After completion, optionally dispatches ScoreCandidateJob if a job_id is provided.
 */
class ProcessCvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10; // seconds between retries

    public function __construct(
        private int $candidateId,
        private ?int $jobPostingId = null
    ) {}

    public function handle(TextExtractorService $extractor, NlpService $nlpService): void
    {
        $candidate = Candidate::find($this->candidateId);

        if (!$candidate) {
            Log::error("ProcessCvJob: Candidate {$this->candidateId} not found");
            return;
        }

        try {
            $candidate->update(['status' => 'processing']);

            // Step 1: Extract raw text from file
            Log::info("Extracting text from CV", ['candidate_id' => $this->candidateId]);
            $rawText = $extractor->extractText($candidate->original_file_path);

            // Store raw text for semantic matching later
            $candidate->update(['raw_resume_text' => $rawText]);

            // Step 2: Send to NLP service for structured extraction
            Log::info("Sending to NLP service", ['candidate_id' => $this->candidateId]);
            $parsed = $nlpService->parseCvText($rawText);

            // Step 3: Store structured data in normalized tables
            $this->saveStructuredData($candidate, $parsed);

            $candidate->update(['status' => 'completed']);

            Log::info("CV processing complete", [
                'candidate_id' => $this->candidateId,
                'skills' => count($parsed['skills'] ?? []),
                'education' => count($parsed['education'] ?? []),
                'experience' => count($parsed['experience'] ?? []),
            ]);

            // Step 4: If a job posting was specified, dispatch scoring
            if ($this->jobPostingId) {
                ScoreCandidateJob::dispatch($this->candidateId, $this->jobPostingId);
            }

        } catch (\Exception $e) {
            $candidate->update(['status' => 'failed']);
            Log::error("ProcessCvJob failed for candidate {$this->candidateId}: {$e->getMessage()}");
            throw $e; // Let Laravel retry mechanism handle it
        }
    }

    /**
     * Save parsed NLP data into normalized DB tables.
     * Matches the output format of Developer 1's NLP parser exactly.
     */
    private function saveStructuredData(Candidate $candidate, array $data): void
    {
        // Clear existing data to avoid duplicates on retry
        $candidate->skills()->delete();
        $candidate->education()->delete();
        $candidate->experience()->delete();

        // Save skills
        if (!empty($data['skills'])) {
            foreach ($data['skills'] as $skillName) {
                Skill::create([
                    'candidate_id' => $candidate->id,
                    'skill_name' => $skillName,
                ]);
            }
        }

        // Save education (matches NLP parser's _extract_education output)
        if (!empty($data['education'])) {
            foreach ($data['education'] as $edu) {
                Education::create([
                    'candidate_id' => $candidate->id,
                    'institution_name' => $edu['institution_name'] ?? null,
                    'is_kenyan_institution' => $edu['is_kenyan_institution'] ?? false,
                    'degree' => $edu['degree'] ?? null,
                    'graduation_year' => $edu['graduation_year'] ?? null,
                ]);
            }
        }

        // Save experience (matches NLP parser's _extract_experience output)
        if (!empty($data['experience'])) {
            foreach ($data['experience'] as $exp) {
                Experience::create([
                    'candidate_id' => $candidate->id,
                    'company_name' => $exp['company_name'] ?? null,
                    'job_title' => $exp['job_title'] ?? null,
                    'years_of_experience' => $exp['years'] ?? 0,
                ]);
            }
        }
    }
}
