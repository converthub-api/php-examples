#!/usr/bin/env php
<?php
/**
 * ConvertHub API - URL File Conversion
 * 
 * Convert files directly from URLs without downloading them first.
 * The API will fetch the file from the URL and convert it.
 * 
 * Usage:
 *   php convert-from-url.php <file_url> <target_format> [--api-key=KEY]
 * 
 * Examples:
 *   php convert-from-url.php https://example.com/document.pdf docx
 *   php convert-from-url.php https://example.com/image.png jpg
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
    echo "URL File Conversion - ConvertHub API\n";
    echo "=====================================\n\n";
    echo "Usage: php convert-from-url.php <file_url> <target_format> [--api-key=KEY]\n\n";
    echo "Examples:\n";
    echo "  php convert-from-url.php https://example.com/document.pdf docx\n";
    echo "  php convert-from-url.php https://example.com/image.png jpg\n";
    echo "  php convert-from-url.php https://example.com/video.mp4 webm\n\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

$fileUrl = $argv[1];
$targetFormat = strtolower($argv[2]);

// Validate URL
if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
    echo "Error: Invalid URL format.\n";
    exit(1);
}

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

// Extract filename from URL
$urlParts = parse_url($fileUrl);
$filename = basename($urlParts['path']) ?: 'file';

echo "URL Conversion: $filename to $targetFormat\n";
echo "Source URL: $fileUrl\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Step 1: Submit URL for conversion
echo "→ Submitting URL for conversion...\n";

$data = [
    'file_url' => $fileUrl,
    'target_format' => $targetFormat,
    'output_filename' => pathinfo($filename, PATHINFO_FILENAME) . '.' . $targetFormat
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.converthub.com/v2/convert-url');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
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
    if (isset($result['error']['code'])) {
        echo "  Code: " . $result['error']['code'] . "\n";
    }
    if (isset($result['error']['details'])) {
        foreach ($result['error']['details'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    }
    exit(1);
}

// Check for cached result
if ($httpCode === 200 && isset($result['result']['download_url'])) {
    echo "✓ Conversion complete (cached result)\n\n";
    displayResult($result['result']);
    exit(0);
}

$jobId = $result['job_id'];
echo "✓ Job created: $jobId\n";
echo "  The API is downloading the file from URL...\n\n";

// Step 2: Monitor conversion progress
echo "→ Converting";

$attempts = 0;
$maxAttempts = 90; // 3 minutes max (URL downloads may take longer)
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

// Step 3: Display results
if ($status === 'completed') {
    echo "✓ Conversion complete!\n\n";
    displayResult($jobStatus['result'], $jobStatus['processing_time'] ?? null);
    
    // Offer to download
    echo "\nDownload converted file? (y/n): ";
    $answer = trim(fgets(STDIN));
    
    if (strtolower($answer) === 'y') {
        downloadFile($jobStatus['result']['download_url'], $jobStatus['result']['format']);
    }
} elseif ($status === 'failed') {
    echo "✗ Conversion failed\n";
    echo "Error: " . ($jobStatus['error']['message'] ?? 'Unknown error') . "\n";
    if (isset($jobStatus['error']['code'])) {
        echo "Code: " . $jobStatus['error']['code'] . "\n";
    }
    exit(1);
} else {
    echo "✗ Timeout: Conversion is taking longer than expected\n";
    echo "The file might be large or the server is busy.\n";
    echo "Check status later with: php job-management/check-status.php $jobId\n";
    exit(1);
}

/**
 * Display conversion result
 */
function displayResult($result, $processingTime = null) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Download URL: " . $result['download_url'] . "\n";
    echo "Format: " . $result['format'] . "\n";
    echo "Size: " . formatFileSize($result['file_size']) . "\n";
    if ($processingTime) {
        echo "Processing time: $processingTime\n";
    }
    echo "Expires: " . $result['expires_at'] . "\n";
}

/**
 * Download the converted file
 */
function downloadFile($url, $format) {
    $outputFile = 'converted_' . time() . '.' . $format;
    echo "Downloading to: $outputFile\n";
    
    $ch = curl_init($url);
    $fp = fopen($outputFile, 'w');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dlSize, $dlNow) {
        if ($dlSize > 0) {
            $percent = round($dlNow / $dlSize * 100);
            echo "\rProgress: $percent%";
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