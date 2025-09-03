#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Simple File Conversion
 *
 * Convert a file from one format to another using the ConvertHub API.
 * Supports files up to 50MB. For larger files, use the chunked upload example.
 *
 * Usage:
 *   php convert.php <input_file> <target_format> [--api-key=KEY]
 *
 * Examples:
 *   php convert.php document.pdf docx
 *   php convert.php image.png jpg
 *   php convert.php audio.wav mp3
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

// Parse command line arguments
if ($argc < 3) {
    echo "Simple File Conversion - ConvertHub API\n";
    echo "========================================\n\n";
    echo "Usage: php convert.php <input_file> <target_format> [--api-key=KEY]\n\n";
    echo "Examples:\n";
    echo "  php convert.php document.pdf docx\n";
    echo "  php convert.php image.png jpg\n";
    echo "  php convert.php video.mp4 webm\n\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

$inputFile = $argv[1];
$targetFormat = strtolower($argv[2]);

// Get API key from environment or command line
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

// Validate input file
if (!file_exists($inputFile)) {
    echo "Error: File '$inputFile' not found.\n";
    exit(1);
}

$fileSize = filesize($inputFile);
$fileSizeMB = round($fileSize / 1048576, 2);

if ($fileSize > 52428800) { // 50MB
    echo "Error: File size ($fileSizeMB MB) exceeds 50MB limit.\n";
    echo "Use chunked-upload/upload-large-file.php for files larger than 50MB.\n";
    exit(1);
}

echo "Converting: " . basename($inputFile) . " ($fileSizeMB MB) to $targetFormat\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Step 1: Submit file for conversion
echo "→ Uploading file...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.converthub.com/v2/convert');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => new CURLFile($inputFile),
    'target_format' => $targetFormat
]);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    echo "✗ Failed to connect to API\n";
    exit(1);
}

$result = json_decode($response, true);

// Handle errors
if ($httpCode >= 400) {
    echo "✗ Error: " . $result['error']['message'] . "\n";
    if (isset($result['error']['details'])) {
        foreach ($result['error']['details'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    }
    exit(1);
}

// Check for cached result (instant conversion)
if ($httpCode === 200 && isset($result['result']['download_url'])) {
    echo "✓ Conversion complete (cached result)\n\n";
    echo "Download URL: " . $result['result']['download_url'] . "\n";
    echo "Size: " . round($result['result']['file_size'] / 1048576, 2) . " MB\n";
    echo "Expires: " . $result['result']['expires_at'] . "\n";
    exit(0);
}

$jobId = $result['job_id'];
echo "✓ Job created: $jobId\n\n";

// Step 2: Monitor conversion progress
echo "→ Converting";

$attempts = 0;
$maxAttempts = 450; // 5 minutes max
$status = 'processing';

while (($status === 'processing' || $status === 'queued') && $attempts < $maxAttempts) {
    sleep(2);
    $attempts++;
    echo ".";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/jobs/$jobId");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response !== false) {
        $jobStatus = json_decode($response, true);
        $status = $jobStatus['status'] ?? 'unknown';
    }
}

echo "\n";

// Step 3: Get results
if ($status === 'completed') {
    echo "✓ Conversion complete!\n\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Download URL: " . $jobStatus['result']['download_url'] . "\n";
    echo "Format: " . $jobStatus['result']['format'] . "\n";
    echo "Size: " . round($jobStatus['result']['file_size'] / 1048576, 2) . " MB\n";
    echo "Processing time: " . ($jobStatus['processing_time'] ?? 'N/A') . "\n";
    echo "Expires: " . $jobStatus['result']['expires_at'] . "\n";

    // Optionally download the file
    echo "\nDownload file now? (y/n): ";
    $answer = trim(fgets(STDIN));

    if (strtolower($answer) === 'y') {
        $outputFile = pathinfo($inputFile, PATHINFO_FILENAME) . '.' . $targetFormat;
        echo "Downloading to: $outputFile\n";

        $ch = curl_init($jobStatus['result']['download_url']);
        $fp = fopen($outputFile, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        echo "✓ File saved: $outputFile\n";
    }
} elseif ($status === 'failed') {
    echo "✗ Conversion failed\n";
    echo "Error: " . ($jobStatus['error']['message'] ?? 'Unknown error') . "\n";
    exit(1);
} else {
    echo "✗ Timeout: Conversion is taking longer than expected\n";
    echo "Check status with: php job-management/check-status.php $jobId\n";
    exit(1);
}
