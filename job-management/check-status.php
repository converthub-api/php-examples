#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Job Management
 * 
 * Check status, download results, cancel or delete conversion jobs.
 * 
 * Usage:
 *   php check-status.php <job_id>              # Check job status
 *   php check-status.php <job_id> --download   # Download if complete
 *   php check-status.php <job_id> --cancel     # Cancel running job
 *   php check-status.php <job_id> --delete     # Delete completed file
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
    echo "Job Management - ConvertHub API\n";
    echo "================================\n\n";
    echo "Usage: php check-status.php <job_id> [options]\n\n";
    echo "Options:\n";
    echo "  --download     Download file if conversion is complete\n";
    echo "  --cancel       Cancel a running job\n";
    echo "  --delete       Delete completed file from storage\n";
    echo "  --watch        Monitor job until completion\n";
    echo "  --api-key=KEY  Your API key\n\n";
    echo "Examples:\n";
    echo "  php check-status.php job_123e4567-e89b-12d3\n";
    echo "  php check-status.php job_123e4567-e89b-12d3 --download\n";
    echo "  php check-status.php job_123e4567-e89b-12d3 --watch\n\n";
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
    exit(1);
}

// Execute requested action
if (isset($options['cancel'])) {
    cancelJob($jobId, $apiKey);
} elseif (isset($options['delete'])) {
    deleteFile($jobId, $apiKey);
} elseif (isset($options['watch'])) {
    watchJob($jobId, $apiKey, isset($options['download']));
} else {
    checkStatus($jobId, $apiKey, isset($options['download']));
}

/**
 * Check job status
 */
function checkStatus($jobId, $apiKey, $autoDownload = false) {
    echo "Job Status - ConvertHub API\n";
    echo "===========================\n\n";
    echo "Job ID: $jobId\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
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
        echo "  The job ID may be incorrect or the job has expired.\n";
        exit(1);
    }
    
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        echo "✗ Error: " . ($error['error']['message'] ?? 'Failed to get status') . "\n";
        exit(1);
    }
    
    $job = json_decode($response, true);
    $status = $job['status'];
    
    // Display status with appropriate icon
    $statusIcon = getStatusIcon($status);
    echo "Status: $statusIcon " . ucfirst($status) . "\n\n";
    
    // Display job details
    if (isset($job['source_format'])) {
        echo "Conversion: " . strtoupper($job['source_format']) . " → " . strtoupper($job['target_format']) . "\n";
    }
    
    if (isset($job['created_at'])) {
        echo "Created: " . $job['created_at'] . "\n";
    }
    
    // Display metadata if present
    if (isset($job['metadata']) && !empty($job['metadata'])) {
        echo "\nMetadata:\n";
        foreach ($job['metadata'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    }
    
    // Handle different statuses
    switch ($status) {
        case 'completed':
            echo "\n✓ Conversion complete!\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            
            if (isset($job['processing_time'])) {
                echo "Processing time: " . $job['processing_time'] . "\n";
            }
            
            echo "Download URL: " . $job['result']['download_url'] . "\n";
            echo "Format: " . $job['result']['format'] . "\n";
            echo "Size: " . formatFileSize($job['result']['file_size']) . "\n";
            echo "Expires: " . $job['result']['expires_at'] . "\n";
            
            if ($autoDownload) {
                echo "\nDownloading file...\n";
                downloadFile($job['result']['download_url'], $job['result']['format']);
            } else {
                echo "\nTo download: php check-status.php $jobId --download\n";
            }
            break;
            
        case 'processing':
        case 'queued':
            echo "\n⏳ Conversion in progress...\n";
            echo "To monitor: php check-status.php $jobId --watch\n";
            echo "To cancel: php check-status.php $jobId --cancel\n";
            break;
            
        case 'failed':
            echo "\n✗ Conversion failed\n";
            if (isset($job['error'])) {
                echo "Error: " . $job['error']['message'] . "\n";
                if (isset($job['error']['code'])) {
                    echo "Code: " . $job['error']['code'] . "\n";
                }
            }
            break;
            
        case 'cancelled':
            echo "\n⚠️ Job was cancelled\n";
            break;
    }
}

/**
 * Watch job until completion
 */
function watchJob($jobId, $apiKey, $autoDownload = false) {
    echo "Monitoring Job: $jobId\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Press Ctrl+C to stop monitoring\n\n";
    
    $previousStatus = null;
    $attempts = 0;
    
    while (true) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/jobs/$jobId");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo "\n✗ Failed to get status\n";
            exit(1);
        }
        
        $job = json_decode($response, true);
        $status = $job['status'];
        
        // Update display if status changed
        if ($status !== $previousStatus) {
            $timestamp = date('H:i:s');
            $icon = getStatusIcon($status);
            echo "[$timestamp] Status: $icon " . ucfirst($status) . "\n";
            $previousStatus = $status;
        } else {
            echo ".";
        }
        
        // Check if job is complete
        if (in_array($status, ['completed', 'failed', 'cancelled'])) {
            echo "\n\n";
            
            if ($status === 'completed') {
                echo "✓ Conversion complete!\n";
                echo "Download URL: " . $job['result']['download_url'] . "\n";
                echo "Size: " . formatFileSize($job['result']['file_size']) . "\n";
                
                if ($autoDownload) {
                    echo "\nDownloading file...\n";
                    downloadFile($job['result']['download_url'], $job['result']['format']);
                }
            } elseif ($status === 'failed') {
                echo "✗ Conversion failed\n";
                if (isset($job['error'])) {
                    echo "Error: " . $job['error']['message'] . "\n";
                }
            } else {
                echo "⚠️ Job was cancelled\n";
            }
            
            break;
        }
        
        $attempts++;
        if ($attempts > 600) { // 20 minutes max
            echo "\n\n⏱️ Timeout: Job is taking too long\n";
            break;
        }
        
        sleep(2);
    }
}

/**
 * Cancel a running job
 */
function cancelJob($jobId, $apiKey) {
    echo "Cancelling job: $jobId\n";
    
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
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        echo "✓ Job cancelled successfully\n";
    } else {
        $error = json_decode($response, true);
        echo "✗ Failed to cancel job\n";
        echo "Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
    }
}

/**
 * Delete completed file
 */
function deleteFile($jobId, $apiKey) {
    echo "Deleting file for job: $jobId\n";
    echo "Warning: This action is irreversible!\n\n";
    echo "Continue? (yes/no): ";
    
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'yes') {
        echo "Cancelled.\n";
        exit(0);
    }
    
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
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        echo "✓ File deleted successfully\n";
        if (isset($result['deleted_at'])) {
            echo "Deleted at: " . $result['deleted_at'] . "\n";
        }
    } else {
        $error = json_decode($response, true);
        echo "✗ Failed to delete file\n";
        echo "Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
        
        if (isset($error['error']['code'])) {
            if ($error['error']['code'] === 'JOB_NOT_COMPLETED') {
                echo "Note: Only completed conversions can be deleted.\n";
            }
        }
    }
}

/**
 * Download file
 */
function downloadFile($url, $format) {
    $outputFile = 'downloaded_' . time() . '.' . $format;
    
    $ch = curl_init($url);
    $fp = fopen($outputFile, 'w');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dlSize, $dlNow) {
        if ($dlSize > 0) {
            $percent = round($dlNow / $dlSize * 100);
            echo "\rDownloading: $percent%";
        }
        return 0;
    });
    
    $success = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    echo "\n";
    
    if ($success) {
        echo "✓ File saved: $outputFile (" . formatFileSize(filesize($outputFile)) . ")\n";
    } else {
        echo "✗ Download failed\n";
        unlink($outputFile);
    }
}

/**
 * Get status icon
 */
function getStatusIcon($status) {
    $icons = [
        'queued' => '⏳',
        'processing' => '🔄',
        'completed' => '✅',
        'failed' => '❌',
        'cancelled' => '⚠️'
    ];
    
    return $icons[$status] ?? '❓';
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