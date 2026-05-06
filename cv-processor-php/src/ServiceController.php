<?php
namespace App;

use GuzzleHttp\Client;

class ServiceController {
    private \PDO $db;
    private TextExtractor $extractor;
    private Client $httpClient;

    public function __construct() {
        $dbInstance = new Database();
        $this->db = $dbInstance->getConnection();
        $this->extractor = new TextExtractor();
        $this->httpClient = new Client([
            'base_uri' => getenv('NLP_SERVICE_URL') ?: 'http://nlp-service:8000/',
            'timeout'  => 30.0,
        ]);
    }

    public function processUpload(array $fileData, string $name, string $email, string $phone): array {
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024;
        if ($fileData['size'] > $maxSize) {
            return ['error' => 'File too large. Maximum size is 10MB.'];
        }

        // Validate MIME type from actual file content (not just extension)
        $allowedMimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fileData['tmp_name']);
        finfo_close($finfo);

        if (!in_array($detectedMime, $allowedMimes)) {
            return ['error' => 'Invalid file type. Only PDF and DOCX files are supported.'];
        }

        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'docx'])) {
            return ['error' => 'Invalid file extension. Only PDF and DOCX are supported.'];
        }

        $filename = uniqid('cv_') . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($fileData['tmp_name'], $destPath)) {
            return ['error' => 'Failed to save the uploaded file.'];
        }

        // Insert into candidates table
        $stmt = $this->db->prepare("INSERT INTO candidates (name, email, phone, original_file_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $phone, $destPath]);
        $candidateId = $this->db->lastInsertId();

        // Add to queue
        $stmt = $this->db->prepare("INSERT INTO parse_jobs_queue (candidate_id) VALUES (?)");
        $stmt->execute([$candidateId]);
        $jobId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => 'CV uploaded and queued for processing successfully',
            'candidate_id' => $candidateId,
            'job_id' => $jobId
        ];
    }

    public function processQueueJob(int $jobId): void {
        try {
            $this->updateJobStatus($jobId, 'processing');

            $stmt = $this->db->prepare("SELECT q.candidate_id, c.original_file_path FROM parse_jobs_queue q JOIN candidates c ON q.candidate_id = c.id WHERE q.id = ?");
            $stmt->execute([$jobId]);
            $jobData = $stmt->fetch();

            if (!$jobData) {
                throw new \Exception("Job not found.");
            }

            $candidateId = $jobData['candidate_id'];
            $filePath = $jobData['original_file_path'];

            $this->updateCandidateStatus($candidateId, 'processing');

            // 1. Extract raw text
            $text = $this->extractor->extractText($filePath);

            // 2. Send to FastAPI NLP microservice
            $response = $this->httpClient->post('parse', [
                'json' => ['text' => $text]
            ]);

            $result = json_decode($response->getBody(), true);

            // 3. Save structured data
            $this->saveStructuredData($candidateId, $result);

            $this->updateJobStatus($jobId, 'completed');
            $this->updateCandidateStatus($candidateId, 'completed');

        } catch (\Exception $e) {
            error_log("Job $jobId failed: " . $e->getMessage());
            $this->updateJobStatus($jobId, 'failed', $e->getMessage());
            if (isset($candidateId)) {
                $this->updateCandidateStatus($candidateId, 'failed');
            }
        }
    }

    private function saveStructuredData(int $candidateId, array $data): void {
        // Save skills
        if (isset($data['skills']) && is_array($data['skills'])) {
            $stmt = $this->db->prepare("INSERT INTO skills (candidate_id, skill_name) VALUES (?, ?)");
            foreach ($data['skills'] as $skill) {
                $stmt->execute([$candidateId, $skill]);
            }
        }

        // Save education
        if (isset($data['education']) && is_array($data['education'])) {
            $stmt = $this->db->prepare("INSERT INTO education (candidate_id, institution_name, is_kenyan_institution, degree, graduation_year) VALUES (?, ?, ?, ?, ?)");
            foreach ($data['education'] as $edu) {
                $stmt->execute([
                    $candidateId, 
                    $edu['institution_name'] ?? null, 
                    $edu['is_kenyan_institution'] ?? false, 
                    $edu['degree'] ?? null, 
                    $edu['graduation_year'] ?? null
                ]);
            }
        }

        // Save experience
        if (isset($data['experience']) && is_array($data['experience'])) {
            $stmt = $this->db->prepare("INSERT INTO experience (candidate_id, company_name, job_title, years_of_experience) VALUES (?, ?, ?, ?)");
            foreach ($data['experience'] as $exp) {
                $stmt->execute([
                    $candidateId, 
                    $exp['company_name'] ?? null, 
                    $exp['job_title'] ?? null, 
                    $exp['years'] ?? 0
                ]);
            }
        }
    }

    public function getCandidate(int $candidateId): array {
        $stmt = $this->db->prepare("SELECT * FROM candidates WHERE id = ?");
        $stmt->execute([$candidateId]);
        $candidate = $stmt->fetch();

        if (!$candidate) {
            return ['error' => 'Candidate not found'];
        }

        $stmt = $this->db->prepare("SELECT skill_name FROM skills WHERE candidate_id = ?");
        $stmt->execute([$candidateId]);
        $skills = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $stmt = $this->db->prepare("SELECT * FROM education WHERE candidate_id = ?");
        $stmt->execute([$candidateId]);
        $education = $stmt->fetchAll();

        $stmt = $this->db->prepare("SELECT * FROM experience WHERE candidate_id = ?");
        $stmt->execute([$candidateId]);
        $experience = $stmt->fetchAll();

        return [
            'id' => (string)$candidate['id'],
            'name' => $candidate['name'],
            'email' => $candidate['email'],
            'skills' => $skills,
            'education' => array_map(function($edu) {
                return [
                    'institution' => $edu['institution_name'],
                    'is_kenyan_institution' => (bool)$edu['is_kenyan_institution'],
                    'degree' => $edu['degree'] ?? 'Unknown',
                    'field_of_study' => 'Unknown',
                    'graduation_year' => $edu['graduation_year']
                ];
            }, $education),
            'experience' => array_map(function($exp) {
                return [
                    'title' => $exp['job_title'],
                    'company' => $exp['company_name'],
                    'years' => (float)$exp['years_of_experience'],
                    'description' => null
                ];
            }, $experience),
            'status' => $candidate['status']
        ];
    }

    private function updateJobStatus(int $jobId, string $status, string $error = null): void {
        $stmt = $this->db->prepare("UPDATE parse_jobs_queue SET status = ?, error_message = ? WHERE id = ?");
        $stmt->execute([$status, $error, $jobId]);
    }

    private function updateCandidateStatus(int $candidateId, string $status): void {
        $stmt = $this->db->prepare("UPDATE candidates SET status = ? WHERE id = ?");
        $stmt->execute([$status, $candidateId]);
    }
}
