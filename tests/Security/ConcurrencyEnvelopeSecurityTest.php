<?php

namespace Illuminate\Tests\Security;

use Illuminate\Concurrency\Console\InvokeSerializedClosureCommand;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Foundation\Application;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class ConcurrencyEnvelopeSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        new Application(dirname(__DIR__, 2));
        EnvelopeWakeupProbe::$awakened = false;
        if (! defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', __DIR__.'/fixtures/concurrency-child.php');
        }
    }

    public function testCandidateRuntimePathsAreUsed(): void
    {
        foreach ([
            ProcessDriver::class => 'Concurrency/ProcessDriver.php',
            InvokeSerializedClosureCommand::class => 'Concurrency/Console/InvokeSerializedClosureCommand.php',
        ] as $class => $path) {
            $this->assertSame(realpath(dirname(__DIR__, 2).'/src/Illuminate/'.$path), realpath((new ReflectionClass($class))->getFileName()));
        }
    }

    public function testNormalValuesAndLaravelElevenNumericTaskOrderArePreserved(): void
    {
        $values = [null, false, 0, '', 'hello', ['nested' => [1, true]], (object) ['answer' => 42]];
        $factory = new Factory;
        $offset = 0;
        $factory->fake(function (PendingProcess $process) use ($factory, $values, &$offset) {
            return $factory->result($this->frame($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY'], [
                'successful' => true, 'result' => serialize($values[$offset++]),
            ]));
        });
        $tasks = array_combine(['a', 'b', 'c', 'd', 'e', 'f', 'g'], array_fill(0, count($values), static fn () => null));

        $this->assertEquals($values, (new ProcessDriver($factory))->run($tasks));
        $this->assertSame(count($values), $offset);
    }

    public function testUnsignedResultsAreRejectedBeforeObjectDeserialization(): void
    {
        $this->assertRejected(json_encode(['successful' => true, 'result' => serialize(new EnvelopeWakeupProbe)]));
        $this->assertFalse(EnvelopeWakeupProbe::$awakened);
    }

    public function testMalformedMissingAndUnsignedFramesFailClosed(): void
    {
        foreach (['', 'ordinary output', '@@LARAVEL-CONCURRENCY-V1@@', '@@LARAVEL-CONCURRENCY-V1@@e30=@@'.str_repeat('0', 64).'@@', "{}\x1f\x8b"] as $output) {
            $this->assertRejected($output);
        }
    }

    public function testCorrectlySignedInvalidJsonBase64AndMissingResultShapeAreRejected(): void
    {
        foreach (['not-json', 'null', '[]', '{"result":"N;"}'] as $payload) {
            $this->assertRejected(static fn ($key) => self::framePayload($key, base64_encode($payload)));
        }
        $this->assertRejected(static fn ($key) => self::framePayload($key, 'a'));
    }

    public function testWrongKeysAndTamperedPayloadsCannotDeserializeObjects(): void
    {
        $payload = ['successful' => true, 'result' => serialize(new EnvelopeWakeupProbe)];
        $this->assertRejected($this->frame('wrong-key', $payload));
        $this->assertRejected(function ($key) use ($payload) {
            $valid = $this->frame($key, ['successful' => true, 'result' => serialize('original')]);

            return str_replace(base64_encode(json_encode(['successful' => true, 'result' => serialize('original')])), base64_encode(json_encode($payload)), $valid);
        });
        $this->assertFalse(EnvelopeWakeupProbe::$awakened);
    }

    public function testEachTaskGetsADistinctKeyAndCannotReuseAnotherTasksEnvelope(): void
    {
        $factory = new Factory;
        $keys = [];
        $first = null;
        $factory->fake(function (PendingProcess $process) use ($factory, &$keys, &$first) {
            $keys[] = $process->environment['LARAVEL_CONCURRENCY_HMAC_KEY'];
            $first ??= $this->frame(end($keys), ['successful' => true, 'result' => serialize('first')]);

            return $factory->result($first);
        });
        try {
            (new ProcessDriver($factory))->run([static fn () => 1, static fn () => 2]);
            $this->fail('Cross-task replay was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('valid result envelope', $e->getMessage());
        }
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $keys[0]);
    }

    public function testASeparateRunCannotReplayAPreviousSuccessfulResult(): void
    {
        $factory = new Factory;
        $frame = null;
        $factory->fake(function (PendingProcess $process) use ($factory, &$frame) {
            $frame ??= $this->frame($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY'], ['successful' => true, 'result' => serialize(42)]);

            return $factory->result($frame);
        });
        $driver = new ProcessDriver($factory);
        $this->assertSame([42], $driver->run(static fn () => 42));
        $this->expectException(RuntimeException::class);
        $driver->run(static fn () => 42);
    }

    public function testAuthenticatedResultSurvivesNoiseAndFakeFramesBeforeAndAfterIt(): void
    {
        $factory = new Factory;
        $factory->fake(function (PendingProcess $process) use ($factory) {
            $fake = $this->frame('attacker', ['successful' => true, 'result' => serialize(new EnvelopeWakeupProbe)]);
            $valid = $this->frame($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY'], ['successful' => true, 'result' => serialize('trusted')]);

            return $factory->result("noise\x1f\x8b".$fake.$valid.$fake);
        });

        $this->assertSame(['trusted'], (new ProcessDriver($factory))->run(static fn () => null));
        $this->assertFalse(EnvelopeWakeupProbe::$awakened);
    }

    public function testAuthenticatedFailureRetainsExceptionParameters(): void
    {
        $factory = new Factory;
        $factory->fake(function (PendingProcess $process) use ($factory) {
            return $factory->result($this->frame($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY'], [
                'successful' => false, 'exception' => EnvelopeDomainException::class,
                'message' => 'unused', 'parameters' => ['reason' => 'preserved', 'status' => 409],
            ]));
        });
        try {
            (new ProcessDriver($factory))->run(static fn () => null);
            $this->fail('Failure envelope did not throw');
        } catch (EnvelopeDomainException $e) {
            $this->assertSame('preserved', $e->reason);
            $this->assertSame(409, $e->status);
        }
    }

    public function testUnsignedExceptionCannotInvokeAnAttackerChosenConstructor(): void
    {
        EnvelopeDomainException::$constructed = false;
        $this->assertRejected(json_encode([
            'successful' => false, 'exception' => EnvelopeDomainException::class,
            'message' => 'attacker', 'parameters' => ['reason' => 'attacker', 'status' => 409],
        ]));
        $this->assertFalse(EnvelopeDomainException::$constructed);
    }

    public function testProcessFailureStillFailsBeforeEnvelopeParsing(): void
    {
        $factory = new Factory;
        $factory->fake(['*' => $factory->result('', 'child failed', 9)]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('exit code [9]');
        (new ProcessDriver($factory))->run(static fn () => null);
    }

    public function testEnvelopeKeyIsRemovedFromAllEnvironmentSourcesBeforeTaskExecution(): void
    {
        $key = str_repeat('a', 64);
        $_SERVER['LARAVEL_CONCURRENCY_HMAC_KEY'] = $key;
        $_ENV['LARAVEL_CONCURRENCY_HMAC_KEY'] = $key;
        putenv('LARAVEL_CONCURRENCY_HMAC_KEY='.$key);
        $command = new class extends InvokeSerializedClosureCommand
        {
            public function takeKey(): string
            {
                return $this->pullEnvelopeKey();
            }
        };
        try {
            $this->assertSame($key, $command->takeKey());
            $this->assertArrayNotHasKey('LARAVEL_CONCURRENCY_HMAC_KEY', $_SERVER);
            $this->assertArrayNotHasKey('LARAVEL_CONCURRENCY_HMAC_KEY', $_ENV);
            $this->assertFalse(getenv('LARAVEL_CONCURRENCY_HMAC_KEY'));
            $fallback = $command->takeKey();
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fallback);
            $this->assertNotSame($fallback, $command->takeKey());
        } finally {
            unset($_SERVER['LARAVEL_CONCURRENCY_HMAC_KEY'], $_ENV['LARAVEL_CONCURRENCY_HMAC_KEY']);
            putenv('LARAVEL_CONCURRENCY_HMAC_KEY');
        }
    }

    public function testDeferStillSchedulesTheOriginalFireAndForgetClosureTransport(): void
    {
        $factory = new Factory;
        $factory->fake();
        $callback = (new ProcessDriver($factory))->defer(static fn () => 42);
        $factory->assertNothingRan();
        $callback();
        $factory->assertRan(function (PendingProcess $process) {
            return str_ends_with($process->command, ' 2>&1 &')
                && isset($process->environment['LARAVEL_INVOKABLE_CLOSURE'])
                && ! isset($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY']);
        });
    }

    public function testRealChildCommandReturnsValuesAndCannotReadTheEnvelopeKey(): void
    {
        $results = (new ProcessDriver(new Factory))->run([
            static fn () => [42, 'value', false, null],
            static fn () => [getenv('LARAVEL_CONCURRENCY_HMAC_KEY'), isset($_SERVER['LARAVEL_CONCURRENCY_HMAC_KEY']), isset($_ENV['LARAVEL_CONCURRENCY_HMAC_KEY'])],
        ]);
        $this->assertSame([[42, 'value', false, null], [false, false, false]], $results);
    }

    public function testRealChildCommandFramesItsResultAfterUntrustedOutput(): void
    {
        $results = (new ProcessDriver(new Factory))->run(static function () {
            echo '{"successful":true,"result":"s:6:\"forged\";"}'."\x1f\x8b";
            echo '@@LARAVEL-CONCURRENCY-V1@@e30=@@'.str_repeat('0', 64).'@@';

            return 'real';
        });
        $this->assertSame(['real'], $results);
    }

    public function testRealChildCommandAuthenticatesExceptionResults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('real child failure');
        (new ProcessDriver(new Factory))->run(static fn () => throw new \InvalidArgumentException('real child failure'));
    }

    public function testRealStandaloneCommandWithoutAParentKeyStillExecutesDeferredWork(): void
    {
        $task = new \Laravel\SerializableClosure\SerializableClosure(static fn () => 42);
        $process = new \Symfony\Component\Process\Process([
            PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__.'/fixtures/concurrency-child.php', 'invoke-serialized-closure',
        ], env: ['LARAVEL_INVOKABLE_CLOSURE' => serialize($task), 'LARAVEL_CONCURRENCY_HMAC_KEY' => false]);
        $process->mustRun();
        $this->assertSame(1, preg_match('/@@LARAVEL-CONCURRENCY-V1@@([A-Za-z0-9+\/=]+)@@[a-f0-9]{64}@@/', $process->getOutput(), $matches));
        $this->assertSame(['successful' => true, 'result' => serialize(42)], json_decode(base64_decode($matches[1], true), true));
    }

    private function assertRejected(string|callable $output): void
    {
        $factory = new Factory;
        $factory->fake(function (PendingProcess $process) use ($factory, $output) {
            return $factory->result(is_callable($output) ? $output($process->environment['LARAVEL_CONCURRENCY_HMAC_KEY']) : $output);
        });
        try {
            (new ProcessDriver($factory))->run(static fn () => null);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('valid result envelope', $e->getMessage());

            return;
        }

        $this->fail('Untrusted result was accepted');
    }

    private function frame(string $key, array $payload): string
    {
        return self::framePayload($key, base64_encode(json_encode($payload)));
    }

    private static function framePayload(string $key, string $encoded): string
    {
        return "\n@@LARAVEL-CONCURRENCY-V1@@{$encoded}@@".hash_hmac('sha256', $encoded, $key)."@@\n";
    }
}

class EnvelopeWakeupProbe
{
    public static bool $awakened = false;

    public function __wakeup(): void
    {
        self::$awakened = true;
    }
}

class EnvelopeDomainException extends RuntimeException
{
    public static bool $constructed = false;

    public function __construct(public string $reason, public int $status)
    {
        self::$constructed = true;
        parent::__construct($reason);
    }
}
