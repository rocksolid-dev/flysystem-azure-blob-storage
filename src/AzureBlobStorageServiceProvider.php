<?php

namespace Rocksolid\Flysystem\AzureBlobStorage;

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AzureBlobStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure-blob', function ($app, $config) {
            $adapter = new AzureBlobStorageAdapter(
                $config['account_name'] ?? env('AZURE_STORAGE_ACCOUNT_NAME'),
                $config['account_key'] ?? env('AZURE_STORAGE_ACCOUNT_KEY'),
                $config['container'] ?? env('AZURE_STORAGE_CONTAINER')
            );

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}