<?php

namespace Illuminate\Tests\Security;

use Illuminate\Foundation\Exceptions\Renderer\Renderer;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;

class RendererSecurityTest extends TestCase
{
    protected function defineRoutes($router)
    {
        $router->get('/failed', fn () => throw new \RuntimeException('Security fixture'));
    }

    #[WithConfig('app.debug', true)]
    public function testActualRendererEscapesAttackerControlledTooltip()
    {
        $payload = '<img src=x onerror="window.frameworkxss=1">';
        $this->assertTrue($this->app->bound(Renderer::class));
        $response = $this->withHeaders([$payload => 'probe'])->get('/failed');
        $response->assertInternalServerError();
        $html = $response->getContent();
        $this->assertTrue(str_contains($html, 'data-tippy-content="'.e($payload).'"'), 'Synthetic untrusted header name must reach actual renderer tooltip');
        $this->assertStringNotContainsString('data-tippy-content="'.$payload.'"', $html);
        if ($output = getenv('FRAMEWORK_RENDERER_FIXTURE')) {
            file_put_contents($output, $html);
        }
    }
}
