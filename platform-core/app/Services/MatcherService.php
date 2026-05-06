<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Service for communicating with Developer 2's AI Matching microservice (FastAPI).
 *
 * Sends job descriptions + candidate data to POST /match for async scoring,
 * and polls GET /task/{id} for results.
 * 
 * Expected payload for POST /match (must match Developer 2's MatchRequest Pydantic model):
 * {
 *   "job": { id, title, required_skills, preferred_skills, minimum_years_experience, description_text },
 *   "candidates": [{ id, name, skills, education, experience, raw_resume_text }]
 * }
 */
class MatcherService
{
    private Client $client;
    private int $retryTimes;
    private int $retryDelayMs;

    public function __construct()
    {
        $config = config('services.matcher');

        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout' => $config['timeout'],
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->retryTimes = $config['retry_times'];
        $this->retryDelayMs = $config['retry_delay_ms'];
    }

    /**
     * Submit a matching task to the AI service.
     * Returns the Celery task ID for polling.
     *
     * @param array $jobPayload   Formatted job description matching JobDescription Pydantic model
     * @param array $candidates   Array of candidate payloads matching Candidate Pydantic model
     * @return string             Celery task ID
     */
    public function submitMatchingTask(array $jobPayload, array $candidates): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryTimes; $attempt++) {
            try {
                Log::info("Matcher submit attempt {$attempt}/{$this->retryTimes}", [
                    'candidates_count' => count($candidates),
                ]);

                $response = $this->client->post('match', [
                    'json' => [
                        'job' => $jobPayload,
                        'candidates' => $candidates,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $taskId = $data['task_id'] ?? null;

                if (!$taskId) {
                    throw new \RuntimeException('Matcher API did not return a task_id');
                }

                Log::info("Matching task queued: {$taskId}");
                return $taskId;

            } catch (GuzzleException $e) {
                $lastException = $e;
                Log::warning("Matcher submit attempt {$attempt} failed: {$e->getMessage()}");

                if ($attempt < $this->retryTimes) {
                    usleep($this->retryDelayMs * 1000);
                }
            }
        }

        throw new \RuntimeException(
            'AI Matching service is unavailable. Please try again later.',
            503,
            $lastException
        );
    }

    /**
     * Poll for the result of a matching task.
     *
     * @param string $taskId Celery task ID
     * @return array { task_status, task_result: { status, total, results: [ScoringCard...] } }
     */
    public function getTaskResult(string $taskId): array
    {
        try {
            $response = $this->client->get("task/{$taskId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error("Failed to get matcher task {$taskId}: {$e->getMessage()}");
            throw new \RuntimeException('Could not retrieve matching results.', 503, $e);
        }
    }

    /**
     * Submit and wait for results (blocking, for synchronous use).
     * Polls every 2 seconds for up to max_wait_seconds.
     *
     * @return array|null The scoring results or null if timeout
     */
    public function matchAndWait(array $jobPayload, array $candidates, int $maxWaitSeconds = 120): ?array
    {
        $taskId = $this->submitMatchingTask($jobPayload, $candidates);

        $elapsed = 0;
        $pollInterval = 2;

        while ($elapsed < $maxWaitSeconds) {
            sleep($pollInterval);
            $elapsed += $pollInterval;

            $result = $this->getTaskResult($taskId);

            if ($result['task_status'] === 'SUCCESS') {
                return $result['task_result'];
            }

            if ($result['task_status'] === 'FAILURE') {
                Log::error("Matching task {$taskId} failed", $result);
                throw new \RuntimeException('Matching task failed: ' . ($result['task_result'] ?? 'Unknown error'));
            }

            // Still processing — continue polling
            Log::debug("Task {$taskId} status: {$result['task_status']}", $result['meta'] ?? []);
        }

        Log::warning("Matching task {$taskId} timed out after {$maxWaitSeconds}s");
        return null;
    }
}
