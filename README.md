# Azure Blob Storage Adapter for Laravel

A Laravel Flysystem adapter for Azure Blob Storage using direct HTTP REST API calls (no SDK required).

## Features

- Full Flysystem v3 compatibility
- Direct Azure REST API integration using Guzzle
- Shared Key authentication
- Support for all standard file operations
- Laravel auto-discovery support
- No external Azure SDK dependencies

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- GuzzleHttp 7.0 or higher
- League Flysystem 3.0 or higher

## Installation

```bash
composer require rocksolid/azure-blob-storage
```

## Configuration

### 1. Add Azure credentials to your `.env` file:

```env
AZURE_STORAGE_ACCOUNT_NAME=your-account-name
AZURE_STORAGE_ACCOUNT_KEY=your-account-key
AZURE_STORAGE_CONTAINER=your-container-name
```

### 2. Add a disk configuration to `config/filesystems.php`:

```php
'disks' => [
    // ... other disks

    'azure' => [
        'driver' => 'azure-blob',
        'account_name' => env('AZURE_STORAGE_ACCOUNT_NAME'),
        'account_key' => env('AZURE_STORAGE_ACCOUNT_KEY'),
        'container' => env('AZURE_STORAGE_CONTAINER'),
    ],
],
```

## Usage

### Basic File Operations

```php
use Illuminate\Support\Facades\Storage;

// Upload a file
Storage::disk('azure')->put('path/to/file.txt', 'File contents');

// Read a file
$contents = Storage::disk('azure')->get('path/to/file.txt');

// Check if file exists
$exists = Storage::disk('azure')->exists('path/to/file.txt');

// Delete a file
Storage::disk('azure')->delete('path/to/file.txt');

// List files
$files = Storage::disk('azure')->files('directory');

// List all files recursively
$allFiles = Storage::disk('azure')->allFiles('directory');
```

### Streaming Operations

```php
// Upload from stream
$stream = fopen('local-file.txt', 'r');
Storage::disk('azure')->writeStream('path/to/file.txt', $stream);
fclose($stream);

// Download as stream
$stream = Storage::disk('azure')->readStream('path/to/file.txt');
```

### Directory Operations

Azure Blob Storage does not have real directories, but you can simulate them using empty files.

```php
// Create directory (creates a placeholder file)
Storage::disk('azure')->makeDirectory('path/to/directory');

// Check if directory exists
$exists = Storage::disk('azure')->directoryExists('path/to/directory');

// Delete directory
Storage::disk('azure')->deleteDirectory('path/to/directory');
```

### File Metadata

```php
// Get file size
$size = Storage::disk('azure')->size('path/to/file.txt');

// Get last modified time
$timestamp = Storage::disk('azure')->lastModified('path/to/file.txt');

// Get MIME type
$mimeType = Storage::disk('azure')->mimeType('path/to/file.txt');
```

### Copy and Move

```php
// Copy file
Storage::disk('azure')->copy('path/to/source.txt', 'path/to/destination.txt');

// Move file
Storage::disk('azure')->move('path/to/source.txt', 'path/to/destination.txt');
```

## Testing

This package includes comprehensive integration tests using the League Flysystem adapter test utilities.

### Running Tests

1. Install development dependencies:

```bash
composer install --dev
```

2. Configure your Azure credentials by copying `phpunit.xml.dist` to `phpunit.xml`:

```bash
cp phpunit.xml.dist phpunit.xml
```

3. Update the environment variables in `phpunit.xml` with your Azure Storage credentials:

```xml
<php>
    <env name="AZURE_STORAGE_ACCOUNT" value="your-account-name"/>
    <env name="AZURE_STORAGE_KEY" value="your-account-key"/>
    <env name="AZURE_STORAGE_CONTAINER" value="test-container"/>
</php>
```

**Important:** Make sure the test container exists in your Azure Storage account before running tests.

4. Run the test suite:

```bash
vendor/bin/phpunit
```

## License

MIT

## Credits

Developed by Rocksolid Development
