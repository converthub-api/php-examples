#!/usr/bin/env php
<?php
/**
 * ConvertHub API - Format Discovery
 *
 * Explore supported formats and check conversion possibilities.
 *
 * Usage:
 *   php list-formats.php                    # List all formats
 *   php list-formats.php --from=pdf         # Show conversions from PDF
 *   php list-formats.php --check=pdf:docx   # Check if PDF to DOCX is supported
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
$options = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)(?:=(.+))?$/', $arg, $matches)) {
        $options[$matches[1]] = $matches[2] ?? true;
    }
}

// Get API key
$apiKey = $options['api-key'] ?? getenv('CONVERTHUB_API_KEY');
if (empty($apiKey)) {
    echo "Error: API key required. Set CONVERTHUB_API_KEY in .env or use --api-key parameter.\n";
    echo "Get your API key at: https://converthub.com/api\n";
    exit(1);
}

// Determine action
if (isset($options['from'])) {
    showConversionsFrom($options['from'], $apiKey);
} elseif (isset($options['check'])) {
    checkConversion($options['check'], $apiKey);
} elseif (isset($options['help'])) {
    showHelp();
} else {
    listAllFormats($apiKey);
}

/**
 * List all supported formats
 */
function listAllFormats($apiKey) {
    echo "ConvertHub Supported Formats\n";
    echo "=============================\n\n";
    echo "Fetching format list...\n\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.converthub.com/v2/formats');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        echo "Error: " . ($error['error']['message'] ?? 'Failed to fetch formats') . "\n";
        exit(1);
    }

    $data = json_decode($response, true);
    $totalFormats = $data['total_formats'];
    $formats = $data['formats'];

    echo "Total supported formats: $totalFormats\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // Display formats by category
    foreach ($formats as $category => $categoryFormats) {
        $categoryName = ucfirst($category);
        $count = count($categoryFormats);

        echo "📁 $categoryName ($count formats)\n";
        echo "   ";

        $extensions = array_map(function($f) {
            return strtoupper($f['extension']);
        }, $categoryFormats);

        // Display in columns
        $perLine = 10;
        for ($i = 0; $i < count($extensions); $i++) {
            echo str_pad($extensions[$i], 8);
            if (($i + 1) % $perLine === 0 && $i < count($extensions) - 1) {
                echo "\n   ";
            }
        }
        echo "\n\n";
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "To see conversions from a specific format:\n";
    echo "  php list-formats.php --from=pdf\n\n";
    echo "To check if a conversion is supported:\n";
    echo "  php list-formats.php --check=pdf:docx\n";
}

/**
 * Show possible conversions from a format
 */
function showConversionsFrom($format, $apiKey) {
    $format = strtolower($format);

    echo "Conversions from " . strtoupper($format) . "\n";
    echo "===================\n\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/formats/$format/conversions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 404) {
        echo "Format '$format' is not supported.\n";
        exit(1);
    }

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        echo "Error: " . ($error['error']['message'] ?? 'Failed to fetch conversions') . "\n";
        exit(1);
    }

    $data = json_decode($response, true);

    echo "Source format: " . strtoupper($data['source_format']) . "\n";
    echo "MIME type: " . $data['mime_type'] . "\n";
    echo "Category: " . ucfirst($data['type']) . "\n";
    echo "Total conversions: " . $data['total_conversions'] . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    echo "Available target formats:\n\n";

    // Group conversions by type
    $grouped = [];
    foreach ($data['available_conversions'] as $conversion) {
        $type = getFormatType($conversion['target_format']);
        if (!isset($grouped[$type])) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $conversion;
    }

    // Display grouped conversions
    foreach ($grouped as $type => $conversions) {
        echo "  " . ucfirst($type) . ":\n";
        echo "    ";

        $formats = array_map(function($c) {
            return strtoupper($c['target_format']);
        }, $conversions);

        for ($i = 0; $i < count($formats); $i++) {
            echo str_pad($formats[$i], 8);
            if (($i + 1) % 8 === 0 && $i < count($formats) - 1) {
                echo "\n    ";
            }
        }
        echo "\n\n";
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Example conversion:\n";
    echo "  php simple-convert/convert.php file.$format " . $data['available_conversions'][0]['target_format'] . "\n";
}

/**
 * Check if a specific conversion is supported
 */
function checkConversion($conversionPair, $apiKey) {
    // Parse conversion pair (format: source:target or source->target)
    $parts = preg_split('/[:>-]+/', $conversionPair);

    if (count($parts) !== 2) {
        echo "Error: Invalid format. Use --check=source:target (e.g., --check=pdf:docx)\n";
        exit(1);
    }

    $sourceFormat = strtolower(trim($parts[0]));
    $targetFormat = strtolower(trim($parts[1]));

    echo "Checking conversion: " . strtoupper($sourceFormat) . " → " . strtoupper($targetFormat) . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/formats/$sourceFormat/to/$targetFormat");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 200 && $data['supported']) {
        echo "✓ Conversion is SUPPORTED\n\n";

        echo "Source format:\n";
        echo "  Extension: " . strtoupper($data['source_format']['extension']) . "\n";
        echo "  MIME type: " . $data['source_format']['mime_type'] . "\n";
        echo "  Category: " . ucfirst($data['source_format']['type']) . "\n\n";

        echo "Target format:\n";
        echo "  Extension: " . strtoupper($data['target_format']['extension']) . "\n";
        echo "  MIME type: " . $data['target_format']['mime_type'] . "\n";
        echo "  Category: " . ucfirst($data['target_format']['type']) . "\n\n";

        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "You can convert files using:\n";
        echo "  php simple-convert/convert.php file.$sourceFormat $targetFormat\n";
    } else {
        echo "✗ Conversion is NOT SUPPORTED\n\n";

        if (isset($data['message'])) {
            echo "Message: " . $data['message'] . "\n\n";
        }

        // Try to get alternative conversions
        echo "Fetching alternatives...\n\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.converthub.com/v2/formats/$sourceFormat/conversions");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $alternatives = json_decode($response, true);

            if (!empty($alternatives['available_conversions'])) {
                echo "Available conversions from " . strtoupper($sourceFormat) . ":\n";

                $formats = array_map(function($c) {
                    return strtoupper($c['target_format']);
                }, array_slice($alternatives['available_conversions'], 0, 20));

                echo "  " . implode(', ', $formats);
                if (count($alternatives['available_conversions']) > 20) {
                    echo ", and " . (count($alternatives['available_conversions']) - 20) . " more";
                }
                echo "\n";
            }
        }
    }
}

/**
 * Show help information
 */
function showHelp() {
    echo "ConvertHub Format Discovery\n";
    echo "===========================\n\n";
    echo "Usage:\n";
    echo "  php list-formats.php [options]\n\n";
    echo "Options:\n";
    echo "  (no options)         List all supported formats\n";
    echo "  --from=FORMAT        Show possible conversions from FORMAT\n";
    echo "  --check=FROM:TO      Check if conversion from FROM to TO is supported\n";
    echo "  --api-key=KEY        Use specific API key\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  php list-formats.php\n";
    echo "  php list-formats.php --from=pdf\n";
    echo "  php list-formats.php --check=pdf:docx\n";
    echo "  php list-formats.php --check=mp4:mp3\n\n";
    echo "Get your API key at: https://converthub.com/api\n";
}

/**
 * Determine format type/category
 */
function getFormatType($format) {
    $types = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg', 'ico', 'heic'],
        'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'html', 'md', 'tex'],
        'spreadsheet' => ['xls', 'xlsx', 'csv', 'ods', 'tsv'],
        'presentation' => ['ppt', 'pptx', 'odp', 'key'],
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'mpg'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'opus'],
        'ebook' => ['epub', 'mobi', 'azw3', 'fb2', 'lit'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2']
    ];

    foreach ($types as $type => $formats) {
        if (in_array(strtolower($format), $formats)) {
            return $type;
        }
    }

    return 'other';
}
