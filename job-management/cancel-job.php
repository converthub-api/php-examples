#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Cancel Job
 * 
 * Cancel a running or queued conversion job.
 * 
 * Usage:
 *   php cancel-job.php <job_id> [--api-key=KEY]
 * 
 * Example:
 *   php cancel-job.php job_123e4567-e89b-12d3-a456-426614174000
 * 
 * Get your API key at: https://converthub.com/api
 */

// Load configuration
$configFile = dirname(__DIR__) . '/.env';
if (file_exists($configFile)) {
    $config = parse_ini_file($configFile);
    foreach ($config as $key => $value) {
        putenv("$key=$value");
    }
}

// Parse arguments
if ($argc < 2) {
    echo "Cancel Job - ConvertHub API\n";
    echo "============================\n\n";
    echo "Usage: php cancel-job.php <job_id> [--api-key=KEY]\n\n";
    echo "Example:\n";
    echo "  php cancel-job.php job_123e4567-e89b-12d3-a456-426614174000\n\n";
    echo "Note: Only jobs that are 'queued' or 'processing' can be cancelled.\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

$jobId = $argv[1];

// Get API key
$apiKey = getenv('CONVERTHUB_API_KEY');
foreach ($argv as $arg) {
    if (strpos($arg, '--api-key=') === 0) {
        $apiKey = substr($arg, 10);
        break;
    }
}

if (empty($apiKey)) {
    echo "Error: API key required. Set CONVERTHUB_API_KEY in .env or use --api-key parameter.\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

echo "Cancel Job\n";
echo "==========\n";
echo "Job ID: $jobId\n\n";

// First, check the current job status
echo "→ Checking job status...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/jobs/$jobId");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 404) {
    echo "✗ Job not found\n";
    echo "  The job ID may be incorrect or has expired.\n";
    exit(1);
}

if ($httpCode !== 200) {
    $error = json_decode($response, true);
    echo "✗ Error: " . ($error['error']['message'] ?? 'Failed to get job status') . "\n";
    exit(1);
}

$job = json_decode($response, true);
$currentStatus = $job['status'];

echo "  Current status: " . ucfirst($currentStatus) . "\n";

// Check if job can be cancelled
if ($currentStatus === 'completed') {
    echo "\n✗ Cannot cancel: Job has already completed.\n";
    echo "  Download URL: " . $job['result']['download_url'] . "\n";
    echo "\nTo delete the file, use:\n";
    echo "  php delete-file.php $jobId\n";
    exit(1);
} elseif ($currentStatus === 'failed') {
    echo "\n✗ Cannot cancel: Job has already failed.\n";
    if (isset($job['error']['message'])) {
        echo "  Error: " . $job['error']['message'] . "\n";
    }
    exit(1);
} elseif ($currentStatus === 'cancelled') {
    echo "\n✗ Job has already been cancelled.\n";
    exit(1);
}

// Show job details before cancelling
if (isset($job['source_format']) && isset($job['target_format'])) {
    echo "  Conversion: " . strtoupper($job['source_format']) . " → " . strtoupper($job['target_format']) . "\n";
}
if (isset($job['created_at'])) {
    echo "  Started: " . $job['created_at'] . "\n";
}

// Confirm cancellation
echo "\n⚠️  Warning: This will cancel the conversion job.\n";
echo "Continue? (yes/no): ";
$answer = trim(fgets(STDIN));

if (strtolower($answer) !== 'yes' && strtolower($answer) !== 'y') {
    echo "Cancelled by user.\n";
    exit(0);
}

// Cancel the job
echo "\n→ Cancelling job...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/jobs/$jobId");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 204) {
    $result = json_decode($response, true);
    echo "\n✓ Job cancelled successfully!\n";
    
    if (isset($result['job_id'])) {
        echo "  Job ID: " . $result['job_id'] . "\n";
    }
    if (isset($result['status'])) {
        echo "  Status: " . $result['status'] . "\n";
    }
    if (isset($result['message'])) {
        echo "  Message: " . $result['message'] . "\n";
    }
    
    echo "\nThe conversion job has been cancelled and any processing has been stopped.\n";
} else {
    $error = json_decode($response, true);
    echo "\n✗ Failed to cancel job\n";
    echo "  Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
    
    if (isset($error['error']['code'])) {
        echo "  Code: " . $error['error']['code'] . "\n";
        
        // Provide helpful messages for common errors
        switch ($error['error']['code']) {
            case 'JOB_NOT_FOUND':
                echo "\nThe job could not be found. It may have expired or the ID is incorrect.\n";
                break;
            case 'JOB_ALREADY_COMPLETED':
                echo "\nThe job has already completed and cannot be cancelled.\n";
                break;
            case 'JOB_ALREADY_CANCELLED':
                echo "\nThe job has already been cancelled.\n";
                break;
        }
    }
    exit(1);
}