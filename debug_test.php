<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'message' => 'Debug test is working',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION
    ]);
    exit();
}

// Get POST data
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

// Test the validation logic step by step
$tests = [];

// Step 1: Basic checks
$tests['email_provided'] = !empty($email);
$tests['password_provided'] = !empty($password);
$tests['email_valid'] = filter_var($email, FILTER_VALIDATE_EMAIL);
$tests['password_length'] = strlen($password);
$tests['password_not_empty'] = !empty(trim($password));

// Step 2: Check for fake passwords
$obvious_fake_passwords = ['123', '1234', '12345', '123456', 'password', 'test', 'admin', 'user'];
$tests['is_fake_password'] = in_array(strtolower($password), $obvious_fake_passwords);

// Step 3: Final decision
$email_valid = filter_var($email, FILTER_VALIDATE_EMAIL);
$password_length = strlen($password);
$password_not_empty = !empty(trim($password));
$is_fake_password = in_array(strtolower($password), $obvious_fake_passwords);

$should_be_valid = ($email_valid && $password_length >= 4 && $password_not_empty && !$is_fake_password);

echo json_encode([
    'input' => [
        'email' => $email,
        'password' => $password,
        'password_length' => strlen($password)
    ],
    'tests' => $tests,
    'final_decision' => [
        'should_be_valid' => $should_be_valid,
        'reason' => $should_be_valid ? 'All checks passed' : 'Failed validation'
    ],
    'debug_details' => [
        'email_valid' => $email_valid,
        'password_length_ok' => $password_length >= 4,
        'password_not_empty' => $password_not_empty,
        'not_fake_password' => !$is_fake_password
    ]
], JSON_PRETTY_PRINT);
?>