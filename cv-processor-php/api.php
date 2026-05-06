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
    $candidateId = (int)$matches[1];
    $result = $controller->getCandidate($candidateId);
    if (isset($result['error'])) {
        http_response_code(404);
    }
    echo json_encode($result);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']);
}
