#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Delete Converted File
 * 
 * Permanently delete a completed conversion file from storage.
 * Use this to immediately remove files before the automatic 24-hour expiration.
 * 
 * Usage:
 *   php delete-file.php <job_id> [--force] [--api-key=KEY]
 * 
 * Examples:
 *   php delete-file.php job_123e4567-e89b-12d3-a456-426614174000
 *   php delete-file.php job_123e4567-e89b-12d3-a456-426614174000 --force
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
    echo "Delete Converted File - ConvertHub API\n";
    echo "=======================================\n\n";
    echo "Permanently delete a converted file from storage.\n\n";
    echo "Usage: php delete-file.php <job_id> [options]\n\n";
    echo "Options:\n";
    echo "  --force        Skip confirmation prompt\n";
    echo "  --api-key=KEY  Your API key\n\n";
    echo "Examples:\n";
    echo "  php delete-file.php job_123e4567-e89b-12d3\n";
    echo "  php delete-file.php job_123e4567-e89b-12d3 --force\n\n";
    echo "Note: This action is IRREVERSIBLE. Deleted files cannot be recovered.\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

$jobId = $argv[1];

// Parse options
$options = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        if (strpos($arg, '=') !== false) {
            list($key, $value) = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        } else {
            $options[substr($arg, 2)] = true;
        }
    }
}

// Get API key
$apiKey = $options['api-key'] ?? getenv('CONVERTHUB_API_KEY');
if (empty($apiKey)) {
    echo "Error: API key required. Set CONVERTHUB_API_KEY in .env or use --api-key parameter.\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

echo "Delete Converted File\n";
echo "=====================\n";
echo "Job ID: $jobId\n\n";

// First, get job details
echo "→ Fetching job details...\n";

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
    echo "  The job ID may be incorrect or has already expired.\n";
    exit(1);
}

if ($httpCode !== 200) {
    $error = json_decode($response, true);
    echo "✗ Error: " . ($error['error']['message'] ?? 'Failed to get job details') . "\n";
    exit(1);
}

$job = json_decode($response, true);
$status = $job['status'];

// Display job information
echo "\nJob Information:\n";
echo "  Status: " . ucfirst($status) . "\n";

if (isset($job['source_format']) && isset($job['target_format'])) {
    echo "  Conversion: " . strtoupper($job['source_format']) . " → " . strtoupper($job['target_format']) . "\n";
}

// Check if job is completed
if ($status !== 'completed') {
    echo "\n✗ Cannot delete: Job is not completed.\n";
    echo "  Current status: " . ucfirst($status) . "\n";
    
    if ($status === 'processing' || $status === 'queued') {
        echo "\nThe job is still being processed. You can:\n";
        echo "  1. Wait for completion: php check-status.php $jobId --watch\n";
        echo "  2. Cancel the job: php cancel-job.php $jobId\n";
    } elseif ($status === 'failed') {
        echo "\nThe job failed and there is no file to delete.\n";
        if (isset($job['error']['message'])) {
            echo "  Error: " . $job['error']['message'] . "\n";
        }
    } elseif ($status === 'cancelled') {
        echo "\nThe job was cancelled and there is no file to delete.\n";
    }
    
    exit(1);
}

// Display file information
if (isset($job['result'])) {
    echo "\nFile Information:\n";
    if (isset($job['result']['download_url'])) {
        echo "  Download URL: " . $job['result']['download_url'] . "\n";
    }
    if (isset($job['result']['file_size'])) {
        echo "  Size: " . formatFileSize($job['result']['file_size']) . "\n";
    }
    if (isset($job['result']['format'])) {
        echo "  Format: " . strtoupper($job['result']['format']) . "\n";
    }
    if (isset($job['result']['expires_at'])) {
        echo "  Expires: " . $job['result']['expires_at'] . "\n";
        
        // Calculate time until expiration
        $expiresAt = strtotime($job['result']['expires_at']);
        $now = time();
        $hoursLeft = round(($expiresAt - $now) / 3600, 1);
        if ($hoursLeft > 0) {
            echo "  Time remaining: " . $hoursLeft . " hours\n";
        }
    }
}

// Display metadata if present
if (isset($job['metadata']) && !empty($job['metadata'])) {
    echo "\nMetadata:\n";
    foreach ($job['metadata'] as $key => $value) {
        echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
    }
}

// Confirmation prompt (unless --force is used)
if (!isset($options['force'])) {
    echo "\n⚠️  WARNING: This action is IRREVERSIBLE!\n";
    echo "The file will be permanently deleted and cannot be recovered.\n\n";
    echo "Are you sure you want to delete this file? (yes/no): ";
    
    $answer = trim(fgets(STDIN));
    
    if (strtolower($answer) !== 'yes' && strtolower($answer) !== 'y') {
        echo "Deletion cancelled by user.\n";
        exit(0);
    }
}

// Delete the file
echo "\n→ Deleting file...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/jobs/$jobId/destroy");
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
    echo "\n✓ File deleted successfully!\n";
    
    if (isset($result['message'])) {
        echo "  " . $result['message'] . "\n";
    }
    if (isset($result['job_id'])) {
        echo "  Job ID: " . $result['job_id'] . "\n";
    }
    if (isset($result['deleted_at'])) {
        echo "  Deleted at: " . $result['deleted_at'] . "\n";
    }
    
    echo "\nThe converted file and all associated data have been permanently removed.\n";
    echo "This action freed up storage and ensures data privacy.\n";
} else {
    $error = json_decode($response, true);
    echo "\n✗ Failed to delete file\n";
    echo "  Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
    
    if (isset($error['error']['code'])) {
        echo "  Code: " . $error['error']['code'] . "\n";
        
        // Provide helpful messages for common errors
        switch ($error['error']['code']) {
            case 'JOB_NOT_FOUND':
                echo "\nThe job could not be found. It may have already expired.\n";
                break;
            case 'JOB_NOT_COMPLETED':
                echo "\nOnly completed conversions can be deleted.\n";
                echo "Current status: " . ($error['error']['details']['status'] ?? 'Unknown') . "\n";
                break;
            case 'FILE_ALREADY_DELETED':
                echo "\nThe file has already been deleted.\n";
                break;
            case 'FILE_NOT_FOUND':
                echo "\nThe converted file could not be found in storage.\n";
                break;
        }
    }
    exit(1);
}

/**
 * Format file size in human-readable format
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