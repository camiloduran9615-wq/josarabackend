<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\Asiento\AsientoAnulado;
use App\Events\Asiento\AsientoAprobado;
use App\Events\Asiento\AsientoReversado;
use App\Events\CierreAnual\CierreAnualEjecutado;
use App\Events\Periodo\PeriodoBloqueadoFiscal;
use App\Events\Periodo\PeriodoCerrado;
use App\Events\Periodo\PeriodoReabierto;
use App\Events\Saldos\SaldosInconsistenciaDetectada;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresión BUG-003: doble registro de listeners.
 *
 * Antes del fix, los listeners de eventos de dominio quedaban registrados dos veces
 * porque convivían el array $listen del EventServiceProvider con el auto-discovery
 * por typehint del framework (activado por defecto en Application::configure()->withEvents()).
 *
 * Síntoma: `php artisan event:list` mostraba cada listener dos veces — una por nombre
 * de clase y otra con sufijo `@handle`. El efecto contable era que cuenta_saldos
 * quedaba con el doble del valor real al aprobar un asiento.
 *
 * Fix: `bootstrap/app.php` agrega `->withEvents(discover: false)`.
 *
 * Este test asegura que el conteo de listeners por evento corresponda EXACTAMENTE
 * al definido en `App\Providers\EventServiceProvider::$listen`. Si alguien quita el
 * `withEvents(discover: false)` o lo invierte a true, este test falla de inmediato.
 */
final class EventListenersNoDuplicatesTest extends TestCase
{
    /**
     * @return iterable<string, array{0:class-string, 1:int}>
     */
    public static function eventosYConteo(): iterable
    {
        // Conteos esperados según App\Providers\EventServiceProvider::$listen
        yield 'AsientoAprobado tiene exactamente 3 listeners'           => [AsientoAprobado::class, 3];
        yield 'AsientoAnulado tiene exactamente 3 listeners'            => [AsientoAnulado::class, 3];
        yield 'AsientoReversado tiene exactamente 2 listeners'          => [AsientoReversado::class, 2];
        yield 'PeriodoCerrado tiene exactamente 4 listeners'            => [PeriodoCerrado::class, 4];
        yield 'PeriodoReabierto tiene exactamente 3 listeners'          => [PeriodoReabierto::class, 3];
        yield 'PeriodoBloqueadoFiscal tiene exactamente 1 listener'     => [PeriodoBloqueadoFiscal::class, 1];
        yield 'CierreAnualEjecutado tiene exactamente 2 listeners'      => [CierreAnualEjecutado::class, 2];
        yield 'SaldosInconsistenciaDetectada tiene exactamente 1 listener' => [SaldosInconsistenciaDetectada::class, 1];
    }

    /**
     * @param class-string $evento
     */
    #[DataProvider('eventosYConteo')]
    public function test_evento_tiene_exactamente_los_listeners_declarados(string $evento, int $esperado): void
    {
        $listeners = Event::getListeners($evento);

        $this->assertCount(
            $esperado,
            $listeners,
            sprintf(
                'El evento %s tiene %d listeners registrados, esperaba %d. '
                . 'Probable causa: regresión de BUG-003 (doble registro). '
                . 'Verifica que bootstrap/app.php tenga ->withEvents(discover: false). '
                . 'Listeners actuales:' . PHP_EOL . '%s',
                $evento,
                count($listeners),
                $esperado,
                $this->describirListeners($listeners),
            ),
        );
    }

    /**
     * Verifica que ningún listener esté registrado con la firma `@handle`,
     * que es la marca distintiva del auto-discovery por typehint del framework.
     *
     * En el array $listen los listeners se registran como "ClassName" (sin sufijo).
     * Si aparece "ClassName@handle", es el auto-discovery duplicando.
     */
    public function test_listeners_no_tienen_sufijo_handle_de_auto_discovery(): void
    {
        $listeners = Event::getListeners(AsientoAprobado::class);

        foreach ($listeners as $idx => $listener) {
            $descripcion = $this->describirListener($listener);
            $this->assertStringNotContainsString(
                '@handle',
                $descripcion,
                sprintf(
                    'Listener #%d tiene sufijo @handle (auto-discovery activo): %s. '
                    . 'Asegúrate de que bootstrap/app.php tenga ->withEvents(discover: false).',
                    $idx,
                    $descripcion,
                ),
            );
        }
    }

    /**
     * @param array<int, mixed> $listeners
     */
    private function describirListeners(array $listeners): string
    {
        return collect($listeners)
            ->map(fn ($l, $i) => "  #{$i}  " . $this->describirListener($l))
            ->implode(PHP_EOL);
    }

    private function describirListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }
        if (is_array($listener) && count($listener) === 2) {
            $cls = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
            return $cls . '@' . (string) $listener[1];
        }
        if ($listener instanceof \Closure) {
            $r = new \ReflectionFunction($listener);
            return 'Closure(' . $r->getFileName() . ':' . $r->getStartLine() . ')';
        }
        return get_debug_type($listener);
    }
}
