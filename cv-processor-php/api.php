<?php
require __DIR__ . '/vendor/autoload.php';

use App\ServiceController;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$controller = new ServiceController();

if ($method === 'POST' && preg_match('/^\/upload\/?$/', $path)) {
    if (!isset($_FILES['cv'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No CV file uploaded.']);
        exit;
    }

    $name = $_POST['name'] ?? 'Unknown';
    $email = $_POST['email'] ?? 'unknown@example.com';
    $phone = $_POST['phone'] ?? '0000000000';

    $result = $controller->processUpload($_FILES['cv'], $name, $email, $phone);
    echo json_encode($result);

} elseif ($method === 'POST' && preg_match('/^\/trigger-job\/(\d+)\/?$/', $path, $matches)) {
    $jobId = (int)$matches[1];
    $controller->processQueueJob($jobId);
    echo json_encode(['message' => "Job $jobId triggered."]);

} elseif ($method === 'GET' && preg_match('/^\/candidate\/(\d+)\/?$/', $path, $matches)) {
    // Basic retrieval
    $candidateId = (int)$matches[1];
    $db = (new \App\Database())->getConnection();
    
    $stmt = $db->prepare("SELECT * FROM candidates WHERE id = ?");
    $stmt->execute([$candidateId]);
    $candidate = $stmt->fetch();
    
    if (!$candidate) {
        http_response_code(404);
        echo json_encode(['error' => 'Candidate not found']);
        exit;
    }

    $stmt = $db->prepare("SELECT skill_name FROM skills WHERE candidate_id = ?");
    $stmt->execute([$candidateId]);
    $skills = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    $stmt = $db->prepare("SELECT * FROM education WHERE candidate_id = ?");
    $stmt->execute([$candidateId]);
    $education = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM experience WHERE candidate_id = ?");
    $stmt->execute([$candidateId]);
    $experience = $stmt->fetchAll();

    $candidateResponse = [
        'id' => (string)$candidate['id'],
        'name' => $candidate['name'],
        'skills' => $skills,
        'education' => array_map(function($edu) {
            return [
                'institution' => $edu['institution_name'],
                'degree' => $edu['degree'] ?? 'Unknown',
                'field_of_study' => 'Unknown' // Adding missing field required by Developer 2
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
        // Adding the raw_resume_text wouldn't hurt, but the model says it's optional
    ];

    echo json_encode($candidateResponse);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']);
}
