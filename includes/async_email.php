<?php
// includes/async_email.php - Background Email Processor
error_reporting(0);
ini_set('display_errors', 0);

// Get POST data
$data = $_POST;

if (empty($data) || !isset($data['type'])) {
    http_response_code(400);
    exit('No data received');
}

// Load required files
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/email_helper.php';

// Create logs directory if not exists
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

$logFile = $logDir . '/email_log.txt';

switch ($data['type']) {
    case 'status_update':
        $result = EmailHelper::sendStatusUpdateEmail(
            $data['email'],
            $data['name'],
            $data['subject'],
            $data['message']
        );
        break;
        
    case 'welcome':
        $result = EmailHelper::sendWelcomeEmail(
            $data['email'],
            $data['name'],
            $data['login_url'] ?? ''
        );
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Unknown email type'];
}

// Log the result
$logEntry = date('Y-m-d H:i:s') . " | Type: {$data['type']} | To: {$data['email']} | " . 
            ($result['success'] ? 'SUCCESS' : 'FAILED: ' . $result['message']) . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Return success (even if email failed - we logged it)
http_response_code(200);
exit();