#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Download Conversion Result
 *
 * Download the converted file from a completed job.
 *
 * Usage:
 *   php download-result.php <job_id> [--output=filename] [--api-key=KEY]
 *
 * Examples:
 *   php download-result.php job_123e4567-e89b-12d3-a456-426614174000
 *   php download-result.php job_123e4567-e89b-12d3 --output=converted.pdf
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
    echo "Download Conversion Result - ConvertHub API\n";
    echo "===========================================\n\n";
    echo "Download the converted file from a completed job.\n\n";
    echo "Usage: php download-result.php <job_id> [options]\n\n";
    echo "Options:\n";
    echo "  --output=FILE  Save with custom filename\n";
    echo "  --api-key=KEY  Your API key\n";
    echo "  --quiet        Suppress progress output\n\n";
    echo "Examples:\n";
    echo "  php download-result.php job_123e4567-e89b-12d3\n";
    echo "  php download-result.php job_123e4567-e89b-12d3 --output=result.pdf\n\n";
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

$quiet = isset($options['quiet']);

if (!$quiet) {
    echo "Download Conversion Result\n";
    echo "==========================\n";
    echo "Job ID: $jobId\n\n";
}

// Step 1: Get job details and download URL
if (!$quiet) {
    echo "→ Fetching job details...\n";
}

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
    echo "✗ Error: " . ($error['error']['message'] ?? 'Failed to get job details') . "\n";
    exit(1);
}

$job = json_decode($response, true);

$status = $job['status'];

// Check if job is completed
if ($status !== 'completed') {
    echo "✗ Cannot download: Job is not completed.\n";
    echo "  Current status: " . ucfirst($status) . "\n";

    if ($status === 'processing' || $status === 'queued') {
        echo "\nThe conversion is still in progress.\n";
        echo "You can:\n";
        echo "  1. Check status: php check-status.php $jobId\n";
        echo "  2. Watch progress: php check-status.php $jobId --watch\n";
    } elseif ($status === 'failed') {
        echo "\nThe conversion failed and there is no file to download.\n";
        if (isset($job['error']['message'])) {
            echo "  Error: " . $job['error']['message'] . "\n";
        }
    } elseif ($status === 'cancelled') {
        echo "\nThe job was cancelled and there is no file to download.\n";
    }

    exit(1);
}

// Get download URL and file info
$downloadUrl = $job['result']['download_url'] ?? null;
$fileFormat = $job['result']['format'] ?? 'unknown';
$fileSize = $job['result']['file_size'] ?? 0;

if (!$downloadUrl) {
    echo "✗ Error: No download URL available for this job.\n";
    exit(1);
}

// Display job information
if (!$quiet) {
    echo "\nJob Information:\n";
    if (isset($job['source_format']) && isset($job['target_format'])) {
        echo "  Conversion: " . strtoupper($job['source_format']) . " → " . strtoupper($job['target_format']) . "\n";
    }
    echo "  Status: Completed\n";
    if (isset($job['processing_time'])) {
        echo "  Processing time: " . $job['processing_time'] . "\n";
    }

    echo "\nFile Information:\n";
    echo "  Format: " . strtoupper($fileFormat) . "\n";
    echo "  Size: " . formatFileSize($fileSize) . "\n";
    if (isset($job['result']['expires_at'])) {
        echo "  Expires: " . $job['result']['expires_at'] . "\n";
    }
}

// Determine output filename
if (isset($options['output'])) {
    $outputFile = $options['output'];
} else {
    // Generate filename based on job ID and format
    $timestamp = date('Y-m-d_His');
    $outputFile = "converted_{$timestamp}.$fileFormat";
}

// Check if file already exists
if (file_exists($outputFile)) {
    if (!$quiet) {
        echo "\n⚠️  File already exists: $outputFile\n";
        echo "Overwrite? (yes/no): ";
        $answer = trim(fgets(STDIN));

        if (strtolower($answer) !== 'yes' && strtolower($answer) !== 'y') {
            echo "Download cancelled.\n";
            exit(0);
        }
    }
}

// Step 2: Download the file
if (!$quiet) {
    echo "\n→ Downloading file...\n";
    echo "  Saving to: $outputFile\n";
}

$fp = fopen($outputFile, 'w+');
if (!$fp) {
    echo "✗ Error: Cannot create output file: $outputFile\n";
    echo "  Check if you have write permissions in this directory.\n";
    exit(1);
}

$ch = curl_init($downloadUrl);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for large files

// Add progress callback unless quiet mode
if (!$quiet) {
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dlSize, $dlNow, $ulSize, $ulNow) {
        static $lastProgress = -1;

        if ($dlSize > 0) {
            $progress = round($dlNow / $dlSize * 100);

            // Only update if progress changed
            if ($progress !== $lastProgress) {
                $downloaded = formatFileSize($dlNow);
                $total = formatFileSize($dlSize);
                $speed = calculateSpeed($dlNow);

                echo "\r  Progress: $progress% [$downloaded / $total] $speed";

                $lastProgress = $progress;
            }
        } else {
            // Size unknown, just show downloaded amount
            $downloaded = formatFileSize($dlNow);
            echo "\r  Downloaded: $downloaded";
        }

        return 0;
    });
}

$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
fclose($fp);

if (!$quiet) {
    echo "\n";
}

// Check download result
if (!$success || $httpCode !== 200) {
    echo "\n✗ Download failed\n";
    if ($error) {
        echo "  Error: $error\n";
    }
    if ($httpCode !== 200 && $httpCode !== 0) {
        echo "  HTTP Code: $httpCode\n";
    }

    // Clean up failed download
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }

    echo "\nTroubleshooting:\n";
    echo "  1. Check if the download URL has expired\n";
    echo "  2. Try downloading directly: $downloadUrl\n";
    echo "  3. Check your internet connection\n";

    exit(1);
}

// Verify downloaded file
$downloadedSize = filesize($outputFile);

if (!$quiet) {
    echo "\n✓ Download complete!\n";
    echo "  File: $outputFile\n";
    echo "  Size: " . formatFileSize($downloadedSize) . "\n";

    // Verify size if known
    if ($fileSize > 0 && abs($downloadedSize - $fileSize) > 1024) {
        echo "\n⚠️  Warning: Downloaded size differs from expected size\n";
        echo "  Expected: " . formatFileSize($fileSize) . "\n";
        echo "  Downloaded: " . formatFileSize($downloadedSize) . "\n";
    }

    echo "\nThe converted file has been successfully downloaded.\n";
} else {
    // In quiet mode, just output the filename
    echo $outputFile . "\n";
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

/**
 * Calculate download speed
 */
function calculateSpeed($bytesDownloaded) {
    static $startTime = null;
    static $lastBytes = 0;
    static $lastTime = null;

    if ($startTime === null) {
        $startTime = microtime(true);
        $lastTime = $startTime;
        return '';
    }

    $currentTime = microtime(true);
    $timeDiff = $currentTime - $lastTime;

    if ($timeDiff >= 1) { // Update speed every second
        $bytesDiff = $bytesDownloaded - $lastBytes;
        $speed = $bytesDiff / $timeDiff;

        $lastBytes = $bytesDownloaded;
        $lastTime = $currentTime;

        if ($speed > 0) {
            return '[' . formatFileSize($speed) . '/s]';
        }
    }

    return '';
}
