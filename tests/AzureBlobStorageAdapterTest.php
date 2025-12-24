<?php

namespace Rocksolid\Flysystem\AzureBlobStorage\Tests;

use DateTime;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Rocksolid\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;

/**
 * @group integration
 */
class AzureBlobStorageAdapterTest extends FilesystemAdapterTestCase
{
    private static string $accountName;
    private static string $accountKey;
    private static string $container;

    public static function setUpBeforeClass(): void
    {
        self::$accountName = getenv('AZURE_STORAGE_ACCOUNT') ?: '';
        self::$accountKey = getenv('AZURE_STORAGE_KEY') ?: '';
        self::$container = getenv('AZURE_STORAGE_CONTAINER') ?: '';

        if (!self::$accountName || !self::$accountKey || !self::$container) {
            self::markTestSkipped(
                'Azure Blob Storage credentials not configured. ' .
                'Set AZURE_STORAGE_ACCOUNT, AZURE_STORAGE_KEY, and AZURE_STORAGE_CONTAINER environment variables.'
            );
        }
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new AzureBlobStorageAdapter(self::$accountName, self::$accountKey, self::$container);
    }

    /**
     * Clear all files from the container before each test
     */
    public function clearStorage(): void
    {
        $adapter = $this->adapter();
        $contents = $adapter->listContents('', true);

        foreach ($contents as $item) {
            if ($item->isFile()) {
                try {
                    $adapter->delete($item->path());
                } catch (\Exception $e) {
                    // Ignore errors during cleanup
                }
            }
        }
    }

    /**
     * @test
     */
    public function writing_and_reading_with_string(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());
        $contents = $adapter->read('path.txt');

        $this->assertEquals('contents', $contents);
    }

    /**
     * @test
     */
    public function writing_and_reading_empty_file(): void
    {
        $adapter = $this->adapter();
        $adapter->write('empty.txt', '', new Config());
        $contents = $adapter->read('empty.txt');

        $this->assertEquals('', $contents);
    }

    /**
     * @test
     */
    public function reading_a_file_that_does_not_exist(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->adapter()->read('path.txt');
    }

    /**
     * @test
     */
    public function checking_if_a_file_exists(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());

        $this->assertTrue($adapter->fileExists('path.txt'));
        $this->assertFalse($adapter->fileExists('non-existent.txt'));
    }

    /**
     * @test
     */
    public function deleting_a_file(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());
        $adapter->delete('path.txt');

        $this->assertFalse($adapter->fileExists('path.txt'));
    }

    /**
     * @test
     */
    public function copying_a_file(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'contents', new Config());
        $adapter->copy('source.txt', 'destination.txt', new Config());

        $this->assertTrue($adapter->fileExists('source.txt'));
        $this->assertTrue($adapter->fileExists('destination.txt'));
        $this->assertEquals('contents', $adapter->read('destination.txt'));
    }

    /**
     * @test
     */
    public function moving_a_file(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'contents', new Config());
        $adapter->move('source.txt', 'destination.txt', new Config());

        $this->assertFalse($adapter->fileExists('source.txt'));
        $this->assertTrue($adapter->fileExists('destination.txt'));
        $this->assertEquals('contents', $adapter->read('destination.txt'));
    }

    /**
     * @test
     */
    public function getting_file_size(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());
        $attributes = $adapter->fileSize('path.txt');

        $this->assertEquals(8, $attributes->fileSize());
    }

    /**
     * @test
     */
    public function getting_mime_type(): void
    {
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'contents', new Config(['mimetype' => 'text/plain']));
        $attributes = $adapter->mimeType('file.txt');

        $this->assertEquals('text/plain', $attributes->mimeType());
    }

    /**
     * @test
     */
    public function getting_last_modified(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());
        $attributes = $adapter->lastModified('path.txt');

        $this->assertIsInt($attributes->lastModified());
        $this->assertGreaterThan(time() - 10, $attributes->lastModified());
    }

    /**
     * @test
     */
    public function listing_contents_shallow(): void
    {
        $adapter = $this->adapter();
        $adapter->write('dir/file1.txt', 'contents', new Config());
        $adapter->write('dir/file2.txt', 'contents', new Config());
        $adapter->write('dir/subdir/file3.txt', 'contents', new Config());

        $contents = iterator_to_array($adapter->listContents('dir', false));

        $this->assertCount(2, $contents);
        $paths = array_map(fn($item) => $item->path(), $contents);
        $this->assertContains('dir/file1.txt', $paths);
        $this->assertContains('dir/file2.txt', $paths);
    }

    /**
     * @test
     */
    public function listing_contents_deep(): void
    {
        $adapter = $this->adapter();
        $adapter->write('dir/file1.txt', 'contents', new Config());
        $adapter->write('dir/file2.txt', 'contents', new Config());
        $adapter->write('dir/subdir/file3.txt', 'contents', new Config());

        $contents = iterator_to_array($adapter->listContents('dir', true));

        $this->assertGreaterThanOrEqual(3, count($contents));
        $paths = array_map(fn($item) => $item->path(), $contents);
        $this->assertContains('dir/file1.txt', $paths);
        $this->assertContains('dir/file2.txt', $paths);
        $this->assertContains('dir/subdir/file3.txt', $paths);
    }

    /**
     * @test
     */
    public function creating_a_directory(): void
    {
        $adapter = $this->adapter();
        $adapter->createDirectory('test-directory', new Config());

        $this->assertTrue($adapter->directoryExists('test-directory'));
    }

    /**
     * @test
     */
    public function deleting_a_directory(): void
    {
        $adapter = $this->adapter();
        $adapter->write('dir/file1.txt', 'contents', new Config());
        $adapter->write('dir/file2.txt', 'contents', new Config());

        $adapter->deleteDirectory('dir');

        $this->assertFalse($adapter->fileExists('dir/file1.txt'));
        $this->assertFalse($adapter->fileExists('dir/file2.txt'));
    }

    /**
     * @test
     */
    public function getting_public_url(): void
    {
        $adapter = $this->adapter();
        $adapter->write('test.txt', 'contents', new Config());

        $url = $adapter->getUrl('test.txt');

        $this->assertStringContainsString('blob.core.windows.net', $url);
        $this->assertStringContainsString('test.txt', $url);
    }

    /**
     * @test
     */
    public function getting_temporary_url(): void
    {
        $adapter = $this->adapter();
        $adapter->write('test.txt', 'contents', new Config());

        $expiresAt = new DateTime('+1 hour');
        $url = $adapter->getTemporaryUrl('test.txt', $expiresAt);

        $this->assertStringContainsString('blob.core.windows.net', $url);
        $this->assertStringContainsString('test.txt', $url);
        $this->assertStringContainsString('sig=', $url);
        $this->assertStringContainsString('se=', $url);
    }

    /**
     * @test
     */
    public function writing_and_reading_with_stream(): void
    {
        $adapter = $this->adapter();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'contents from stream');
        rewind($stream);

        $adapter->writeStream('stream.txt', $stream, new Config());
        fclose($stream);

        $readStream = $adapter->readStream('stream.txt');
        $contents = stream_get_contents($readStream);
        fclose($readStream);

        $this->assertEquals('contents from stream', $contents);
    }

    /**
     * @test
     */
    public function writing_with_mime_type_detection(): void
    {
        $adapter = $this->adapter();
        $adapter->write('test.json', '{"key":"value"}', new Config());

        $attributes = $adapter->mimeType('test.json');

        $this->assertNotEmpty($attributes->mimeType());
    }

    /**
     * @test
     */
    public function writing_with_explicit_mime_type(): void
    {
        $adapter = $this->adapter();
        $adapter->write('test.custom', 'data', new Config(['mimetype' => 'application/custom']));

        $attributes = $adapter->mimeType('test.custom');

        $this->assertEquals('application/custom', $attributes->mimeType());
    }

    // Override visibility tests - Azure Blob Storage doesn't support blob-level visibility

    /**
     * @test
     */
    public function setting_visibility(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility (only container-level)');
    }

    /**
     * @test
     */
    public function overwriting_a_file(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility (only container-level)');
    }

    /**
     * @test
     */
    public function copying_a_file_again(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility (only container-level)');
    }

    /**
     * @test
     */
    public function fetching_visibility(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility');
    }

    /**
     * @test
     */
    public function fetching_visibility_of_non_existing_file(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility');
    }

    /**
     * @test
     */
    public function visibility_can_be_changed(): void
    {
        $this->markTestSkipped('Azure Blob Storage does not support blob-level visibility');
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->markTestSkipped('Azure Blob Storage always returns a mime type');
    }
}