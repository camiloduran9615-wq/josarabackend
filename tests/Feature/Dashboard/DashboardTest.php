<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use Tests\TenantTestCase;

/**
 * Tests del endpoint GET /{tenant}/dashboard.
 *
 * Verifica que la respuesta tenga la estructura correcta de KPIs
 * y que funcione con datos vacíos (0s) sin errores.
 */
class DashboardTest extends TenantTestCase
{
    private function url(): string
    {
        return '/api/v1/' . $this->tenant->id . '/dashboard';
    }

    public function test_dashboard_devuelve_estructura_correcta(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url());

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'periodo'  => ['inicio', 'fin', 'label'],
                    'kpis'     => [
                        'facturas_mes'  => ['cantidad', 'total', 'variacion'],
                        'cartera_cxc'   => ['saldo'],
                        'ingresos_mes'  => ['total', 'utilidad', 'gastos'],
                        'cobrado_mes'   => ['total'],
                        'compras_mes'   => ['cantidad', 'total'],
                    ],
                    'tendencia_ingresos',
                    'asientos_recientes',
                    'meta' => ['ms'],
                ],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_dashboard_tendencia_tiene_15_dias(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url());

        $response->assertOk();

        $tendencia = $response->json('data.tendencia_ingresos');
        $this->assertCount(15, $tendencia);
        $this->assertArrayHasKey('dia', $tendencia[0]);
        $this->assertArrayHasKey('ingreso', $tendencia[0]);
    }

    public function test_dashboard_kpis_son_numericos_cuando_no_hay_datos(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url());

        $response->assertOk();

        $kpis = $response->json('data.kpis');

        $this->assertIsInt($kpis['facturas_mes']['cantidad']);
        $this->assertIsFloat($kpis['facturas_mes']['total']);
        $this->assertIsFloat($kpis['cartera_cxc']['saldo']);
        $this->assertIsFloat($kpis['ingresos_mes']['total']);
        $this->assertIsFloat($kpis['cobrado_mes']['total']);
    }

    public function test_dashboard_requiere_autenticacion(): void
    {
        $response = $this->getJson($this->url());
        // Sin withoutMiddleware → el middleware sanctum rechaza
        $response->assertUnauthorized();
    }
}
