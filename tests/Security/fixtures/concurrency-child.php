<?php

require __DIR__.'/concurrency-bootstrap.php';

$app = new Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class implements Illuminate\Contracts\Debug\ExceptionHandler
{
    public function report(Throwable $e)
    {
    }

    public function shouldReport(Throwable $e)
    {
        return false;
    }

    public function render($request, Throwable $e)
    {
    }

    public function renderForConsole($output, Throwable $e)
    {
    }
});
$command = new Illuminate\Concurrency\Console\InvokeSerializedClosureCommand;
$command->setLaravel($app);
$console = new Symfony\Component\Console\Application;
$console->add($command);
$console->run();
