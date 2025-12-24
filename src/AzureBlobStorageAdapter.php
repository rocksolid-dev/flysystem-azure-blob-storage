<?php

namespace Rocksolid\Flysystem\AzureBlobStorage;

use DateTimeInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class AzureBlobStorageAdapter implements FilesystemAdapter
{
  const API_VERSION = '2024-11-04';

  private Client $client;
  private string $accountName;
  private string $accountKey;
  private string $container;
  private string $baseUrl;

  private MimeTypeDetector $mimeTypeDetector;

  public function __construct(string $accountName, string $accountKey, string $container)
  {
    $this->accountName = $accountName;
    $this->accountKey = $accountKey;
    $this->container = $container;
    $this->baseUrl = "https://{$accountName}.blob.core.windows.net";
    $this->client = new Client([
      'base_uri' => $this->baseUrl,
      'timeout' => 30,
    ]);
    $this->mimeTypeDetector = new FinfoMimeTypeDetector();
  }

  /**
   * Generate the Authorization header for Azure Blob Storage REST API
   * @param string $method HTTP method (GET, PUT, etc.)
   * @param string $path Request path
   * @param array $headers Request headers
   * @param array $queryParams Query parameters
   * @return string Authorization header value
   */
  private function getAuthorizationHeader(string $method, string $path, array $headers = [], array $queryParams = []): string
  {
    $canonicalizedHeaders = $this->getCanonicalizedHeaders($headers);
    $canonicalizedResource = $this->getCanonicalizedResource($path, $queryParams);

    // For GET/HEAD/DELETE and copy operations, Content-Length should be empty, not "0"
    $contentLength = $headers['Content-Length'] ?? '';
    $isCopyOperation = isset($headers['x-ms-copy-source']);

    if ($contentLength === '0' && (in_array($method, ['GET', 'HEAD', 'DELETE']) || $isCopyOperation)) {
      $contentLength = '';
    }

    $contentMd5 = $headers['Content-MD5'] ?? '';
    $contentType = $headers['Content-Type'] ?? '';

    // IMPORTANT: Canonicalized headers and resource must be concatenated together
    // as the LAST element (no newline between them)
    $stringToSign = implode("\n", [
      $method,
      '', // Content-Encoding
      '', // Content-Language
      $contentLength,
      $contentMd5,
      $contentType,
      '', // Date
      '', // If-Modified-Since
      '', // If-Match
      '', // If-None-Match
      '', // If-Unmodified-Since
      '', // Range
      $canonicalizedHeaders . $canonicalizedResource,
    ]);

    $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));

    return "SharedKey {$this->accountName}:{$signature}";
  }

  /**
   * Get canonicalized headers for signing
   * @param array $headers Request headers
   * @return string Canonicalized headers string
   */
  private function getCanonicalizedHeaders(array $headers): string
  {
    $canonicalizedHeaders = [];
    foreach ($headers as $key => $value) {
      $lowerKey = strtolower($key);
      if (str_starts_with($lowerKey, 'x-ms-')) {
        $canonicalizedHeaders[$lowerKey] = "{$lowerKey}:{$value}";
      }
    }
    ksort($canonicalizedHeaders);

    // Each header should end with \n, and they should be concatenated
    if (empty($canonicalizedHeaders)) {
      return '';
    }
    return implode("\n", $canonicalizedHeaders) . "\n";
  }

  /**
   * Get canonicalized resource for signing
   * @param string $path Request path
   * @param array $queryParams Query parameters
   * @return string Canonicalized resource string
   */
  private function getCanonicalizedResource(string $path, array $queryParams = []): string
  {
    $resource = "/{$this->accountName}{$path}";

    if (!empty($queryParams)) {
      ksort($queryParams);
      $params = [];
      foreach ($queryParams as $key => $value) {
        $params[] = strtolower($key) . ':' . $value;
      }
      $resource .= "\n" . implode("\n", $params);
    }

    return $resource;
  }

  /**
   * Get the full blob path including container
   * @param string $path Blob path
   * @return string Full blob path
   */
  private function getBlobPath(string $path): string
  {
    return "/{$this->container}/" . ltrim($path, '/');
  }

  /**
   * Encode the blob path for use in HTTP requests
   * Encodes each path segment while preserving forward slashes
   * @param string $path Blob path
   * @return string Encoded path
   */
  private function encodeBlobPath(string $path): string
  {
    $segments = explode('/', $path);
    $encoded = array_map(function($segment) {
      return rawurlencode($segment);
    }, $segments);
    return implode('/', $encoded);
  }

  /**
   * Make an HTTP request to Azure Blob Storage with proper signing
   * @param string $method HTTP method
   * @param string $path Request path (unencoded)
   * @param array $options Request options
   * @return ResponseInterface
   * @throws GuzzleException
   */
  private function makeRequest(string $method, string $path, array $options = []): ResponseInterface
  {
    $headers = $options['headers'] ?? [];
    $headers['x-ms-version'] = self::API_VERSION;
    $headers['x-ms-date'] = gmdate('D, d M Y H:i:s') . ' GMT';

    // Handle Content-Length explicitly - must be set BEFORE generating auth signature
    if (isset($options['body']) && is_string($options['body'])) {
      $bodyLength = strlen($options['body']);

      // Convert empty string to a PSR-7 stream to ensure Guzzle handles it correctly
      if ($bodyLength === 0 && !in_array($method, ['GET', 'HEAD', 'DELETE'])) {
        // Use Guzzle's stream factory to create an empty stream
        $options['body'] = Utils::streamFor('');
      } else {
        $headers['Content-Length'] = (string)$bodyLength;
      }
    } elseif (!in_array($method, ['GET', 'HEAD', 'DELETE'])) {
      // For PUT/POST without body, set Content-Length to 0
      $headers['Content-Length'] = '0';
    }
    // For GET/HEAD/DELETE, don't set Content-Length at all

    $queryParams = $options['query'] ?? [];

    // Encode the path before passing to Guzzle to ensure consistent encoding
    $encodedPath = $this->encodeBlobPath($path);

    // Generate signature using the encoded path (Azure expects it to match the actual request URI)
    $headers['Authorization'] = $this->getAuthorizationHeader($method, $encodedPath, $headers, $queryParams);

    $options['headers'] = $headers;

    return $this->client->request($method, $encodedPath, $options);
  }

  /**
   * Check if a file exists at the given path
   * @param string $path File path
   * @return bool
   * @throws UnableToCheckExistence
   */
  public function fileExists(string $path): bool
  {
    try {
      $this->makeRequest('HEAD', $this->getBlobPath($path));
      return true;
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        return false;
      }
      throw UnableToCheckExistence::forLocation($path, $e);
    } catch (Exception $e) {
      throw UnableToCheckExistence::forLocation($path, $e);
    }
  }

  /**
   * Check if a directory exists at the given path
   * @param string $path Directory path
   * @return bool
   */
  public function directoryExists(string $path): bool
  {
    // Azure Blob Storage doesn't have real directories, only virtual ones
    // We check if any blobs exist with the directory prefix
    try {
      $prefix = rtrim($path, '/') . '/';
      $response = $this->makeRequest('GET', "/{$this->container}", [
        'query' => [
          'restype' => 'container',
          'comp' => 'list',
          'prefix' => ltrim($prefix, '/'),
          'maxresults' => '1',
        ],
      ]);

      $xml = simplexml_load_string($response->getBody()->getContents());
      return isset($xml->Blobs) && isset($xml->Blobs->Blob) && count($xml->Blobs->Blob) > 0;
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Write a file to the given path
   * @param string $path File path
   * @param string $contents File contents
   * @param Config $config Configuration options
   * @throws UnableToWriteFile
   */
  public function write(string $path, string $contents, Config $config): void
  {
    $headers = [
      'x-ms-blob-type' => 'BlockBlob',
      'Content-Type' => $config->get('mimetype'),
    ];
    $shouldDetermineMimeType = $config->get('mimetype') === null;
    if ($shouldDetermineMimeType && $mimeType = $this->mimeTypeDetector->detectMimeType($path, $contents)) {
      $headers['Content-Type'] = $mimeType;
    }

    try {
      // Always pass body - even if empty
      $this->makeRequest('PUT', $this->getBlobPath($path), [
        'headers' => $headers,
        'body' => $contents,
      ]);
    } catch (Exception $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Write a stream to the given path
   * @param string $path File path
   * @param resource|string $contents Stream resource or string
   * @param Config $config Configuration options
   * @throws UnableToWriteFile
   */
  public function writeStream(string $path, $contents, Config $config): void
  {
    try {
      $body = '';
      if (is_resource($contents)) {
        while (!feof($contents)) {
          $body .= fread($contents, 8192);
        }
      } else {
        $body = (string)$contents;
      }

      $this->write($path, $body, $config);
    } catch (Exception $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Read a file from the given path
   * @param string $path File path
   * @return string File contents
   * @throws UnableToReadFile
   */
  public function read(string $path): string
  {
    try {
      $response = $this->makeRequest('GET', $this->getBlobPath($path));
      return $response->getBody()->getContents();
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        throw UnableToReadFile::fromLocation($path, 'File not found');
      }
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    } catch (Exception $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Read a file as a stream from the given path
   * @param string $path File path
   * @return resource Stream resource
   * @throws UnableToReadFile
   */
  public function readStream(string $path)
  {
    try {
      $response = $this->makeRequest('GET', $this->getBlobPath($path));
      $stream = fopen('php://temp', 'r+');
      if ($stream === false) {
        throw new RuntimeException('Unable to create temporary stream');
      }
      fwrite($stream, $response->getBody()->getContents());
      rewind($stream);
      return $stream;
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        throw UnableToReadFile::fromLocation($path, 'File not found');
      }
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    } catch (Exception $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Delete a file at the given path
   * @param string $path File path
   * @throws UnableToDeleteFile
   */
  public function delete(string $path): void
  {
    try {
      $this->makeRequest('DELETE', $this->getBlobPath($path));
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() !== 404) {
        throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
      }
    } catch (Exception $e) {
      throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Delete a directory at the given path
   * @param string $path Directory path
   * @throws UnableToDeleteDirectory
   */
  public function deleteDirectory(string $path): void
  {
    try {
      $blobs = $this->listContents($path, true);

      foreach ($blobs as $blob) {
        if ($blob->isFile()) {
          $this->delete($blob->path());
        }
      }
    } catch (Exception $e) {
      throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Create a directory at the given path
   * Azure Blob Storage doesn't have real directories, so we create a zero-byte blob as a marker
   * @param string $path Directory path
   * @param Config $config Configuration options
   * @throws UnableToCreateDirectory
   */
  public function createDirectory(string $path, Config $config): void
  {
    // Azure Blob Storage doesn't support empty directories
    // Create a directory marker blob ending with '/' (zero-byte blob)
    // This makes Azure tools show it as a folder
    try {
      $directoryMarker = rtrim($path, '/') . '/';
      $this->write($directoryMarker, ' ', $config);
    } catch (Exception $e) {
      throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
    }
  }

  /**
   * Azure Blob Storage does not support setting visibility on blobs only container level
   * @param string $path File path
   * @param string $visibility Visibility ('public' or 'private')
   * @throws UnableToSetVisibility
   */
  public function setVisibility(string $path, string $visibility): void
  {
    throw UnableToSetVisibility::atLocation($path, 'Setting visibility is not supported in this adapter.');
  }

  /**
   * Azure Blob Storage does not support getting visibility on blobs only container level
   * @param string $path File path
   * @throws UnableToRetrieveMetadata
   */
  public function visibility(string $path): FileAttributes
  {
    throw UnableToRetrieveMetadata::visibility($path, 'Getting visibility is not supported in this adapter.');
  }

  /**
   * Get the MIME type of a file at the given path
   * @param string $path File path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function mimeType(string $path): FileAttributes
  {
    // Directories don't have mime types
    if ($this->directoryExists($path)) {
      throw UnableToRetrieveMetadata::mimeType($path, 'Path is a directory, not a file');
    }

    try {
      $response = $this->makeRequest('HEAD', $this->getBlobPath($path));
      $mimeType = $response->getHeader('Content-Type')[0] ?? 'application/octet-stream';
      return new FileAttributes($path, null, null, null, $mimeType);
    } catch (Exception $e) {
      throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
    }
  }

  /**
   * Get the last modified time of a file at the given path
   * @param string $path File path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function lastModified(string $path): FileAttributes
  {
    try {
      $response = $this->makeRequest('HEAD', $this->getBlobPath($path));
      $lastModified = $response->getHeader('Last-Modified')[0] ?? null;
      $timestamp = $lastModified ? strtotime($lastModified) : null;
      return new FileAttributes($path, null, null, $timestamp);
    } catch (Exception $e) {
      throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
    }
  }

  /**
   * Get the file size of a file at the given path
   * @param string $path File path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function fileSize(string $path): FileAttributes
  {
    // Directories don't have file sizes
    if ($this->directoryExists($path)) {
      throw UnableToRetrieveMetadata::fileSize($path, 'Path is a directory, not a file');
    }

    try {
      $response = $this->makeRequest('HEAD', $this->getBlobPath($path));
      $size = $response->getHeader('Content-Length')[0] ?? 0;
      return new FileAttributes($path, (int)$size);
    } catch (Exception $e) {
      throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
    }
  }

  /**
   * List contents of a directory at the given path
   * @param string $path Directory path
   * @param bool $deep Whether to list contents recursively
   * @return iterable
   */
  public function listContents(string $path, bool $deep): iterable
  {
    $prefix = trim($path, '/');
    if ($prefix !== '') {
      $prefix .= '/';
    }

    $results = [];
    $marker = null;

    do {
      try {
        $query = [
          'restype' => 'container',
          'comp' => 'list',
          'prefix' => $prefix,
        ];

        if ($marker !== null) {
          $query['marker'] = $marker;
        }

        $response = $this->makeRequest('GET', "/{$this->container}", [
          'query' => $query,
        ]);

        $xml = simplexml_load_string($response->getBody()->getContents());

        if (isset($xml->Blobs->Blob)) {
          foreach ($xml->Blobs->Blob as $blob) {
            $blobPath = (string)$blob->Name;

            // Skip if not in current directory and not deep listing
            if (!$deep) {
              $relativePath = substr($blobPath, strlen($prefix));
              if (strpos($relativePath, '/') !== false && strpos($relativePath, '/') !== strlen($relativePath) - 1) {
                continue;
              }
            }

            $size = isset($blob->Properties->{'Content-Length'}) ? (int)$blob->Properties->{'Content-Length'} : 0;
            $lastModified = isset($blob->Properties->{'Last-Modified'}) ? strtotime((string)$blob->Properties->{'Last-Modified'}) : null;
            $mimeType = isset($blob->Properties->{'Content-Type'}) ? (string)$blob->Properties->{'Content-Type'} : null;

            $results[] = new FileAttributes(
              $blobPath,
              $size,
              null,
              $lastModified,
              $mimeType
            );
          }
        }

        // Handle virtual directories if not deep
        if (!$deep && isset($xml->Blobs->BlobPrefix)) {
          foreach ($xml->Blobs->BlobPrefix as $blobPrefix) {
            $dirPath = rtrim((string)$blobPrefix->Name, '/');
            $results[] = new FileAttributes($dirPath, null, null, null, null, ['type' => 'dir']);
          }
        }

        $marker = isset($xml->NextMarker) && (string)$xml->NextMarker !== '' ? (string)$xml->NextMarker : null;
      } catch (Exception $e) {
        break;
      }
    } while ($marker !== null);

    return $results;
  }

  /**
   * Move a file from source to destination
   * @param string $source Source file path
   * @param string $destination Destination file path
   * @param Config $config Configuration options
   * @throws UnableToMoveFile
   */
  public function move(string $source, string $destination, Config $config): void
  {
    // If source and destination are the same, no operation needed
    if ($source === $destination) {
      return;
    }

    try {
      $this->copy($source, $destination, $config);
      $this->delete($source);
    } catch (Exception $e) {
      throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
    }
  }

  /**
   * Copy a file from source to destination
   * @param string $source Source file path
   * @param string $destination Destination file path
   * @param Config $config Configuration options
   * @throws UnableToCopyFile
   */
  public function copy(string $source, string $destination, Config $config): void
  {
    try {
      // Encode the source path for the URL
      $sourcePath = $this->getBlobPath($source);
      $encodedSourcePath = $this->encodeBlobPath($sourcePath);
      $sourceUrl = $this->baseUrl . $encodedSourcePath;

      // Copy operation - Azure will handle server-side copy
      // No body needed, makeRequest will set Content-Length: 0 automatically
      $response = $this->makeRequest('PUT', $this->getBlobPath($destination), [
        'headers' => [
          'x-ms-copy-source' => $sourceUrl,
        ],
      ]);

      // Azure returns 202 Accepted for copy operations
      $statusCode = $response->getStatusCode();
      if ($statusCode !== 202 && $statusCode !== 201) {
        throw new RuntimeException("Unexpected status code: {$statusCode}");
      }

      // Check copy status - Azure copy can be async
      $copyStatus = $response->getHeader('x-ms-copy-status')[0] ?? null;

      // If copy is still pending, wait for it to complete (for small files it should be instant)
      if ($copyStatus === 'pending') {
        // Poll for completion (max 10 seconds for small files)
        $maxAttempts = 20;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
          usleep(500000); // 0.5 seconds
          $attempt++;

          $statusResponse = $this->makeRequest('HEAD', $this->getBlobPath($destination));
          $status = $statusResponse->getHeader('x-ms-copy-status')[0] ?? 'success';

          if ($status === 'success') {
            return;
          } elseif ($status === 'failed' || $status === 'aborted') {
            $errorDesc = $statusResponse->getHeader('x-ms-copy-status-description')[0] ?? 'Unknown error';
            throw new RuntimeException("Copy operation failed: {$errorDesc}");
          }
        }

        throw new RuntimeException('Copy operation timed out');
      } elseif ($copyStatus === 'success') {
        // Copy completed synchronously (typical for small files in same region)
        return;
      } elseif ($copyStatus === 'failed' || $copyStatus === 'aborted') {
        $errorDesc = $response->getHeader('x-ms-copy-status-description')[0] ?? 'Unknown error';
        throw new RuntimeException("Copy operation failed: {$errorDesc}");
      }
    } catch (Exception $e) {
      throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
    }
  }

  /**
   * Get the public URL for a blob
   * @param string $path Blob path
   * @return string Public URL
   */
  public function getUrl(string $path): string
  {
    $blobPath = $this->getBlobPath($path);
    $encodedPath = $this->encodeBlobPath($blobPath);
    return $this->baseUrl . $encodedPath;
  }

  /**
   * Get a temporary URL for a blob with SAS token
   * @param string $path Blob path
   * @param DateTimeInterface $expiresAt DateTime when the URL should expire
   * @param array $headers Extra headers to include in the SAS token (rscc, rscd, rsce, rscl, rsct)
   * @return string Temporary URL with SAS token
   */
  public function getTemporaryUrl(string $path, DateTimeInterface $expiresAt, array $headers = []): string
  {
    $blobPath = $this->getBlobPath($path);
    $expiryTime = $expiresAt->format('Y-m-d\TH:i:s\Z');
    $startTime = gmdate('Y-m-d\TH:i:s\Z', time() - 300); // Start 5 minutes ago to account for clock skew

    // Construct the string to sign for SAS
    $canonicalizedResource = "/blob/{$this->accountName}{$blobPath}";

    $stringToSign = implode("\n", [
      'r',                             // signedpermissions (read-only)
      $startTime,                      // signedstart
      $expiryTime,                     // signedexpiry
      $canonicalizedResource,          // canonicalizedresource
      '',                              // signedidentifier
      '',                              // signedIP
      'https',                         // signedProtocol
      self::API_VERSION,               // signedversion
      'b',                             // signedResource (b = blob)
      '',                              // signedSnapshotTime
      '',                              // signedEncryptionScope
      $headers['rscc'] ?? '',          // rscc (Cache-Control)
      $headers['rscd'] ?? '',          // rscd (Content-Disposition)
      $headers['rsce'] ?? '',          // rsce (Content-Encoding)
      $headers['rscl'] ?? '',          // rscl (Content-Language)
      $headers['rsct'] ?? '',          // rsct (Content-Type)
    ]);

    $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));

    $sasTokenParams = [
      'sv' => self::API_VERSION,       // signedversion
      'st' => $startTime,              // signedstart
      'se' => $expiryTime,             // signedexpiry
      'sr' => 'b',                     // signedresource (b = blob)
      'sp' => 'r',                     // signedpermissions (read-only)
      'spr' => 'https',                // signedProtocol
      'sig' => $signature,             // signature
    ];

    // Add optional response headers if provided
    if (isset($headers['rscc'])) $sasTokenParams['rscc'] = $headers['rscc'];
    if (isset($headers['rscd'])) $sasTokenParams['rscd'] = $headers['rscd'];
    if (isset($headers['rsce'])) $sasTokenParams['rsce'] = $headers['rsce'];
    if (isset($headers['rscl'])) $sasTokenParams['rscl'] = $headers['rscl'];
    if (isset($headers['rsct'])) $sasTokenParams['rsct'] = $headers['rsct'];

    $sasToken = http_build_query($sasTokenParams, '', '&', PHP_QUERY_RFC3986);

    $encodedPath = $this->encodeBlobPath($blobPath);
    return $this->baseUrl . $encodedPath . '?' . $sasToken;
  }
}
