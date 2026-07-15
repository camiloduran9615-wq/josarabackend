<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Http\Controllers\Api\ProductoController;
use App\Models\Tenant\Producto;
use ReflectionMethod;
use Tests\TenantTestCase;

class ProductoRouteParametersTest extends TenantTestCase
{
    public function test_controller_declares_tenant_route_parameters_in_dispatch_order(): void
    {
        $this->assertSame(['tenant'], $this->parameterNames('index'));
        $this->assertSame(['request', 'tenant'], $this->parameterNames('store'));
        $this->assertSame(['tenant', 'id'], $this->parameterNames('show'));
        $this->assertSame(['request', 'tenant', 'id'], $this->parameterNames('update'));
        $this->assertSame(['tenant', 'id'], $this->parameterNames('destroy'));
        $this->assertSame(['request', 'tenant'], $this->parameterNames('registrarMovimiento'));
    }

    public function test_show_uses_product_id_instead_of_tenant_slug(): void
    {
        $producto = Producto::query()->firstOrFail();

        $response = $this->controller()->show(
            'comercializadoraaaaa',
            (string) $producto->id,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($producto->id, $response->getData(true)['data']['id']);
    }

    /** @return list<string> */
    private function parameterNames(string $method): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(ProductoController::class, $method))->getParameters(),
        );
    }

    private function controller(): ProductoController
    {
        return $this->app->make(ProductoController::class);
    }
}
