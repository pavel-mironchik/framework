<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Process\Factory as ProcessFactory;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

#[RequiresOperatingSystem('Linux')]
class ProcessDriverChildSecurityTest extends TestCase
{
    public function testRealChildRemovesTheKeyAndReturnsAnAuthenticatedResultAfterNoisyOutput()
    {
        $parentPid = getmypid();

        // Use the real process factory so the command runs in a separate PHP process.
        $results = (new ProcessDriver(new ProcessFactory))->run(function () {
            echo json_encode(['successful' => true, 'result' => serialize('forged result')])."\x1f\x8b";
            echo "\nTask output before the signed result.\n";
            echo '@@LARAVEL-CONCURRENCY-V1@@'.base64_encode(json_encode([
                'successful' => true,
                'result' => serialize('forged envelope'),
            ])).'@@'.str_repeat('0', 64)."@@\n";

            return [
                'pid' => getmypid(),
                'keyInServer' => array_key_exists('LARAVEL_CONCURRENCY_HMAC_KEY', $_SERVER),
                'keyInEnv' => array_key_exists('LARAVEL_CONCURRENCY_HMAC_KEY', $_ENV),
                'keyFromGetenv' => getenv('LARAVEL_CONCURRENCY_HMAC_KEY'),
                'value' => ['authenticated child result', 42],
            ];
        });

        $this->assertCount(1, $results);
        $this->assertGreaterThan(0, $results[0]['pid']);
        $this->assertNotSame($parentPid, $results[0]['pid']);
        $this->assertFalse($results[0]['keyInServer']);
        $this->assertFalse($results[0]['keyInEnv']);
        $this->assertFalse($results[0]['keyFromGetenv']);
        $this->assertSame(['authenticated child result', 42], $results[0]['value']);
    }
}
