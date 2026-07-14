<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Http\Controllers\Api\ParametrizacionContableController;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TenantTestCase;

class ParametrizacionContableRouteTest extends TenantTestCase
{
    public function test_controller_declares_tenant_route_parameters_in_dispatch_order(): void
    {
        $this->assertSame(['tenant'], $this->parameterNames('index'));
        $this->assertSame(['tenant', 'modulo'], $this->parameterNames('validar'));
        $this->assertSame(['request', 'tenant', 'clave'], $this->parameterNames('update'));
        $this->assertSame(['request', 'tenant'], $this->parameterNames('bulk'));
    }

    public function test_compra_validation_uses_module_instead_of_tenant_slug(): void
    {
        $response = $this->controller()->validar('comercializadoraaaaa', 'compra');
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('compra', $payload['data']['modulo']);
        $this->assertSame(4, $payload['data']['total']);
    }

    public function test_unknown_module_returns_a_controlled_validation_response(): void
    {
        $response = $this->controller()->validar('comercializadoraaaaa', 'desconocido');
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString("Módulo 'desconocido'", $payload['message']);
        $this->assertStringNotContainsString('comercializadoraaaaa', $payload['message']);
    }

    public function test_update_uses_the_key_after_the_tenant_parameter(): void
    {
        $param = ParametrizacionContable::query()
            ->whereNotNull('cuenta_contable_id')
            ->firstOrFail();

        $request = Request::create('/parametrizacion-contable/'.$param->clave, 'PUT', [
            'cuenta_contable_id' => $param->cuenta_contable_id,
            'descripcion' => 'Validación de parámetros de ruta',
        ]);

        $response = $this->controller()->update(
            $request,
            'comercializadoraaaaa',
            $param->clave,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($param->clave, $response->getData(true)['data']['clave']);
    }

    /**
     * @return list<string>
     */
    private function parameterNames(string $method): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(ParametrizacionContableController::class, $method))->getParameters(),
        );
    }

    private function controller(): ParametrizacionContableController
    {
        return $this->app->make(ParametrizacionContableController::class);
    }
}
