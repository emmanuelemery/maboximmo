<?php
header('Content-Type: application/json');
session_start();

// READ RAW INPUT FIRST
$rawInput = file_get_contents('php://input');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$roleId = current_role_id();
$userId = current_user_id();

$decoded = json_decode($rawInput, true);

echo json_encode([
    'debug' => true,
    'roleId' => $roleId,
    'userId' => $userId,
    'raw_input_length' => strlen($rawInput),
    'raw_input' => $rawInput,
    'decoded_data' => $decoded,
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'message' => 'Test API endpoint'
]);
?>
