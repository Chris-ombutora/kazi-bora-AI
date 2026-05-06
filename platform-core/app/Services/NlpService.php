<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Service for communicating with Developer 1's NLP Parsing microservice (FastAPI).
 * 
 * Sends extracted CV text to POST /parse and receives structured candidate data:
 *   { skills: [...], education: [...], experience: [...] }
 */
class NlpService
{
    private Client $client;
    private int $retryTimes;
    private int $retryDelayMs;

    public function __construct()
    {
        $config = config('services.nlp');

        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout' => $config['timeout'],
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->retryTimes = $config['retry_times'];
        $this->retryDelayMs = $config['retry_delay_ms'];
    }

    /**
     * Send raw CV text to the NLP service for structured data extraction.
     *
     * @param string $text Raw text extracted from a PDF/DOCX CV file
     * @return array Structured data: { skills: [], education: [], experience: [] }
     * @throws \RuntimeException If all retries fail
     */
    public function parseCvText(string $text): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryTimes; $attempt++) {
            try {
                Log::info("NLP parse attempt {$attempt}/{$this->retryTimes}");

                $response = $this->client->post('parse', [
                    'json' => ['text' => $text],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                Log::info('NLP parse successful', [
                    'skills_count' => count($data['skills'] ?? []),
                    'education_count' => count($data['education'] ?? []),
                    'experience_count' => count($data['experience'] ?? []),
                ]);

                return $data;

            } catch (GuzzleException $e) {
                $lastException = $e;
                Log::warning("NLP parse attempt {$attempt} failed: {$e->getMessage()}");

                if ($attempt < $this->retryTimes) {
                    usleep($this->retryDelayMs * 1000);
                }
            }
        }

        Log::error('NLP service unreachable after all retries', [
            'error' => $lastException?->getMessage(),
        ]);

        throw new \RuntimeException(
            'NLP parsing service is unavailable. Please try again later.',
            503,
            $lastException
        );
    }

    /**
     * Check if the NLP service is healthy.
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->client->get('health');
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }
}
