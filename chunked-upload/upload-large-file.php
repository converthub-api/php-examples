#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Chunked Upload for Large Files
 * 
 * Upload and convert large files (up to 2GB) using chunked upload.
 * Files are split into chunks and uploaded sequentially.
 * 
 * Usage:
 *   php upload-large-file.php <input_file> <target_format> [--chunk-size=MB]
 * 
 * Examples:
 *   php upload-large-file.php video.mov mp4
 *   php upload-large-file.php large-document.pdf docx --chunk-size=10
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
if ($argc < 3) {
    echo "Chunked Upload - ConvertHub API\n";
    echo "================================\n\n";
    echo "Upload and convert large files (up to 2GB) in chunks.\n\n";
    echo "Usage: php upload-large-file.php <input_file> <target_format> [options]\n\n";
    echo "Options:\n";
    echo "  --chunk-size=MB    Chunk size in megabytes (default: 5MB)\n";
    echo "  --api-key=KEY      Your API key\n";
    echo "  --webhook=URL      Webhook URL for notifications\n\n";
    echo "Examples:\n";
    echo "  php upload-large-file.php video.mov mp4\n";
    echo "  php upload-large-file.php large.pdf docx --chunk-size=10\n\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

$inputFile = $argv[1];
$targetFormat = strtolower($argv[2]);

// Parse options
$options = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)=(.+)$/', $arg, $matches)) {
        $options[$matches[1]] = $matches[2];
    }
}

// Get API key
$apiKey = $options['api-key'] ?? getenv('CONVERTHUB_API_KEY');
if (empty($apiKey)) {
    echo "Error: API key required. Set CONVERTHUB_API_KEY in .env or use --api-key parameter.\n";
    exit(1);
}

// Validate file
if (!file_exists($inputFile)) {
    echo "Error: File '$inputFile' not found.\n";
    exit(1);
}

$fileSize = filesize($inputFile);
$fileSizeMB = round($fileSize / 1048576, 2);
$filename = basename($inputFile);

// Check file size limit (2GB)
if ($fileSize > 2147483648) {
    echo "Error: File size ($fileSizeMB MB) exceeds 2GB limit.\n";
    exit(1);
}

// Determine chunk size (default 5MB)
$chunkSizeMB = isset($options['chunk-size']) ? intval($options['chunk-size']) : 5;
$chunkSize = $chunkSizeMB * 1048576;
$totalChunks = ceil($fileSize / $chunkSize);

echo "Chunked Upload - ConvertHub API\n";
echo "================================\n";
echo "File: $filename ($fileSizeMB MB)\n";
echo "Target format: $targetFormat\n";
echo "Chunk size: $chunkSizeMB MB\n";
echo "Total chunks: $totalChunks\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Step 1: Initialize chunked upload session
echo "→ Initializing upload session...\n";

$initData = [
    'filename' => $filename,
    'file_size' => $fileSize,
    'total_chunks' => $totalChunks,
    'target_format' => $targetFormat
];

// Add optional webhook
if (isset($options['webhook'])) {
    $initData['webhook_url'] = $options['webhook'];
}

// Add metadata
$initData['metadata'] = [
    'original_size' => $fileSize,
    'chunk_size' => $chunkSize,
    'upload_time' => date('Y-m-d H:i:s')
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.converthub.com/v2/upload/init');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($initData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 && $httpCode !== 201) {
    $error = json_decode($response, true);
    echo "✗ Failed to initialize upload\n";
    echo "Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
    exit(1);
}

$session = json_decode($response, true);
$sessionId = $session['session_id'];
$expiresAt = $session['expires_at'];

echo "✓ Session created: $sessionId\n";
echo "  Expires at: $expiresAt\n\n";

// Step 2: Upload chunks
echo "→ Uploading chunks...\n\n";

$file = fopen($inputFile, 'rb');
if (!$file) {
    echo "✗ Failed to open file for reading\n";
    exit(1);
}

$uploadedChunks = 0;
$startTime = time();

for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
    // Read chunk data
    $chunkData = fread($file, $chunkSize);
    if ($chunkData === false) {
        echo "✗ Failed to read chunk $chunkIndex\n";
        fclose($file);
        exit(1);
    }
    
    // Create temporary file for chunk
    $tempFile = tempnam(sys_get_temp_dir(), 'chunk_');
    file_put_contents($tempFile, $chunkData);
    
    // Upload chunk
    $progress = round(($chunkIndex + 1) / $totalChunks * 100);
    echo "\rUploading chunk " . ($chunkIndex + 1) . "/$totalChunks ($progress%)...";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/upload/$sessionId/chunks/$chunkIndex");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chunk' => new CURLFile($tempFile)
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Clean up temp file
    unlink($tempFile);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        echo "\n✗ Failed to upload chunk " . ($chunkIndex + 1) . "\n";
        $error = json_decode($response, true);
        echo "Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
        fclose($file);
        exit(1);
    }
    
    $uploadedChunks++;
    
    // Calculate and display upload speed
    $elapsed = time() - $startTime;
    if ($elapsed > 0) {
        $speed = ($uploadedChunks * $chunkSize) / $elapsed / 1048576; // MB/s
        echo " [" . round($speed, 1) . " MB/s]";
    }
}

fclose($file);

$uploadTime = time() - $startTime;
echo "\n✓ All chunks uploaded successfully in " . formatTime($uploadTime) . "\n\n";

// Step 3: Complete upload and start conversion
echo "→ Finalizing upload and starting conversion...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/upload/$sessionId/complete");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 && $httpCode !== 202) {
    $error = json_decode($response, true);
    echo "✗ Failed to complete upload\n";
    echo "Error: " . ($error['error']['message'] ?? 'Unknown error') . "\n";
    if (isset($error['error']['details']['missing_chunks'])) {
        echo "Missing chunks: " . implode(', ', $error['error']['details']['missing_chunks']) . "\n";
    }
    exit(1);
}

$job = json_decode($response, true);
$jobId = $job['job_id'];

echo "✓ Upload complete! Conversion started.\n";
echo "  Job ID: $jobId\n\n";

// Step 4: Monitor conversion progress
echo "→ Converting";

$attempts = 0;
$maxAttempts = 180; // 6 minutes for large files
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

// Step 5: Display results
if ($status === 'completed') {
    echo "✓ Conversion complete!\n\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Results:\n";
    echo "  Download URL: " . $jobStatus['result']['download_url'] . "\n";
    echo "  Format: " . $jobStatus['result']['format'] . "\n";
    echo "  Size: " . formatFileSize($jobStatus['result']['file_size']) . "\n";
    echo "  Processing time: " . ($jobStatus['processing_time'] ?? 'N/A') . "\n";
    echo "  Total time: " . formatTime(time() - $startTime) . "\n";
    echo "  Expires: " . $jobStatus['result']['expires_at'] . "\n";
    
    // Offer to download
    echo "\nDownload converted file? (y/n): ";
    $answer = trim(fgets(STDIN));
    
    if (strtolower($answer) === 'y') {
        downloadLargeFile($jobStatus['result']['download_url'], $targetFormat);
    }
} elseif ($status === 'failed') {
    echo "✗ Conversion failed\n";
    echo "Error: " . ($jobStatus['error']['message'] ?? 'Unknown error') . "\n";
    exit(1);
} else {
    echo "✗ Timeout: Conversion is taking longer than expected\n";
    echo "Large files may take more time to process.\n";
    echo "Check status later with: php job-management/check-status.php $jobId\n";
    
    if (isset($options['webhook'])) {
        echo "You will receive a webhook notification when complete.\n";
    }
    exit(1);
}

/**
 * Download large file with progress
 */
function downloadLargeFile($url, $format) {
    $outputFile = 'converted_' . time() . '.' . $format;
    echo "Downloading to: $outputFile\n\n";
    
    $ch = curl_init($url);
    $fp = fopen($outputFile, 'w');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for large files
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dlSize, $dlNow) {
        if ($dlSize > 0) {
            $percent = round($dlNow / $dlSize * 100);
            $downloaded = formatFileSize($dlNow);
            $total = formatFileSize($dlSize);
            echo "\rDownloading: $percent% ($downloaded / $total)";
        }
        return 0;
    });
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    echo "\n";
    
    if ($success && $httpCode === 200) {
        echo "✓ File saved: $outputFile (" . formatFileSize(filesize($outputFile)) . ")\n";
    } else {
        echo "✗ Download failed\n";
        unlink($outputFile);
    }
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
 * Format time duration
 */
function formatTime($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    } elseif ($seconds < 3600) {
        return round($seconds / 60, 1) . ' minutes';
    } else {
        return round($seconds / 3600, 1) . ' hours';
    }
}