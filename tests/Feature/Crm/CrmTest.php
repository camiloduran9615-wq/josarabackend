<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use Tests\TenantTestCase;

/**
 * Tests del CRM básico: prospectos, oportunidades y actividades.
 */
class CrmTest extends TenantTestCase
{
    private function url(string $path): string
    {
        return '/api/v1/' . $this->tenant->id . $path;
    }

    /** Crea un prospecto via HTTP y retorna el row completo. */
    private function crearProspecto(string $razonSocial = 'Empresa Test SAS'): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/prospectos'), [
                'razon_social' => $razonSocial,
                'ciudad'       => 'Bogotá',
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    /** Crea una oportunidad via HTTP y retorna el row completo. */
    private function crearOportunidad(string $nombre = 'Oportunidad Test'): object
    {
        $r = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/oportunidades'), [
                'nombre' => $nombre,
                'etapa'  => 'prospecto',
            ]);
        $r->assertCreated();
        return (object) $r->json('data');
    }

    // ── Prospectos ────────────────────────────────────────────────────────────

    public function test_lista_prospectos_vacia(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url('/prospectos'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_crear_prospecto(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/prospectos'), [
                'razon_social'      => 'Empresa Test SAS',
                'contacto_nombre'   => 'Juan García',
                'contacto_email'    => 'juan@empresa.com',
                'contacto_telefono' => '3001234567',
                'ciudad'            => 'Bogotá',
                'sector'            => 'Tecnología',
                'fuente'            => 'web',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.razon_social', 'Empresa Test SAS');

        $this->assertDatabaseHas('prospectos', ['razon_social' => 'Empresa Test SAS']);
    }

    public function test_crear_prospecto_requiere_razon_social(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/prospectos'), [
                'contacto_email' => 'sin-empresa@test.com',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['razon_social']);
    }

    public function test_actualizar_prospecto(): void
    {
        $prospecto = $this->crearProspecto('Original SAS');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson($this->url("/prospectos/{$prospecto->id}"), [
                'razon_social' => 'Actualizada SAS',
                'estado'       => 'descartado',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.razon_social', 'Actualizada SAS');
    }

    public function test_eliminar_prospecto(): void
    {
        $prospecto = $this->crearProspecto('Para Eliminar SAS');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson($this->url("/prospectos/{$prospecto->id}"));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('prospectos', ['id' => $prospecto->id]);
    }

    // ── Oportunidades ─────────────────────────────────────────────────────────

    public function test_lista_oportunidades(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson($this->url('/oportunidades'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_crear_oportunidad(): void
    {
        $prospecto = $this->crearProspecto();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/oportunidades'), [
                'prospecto_id'   => $prospecto->id,
                'nombre'         => 'Oportunidad ERP',
                'valor_estimado' => 5000000,
                'etapa'          => 'prospecto',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Oportunidad ERP');
    }

    public function test_cambiar_etapa_oportunidad(): void
    {
        $oportunidad = $this->crearOportunidad('Test Etapa');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson($this->url("/oportunidades/{$oportunidad->id}/etapa"), [
                'etapa' => 'propuesta',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('oportunidades', [
            'id'    => $oportunidad->id,
            'etapa' => 'propuesta',
        ]);
    }

    public function test_cambiar_etapa_invalida_retorna_422(): void
    {
        $oportunidad = $this->crearOportunidad('Test Etapa Invalida');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson($this->url("/oportunidades/{$oportunidad->id}/etapa"), [
                'etapa' => 'etapa_inexistente',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['etapa']);
    }

    // ── Actividades ───────────────────────────────────────────────────────────

    public function test_crear_actividad_crm(): void
    {
        $oportunidad = $this->crearOportunidad('Oport Actividad');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson($this->url('/actividades-crm'), [
                'oportunidad_id'  => $oportunidad->id,
                'tipo'            => 'llamada',
                'asunto'          => 'Llamada de seguimiento',
                'fecha_actividad' => now()->toDateTimeString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);
    }
}
