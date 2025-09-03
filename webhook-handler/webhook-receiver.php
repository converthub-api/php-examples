<?php
/**
 * ConvertHub API - Webhook Receiver
 * 
 * This script handles webhook notifications from ConvertHub API.
 * Deploy this on your server and use the URL as webhook_url when converting files.
 * 
 * Example webhook URL:
 *   https://your-server.com/webhook-receiver.php
 * 
 * The ConvertHub API will POST to this URL when conversion completes.
 */

// Log file for webhook events
$logFile = __DIR__ . '/webhook_events.log';

// Get the raw POST data
$rawData = file_get_contents('php://input');

// Parse JSON payload
$data = json_decode($rawData, true);

// Log the webhook event
logEvent($logFile, $data);

// Validate webhook data
if (empty($data) || !isset($data['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook payload']);
    exit;
}

// Process webhook based on event type
switch ($data['event']) {
    case 'conversion.completed':
        handleConversionCompleted($data);
        break;
        
    case 'conversion.failed':
        handleConversionFailed($data);
        break;
        
    default:
        logEvent($logFile, ['warning' => 'Unknown event type: ' . $data['event']]);
}

// Always return 200 OK to acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'received']);

/**
 * Handle successful conversion
 */
function handleConversionCompleted($data) {
    global $logFile;
    
    $jobId = $data['job_id'];
    $downloadUrl = $data['result']['download_url'];
    $format = $data['result']['format'];
    $fileSize = $data['result']['file_size'];
    $expiresAt = $data['result']['expires_at'];
    
    // Log success
    logEvent($logFile, [
        'event' => 'conversion.completed',
        'job_id' => $jobId,
        'format' => $format,
        'size' => formatFileSize($fileSize),
        'expires' => $expiresAt
    ]);
    
    // Process metadata if present
    if (isset($data['metadata']) && !empty($data['metadata'])) {
        processMetadata($data['metadata'], $jobId);
    }
    
    // Optional: Download the file automatically
    if (getenv('AUTO_DOWNLOAD') === 'true') {
        downloadFile($downloadUrl, $jobId, $format);
    }
    
    // Optional: Send notification email
    if ($emailTo = getenv('NOTIFICATION_EMAIL')) {
        sendNotificationEmail($emailTo, $jobId, $downloadUrl, $format);
    }
    
    // Optional: Update database
    if (function_exists('updateDatabase')) {
        updateDatabase($jobId, 'completed', $downloadUrl);
    }
}

/**
 * Handle failed conversion
 */
function handleConversionFailed($data) {
    global $logFile;
    
    $jobId = $data['job_id'];
    $error = $data['error'] ?? ['message' => 'Unknown error'];
    
    // Log failure
    logEvent($logFile, [
        'event' => 'conversion.failed',
        'job_id' => $jobId,
        'error' => $error['message'],
        'code' => $error['code'] ?? 'UNKNOWN'
    ]);
    
    // Process metadata to identify the user/request
    if (isset($data['metadata']) && !empty($data['metadata'])) {
        $metadata = $data['metadata'];
        
        // Notify user about failure
        if (isset($metadata['user_email'])) {
            sendFailureNotification($metadata['user_email'], $jobId, $error['message']);
        }
        
        // Update database
        if (isset($metadata['request_id']) && function_exists('updateDatabase')) {
            updateDatabase($metadata['request_id'], 'failed', null, $error['message']);
        }
    }
    
    // Optional: Alert admin for critical failures
    if ($error['code'] === 'SYSTEM_ERROR' && $adminEmail = getenv('ADMIN_EMAIL')) {
        alertAdmin($adminEmail, $jobId, $error);
    }
}

/**
 * Process custom metadata
 */
function processMetadata($metadata, $jobId) {
    global $logFile;
    
    // Example: Update your application based on metadata
    if (isset($metadata['user_id'])) {
        // Update user's conversion history
        logEvent($logFile, [
            'action' => 'update_user_history',
            'user_id' => $metadata['user_id'],
            'job_id' => $jobId
        ]);
    }
    
    if (isset($metadata['order_id'])) {
        // Mark order as processed
        logEvent($logFile, [
            'action' => 'update_order',
            'order_id' => $metadata['order_id'],
            'job_id' => $jobId
        ]);
    }
    
    // Add your custom metadata processing here
}

/**
 * Download converted file automatically
 */
function downloadFile($url, $jobId, $format) {
    $downloadDir = __DIR__ . '/downloads';
    if (!is_dir($downloadDir)) {
        mkdir($downloadDir, 0755, true);
    }
    
    $filename = $downloadDir . '/' . $jobId . '.' . $format;
    
    $ch = curl_init($url);
    $fp = fopen($filename, 'w');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    fclose($fp);
    
    if ($success && $httpCode === 200) {
        logEvent($GLOBALS['logFile'], [
            'action' => 'file_downloaded',
            'job_id' => $jobId,
            'path' => $filename,
            'size' => formatFileSize(filesize($filename))
        ]);
    } else {
        unlink($filename);
        logEvent($GLOBALS['logFile'], [
            'error' => 'download_failed',
            'job_id' => $jobId,
            'http_code' => $httpCode
        ]);
    }
}

/**
 * Send email notification
 */
function sendNotificationEmail($to, $jobId, $downloadUrl, $format) {
    $subject = "ConvertHub: Conversion Complete - Job $jobId";
    $message = "Your file conversion has completed successfully.\n\n";
    $message .= "Job ID: $jobId\n";
    $message .= "Format: $format\n";
    $message .= "Download URL: $downloadUrl\n\n";
    $message .= "Note: The download link will expire in 24 hours.\n";
    
    $headers = "From: noreply@your-server.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    mail($to, $subject, $message, $headers);
}

/**
 * Send failure notification
 */
function sendFailureNotification($to, $jobId, $errorMessage) {
    $subject = "ConvertHub: Conversion Failed - Job $jobId";
    $message = "Your file conversion has failed.\n\n";
    $message .= "Job ID: $jobId\n";
    $message .= "Error: $errorMessage\n\n";
    $message .= "Please try again or contact support if the problem persists.\n";
    
    $headers = "From: noreply@your-server.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    mail($to, $subject, $message, $headers);
}

/**
 * Alert admin about critical errors
 */
function alertAdmin($adminEmail, $jobId, $error) {
    $subject = "ALERT: ConvertHub System Error - Job $jobId";
    $message = "A critical error occurred during conversion.\n\n";
    $message .= "Job ID: $jobId\n";
    $message .= "Error Code: " . ($error['code'] ?? 'UNKNOWN') . "\n";
    $message .= "Error Message: " . ($error['message'] ?? 'Unknown error') . "\n";
    $message .= "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Please investigate this issue.\n";
    
    $headers = "From: alerts@your-server.com\r\n";
    $headers .= "X-Priority: 1\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    mail($adminEmail, $subject, $message, $headers);
}

/**
 * Log webhook events
 */
function logEvent($logFile, $data) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = [
        'timestamp' => $timestamp,
        'data' => $data
    ];
    
    file_put_contents(
        $logFile,
        json_encode($logEntry) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Example database update function (implement based on your needs)
 */
/*
function updateDatabase($requestId, $status, $downloadUrl = null, $error = null) {
    // Connect to your database
    $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
    
    // Update conversion record
    $sql = "UPDATE conversions SET 
            status = :status,
            download_url = :url,
            error_message = :error,
            updated_at = NOW()
            WHERE request_id = :id";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':status' => $status,
        ':url' => $downloadUrl,
        ':error' => $error,
        ':id' => $requestId
    ]);
}
*/