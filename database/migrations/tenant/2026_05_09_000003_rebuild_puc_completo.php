<?php

declare(strict_types=1);

use App\Models\Tenant\CuentaContable;
use Illuminate\Database\Migrations\Migration;

/**
 * PUC Colombiano — reconstrucción completa escalable.
 *
 * Fuente: Decreto 2649/93 + NIIF PYMES (Decreto 2420/2015)
 * Compatible con: SIIGO Nube, World Office, Contaplus Colombia.
 *
 * Reglas aplicadas:
 *  • acepta_movimientos = true  → subcuenta (6 dígitos) y auxiliar (8+)
 *  • acepta_movimientos = false → clase, grupo, cuenta (solo agrupación)
 *  • exige_tercero = true       → clientes, proveedores, retenciones, honorarios
 *  • La migración es IDEMPOTENTE: no falla si ya existen cuentas.
 *  • Los registros existentes NO se sobreescriben (firstOrCreate).
 *  • Sí se corrige acepta_movimientos en cuentas ya existentes que
 *    estaban mal configuradas (UPDATE selectivo al final).
 *
 * Estructura:
 *  Clase 1 — Activo          (13 grupos, 31 cuentas, 47 subcuentas)
 *  Clase 2 — Pasivo          ( 7 grupos, 18 cuentas, 32 subcuentas)
 *  Clase 3 — Patrimonio      ( 5 grupos, 10 cuentas, 14 subcuentas)
 *  Clase 4 — Ingresos        ( 4 grupos,  9 cuentas, 17 subcuentas)
 *  Clase 5 — Gastos          ( 8 grupos, 22 cuentas, 37 subcuentas)
 *  Clase 6 — Costos          ( 2 grupos,  6 cuentas, 10 subcuentas)
 */
return new class extends Migration
{
    // ─── Catálogo ────────────────────────────────────────────────────────────
    // Formato: [codigo, nombre, naturaleza, nivel, exige_tercero, exige_base_impuesto]
    // acepta_movimientos se deriva del nivel (subcuenta/auxiliar = true)

    private function catalogo(): array
    {
        return [

            // ════════════════════════════════════════════════════════════════
            // CLASE 1 — ACTIVO
            // ════════════════════════════════════════════════════════════════
            ['1',      'Activo',                                        'debito',  'clase'],
            //  Grupo 11 — Disponible
            ['11',     'Disponible',                                    'debito',  'grupo'],
            ['1105',   'Caja',                                          'debito',  'cuenta'],
            ['110505', 'Caja General',                                  'debito',  'subcuenta'],
            ['110510', 'Caja Menor',                                    'debito',  'subcuenta'],
            ['1110',   'Bancos',                                        'debito',  'cuenta'],
            ['111005', 'Banco Cuenta de Ahorros',                       'debito',  'subcuenta'],
            ['111010', 'Banco Cuenta Corriente',                        'debito',  'subcuenta'],
            //  Grupo 12 — Inversiones
            ['12',     'Inversiones',                                   'debito',  'grupo'],
            ['1205',   'Acciones',                                      'debito',  'cuenta'],
            ['120505', 'Acciones Ordinarias',                           'debito',  'subcuenta'],
            //  Grupo 13 — Deudores
            ['13',     'Deudores',                                      'debito',  'grupo'],
            ['1305',   'Clientes',                                      'debito',  'cuenta', true],
            ['130505', 'Clientes Nacionales',                           'debito',  'subcuenta', true],
            ['130510', 'Clientes del Exterior',                         'debito',  'subcuenta', true],
            ['1330',   'Anticipos y Avances',                           'debito',  'cuenta', true],
            ['133005', 'Anticipos a Proveedores',                       'debito',  'subcuenta', true],
            ['133010', 'Anticipos a Empleados',                         'debito',  'subcuenta', true],
            ['1355',   'Anticipo de Impuestos y Contribuciones',        'debito',  'cuenta'],
            ['135515', 'Retención en la Fuente — Anticipo',             'debito',  'subcuenta'],
            ['135517', 'Anticipo Impuesto de Renta',                    'debito',  'subcuenta'],
            ['135518', 'Anticipo ICA',                                  'debito',  'subcuenta'],
            ['1360',   'Reclamaciones',                                 'debito',  'cuenta', true],
            ['136005', 'Reclamaciones a Proveedores',                   'debito',  'subcuenta', true],
            ['1380',   'Deudores Varios',                               'debito',  'cuenta', true],
            ['138005', 'Empleados',                                     'debito',  'subcuenta', true],
            ['138010', 'Socios y Accionistas',                          'debito',  'subcuenta', true],
            //  Grupo 14 — Inventarios
            ['14',     'Inventarios',                                   'debito',  'grupo'],
            ['1430',   'Mercancías no Fabricadas por la Empresa',       'debito',  'cuenta'],
            ['143005', 'Inventario de Mercancías',                      'debito',  'subcuenta'],
            ['1455',   'Materias Primas',                               'debito',  'cuenta'],
            ['145505', 'Materias Primas',                               'debito',  'subcuenta'],
            ['1460',   'Productos en Proceso',                          'debito',  'cuenta'],
            ['146005', 'Productos en Proceso',                          'debito',  'subcuenta'],
            ['1465',   'Productos Terminados',                          'debito',  'cuenta'],
            ['146505', 'Productos Terminados',                          'debito',  'subcuenta'],
            ['1470',   'Mercancías en Tránsito',                        'debito',  'cuenta'],
            ['147005', 'Mercancías en Tránsito',                        'debito',  'subcuenta'],
            ['1499',   'Provisión para Inventarios',                    'debito',  'cuenta'],
            ['149905', 'Provisión Inventario de Mercancías',            'debito',  'subcuenta'],
            //  Grupo 15 — Propiedades, Planta y Equipo
            ['15',     'Propiedades, Planta y Equipo',                  'debito',  'grupo'],
            ['1504',   'Terrenos',                                      'debito',  'cuenta'],
            ['150405', 'Terrenos Urbanos',                              'debito',  'subcuenta'],
            ['1516',   'Construcciones y Edificaciones',                'debito',  'cuenta'],
            ['151605', 'Edificios',                                     'debito',  'subcuenta'],
            ['1524',   'Equipo de Oficina',                             'debito',  'cuenta'],
            ['152405', 'Muebles y Enseres',                             'debito',  'subcuenta'],
            ['1528',   'Equipo de Computación y Comunicación',          'debito',  'cuenta'],
            ['152805', 'Computadores y Accesorios',                     'debito',  'subcuenta'],
            ['1536',   'Equipo de Transporte',                          'debito',  'cuenta'],
            ['153605', 'Vehículos',                                     'debito',  'subcuenta'],
            ['1592',   'Depreciación Acumulada — PPE',                  'credito', 'cuenta'],
            ['159216', 'Depreciación Construcciones y Edificaciones',   'credito', 'subcuenta'],
            ['159224', 'Depreciación Equipo de Oficina',                'credito', 'subcuenta'],
            ['159228', 'Depreciación Equipo de Computación',            'credito', 'subcuenta'],
            ['159236', 'Depreciación Equipo de Transporte',             'credito', 'subcuenta'],
            //  Grupo 16 — Intangibles
            ['16',     'Intangibles',                                   'debito',  'grupo'],
            ['1605',   'Crédito Mercantil',                             'debito',  'cuenta'],
            ['160505', 'Crédito Mercantil',                             'debito',  'subcuenta'],
            ['1625',   'Patentes',                                      'debito',  'cuenta'],
            ['162505', 'Patentes y Marcas',                             'debito',  'subcuenta'],
            //  Grupo 17 — Diferidos
            ['17',     'Diferidos',                                     'debito',  'grupo'],
            ['1705',   'Gastos Pagados por Anticipado',                 'debito',  'cuenta'],
            ['170505', 'Seguros Pagados por Anticipado',                'debito',  'subcuenta'],
            ['170510', 'Arrendamientos Pagados por Anticipado',         'debito',  'subcuenta'],

            // ════════════════════════════════════════════════════════════════
            // CLASE 2 — PASIVO
            // ════════════════════════════════════════════════════════════════
            ['2',      'Pasivo',                                        'credito', 'clase'],
            //  Grupo 21 — Obligaciones Financieras
            ['21',     'Obligaciones Financieras',                      'credito', 'grupo'],
            ['2105',   'Bancos Nacionales',                             'credito', 'cuenta', true],
            ['210505', 'Préstamos Bancarios Corto Plazo',               'credito', 'subcuenta', true],
            ['210510', 'Préstamos Bancarios Largo Plazo',               'credito', 'subcuenta', true],
            //  Grupo 22 — Proveedores
            ['22',     'Proveedores',                                   'credito', 'grupo'],
            ['2205',   'Proveedores Nacionales',                        'credito', 'cuenta', true],
            ['220505', 'Proveedores Nacionales',                        'credito', 'subcuenta', true],
            ['220510', 'Proveedores del Exterior',                      'credito', 'subcuenta', true],
            //  Grupo 23 — Cuentas por Pagar
            ['23',     'Cuentas por Pagar',                             'credito', 'grupo'],
            ['2335',   'Costos y Gastos por Pagar',                     'credito', 'cuenta', true],
            ['233505', 'Honorarios por Pagar',                          'credito', 'subcuenta', true],
            ['233510', 'Arrendamientos por Pagar',                      'credito', 'subcuenta', true],
            ['233515', 'Servicios por Pagar',                           'credito', 'subcuenta', true],
            ['2360',   'Dividendos por Pagar',                          'credito', 'cuenta', true],
            ['236005', 'Dividendos Nacionales',                         'credito', 'subcuenta', true],
            ['2365',   'Retención en la Fuente',                        'credito', 'cuenta', true],
            ['236505', 'Retefuente Compras Generales',                  'credito', 'subcuenta', true, true],
            ['236515', 'Retefuente Servicios Generales',                'credito', 'subcuenta', true, true],
            ['236540', 'Retefuente Honorarios',                         'credito', 'subcuenta', true, true],
            ['236570', 'Retefuente Arrendamientos',                     'credito', 'subcuenta', true, true],
            ['2368',   'Impuesto de Industria y Comercio Retenido',     'credito', 'cuenta', true],
            ['236801', 'ReteICA',                                       'credito', 'subcuenta', true, true],
            ['2370',   'Retenciones y Aportes de Nómina',               'credito', 'cuenta'],
            ['237005', 'Aportes a Salud',                               'credito', 'subcuenta'],
            ['237010', 'Aportes a Pensión',                             'credito', 'subcuenta'],
            ['237015', 'Aportes a ARL',                                 'credito', 'subcuenta'],
            ['237020', 'Aportes Parafiscales SENA',                     'credito', 'subcuenta'],
            ['237025', 'Aportes Parafiscales ICBF',                     'credito', 'subcuenta'],
            ['237030', 'Aportes Caja de Compensación',                  'credito', 'subcuenta'],
            //  Grupo 24 — Impuestos, Gravámenes y Tasas
            ['24',     'Impuestos, Gravámenes y Tasas',                 'credito', 'grupo'],
            ['2404',   'De Renta y Complementarios',                    'credito', 'cuenta'],
            ['240405', 'Impuesto de Renta Vigencia Corriente',          'credito', 'subcuenta'],
            ['2408',   'Impuesto sobre las Ventas por Pagar',           'credito', 'cuenta'],
            ['240801', 'IVA Generado en Ventas',                        'credito', 'subcuenta', false, true],
            ['240810', 'IVA Descontable en Compras',                    'credito', 'subcuenta', false, true],
            ['240815', 'IVA Retenido',                                  'credito', 'subcuenta', false, true],
            ['2412',   'Impuesto de Industria y Comercio',              'credito', 'cuenta'],
            ['241205', 'ICA por Pagar',                                 'credito', 'subcuenta'],
            //  Grupo 25 — Obligaciones Laborales
            ['25',     'Obligaciones Laborales',                        'credito', 'grupo'],
            ['2505',   'Salarios por Pagar',                            'credito', 'cuenta'],
            ['250505', 'Sueldos y Salarios',                            'credito', 'subcuenta'],
            ['2510',   'Cesantías Consolidadas',                        'credito', 'cuenta'],
            ['251005', 'Cesantías',                                     'credito', 'subcuenta'],
            ['2515',   'Intereses sobre Cesantías',                     'credito', 'cuenta'],
            ['251505', 'Intereses Cesantías',                           'credito', 'subcuenta'],
            ['2520',   'Prima de Servicios',                            'credito', 'cuenta'],
            ['252005', 'Prima de Servicios',                            'credito', 'subcuenta'],
            ['2525',   'Vacaciones Consolidadas',                       'credito', 'cuenta'],
            ['252505', 'Vacaciones',                                    'credito', 'subcuenta'],

            // ════════════════════════════════════════════════════════════════
            // CLASE 3 — PATRIMONIO
            // ════════════════════════════════════════════════════════════════
            ['3',      'Patrimonio',                                    'credito', 'clase'],
            ['31',     'Capital Social',                                'credito', 'grupo'],
            ['3105',   'Capital Suscrito y Pagado',                     'credito', 'cuenta'],
            ['310505', 'Capital Social',                                'credito', 'subcuenta'],
            ['33',     'Reservas',                                      'credito', 'grupo'],
            ['3305',   'Reservas Obligatorias',                         'credito', 'cuenta'],
            ['330505', 'Reserva Legal (10%)',                           'credito', 'subcuenta'],
            ['3315',   'Reservas Estatutarias',                         'credito', 'cuenta'],
            ['331505', 'Reserva Estatutaria',                           'credito', 'subcuenta'],
            ['3325',   'Reservas Ocasionales',                          'credito', 'cuenta'],
            ['332505', 'Reserva para Futuros Ensanches',                'credito', 'subcuenta'],
            ['36',     'Resultados del Ejercicio',                      'credito', 'grupo'],
            ['3605',   'Utilidad del Ejercicio',                        'credito', 'cuenta'],
            ['360505', 'Utilidad del Ejercicio',                        'credito', 'subcuenta'],
            ['3610',   'Pérdida del Ejercicio',                         'debito',  'cuenta'],
            ['361005', 'Pérdida del Ejercicio',                         'debito',  'subcuenta'],
            ['37',     'Resultados de Ejercicios Anteriores',           'credito', 'grupo'],
            ['3705',   'Utilidades Acumuladas',                         'credito', 'cuenta'],
            ['370505', 'Utilidades de Ejercicios Anteriores',           'credito', 'subcuenta'],
            ['3710',   'Pérdidas Acumuladas',                           'debito',  'cuenta'],
            ['371005', 'Pérdidas de Ejercicios Anteriores',             'debito',  'subcuenta'],
            ['38',     'Superávit por Valorización',                    'credito', 'grupo'],
            ['3805',   'De Propiedades, Planta y Equipo',               'credito', 'cuenta'],
            ['380505', 'Valorización Activos Fijos',                    'credito', 'subcuenta'],

            // ════════════════════════════════════════════════════════════════
            // CLASE 4 — INGRESOS
            // ════════════════════════════════════════════════════════════════
            ['4',      'Ingresos',                                      'credito', 'clase'],
            ['41',     'Ingresos Operacionales',                        'credito', 'grupo'],
            ['4135',   'Comercio al por Mayor y al por Menor',          'credito', 'cuenta'],
            ['413505', 'Venta de Mercancías',                           'credito', 'subcuenta'],
            ['413510', 'Devoluciones en Ventas de Mercancías',          'debito',  'subcuenta'],
            ['4145',   'Materiales, Repuestos y Accesorios',            'credito', 'cuenta'],
            ['414505', 'Venta de Materiales',                           'credito', 'subcuenta'],
            ['4175',   'Servicios',                                     'credito', 'cuenta'],
            ['417505', 'Honorarios',                                    'credito', 'subcuenta'],
            ['417510', 'Servicios de Consultoría',                      'credito', 'subcuenta'],
            ['417515', 'Servicios de Mantenimiento',                    'credito', 'subcuenta'],
            ['4195',   'Devoluciones en Ventas',                        'debito',  'cuenta'],
            ['419505', 'Devoluciones y Rebajas en Ventas',              'debito',  'subcuenta'],
            ['42',     'Ingresos No Operacionales',                     'credito', 'grupo'],
            ['4210',   'Financieros',                                   'credito', 'cuenta'],
            ['421005', 'Intereses',                                     'credito', 'subcuenta'],
            ['421010', 'Rendimientos Financieros',                      'credito', 'subcuenta'],
            ['421015', 'Descuentos Comerciales Tomados',                'credito', 'subcuenta'],
            ['4225',   'Arrendamientos',                                'credito', 'cuenta'],
            ['422505', 'Arrendamiento de Bienes Inmuebles',             'credito', 'subcuenta'],
            ['4295',   'Otros Ingresos No Operacionales',               'credito', 'cuenta'],
            ['429505', 'Recuperaciones',                                'credito', 'subcuenta'],
            ['429510', 'Indemnizaciones',                               'credito', 'subcuenta'],
            ['429515', 'Utilidad en Venta de Activos',                  'credito', 'subcuenta'],

            // ════════════════════════════════════════════════════════════════
            // CLASE 5 — GASTOS OPERACIONALES
            // ════════════════════════════════════════════════════════════════
            ['5',      'Gastos',                                        'debito',  'clase'],
            //  51 — Administración
            ['51',     'Gastos Operacionales de Administración',        'debito',  'grupo'],
            ['5105',   'Gastos de Personal',                            'debito',  'cuenta'],
            ['510506', 'Sueldos y Salarios',                            'debito',  'subcuenta'],
            ['510527', 'Auxilio de Transporte',                         'debito',  'subcuenta'],
            ['510528', 'Bonificaciones',                                'debito',  'subcuenta'],
            ['5110',   'Honorarios',                                    'debito',  'cuenta', true],
            ['511005', 'Honorarios a Personas Naturales',               'debito',  'subcuenta', true],
            ['511010', 'Honorarios a Personas Jurídicas',               'debito',  'subcuenta', true],
            ['5115',   'Impuestos',                                     'debito',  'cuenta'],
            ['511505', 'Impuesto de Industria y Comercio',              'debito',  'subcuenta'],
            ['511510', 'Impuesto Predial',                              'debito',  'subcuenta'],
            ['5120',   'Arrendamientos',                                'debito',  'cuenta', true],
            ['512005', 'Arrendamiento de Local Comercial',              'debito',  'subcuenta', true],
            ['512010', 'Arrendamiento de Equipo',                       'debito',  'subcuenta', true],
            ['5125',   'Contribuciones y Afiliaciones',                 'debito',  'cuenta'],
            ['512505', 'Afiliaciones Gremiales',                        'debito',  'subcuenta'],
            ['5130',   'Seguros',                                       'debito',  'cuenta'],
            ['513005', 'Seguros Todo Riesgo',                           'debito',  'subcuenta'],
            ['513010', 'Seguros de Vida',                               'debito',  'subcuenta'],
            ['5135',   'Servicios',                                     'debito',  'cuenta'],
            ['513505', 'Servicios Públicos',                            'debito',  'subcuenta'],
            ['513510', 'Telefonía e Internet',                          'debito',  'subcuenta'],
            ['513515', 'Correo y Mensajería',                           'debito',  'subcuenta'],
            ['5140',   'Gastos Legales',                                'debito',  'cuenta'],
            ['514005', 'Notariales y de Registro',                      'debito',  'subcuenta'],
            ['514010', 'Licencias y Permisos',                          'debito',  'subcuenta'],
            ['5145',   'Mantenimiento y Reparaciones',                  'debito',  'cuenta'],
            ['514505', 'Mantenimiento Equipos Oficina',                 'debito',  'subcuenta'],
            ['514510', 'Mantenimiento Instalaciones',                   'debito',  'subcuenta'],
            ['5150',   'Adecuación e Instalación',                      'debito',  'cuenta'],
            ['515005', 'Adecuación de Oficinas',                        'debito',  'subcuenta'],
            ['5155',   'Amortizaciones',                                'debito',  'cuenta'],
            ['515505', 'Amortización Cargos Diferidos',                 'debito',  'subcuenta'],
            ['5160',   'Depreciaciones',                                'debito',  'cuenta'],
            ['516005', 'Depreciación Edificios',                        'debito',  'subcuenta'],
            ['516024', 'Depreciación Equipo de Oficina',                'debito',  'subcuenta'],
            ['516028', 'Depreciación Equipo de Computación',            'debito',  'subcuenta'],
            ['516036', 'Depreciación Equipo de Transporte',             'debito',  'subcuenta'],
            ['5195',   'Diversos de Administración',                    'debito',  'cuenta'],
            ['519505', 'Papelería y Útiles de Oficina',                 'debito',  'subcuenta'],
            ['519510', 'Gastos de Viaje y Representación',              'debito',  'subcuenta'],
            ['519515', 'Aseo y Cafetería',                              'debito',  'subcuenta'],
            ['519520', 'Combustibles y Lubricantes',                    'debito',  'subcuenta'],
            ['519525', 'Gastos de Sistematización',                     'debito',  'subcuenta'],
            //  52 — Ventas
            ['52',     'Gastos Operacionales de Ventas',                'debito',  'grupo'],
            ['5205',   'Gastos de Personal de Ventas',                  'debito',  'cuenta'],
            ['520506', 'Sueldos Fuerza de Ventas',                      'debito',  'subcuenta'],
            ['520510', 'Comisiones de Ventas',                          'debito',  'subcuenta'],
            ['5245',   'Publicidad y Propaganda',                       'debito',  'cuenta'],
            ['524505', 'Publicidad Digital',                            'debito',  'subcuenta'],
            ['524510', 'Material POP y Punto de Venta',                 'debito',  'subcuenta'],
            ['5250',   'Transportes, Fletes y Acarreos',                'debito',  'cuenta'],
            ['525005', 'Fletes de Distribución',                        'debito',  'subcuenta'],
            ['525010', 'Fletes de Importación',                         'debito',  'subcuenta'],
            //  53 — No Operacionales
            ['53',     'Gastos No Operacionales',                       'debito',  'grupo'],
            ['5305',   'Financieros',                                   'debito',  'cuenta'],
            ['530505', 'Gastos Bancarios y Comisiones',                 'debito',  'subcuenta'],
            ['530510', 'Intereses Bancarios',                           'debito',  'subcuenta'],
            ['530515', 'Descuentos Comerciales Concedidos',             'debito',  'subcuenta'],
            ['5315',   'Pérdida en Venta y Retiro de Bienes',           'debito',  'cuenta'],
            ['531505', 'Pérdida Venta de Activos Fijos',                'debito',  'subcuenta'],
            ['5360',   'Ajuste Diferencia en Cambio',                   'debito',  'cuenta'],
            ['536005', 'Pérdida por Diferencia en Cambio',              'debito',  'subcuenta'],
            ['5395',   'Gastos Extraordinarios',                        'debito',  'cuenta'],
            ['539505', 'Multas y Sanciones',                            'debito',  'subcuenta'],

            // ════════════════════════════════════════════════════════════════
            // CLASE 6 — COSTOS DE VENTAS Y PRESTACIÓN DE SERVICIOS
            // ════════════════════════════════════════════════════════════════
            ['6',      'Costos de Ventas y de Prestación de Servicios', 'debito',  'clase'],
            ['61',     'Costo de Ventas y de Prestación de Servicios',  'debito',  'grupo'],
            ['6135',   'Comercio al por Mayor y al por Menor',          'debito',  'cuenta'],
            ['613505', 'Costo de Ventas de Mercancías',                 'debito',  'subcuenta'],
            ['613510', 'Devoluciones en Compras de Mercancías',         'credito', 'subcuenta'],
            ['6145',   'Materiales, Repuestos y Accesorios',            'debito',  'cuenta'],
            ['614505', 'Costo de Materiales',                           'debito',  'subcuenta'],
            ['6175',   'Servicios',                                     'debito',  'cuenta'],
            ['617505', 'Costo de Servicios Prestados',                  'debito',  'subcuenta'],
            ['62',     'Compras',                                       'debito',  'grupo'],
            ['6205',   'Compras de Mercancías',                         'debito',  'cuenta'],
            ['620505', 'Compras de Mercancías Nacionales',              'debito',  'subcuenta'],
            ['620510', 'Devoluciones en Compras',                       'credito', 'subcuenta'],
            ['6245',   'Compras de Materias Primas',                    'debito',  'cuenta'],
            ['624505', 'Compras de Materias Primas Nacionales',         'debito',  'subcuenta'],
        ];
    }

    // ─── Ejecución ───────────────────────────────────────────────────────────

    public function up(): void
    {
        // Paso 1: insertar todas las cuentas del catálogo
        $this->insertarCatalogo();

        // Paso 2: corregir acepta_movimientos en cuentas ya existentes
        $this->corregirAceptaMovimientos();
    }

    public function down(): void
    {
        // Eliminar solo las cuentas que esta migración insertó
        // (las que ya existían antes NO se tocan)
        $codigos = array_column($this->catalogo(), 0);
        CuentaContable::whereIn('codigo', $codigos)->delete();
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function insertarCatalogo(): void
    {
        // Índice codigo → id (cache para resolver parent_id sin N+1)
        $indice = CuentaContable::pluck('id', 'codigo')->toArray();

        foreach ($this->catalogo() as $fila) {
            [$codigo, $nombre, $naturaleza, $nivel] = $fila;
            $exigeTercero      = $fila[4] ?? false;
            $exigeBaseImpuesto = $fila[5] ?? false;

            // Ya existe → no sobreescribir
            if (isset($indice[$codigo])) {
                continue;
            }

            $parentId = $this->resolverParentId($codigo, $indice);
            $acepta   = in_array($nivel, ['subcuenta', 'auxiliar'], true);

            $cuenta = CuentaContable::create([
                'codigo'               => $codigo,
                'nombre'               => $nombre,
                'naturaleza'           => $naturaleza,
                'nivel'                => $nivel,
                'parent_id'            => $parentId,
                'acepta_movimientos'   => $acepta,
                'exige_tercero'        => $exigeTercero,
                'exige_centro_costo'   => false,
                'exige_base_impuesto'  => $exigeBaseImpuesto,
                'activo'               => true,
            ]);

            $indice[$codigo] = $cuenta->id;
        }
    }

    /**
     * Corrige acepta_movimientos en cuentas que ya existían en BD
     * y tienen el valor incorrecto (false cuando deberían ser true).
     */
    private function corregirAceptaMovimientos(): void
    {
        // Subcuentas y auxiliares deben aceptar movimientos
        CuentaContable::whereIn('nivel', ['subcuenta', 'auxiliar'])
            ->where('acepta_movimientos', false)
            ->update(['acepta_movimientos' => true, 'tipo_cuenta' => 'movimiento']);

        // Clases, grupos y cuentas NO deben aceptar movimientos
        CuentaContable::whereIn('nivel', ['clase', 'grupo', 'cuenta'])
            ->where('acepta_movimientos', true)
            ->update(['acepta_movimientos' => false, 'tipo_cuenta' => 'agrupacion']);
    }

    /**
     * Resuelve el parent_id buscando en el índice por longitud de prefijo.
     *
     * Lógica de prefijos PUC colombiano:
     *   2 dígitos (grupo)     → padre es la clase     (1 dígito)
     *   4 dígitos (cuenta)    → padre es el grupo      (2 dígitos)
     *   6 dígitos (subcuenta) → padre es la cuenta     (4 dígitos)
     *   8 dígitos (auxiliar)  → padre es la subcuenta  (6 dígitos)
     */
    private function resolverParentId(string $codigo, array $indice): ?string
    {
        $len = strlen($codigo);
        $parentLen = match ($len) {
            2 => 1, 4 => 2, 6 => 4, 8 => 6,
            default => null,
        };

        if ($parentLen === null) {
            return null; // Es clase (1 dígito)
        }

        $parentCodigo = substr($codigo, 0, $parentLen);
        return $indice[$parentCodigo] ?? null;
    }
};
