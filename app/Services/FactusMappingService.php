<?php

namespace App\Services;

/**
 * Mapeo entre códigos DIAN/locales y los IDs numéricos de Factus.
 */
class FactusMappingService
{
    /**
     * Código DIAN de tipo de documento → ID numérico Factus
     * DIAN: 11=RC, 12=TI, 13=CC, 21=TE, 22=CE, 31=NIT, 41=PP, 42=DE, 47=PEP, 50=NIT_OTRO, 91=NUIP
     */
    public static function documentoId(?string $dianCode): int
    {
        return match ((string) $dianCode) {
            '11'    => 1,   // Registro civil
            '12'    => 2,   // Tarjeta de identidad
            '13'    => 3,   // Cédula de ciudadanía
            '21'    => 4,   // Tarjeta de extranjería
            '22'    => 5,   // Cédula de extranjería
            '31'    => 6,   // NIT
            '41'    => 7,   // Pasaporte
            '42'    => 8,   // Documento de identificación extranjero
            '47'    => 9,   // PEP
            '50'    => 10,  // NIT otro país
            '91'    => 11,  // NUIP
            default => 3,   // CC por defecto
        };
    }

    /**
     * Organización jurídica: 1=Persona Jurídica, 2=Persona Natural
     * Infiere por tipo de documento si no está explícito.
     */
    public static function organizacionJuridicaId(?string $stored, ?string $dianDocCode): int
    {
        if ($stored !== null && is_numeric($stored)) {
            return (int) $stored;
        }
        // Si tiene NIT → Persona Jurídica; si tiene CC → Persona Natural
        return ($dianDocCode === '31') ? 1 : 2;
    }

    /**
     * Régimen tributario del cliente (customer level):
     * 18=IVA (responsable), 21=No responsable de IVA
     */
    public static function tributoClienteId(?string $stored): int
    {
        return match (strtoupper((string) $stored)) {
            '01', 'IVA', '18' => 18,  // Responsable de IVA
            default           => 21,  // No responsable de IVA (ZZ, null, etc.)
        };
    }

    /**
     * Tribute ID a nivel de ítem (tipo de impuesto del producto):
     * 1=IVA, 2=IC, 3=ICA, 4=INC, etc.
     */
    public static function tributoItemId(float $taxRate): int
    {
        if ($taxRate == 0) return 1;  // IVA 0% sigue siendo IVA
        return 1;                     // IVA
    }

    /**
     * Unidad de medida DIAN code → ID numérico Factus
     */
    public static function unidadMedidaId(?string $dianCode): int
    {
        return match (strtoupper((string) $dianCode)) {
            '94'  => 70,   // Unidad
            'KGM' => 414,  // Kilogramo
            'LBR' => 449,  // Libra
            'MTR' => 512,  // Metro
            'GLL' => 874,  // Galón
            default => 70, // Unidad por defecto
        };
    }

    /**
     * Municipio: intenta obtener el ID numérico de Factus.
     * Acepta: ID numérico directo, código DANE (5 dígitos), o nombre de ciudad.
     */
    /**
     * Descarga TODO el catálogo de municipios de Factus y lo cachea por 7 días.
     * Devuelve mapa: codigo_dane → factus_id.
     * Se llama bajo demanda (lazy) la primera vez que se necesita un municipio.
     */
    private static function loadFactusMunicipiosCache(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'factus_municipios_map',
            now()->addDays(7),
            function () {
                try {
                    $factus = app(\App\Services\FactusService::class);
                    $token  = $factus->getAccessToken();
                    if (!$token) return [];

                    // Detectar base URL del tenant (o fallback a config)
                    $baseUrl = config('services.factus.base_url', 'https://api-sandbox.factus.com.co');
                    try {
                        $tenantUrl = \App\Models\Tenant\Config::get('factus_base_url');
                        if ($tenantUrl) $baseUrl = $tenantUrl;
                    } catch (\Throwable) { /* sin tenant — usar config */ }

                    $resp = \Illuminate\Support\Facades\Http::withToken($token)
                        ->acceptJson()
                        ->timeout(30)
                        ->get("{$baseUrl}/v1/municipalities", ['per_page' => 1500]);

                    if (!$resp->successful()) return [];
                    $data = $resp->json('data') ?? [];

                    $map = [];
                    foreach ($data as $m) {
                        if (!empty($m['code']) && !empty($m['id'])) {
                            $map[(string) $m['code']] = (int) $m['id'];
                        }
                    }
                    \Illuminate\Support\Facades\Log::info('Factus municipios cacheados', ['count' => count($map)]);
                    return $map;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('No se pudo cargar catálogo Factus', ['err' => $e->getMessage()]);
                    return [];
                }
            }
        );
    }

    public static function municipioId($stored): int
    {
        // Si parece código DANE (5 dígitos), buscarlo en el catálogo real de Factus
        // (descargado bajo demanda y cacheado 7 días).
        $storedStr = (string) $stored;
        $esDane = preg_match('/^\d{5}$/', $storedStr) === 1;

        if ($esDane) {
            $factusMap = self::loadFactusMunicipiosCache();
            if (isset($factusMap[$storedStr])) {
                return $factusMap[$storedStr];
            }
            // Si Factus no devolvió el catálogo (offline/error), fallback al mapeo estático
        }

        // Mapeo de códigos DANE → Factus ID (más comunes)
        $daneToFactus = [
            '11001' => 169, // Bogotá
            '05001' => 80,  // Medellín
            '76001' => 897, // Cali
            '08001' => 147, // Barranquilla
            '13001' => 178, // Cartagena
            '41001' => 675, // Neiva
            '41298' => 666, // Garzón
            '17001' => 425, // Manizales
            '63001' => 818, // Armenia
            '66001' => 826, // Pereira
            '68001' => 834, // Bucaramanga
            '54001' => 748, // Cúcuta
            '73001' => 860, // Ibagué
            '15001' => 227, // Tunja
            '18001' => 398, // Florencia
            '85001' => 793, // Yopal
            '50001' => 689, // Villavicencio
            '52001' => 723, // Pasto
            '23001' => 466, // Montería
            '19001' => 413, // Popayán
        ];

        if (isset($daneToFactus[$storedStr])) {
            return $daneToFactus[$storedStr];
        }

        // Si parece DANE pero no está mapeado, intentar resolver el departamento
        // y caer a la capital (estimación). Mejor enviar Bogotá que enviar un
        // ID inexistente que Factus rechaza con 422.
        if ($esDane) {
            $deptCode = substr($storedStr, 0, 2);
            $capitalByDept = [
                '05' => 80,   '08' => 147,  '11' => 169,  '13' => 178,
                '15' => 227,  '17' => 425,  '18' => 398,  '19' => 413,
                '20' => 950,  '23' => 466,  '25' => 169,  '27' => 1000, // Quibdó
                '41' => 675,  '44' => 700,  '47' => 720,  '50' => 689,
                '52' => 723,  '54' => 748,  '63' => 818,  '66' => 826,
                '68' => 834,  '70' => 850,  '73' => 860,  '76' => 897,
                '81' => 900,  '85' => 793,  '86' => 904,  '88' => 950,
                '91' => 960,  '94' => 970,  '95' => 980,  '97' => 990,  '99' => 999,
            ];
            if (isset($capitalByDept[$deptCode])) {
                return $capitalByDept[$deptCode];
            }
            return 169; // Bogotá fallback final si dpto no reconocido
        }

        // Solo si NO es código DANE (string libre tipo "Cali"), intentar devolver
        // directamente cuando ya es un ID Factus válido (rango ~1..1100).
        if (is_numeric($stored) && (int) $stored > 0 && (int) $stored <= 1200) {
            return (int) $stored;
        }

        // Mapeo por nombre de ciudad (normalizado)
        $nameToFactus = [
            'bogota'        => 169,
            'bogotá'        => 169,
            'medellin'      => 80,
            'medellín'      => 80,
            'cali'          => 897,
            'barranquilla'  => 147,
            'cartagena'     => 178,
            'neiva'         => 675,
            'garzon'        => 666,
            'garzón'        => 666,
            'manizales'     => 425,
            'armenia'       => 818,
            'pereira'       => 826,
            'bucaramanga'   => 834,
            'cucuta'        => 748,
            'cúcuta'        => 748,
            'ibague'        => 860,
            'ibagué'        => 860,
            'tunja'         => 227,
            'florencia'     => 398,
            'yopal'         => 793,
            'villavicencio' => 689,
            'pasto'         => 723,
            'monteria'      => 466,
            'montería'      => 466,
            'popayan'       => 413,
            'popayán'       => 413,
        ];

        $key = strtolower(trim((string) $stored));
        return $nameToFactus[$key] ?? 169; // Bogotá como fallback
    }
}
