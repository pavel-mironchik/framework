<?php

namespace Illuminate\Tests\Integration\Filesystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

class ServeFileTest extends TestCase
{
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            Storage::put('serve-file-test.txt', 'Hello World');
            Storage::put('serve-file-test.txt?pad=x', 'Hello Question');
            Storage::put('nested/folder/serve-file-test.txt', 'Hello Nested');
        });

        $this->beforeApplicationDestroyed(function () {
            Storage::delete(['serve-file-test.txt', 'serve-file-test.txt?pad=x', 'nested/folder/serve-file-test.txt']);
        });

        parent::setUp();
    }

    public function testItCanServeAnExistingFile()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt', now()->addMinutes(1));

        $response = $this->get($url);

        $this->assertEquals('Hello World', $response->streamedContent());
    }

    public function testItWill404OnMissingFile()
    {
        $url = Storage::temporaryUrl('serve-missing-test.txt', now()->addMinutes(1));

        $response = $this->get($url);

        $response->assertNotFound();
    }

    public function testItWill403OnWrongSignature()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt', now()->addMinutes(1));

        $url = $url.'c';

        $response = $this->get($url);

        $response->assertForbidden();
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testItCanServeAFileWithUriDelimitersInThePath()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt?pad=x', Carbon::now()->addMinute());

        $response = $this->get($url);

        $this->assertSame('Hello Question', $response->streamedContent());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testTemporaryUrlPreservesPathSeparatorsInNestedPaths()
    {
        $url = Storage::temporaryUrl('nested/folder/serve-file-test.txt', Carbon::now()->addMinute());

        $this->assertStringContainsString('nested/folder/serve-file-test.txt', $url);

        $response = $this->get($url);

        $this->assertSame('Hello Nested', $response->streamedContent());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testUriDelimitersInThePathCannotHideAnExpiredUrl()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt?pad=x', Carbon::now()->subMinute());

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function testAdditionalDelimitersCannotChangeResourceOrExpiration()
    {
        foreach (['fragment#part.txt', 'percent%2Fpart.txt', 'query?expires=9999999999&x=1.txt', 'nested/space name.txt'] as $path) {
            Storage::put($path, 'Exact '.$path);
            try {
                $url = Storage::temporaryUrl($path, Carbon::now()->addMinute());
                $this->assertSame('Exact '.$path, $this->get($url)->streamedContent());
                $this->travel(2)->minutes();
                $this->get($url)->assertForbidden();
            } finally {
                $this->travelBack();
                Storage::delete($path);
            }
        }
    }

    protected function getEnvironmentSetup($app)
    {
        tap($app['config'], function ($config) {
            $config->set('filesystems.disks.local.serve', true);
        });
    }
}
