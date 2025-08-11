<?php
// Simple connection test file
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$response = [
    'status' => 'success',
    'message' => 'PHP backend is working correctly',
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'php_version' => PHP_VERSION,
    'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response['post_data'] = $_POST;
    $response['message'] = 'POST request received successfully';
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>