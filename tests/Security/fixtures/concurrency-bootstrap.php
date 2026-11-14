<?php

// Also supports a dependency-only vendor directory for isolated source checks.
$root = dirname(__DIR__, 3);
$loader = require getenv('FRAMEWORK_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php';
$loader->addPsr4('Illuminate\\Tests\\', $root.'/tests/');
$resolve = static function (string $class) use ($root): ?string {
    if (! str_starts_with($class, 'Illuminate\\')) {
        return null;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Illuminate\\'))).'.php';
    $paths = [$root.'/src/Illuminate/'.$relative];
    if (str_starts_with($relative, 'Support/')) {
        foreach (['Collections', 'Macroable', 'Conditionable'] as $directory) {
            $paths[] = $root.'/src/Illuminate/'.$directory.'/'.substr($relative, strlen('Support/'));
        }
    }

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
};
spl_autoload_register(static function (string $class) use ($resolve): void {
    if ($path = $resolve($class)) {
        require $path;
    }
}, true, true);

foreach ([Illuminate\Concurrency\ProcessDriver::class, Illuminate\Concurrency\Console\InvokeSerializedClosureCommand::class] as $class) {
    if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($resolve($class))) {
        throw new RuntimeException('Concurrency checks must load candidate source: '.$class);
    }
}
