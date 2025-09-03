# ConvertHub API PHP Examples

Complete PHP code examples for integrating with the [ConvertHub API](https://converthub.com/api) - a powerful file conversion service supporting 800+ format pairs.

## 🚀 Quick Start

1. **Get your API key** from [https://converthub.com/api](https://converthub.com/api)
2. **Clone this repository**:
   ```bash
   git clone https://github.com/converthub-api/php-examples.git
   cd php-examples
   ```
3. **Install dependencies** (optional, for advanced features):
   ```bash
   composer install
   ```
4. **Configure your API key**:
   ```bash
   cp .env.example .env
   # Edit .env and add your API key
   ```
5. **Run any example**:
   ```bash
   php simple-convert/convert.php document.pdf docx
   ```

## 📁 Examples Directory Structure

Each directory contains working examples for specific API endpoints:

### 1. Simple Convert (`/simple-convert`)
Direct file upload and conversion (files up to 50MB).

- `convert.php` - Convert a local file with optional quality settings

```bash
# Basic conversion
php simple-convert/convert.php image.png jpg

# With options
php simple-convert/convert.php document.pdf docx --api-key=YOUR_KEY
```

### 2. URL Convert (`/url-convert`)
Convert files directly from URLs without downloading them first.

- `convert-from-url.php` - Convert a file from any public URL

```bash
php url-convert/convert-from-url.php https://example.com/file.pdf docx
```

### 3. Chunked Upload (`/chunked-upload`)
Upload and convert large files (up to 2GB) in chunks.

- `upload-large-file.php` - Upload large files in configurable chunks

```bash
# Default 5MB chunks
php chunked-upload/upload-large-file.php video.mov mp4

# Custom chunk size
php chunked-upload/upload-large-file.php large.pdf docx --chunk-size=10
```

### 4. Job Management (`/job-management`)
Track and manage conversion jobs with dedicated scripts for each operation.

- `check-status.php` - Check job status and optionally watch progress
- `cancel-job.php` - Cancel a running or queued job
- `delete-file.php` - Delete converted file from storage
- `download-result.php` - Download the converted file

```bash
# Check job status
php job-management/check-status.php job_123e4567-e89b-12d3

# Watch progress until complete
php job-management/check-status.php job_123e4567-e89b-12d3 --watch

# Cancel a running job
php job-management/cancel-job.php job_123e4567-e89b-12d3

# Delete a completed file (with confirmation)
php job-management/delete-file.php job_123e4567-e89b-12d3

# Force delete without confirmation
php job-management/delete-file.php job_123e4567-e89b-12d3 --force

# Download conversion result
php job-management/download-result.php job_123e4567-e89b-12d3

# Download with custom filename
php job-management/download-result.php job_123e4567-e89b-12d3 --output=myfile.pdf
```

### 5. Format Discovery (`/format-discovery`)
Explore supported formats and conversions.

- `list-formats.php` - List formats, check conversions, explore possibilities

```bash
# List all supported formats
php format-discovery/list-formats.php

# Show all conversions from PDF
php format-discovery/list-formats.php --from=pdf

# Check if specific conversion is supported
php format-discovery/list-formats.php --check=pdf:docx
```

### 6. Webhook Handler (`/webhook-handler`)
Receive real-time conversion notifications.

- `webhook-receiver.php` - Production-ready webhook endpoint

Deploy this file on your server and use its URL as the webhook endpoint:
```php
// When submitting conversions:
$data = [
    'file' => $file,
    'target_format' => 'pdf',
    'webhook_url' => 'https://your-server.com/webhook-receiver.php'
];
```

## 🔑 Authentication

All API requests require a Bearer token. Get your API key at [https://converthub.com/api](https://converthub.com/api).

### Method 1: Environment File (Recommended)
```bash
# Copy the example file
cp .env.example .env

# Edit .env and add your key
CONVERTHUB_API_KEY="your_api_key_here"
```

### Method 2: Command Line Parameter
```bash
php simple-convert/convert.php file.pdf docx --api-key=your_key_here
```

### Method 3: Direct in Code
```php
$apiKey = 'your_api_key_here';
$headers = ['Authorization: Bearer ' . $apiKey];
```

## 📊 Supported Conversions

The API supports 800+ format conversions, some popular ones include:

| Category | Formats |
|----------|---------|
| **Images** | JPG, PNG, WEBP, GIF, BMP, TIFF, SVG, HEIC, ICO, TGA |
| **Documents** | PDF, DOCX, DOC, TXT, RTF, ODT, HTML, MARKDOWN, TEX |
| **Spreadsheets** | XLSX, XLS, CSV, ODS, TSV |
| **Presentations** | PPTX, PPT, ODP, KEY |
| **Videos** | MP4, WEBM, AVI, MOV, MKV, WMV, FLV, MPG |
| **Audio** | MP3, WAV, OGG, M4A, FLAC, AAC, WMA, OPUS |
| **eBooks** | EPUB, MOBI, AZW3, FB2, LIT |
| **Archives** | ZIP, RAR, 7Z, TAR, GZ, BZ2 |

## ⚙️ Conversion Options

Customize your conversions with various options:

```php
// In simple-convert/convert.php:
php convert.php image.png jpg --quality=85 --resolution=1920x1080

// Available options:
--quality=N        # Image quality (1-100)
--resolution=WxH   # Output resolution
--bitrate=RATE     # Audio/video bitrate (e.g., "320k")
--sample-rate=N    # Audio sample rate (e.g., 44100)
--output=FILENAME  # Custom output filename
```

## 🚦 Error Handling

All examples include comprehensive error handling:

```php
// Every script handles API errors properly:
if ($httpCode >= 400) {
    $error = json_decode($response, true);
    echo "Error: " . $error['error']['message'] . "\n";
    echo "Code: " . $error['error']['code'] . "\n";
}
```

Common error codes:
- `AUTHENTICATION_REQUIRED` - Missing or invalid API key
- `NO_MEMBERSHIP` - No active membership found
- `INSUFFICIENT_CREDITS` - No credits remaining
- `FILE_TOO_LARGE` - File exceeds size limit
- `UNSUPPORTED_FORMAT` - Format not supported
- `CONVERSION_FAILED` - Processing error

## 📈 Rate Limits

| Endpoint | Limit | Script |
|----------|-------|--------|
| Convert | 60/minute | `simple-convert/convert.php` |
| Convert URL | 60/minute | `url-convert/convert-from-url.php` |
| Status Check | 100/minute | `job-management/check-status.php` |
| Format Discovery | 200/minute | `format-discovery/list-formats.php` |
| Chunked Upload | 500/minute | `chunked-upload/upload-large-file.php` |

## 🔧 Requirements

- PHP 7.4 or higher
- cURL extension enabled
- (Optional) Composer for dependency management

## 📚 File Descriptions

| File | Purpose |
|------|---------|
| `.env.example` | Environment configuration template |
| `.gitignore` | Git ignore rules for sensitive files |
| `composer.json` | PHP package dependencies |
| **Simple Convert** | |
| `simple-convert/convert.php` | Convert local files up to 50MB |
| **URL Convert** | |
| `url-convert/convert-from-url.php` | Convert files from URLs |
| **Chunked Upload** | |
| `chunked-upload/upload-large-file.php` | Upload files up to 2GB in chunks |
| **Job Management** | |
| `job-management/check-status.php` | Check job status and watch progress |
| `job-management/cancel-job.php` | Cancel running or queued jobs |
| `job-management/delete-file.php` | Delete converted files from storage |
| `job-management/download-result.php` | Download conversion results |
| **Format Discovery** | |
| `format-discovery/list-formats.php` | Explore supported formats |
| **Webhook Handler** | |
| `webhook-handler/webhook-receiver.php` | Handle webhook notifications |

## 💡 Usage Examples

### Convert a PDF to Word
```bash
php simple-convert/convert.php document.pdf docx
```

### Convert an image from URL
```bash
php url-convert/convert-from-url.php https://example.com/photo.png jpg
```

### Upload a large video
```bash
php chunked-upload/upload-large-file.php movie.mov mp4 --chunk-size=10
```

### Monitor conversion progress
```bash
php job-management/check-status.php job_abc123 --watch
```

### Check if conversion is supported
```bash
php format-discovery/list-formats.php --check=heic:jpg
```

## 🤝 Support

- **API Documentation**: [https://converthub.com/api/docs](https://converthub.com/api/docs)
- **Developer Dashboard**: [https://converthub.com/dashboard](https://converthub.com/dashboard)
- **Get API Key**: [https://converthub.com/api](https://converthub.com/api)
- **Email Support**: support@converthub.com

## 📄 License

These examples are provided under the MIT License. Feel free to use and modify them for your projects.

---

Built with ❤️ by [ConvertHub](https://converthub.com) - Making file conversion simple and powerful.
