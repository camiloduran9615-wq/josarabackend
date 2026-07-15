# JOSARA CLOUD — HANDOFF TÉCNICO Y FUNCIONAL

> **Fecha de corte:** 2026-07-14 17:35 UTC
> **Leyenda:** ✅ confirmado · ⚠️ parcial/requiere revisión · ❌ fallo confirmado · ⏳ pendiente · ℹ️ informativo

## 1. Información del documento

| Campo | Valor |
|---|---|
| Fecha | 2026-07-14 (UTC) |
| Rama backend | `main` |
| Commit backend | `161fc9e` — `feat(users): secure account status changes` (2026-07-14) |
| Rama frontend | `main` |
| Commit frontend | `c93b3d1` — `feat(users): add account status controls` (2026-07-14) |
| Entorno analizado | Servidor Linux en `/srv/apps`; producción conocida `https://josara.colombiaapp.fun` |
| Responsable | Codex, análisis estático y diagnóstico no destructivo |
| Alcance | Backend, frontend, PostgreSQL, tenancy, autenticación, módulos, Factus, Super Admin, seguridad, QA, Git, despliegue y continuidad |
| Limitaciones | Sin llamadas reales a Factus; sin emitir documentos; sin alterar datos; sin instalar dependencias; sin ejecutar PHPUnit por falta de dependencias `dev` y falta de garantía de aislamiento de la BD de pruebas; sin pruebas ofensivas; sin inspección visual manual en navegador |

La ruta Windows indicada originalmente (`C:\dev\saas_contable`) no existe en este servidor. La instalación está separada en `/srv/apps/josara-api`, `/srv/apps/josara-web` y `/srv/apps/josara-db`. Desde el 2026-07-14 este documento vive versionado en la raíz de `josara-api` para poder continuar el trabajo desde otros equipos.

Este análisis no modificó código, configuración, migraciones ni lógica de negocio. El único archivo fuente creado por la tarea es este documento. El build frontend solo actualizó artefactos ignorados en `dist/` y metadatos ignorados de TypeScript.

## 2. Resumen ejecutivo

JOSARA CLOUD es un ERP contable SaaS multiempresa colombiano con una superficie funcional amplia: registro de empresas, facturación electrónica, inventario, compras, cartera, ciclo contable, reportes, impuestos, nómina, CRM, conciliación bancaria, activos fijos, auditoría y panel de plataforma.

### Estado general

- ✅ Arquitectura multi-tenant de **base de datos por empresa**, implementada con `stancl/tenancy`.
- ✅ API versionada y protegida con Sanctum; rutas tenant bajo `/api/v1/{tenant}/...`.
- ✅ Backend central y tenant separados lógicamente; Super Admin usa modelos y rutas centrales.
- ✅ 17 migraciones centrales aparecen aplicadas y la única BD tenant encontrada registra 75 migraciones, igual al número de archivos tenant actuales.
- ✅ Frontend compila, lint finaliza correctamente y el smoke estático visual pasa.
- ⚠️ Los 301 tests PHP existentes no pudieron ejecutarse en este servidor: no están instalados PHPUnit, PHPStan ni Pint.
- ⚠️ Factus tiene manejo mejorado de 4xx/5xx y secretos cifrados por tenant, pero las credenciales operativas conocidas fueron rechazadas y persisten riesgos de caché, logging e idempotencia.
- ❌ No hay evidencia de un worker de cola ni scheduler Laravel activo en el despliegue host Nginx/PHP-FPM. Correos encolados, webhooks y tareas nocturnas pueden no ejecutarse.
- ❌ Existen archivos locales sensibles no versionados y copias de `.env` con permisos de lectura para grupo/otros. Su contenido no fue leído ni se reproduce aquí.
- ⚠️ La autorización fina no cubre todos los controladores: existe un middleware global de abilities como mitigación, pero no reemplaza permisos por recurso/acción.
- ⚠️ No existe flujo HTTP/UI confirmado de recuperación de contraseña, verificación de correo, MFA ni expiración de tokens Sanctum.

### Nivel de madurez

**Beta avanzada / preproducción operativa.** Hay implementación extensa y pruebas diseñadas, pero faltan una ejecución reproducible de toda la suite, operación de colas/scheduler, cierre de riesgos de secretos y autorización, y validación end-to-end de Factus.

### Veredicto condicionado

## **NO APTO PARA PRODUCCIÓN**

Aunque el sitio está desplegado y el build es correcto, el criterio de producción no se limita a compilar. El veredicto se sustenta en: ausencia confirmada de procesos de cola/scheduler, archivos sensibles locales, falta de ejecución de la suite PHP, credenciales Factus no operativas según evidencia previa, logging potencialmente sensible de respuestas Factus, ausencia de expiración de tokens y cobertura de autorización incompleta. El aislamiento por BD reduce significativamente el riesgo cross-tenant, pero debe validarse con la suite completa antes de cambiar el veredicto.

## 3. Descripción del producto

JOSARA CLOUD ofrece contabilidad, administración y cumplimiento colombiano en modalidad SaaS multiempresa. Sus usuarios objetivo son administradores de empresa, contadores, auxiliares, auditores y usuarios de consulta. La plataforma incorpora un panel separado para operadores globales (`super_admin`, soporte, facturación y solo lectura).

La propuesta observada combina:

- ciclo de ventas, compras, cartera e inventario;
- contabilidad de doble partida, cierres, saldos y reportes;
- catálogos colombianos DANE/DIAN, impuestos e información exógena;
- integración de facturación electrónica con Factus;
- aislamiento físico por base de datos tenant;
- control central de planes, suscripciones, empresas y operación.

## 4. Arquitectura

### Esquema real

```text
Navegador / React SPA
        ↓ HTTPS (Cloudflare/proxy externo, pendiente de confirmar detalle)
Nginx :80 → assets de josara-web/dist
        ↓ /api, /storage y logos
Nginx interno 127.0.0.1:18083
        ↓ FastCGI
PHP 8.3-FPM / Laravel 12
        ↓
Rutas centrales o middleware InitializeTenancyByTenantIdentifier
        ↓
Sanctum + abilities + límites de plan + Policies parciales
        ↓
Controladores → servicios/repositorios → modelos Eloquent/SQL
        ↓
PostgreSQL central + una base PostgreSQL independiente por tenant
        ↓
Factus / DANE / correo / n8n / filesystem tenant
```

### Backend

Monolito modular Laravel. `routes/api.php` contiene plataforma, registro/login y Super Admin. `routes/tenant.php` contiene recursos contables. La capa HTTP está en `app/Http`; la aplicación/dominio se distribuye entre `app/Services`, `app/Domain`, `app/Events`, `app/Listeners`, `app/Jobs` y `app/Repositories`; persistencia en `app/Models` y migraciones.

El manejo de excepciones se configura en `bootstrap/app.php`; errores Factus esperados usan `FactusIntegrationException`. No existe una capa uniforme de DTO/FormRequest en todos los módulos: algunos controladores validan inline y otros usan requests dedicados.

### Frontend

SPA React/TypeScript. `src/App.tsx` concentra rutas. Conviven una capa “legacy” (`src/lib/api.ts`, `src/context/AuthContext.tsx`, servicios por módulo) y una capa nueva (`src/shared/api/client.ts`, Zustand y features). React Query se usa principalmente en features nuevas; otras pantallas gestionan estado y llamadas directamente.

### Persistencia

- BD central: tenants, dominios, catálogos compartidos, auditoría central, planes, suscripciones, administradores y operación.
- BD tenant: usuarios, tokens, configuraciones y todos los datos contables/operativos.
- El código no usa `company_id` en cada tabla tenant porque el aislamiento se realiza por conexión/base física.
- UUID se usa ampliamente para IDs; `tenant_slug`/`company_code` son identificadores públicos.

### Eventos, colas y programación

- Eventos de asientos y periodos actualizan saldos, auditoría y caché mediante `EventServiceProvider`.
- `NotificarN8nListener` implementa `ShouldQueue`, HMAC y reintentos.
- Jobs de saldos: backfill, recálculo y reconciliación.
- Scheduler: reconciliación 02:00 Colombia, auditoría 03:00 servidor y limpieza diaria.
- ❌ En el host analizado no se observó `queue:work`, `queue:listen` ni `schedule:work`.

### Caché, archivos y comunicaciones

- Cache efectivo: `database`; tenancy agrega tags, incompatibles con el store database en operaciones que usan cache tenant etiquetada.
- Archivos: discos `local/public` con `FilesystemTenancyBootstrapper`; logos bajo ruta tenant.
- Correo: el entorno reporta driver `log`; con cola distinta de `sync`, el servicio de registro encola correos.
- Webhooks: URL/secret por metadata central del tenant, firma HMAC; la URL configurable requiere mitigación SSRF adicional.
- Logging: stack/single, Nginx en `/srv/apps/_logs`, Laravel en `storage/logs`.

## 5. Estructura del repositorio

| Ruta | Responsabilidad |
|---|---|
| `/srv/apps/josara-api` | API Laravel, dominio, migraciones, pruebas y configuración |
| `/srv/apps/josara-api/app/Http` | Controladores, middleware, requests y resources |
| `/srv/apps/josara-api/app/Services` | Reglas contables, Factus, reportes, planes y registro |
| `/srv/apps/josara-api/app/Models` | Modelos centrales y tenant |
| `/srv/apps/josara-api/database/migrations` | 17 migraciones centrales |
| `/srv/apps/josara-api/database/migrations/tenant` | 75 migraciones tenant |
| `/srv/apps/josara-api/tests` | 58 archivos y 301 métodos de prueba |
| `/srv/apps/josara-web` | SPA React/Vite |
| `/srv/apps/josara-web/src/pages` | Pantallas por módulo |
| `/srv/apps/josara-web/src/features` | Features nuevas de asientos, periodos y auditoría |
| `/srv/apps/josara-web/src/shared` | API/store/componentes compartidos nuevos |
| `/srv/apps/josara-db` | Compose y volumen persistente PostgreSQL |
| `/srv/apps/_templates/josara-nginx.conf` | Proxy y servidor estático |
| `/srv/apps/deploy-josara-api.sh` | Despliegue host del backend |
| `/srv/apps/josara-web/deploy-web.sh` | Despliegue local no versionado del frontend |

No existe monorepo Git padre. Backend y frontend son repositorios separados; `josara-db` no mostró metadatos Git.

## 6. Stack tecnológico

| Tecnología | Versión confirmada | Propósito | Evidencia |
|---|---:|---|---|
| PHP CLI | 8.3.6 | Runtime backend | `php -v` |
| Laravel | 12.58.0 | Framework API | `composer.lock` |
| Sanctum | 4.3.1 | Bearer tokens | `composer.lock`, `config/sanctum.php` |
| stancl/tenancy | 3.10.0 | Multi-tenancy database-per-tenant | `composer.lock`, `config/tenancy.php` |
| PostgreSQL | 15.18 | BD real | contenedor `josara-db` |
| React | 19.2.5 | SPA | `package-lock.json` |
| React Router | 7.14.2 | Rutas cliente | `package-lock.json`, `src/App.tsx` |
| TypeScript | 6.0.3 | Tipado/build | `package-lock.json` |
| Vite | 8.0.10 | Dev server/build | `package-lock.json`, `vite.config.ts` |
| Axios | 1.15.2 | Cliente HTTP | `package-lock.json` |
| React Query | 5.100.7 | Estado servidor | `package-lock.json` |
| Zustand | 5.0.13 | Estado global nuevo | `package-lock.json` |
| Tailwind Vite | 4.3.0 | Utilidades CSS/build | `package.json` |
| ESLint | 10.3.0 | Calidad frontend | `package-lock.json`, `eslint.config.js` |
| PHPUnit | 11.5.55 bloqueado en lock | Tests PHP | `composer.lock`; binario ausente |
| Larastan | 3.9.6 bloqueado en lock | Análisis estático nivel 8 | `composer.lock`, `phpstan.neon`; binario ausente |
| Nginx | activo | SPA/proxy | `_templates/josara-nginx.conf`, `systemctl` |
| PHP-FPM | 8.3 activo | Ejecución producción | Nginx y `systemctl` |
| Docker Compose | PostgreSQL 15 | BD persistente | `josara-db/docker-compose.yml` |

## 7. Configuración de entornos

### Local

`josara-api/compose.yaml` define Sail con PHP 8.3, PostgreSQL 18 y Redis; no coincide con producción real. `josara-web/vite.config.ts` sirve en `localhost:3000` y proxifica `/api` al `VITE_API_TARGET` (fallback `localhost:8000`).

### Pruebas

`phpunit.xml` usa `APP_ENV=testing`, cache/queue/session en memoria o sync, pero fija `DB_DATABASE=saas_contable` sin demostrar una instancia aislada. Antes de ejecutar tests se debe confirmar host, usuario y base exclusiva. No usar jamás la BD `josara` o una BD tenant real.

### Producción observada

- Dominio: `https://josara.colombiaapp.fun`.
- Nginx sirve `josara-web/dist` y proxifica API al vhost interno `127.0.0.1:18083`.
- PHP-FPM usa `/run/php/php8.3-fpm.sock`.
- PostgreSQL Docker escucha solo `127.0.0.1:5433`.
- Drivers efectivos: PostgreSQL, cache/database, queue/database, session/database, mail/log.
- CORS toma `CORS_ALLOWED_ORIGINS` o `APP_URL`, sin credentials.

Variables requeridas, sin valores: identidad/URL de aplicación, `APP_KEY`, DB, cache/queue/session, correo, CORS, branding, admins de plataforma, DANE, Factus y opcionalmente Redis/AWS. Consultar `.env.example`; no copiar `.env` real.

⚠️ La plantilla Nginx escucha HTTP 80 y establece HSTS. Se infiere terminación TLS aguas arriba (posiblemente Cloudflare), pero no se verificó el túnel/certificado ni `TrustProxies`. Confirmar que Laravel reciba correctamente `X-Forwarded-Proto=https`.

## 8. Multi-tenancy

### Resolución

`InitializeTenancyByTenantIdentifier` obtiene primero `{tenant}` de la ruta y puede resolver subdominio respecto a `central_domains`. `Tenant::resolveByPublicIdentifier()` acepta UUID interno, `tenant_slug` o `company_code`, normalizados. El frontend persiste y envía preferentemente `tenant_slug`.

### Aislamiento

- ✅ Una BD física por tenant; una única BD tenant fue detectada.
- ✅ Database, cache, filesystem y queue bootstrappers están habilitados.
- ✅ Sanctum usa `App\Models\PersonalAccessToken` después de inicializar tenancy.
- ✅ Existe `CrossTenantLeakTest` y tests de aislamiento de auditoría, aunque no se ejecutaron en este corte.
- ✅ Archivos `public/local` reciben sufijo tenant.
- ⚠️ Los endpoints centrales deben usar conexión explícita cuando se ejecutan dentro de contexto tenant; `AuditLogService` lo hace con `pgsql` hardcodeado.
- ⚠️ Aceptar UUID interno como identificador público mantiene compatibilidad, pero amplía la superficie de enumeración y perpetúa el camino legado.
- ⚠️ No existe un modelo de usuario multiempresa/pivot: cada usuario pertenece implícitamente a una sola BD tenant. Acceso a varias empresas requiere cuentas/tokens separados.

### Estado y planes

`Tenant` define activa, trial, suspendida, vencida, cancelada, bloqueada y pendiente de pago, pero el login solo comprueba `activo`; no se observó enforcement explícito de todos los estados/billing en el resolver tenant. Super Admin puede suspender/reactivar y cambiar plan.

`EnsureTenantPlanLimits` aplica solo a POST y solo a usuarios, terceros, productos, facturas, compras, bodegas y centros de costo. Sin suscripción/feature activa permite crear. No cubre almacenamiento, módulos, nómina, CRM ni otros documentos. Es enforcement backend real, pero parcial.

### Riesgos tenant

- **Alto:** autorización por recurso incompleta; el middleware de abilities evita mutación para auditor/readonly, pero roles con `create/update` pueden mutar prácticamente cualquier recurso protegido.
- **Medio:** jobs/comandos deben inicializar cada tenant; los jobs de saldos parecen diseñados para ello, pero se requiere test end-to-end con worker real.
- **Medio:** URLs de webhook por tenant no tienen allowlist/prohibición de redes privadas visible (SSRF).
- **Medio:** caché de municipios Factus usa `Cache::remember` bajo contexto tenant y puede reproducir el error de tags con cache database.
- **Bajo:** rutas aceptan UUID, slug y código; mantener una sola identidad pública reduciría complejidad.

## 9. Autenticación y autorización

### Tenant

1. Registro público `POST /api/v1/tenants`, limitado a 5/hora/IP.
2. Se crea tenant central, BD física, migraciones, configuración, sucursal, admin y catálogos.
3. Login central `POST /api/v1/auth/login`, limitado a 5/minuto/IP.
4. Resuelve tenant por `tenant_slug`, `company_code` o `tenant_id` legado; inicializa BD y valida usuario.
5. Emite token Sanctum Bearer en BD tenant y revoca tokens anteriores llamados `api-token`.
6. `me/logout` se llaman bajo `/api/v1/{tenant}/auth/...`.

La dificultad histórica de UUID está **mitigada pero no eliminada**: registro/login/frontend usan slug público, existen migraciones y tests específicos, pero backend aún acepta UUID y tests Factus todavía construyen URL con `$tenant->id`.

Roles tenant y abilities:

| Rol | Abilities emitidas | Efecto observado |
|---|---|---|
| admin | `*` | Acceso total tenant |
| contador | read/create/update/approve/void/close-period | Escritura global + Policies puntuales |
| auxiliar | read/create/update | Escritura global excepto restricciones puntuales |
| auditor | read/export | Lectura y POST de auditoría expresamente permitido |
| readonly | read | Mutaciones bloqueadas por middleware |

Solo Asiento, Periodo, AuditLog, Impuesto y operaciones de Reporte tienen Policies/FormRequests evidentes. El resto depende principalmente del middleware genérico.

### Super Admin

Login separado `POST /api/v1/admin/auth/login`, modelo `PlatformAdmin`, token central con ability `platform:admin`, middleware por clase/rol y auditoría central. Los roles no super pueden leer el panel; mutaciones sensibles exigen `super_admin` en rutas específicas.

⚠️ `AdminLayout` debe comprobarse: las rutas frontend se declaran bajo el layout, pero la seguridad real depende siempre del backend. Suscripciones, pagos, alertas y auditoría del panel son placeholders.

### Riesgos de sesión

- Tokens tenant y admin se guardan en `localStorage`; una XSS permitiría robo de sesión.
- `sanctum.expiration=null`: tokens sin expiración global; solo revocación/logout.
- No se encontraron endpoints/UI de recuperación de contraseña o verificación de correo.
- No se encontró MFA, bloqueo progresivo por cuenta ni cambio obligatorio de contraseña.
- Rate limit de login existe por IP; mensaje genérico reduce enumeración.
- Uso Bearer sin cookies implica que CSRF clásico no es el vector principal; XSS cobra mayor importancia.

## 10. Módulos del sistema

La madurez se basa en presencia conjunta de UI, rutas, backend y tests; “validado” significa que existen pruebas en código, no que se ejecutaron en este corte.

| Módulo | Estado | Frontend | Backend principal | Pruebas/observaciones |
|---|---|---|---|---|
| Registro de empresa | Implementado sin pruebas suficientes | `/register` | `TenantController` | Tests de catálogos/sucursal; provisionamiento síncrono costoso |
| Login/sesión tenant | Implementado y validado históricamente | `/login` | `AuthController` | `AuthTest`, slug tests; suite no ejecutada ahora |
| Recuperación/verificación/MFA | No implementado | — | solo comando CLI reset | Brecha de producto/seguridad |
| Perfil/configuración empresa | Implementado parcialmente | `/configuracion` | `ConfigController` | Logo y KV; revisar permisos por rol |
| Sucursales | Implementado y validado históricamente | configuración | `SucursalController` | Tests de sucursal principal/índice |
| Usuarios/roles | Implementado parcialmente | `/usuarios` | `UserController` | Roles fijos; autorización fina parcial |
| Planes SaaS | Implementado parcialmente | `/admin/planes` | `AdminPlanController`, `PlanLimitService` | Test de límites; cobertura de recursos incompleta |
| Super Admin | Implementado parcialmente | `/admin/*` | controladores `Api/Admin` | Tests acceso/operaciones; cuatro pantallas placeholder |
| Dashboard | Implementado sin pruebas suficientes | `/dashboard` | `DashboardController` | Test feature presente |
| Dashboard ejecutivo | Implementado sin pruebas suficientes | `/dashboard-ejecutivo` | `DashboardEjecutivoController` | Test feature presente |
| Terceros/clientes/proveedores | Implementado sin pruebas suficientes | `/terceros` | `TerceroController` | Modelo unificado; búsqueda DIAN |
| PUC/cuentas | Implementado sin pruebas suficientes | `/puc` | `CuentaContableController` | Catálogo sembrado; usado por todo el ciclo |
| Productos/inventario | Implementado y validado históricamente | `/inventario` | `ProductoController`, servicios inventario | Tests compra/venta/Kardex indirectos |
| Bodegas/Kardex | Implementado sin pruebas suficientes | `/inventario/bodegas`, `/kardex` | `BodegasController`, `KardexController` | Índices y stock por bodega |
| Compras/documentos ingreso | Implementado y validado históricamente | `/facturas-compra` | `DocumentoIngresoController` | `CompraInventarioTest` |
| Ventas/facturas | Implementado y validado históricamente | `/facturas` | `FacturaController` | Tests de asiento/Factus; integración real pendiente |
| Notas crédito/débito | Implementado sin pruebas suficientes | rutas propias | controladores homónimos | Sin suite específica visible |
| Remisiones/cotizaciones | Implementado sin pruebas suficientes | rutas propias | controladores homónimos | CRUD presente |
| Cartera/recibos/egresos | Implementado y validado históricamente | rutas propias | controladores homónimos | Tests de recibo/asiento; otros parciales |
| Ajustes de cartera | Implementado sin pruebas suficientes | `/ajuste-cartera` | `AjusteCarteraController` | Sin test dedicado visible |
| Centros de costo | Implementado sin pruebas suficientes | `/centros-costo` | `CentrosCostoController` | Jerarquía e índices |
| Asientos contables | Implementado y validado históricamente | `/asientos` | `AsientoController`, `AsientoService` | Suite extensa, Policies y saldos |
| Periodos/cierre anual | Implementado y validado históricamente | `/periodos`, cierre anual | servicios Periodo | Tests feature; aprobación/reapertura |
| Impuestos | Implementado y validado históricamente | configuración | `ImpuestoController` | Tests cálculo/integridad |
| Reportes financieros | Implementado y validado históricamente | `/reportes/*` | servicios Reportes | Múltiples suites feature/CSV |
| Reportes tributarios/exógena | Implementado y validado históricamente | `/reportes/tributarios`, exógena | `ReportController`, Exógena | Tests por reporte |
| Nómina | Implementado y validado históricamente | `/nomina` | `NominaController`, servicios | Tests nómina/aportes; migración futura ya aplicada |
| CRM | Implementado y validado históricamente | `/crm` | `CrmController` | `CrmTest` |
| Conciliación bancaria | Implementado y validado históricamente | `/conciliacion` | controlador/servicios Conciliación | Dos suites feature |
| Activos fijos | Implementado y validado históricamente | `/activos-fijos` | `ActivoFijoController`, Depreciación | Test presente; migración fechada 2026-07-20 ya aplicada |
| Auditoría tenant | Implementado y validado históricamente | `/auditoria` | `AuditLogController/Service` | Hash chain y tests; scheduler inactivo |
| Notificaciones/correo | Implementado parcialmente | Toaster; campana placeholder | Mail + n8n listener | Worker ausente; mail actual a log |
| Branding/tema | Implementado parcialmente | providers/componentes de marca | `config/platform.php`, `/api/platform` | Tema claro/oscuro/sistema; inconsistencias hardcoded |

## 11. Factus y facturación electrónica

### Arquitectura y configuración

`ConfigController` guarda configuración por tenant en `configs`. `factus_client_secret` y `factus_password` se cifran con `Crypt`; valores vacíos conservan secretos existentes. `FactusService` lee tenant primero y usa `config/services.php` como fallback.

Endpoints críticos:

| Endpoint | Middleware | Controlador/servicio | Resultado esperado |
|---|---|---|---|
| `POST /api/v1/{tenant}/configs/factus` | tenancy, Sanctum, ability, plan middleware | `ConfigController::updateFactus` | 200; secretos cifrados |
| `POST /api/v1/{tenant}/configs/factus/test` | igual | `ConfigController::testFactus`, `FactusService` | 200 o error seguro |
| `POST /api/v1/{tenant}/resoluciones/sync` | igual | `ResolucionController::syncFromFactus` | `updateOrCreate` de rangos |
| `POST /api/v1/{tenant}/facturas/{id}/enviar` | igual | `FacturaController::enviar`, `FactusService` | envío/validación Factus |

### Flujo

- OAuth password grant contra `/oauth/token`, timeout 15 s.
- No se cachea el access token: se solicita uno nuevo por operación.
- Rangos intentan v1 y luego v2; resolución normalizada y persistida por `factus_id`.
- Facturas intentan `/v1/bills/validate` y `/v2/bills/validate`.
- Errores auth 400/401/403/422 se traducen a 422; conectividad a 502; configuración incompleta a 404.

### Evidencia y estado

- `FactusIntegrationTest` cubre guardado cifrado, validación 422, readonly 403, auth 422, sync 200, error externo 502 y tenant desconocido 404.
- Reporte 2026-07-08: los antiguos 500 de configs/sync provenían de tags de cache con store database; el token dejó de cachearse.
- Reporte 2026-07-08: credenciales operativas de `colombiaapp` devolvían 401 externo.
- Logs Nginx agregados aún contienen 500 históricos en configs/sync/certificados y 502 de servicios externos; no se atribuyen al beacon Cloudflare.

### Riesgos específicos

1. **Alto:** `FactusService::createBill/createCreditNote` registra respuestas/bodies completos. Podrían contener PII, payload fiscal o URLs; contradice el logging seguro aplicado solo a auth/rangos.
2. **Alto:** no hay evidencia de idempotency key externa. La BD evita duplicar asientos, pero reintentos de emisión pueden duplicar efectos en Factus si el proveedor no deduplica.
3. **Medio:** `FactusMappingService::loadFactusMunicipiosCache()` conserva `Cache::remember`; con bootstrap de cache tenant y store database puede disparar nuevamente “store does not support tagging”.
4. **Medio:** `resoluciones.factus_id` no tiene índice único; `updateOrCreate` reduce duplicados secuenciales pero no evita carrera.
5. **Medio:** token nuevo por cada operación aumenta latencia y dependencia externa; no hay refresh/caché compatible.
6. **Medio:** algunos métodos HTTP Factus no muestran timeout/retry uniforme.
7. **Pendiente fiscal:** validar sandbox y producción con credenciales corregidas, sin emitir documentos reales durante QA.

## 12. Base de datos

### Estado confirmado

- PostgreSQL 15.18; contenedor `josara-db` saludable.
- Una BD central `josara`: 29 tablas y 1 tenant activo.
- Una BD tenant: 56 tablas y 75 migraciones registradas.
- `php artisan migrate:status`: 17/17 centrales ejecutadas.
- Fuentes: 17 migraciones centrales, 75 tenant, 10 seeders y 1 factory.

Tablas centrales principales: `tenants`, `domains`, `audit_logs`, `dual_approvals`, `municipios_dane`, `uvt_anual`, `tarifas_ica`, `plans`, `plan_features`, `subscriptions`, historial/uso, `platform_admins`, auditoría/operaciones/soporte/settings.

Tablas tenant principales: `users`, `personal_access_tokens`, `configs`, `terceros`, `cuentas_contables`, `facturas`, items/retenciones, documentos de ingreso, inventario/bodegas, asientos/saldos/periodos, impuestos, nómina, CRM, conciliación y activos fijos.

### Integridad

- UUID en usuarios y numerosos documentos.
- Únicos relevantes: slug/código/NIT tenant, emails, referencias de factura, PUC, documento de tercero, stock producto+bodega, periodos y sucursal principal parcial.
- Índice parcial evita asiento duplicado por origen.
- Índice compuesto evita documentos de compra duplicados por proveedor.
- `resoluciones.factus_id` carece de unique.
- Soft deletes existen en varios modelos/migraciones, pero no son uniformes.

### Observaciones

- ⚠️ Hay migraciones con fecha futura (2026-07-15 y 2026-07-20) ya aplicadas el 2026-07-10. Laravel ordena por nombre, pero esto confunde cronología y releases.
- ⚠️ Varias migraciones rastreadas tienen cambios locales no confirmados; no ejecutar migraciones hasta revisar el diff y reconciliarlo con el esquema real.
- ⚠️ `AuditLogService` fija conexión central como `pgsql`; renombrar la conexión rompería auditoría.
- ⏳ No se probaron backups, PITR ni restauración.

## 13. Frontend y UX

### Arquitectura

React Router centralizado, `ProtectedLayout`, QueryClient global y Sonner. La autenticación optimista conserva usuario/sucursales en localStorage y verifica `/me` en background. Dos clientes Axios y dos stores conviven; esto aumenta riesgo de divergencia en tenant/token/error handling.

### Configuración, Factus y resoluciones

`SettingsHub` organiza perfil, Factus, resoluciones, comprobantes, impuestos, parametrización, sucursales, tipos de ingreso, municipios y unidades DIAN. El informe UI confirma mejoras de padding, grid responsive, navegación, foco y modal de resolución; build/lint actuales sostienen que compila. No hubo validación visual real en navegador en este corte.

### Tema y branding

- Nombre oficial fallback: JOSARA CLOUD; tagline y metadata en `.env.example`/`platform.ts`.
- `PlatformProvider` consume `/api/platform`; `platform.ts` es fallback.
- `PlatformThemeProvider`/`theme.tsx` soportan light/dark/system.
- `designTokens.ts` y variables CSS definen base negro/dorado/azul.
- `PlatformLogo`/`PlatformBrand` centralizan marca; backend sirve logos claro/oscuro.
- Favicon existe en backend/frontend.
- ⚠️ Hay textos `JOSARA CLOUD` hardcodeados en Super Admin y clave Zustand heredada `saas-auth`; no es fallo funcional, pero impide branding totalmente dinámico.
- ⚠️ `src/config/platform.ts` tiene cambios locales no confirmados y un backup; tratarlo como trabajo en curso.

### UX pendiente

- Cuatro rutas Super Admin son placeholders.
- Campana de notificaciones es placeholder.
- No hay tests de componentes ni navegador real; el “visual smoke” solo inspecciona archivos/rutas/assets.
- CSS global supera 4.000 líneas y coexiste con estilos inline/utilidades; riesgo de inconsistencia.
- Bundle principal supera históricamente 500 kB; build actual generó `dist` de 1.8 MB total.
- Revisar contraste, dark mode, teclado, estados vacíos/loading/error y responsive en matriz real de pantallas.

## 14. API

- Prefijo central: `/api/v1`; salud/branding también tienen rutas públicas bajo `/api`.
- Tenant: `/api/v1/{tenant}`.
- 252 rutas registradas: 131 GET/HEAD, 68 POST, 27 PUT, 26 DELETE, 14 PATCH (métodos pueden compartir ruta).
- Middleware: tenancy → Sanctum → `token.can-mutate` → `tenant.plan-limits`, con throttles adicionales en operaciones críticas.
- Respuestas suelen usar `{success,data,message,errors}`, pero no se confirmó uniformidad completa.
- Validación Laravel produce 422; auth 401/403; conflicto 409 en algunos dominios; externos 502; inesperados 500.
- API es privada salvo health, branding, registro/login y catálogo DANE público.

⚠️ Las rutas tenant están todas dentro del middleware de límites incluso para GET; el servicio permite no-POST, con coste menor innecesario. La autorización de lectura es amplia para todo token autenticado salvo Policies puntuales.

## 15. Seguridad

Revisión estática, no pentest. Severidad combina impacto y evidencia.

| ID | Severidad | Hallazgo y evidencia | Recomendación | Estado |
|---|---|---|---|---|
| SEC-01 | Crítico | Archivos privados/copies `.env` no rastreados en los repos; backups con modo `664`. No se leyó contenido. | Retirar del web tree/repos, restringir `600`, rotar material si estuvo expuesto y ampliar `.gitignore`/secret scanning. | ❌ confirmado |
| SEC-02 | Alto | Sin worker/scheduler activo; auditoría nocturna, reconciliación y webhooks no operan. | Crear units supervisadas, alertas y prueba de ejecución/fallo. | ❌ confirmado |
| SEC-03 | Alto | Tokens tenant/admin en `localStorage`; `sanctum.expiration=null`. | Evaluar cookies HttpOnly/SameSite o hardening CSP + tokens cortos/rotación; configurar expiración. | ⚠️ abierto |
| SEC-04 | Alto | Solo pocos recursos tienen Policies; abilities create/update actúan globalmente. | Matriz RBAC por acción/recurso, Policies/FormRequests en todos los módulos y tests negativos. | ⚠️ mitigación parcial |
| SEC-05 | Alto | Factus registra response/body completo en emisión/notas. | Logging allowlist, hash/IDs técnicos, nunca payload fiscal/PII/token. | ❌ confirmado en código |
| SEC-06 | Alto | Webhook URL tenant configurable sin validación SSRF visible. | HTTPS/allowlist, bloquear IP privadas/metadata, resolver DNS de forma segura. | ⚠️ potencial fuerte |
| SEC-07 | Medio | Upload de logo permite SVG basado en extensión/MIME Laravel; SVG público puede contener contenido activo. | Sanitizar SVG o limitar a raster; validar MIME real y servir con headers restrictivos. | ⚠️ abierto |
| SEC-08 | Medio | No recuperación segura, MFA, verificación de correo ni bloqueo progresivo por cuenta. | Diseñar flujos con tokens cortos, rate limit y auditoría; MFA para admins. | ❌ no implementado |
| SEC-09 | Medio | Cache Factus municipios potencialmente incompatible con tags/database. | Store taggable o eliminar cache tenant de ese flujo, con test. | ⚠️ abierto |
| SEC-10 | Medio | Identificador tenant acepta UUID interno legado. | Migrar clientes a slug y deprecar UUID con telemetría. | ⚠️ compatibilidad |
| SEC-11 | Medio | No CSP visible; Nginx sí añade nosniff/frame/referrer/permissions/HSTS. | CSP con nonces/hashes, confirmar headers en HTTPS y trusted proxy. | ⚠️ parcial |
| SEC-12 | Medio | CORS deriva de `APP_URL`; configuración cacheada y override runtime pueden divergir. | Variable dedicada validada, pruebas preflight y eliminación de doble fuente. | ⚠️ parcial |
| SEC-13 | Medio | N8n loguea hasta 500 bytes de respuesta externa. | Redactar y limitar a status/correlation ID. | ⚠️ abierto |
| SEC-14 | Bajo | No `dangerouslySetInnerHTML` encontrado; React escapa texto por defecto. | Mantener regla y añadir tests/lint de sinks XSS. | ✅ mitigado |
| SEC-15 | Bajo | SQL raw existe, pero búsquedas revisadas usan bindings; no se confirmó inyección. | Mantener bindings y revisar consultas dinámicas en auditoría dedicada. | ℹ️ sin fallo confirmado |

Mass assignment está limitado por `$fillable` en modelos revisados. Passwords usan cast `hashed`/Hash. CORS no permite credenciales. Login devuelve mensajes genéricos. La auditoría aplica blacklist de secretos y hash chain, aunque existen logs fuera de ese servicio con menor sanitización.

## 16. Pruebas y validaciones

Ejecutadas el 2026-07-10 UTC en el servidor, sin instalar dependencias ni tocar datos:

| Comando | Resultado | Métricas | Observación |
|---|---|---|---|
| `composer validate --no-check-publish` | ✅ OK | composer.json válido | Backend |
| `php artisan route:list --json` | ✅ OK | 252 rutas | Solo lectura |
| `php artisan migrate:status` | ✅ OK | 17/17 centrales ran | No ejecutó migraciones |
| consulta PostgreSQL metadata | ✅ OK | 1 tenant; 75/75 migraciones tenant; 56 tablas tenant | Sin leer datos de negocio |
| `npm run lint` | ✅ OK | salida sin errores | A diferencia del informe antiguo, no imprimió 124 warnings |
| `npm run build` | ✅ OK | `dist/index.html` actualizado; `dist` 1.8 MB | Artefactos ignorados |
| `npm run test:e2e:visual` | ✅ OK | rutas/assets/branding estáticos | No abre navegador ni prueba API |
| `php artisan test` | ⏳ no ejecutado | 58 archivos, 301 tests inventariados | `vendor/bin/phpunit` ausente; BD de prueba no aislada confirmada |
| `vendor/bin/phpstan analyse` | ⏳ no ejecutado | config nivel 8 | binario ausente |
| `vendor/bin/pint` | ⏳ no ejecutado | — | binario ausente |

Cobertura por archivos: 6 unitarios y 52 feature. Incluye tenancy/seguridad, Factus, Super Admin, límites, asientos, saldos, periodos, inventario, nómina, CRM, conciliación y reportes. Frontend no tiene tests unitarios/componentes; solo smoke estático.

No declarar la suite “verde” hasta ejecutarla en CI o BD efímera.

## 17. Infraestructura y despliegue

### Servicios reales

- `josara-db` PostgreSQL 15, healthy, loopback 5433.
- Nginx y PHP 8.3-FPM activos en host.
- Frontend estático desde `josara-web/dist`.
- API desde `josara-api/public` vía vhost interno.
- 53 GB libres (44% del filesystem usado al corte).
- Logs Nginx separados para API/web; error logs vacíos al corte, access logs activos.

### Despliegue confirmado por scripts

Backend: pull fast-forward, `composer install --no-dev`, migración central, caches, permisos y reload FPM. Frontend: pull, `npm ci`, Vite build. El script backend **no ejecuta `tenants:migrate`**, no inicia worker/scheduler y no incluye healthcheck/rollback.

### Procedimiento seguro propuesto

Pasos confirmados por evidencia, pero deben ejecutarse solo en ventana aprobada:

```bash
git status --short
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate:status
php artisan route:list
npm ci
npm run lint
npm run build
```

Pasos de producción que requieren aprobación, backup y dry-run:

```bash
# confirmar backup/restauración y revisar diffs antes
php artisan migrate --force
php artisan tenants:migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.3-fpm
```

Pendiente diseñar: release directories/symlink atómico, backup previo, rollback de código compatible con schema, workers systemd/Supervisor, scheduler cron/systemd, métricas, alertas, healthcheck externo y prueba de restauración.

### Cloudflare/CORS

El beacon de `static.cloudflareinsights.com` bloqueado por CORS es externo y no explica 500 de Laravel. Separar siempre:

- warning beacon: no crítico para negocio;
- 401/422 Factus: credenciales/configuración;
- 502: proveedor/conectividad;
- 500: excepción interna, revisar Laravel/Nginx correlation;
- 4xx locales: validación/autorización/recurso.

## 18. Estado de Git

### Backend

- Rama `main`, commit `3713afd`, tracking `origin/main` sin ahead/behind reportado.
- Modificados previos: `config/database.php`, varias migraciones centrales/tenant y múltiples `.gitignore` de storage/cache.
- No rastreados: dos backups locales de `.env` (nombres omitidos deliberadamente).
- Remoto SSH GitHub configurado; no se registran credenciales en este documento.

### Frontend

- Rama `main`, commit `659f405`, tracking `origin/main` sin ahead/behind reportado.
- Modificado previo: `src/config/platform.ts`.
- No rastreados: script de despliegue, backup de platform y dos archivos de material criptográfico (nombres omitidos).

No se ejecutó `git add`, commit, push, checkout, reset ni clean.

## 19. Problemas conocidos

### Bloqueadores

1. Material sensible local y backups de entorno con permisos inadecuados.
2. Worker y scheduler ausentes en el despliegue real.
3. Suite PHP no ejecutable en el servidor y entorno de test no confirmado como aislado.
4. Factus operativo no validado con credenciales válidas; logging e idempotencia pendientes.
5. Autorización fina incompleta para numerosos módulos.

### Errores/riesgos funcionales

- Cache de municipios Factus puede fallar con el store actual.
- Resoluciones sin unique de `factus_id`.
- Estados tenant adicionales a `activo` no parecen bloquear login uniformemente.
- Correos configurados a log y colas sin worker: notificaciones reales no garantizadas.
- Super Admin tiene suscripciones, pagos, alertas y auditoría como placeholders.

### Deuda heredada/documental

- README backend/frontend son plantillas Laravel/Vite, no documentación del producto.
- Comentarios de código citan `HANDOFF.md`, `QA_TEST_REPORT.md`, `ACCEPTANCE_REPORT.md` y `FIX_REPORT.md`, pero esos documentos no existen en los repositorios actuales ni en su historial consultado.
- El reporte Factus es vigente para la causa del 500, pero incompleto frente al cache de municipios y logging de emisión.
- El reporte UI es vigente en estructura/CSS; su afirmación de 124 warnings quedó obsoleta frente al lint actual sin salida.
- Compose Sail usa PostgreSQL 18/Redis, mientras producción real usa PostgreSQL 15 y no muestra Redis.
- Fechas futuras en migraciones ya aplicadas dificultan trazabilidad.

## 20. Trabajo pendiente

### Crítico

- Aislar/eliminar material sensible local, rotar si procede y activar secret scanning.
- Levantar y monitorizar worker/scheduler.
- Preparar CI con PostgreSQL efímero y ejecutar 301 tests + PHPStan.
- Validar Factus sandbox con credenciales válidas y observabilidad redactada.
- Completar matriz RBAC/Policies.

### Alto

- Implementar expiración/rotación de tokens y MFA para plataforma.
- Corregir riesgo SSRF de webhook.
- Confirmar backup/restauración y rollback.
- Validar estados tenant/plan en login y cada request.
- Añadir idempotencia fiscal y unique de resoluciones mediante cambio controlado futuro.

### Medio

- Recuperación/verificación de cuenta.
- Tests frontend de componentes/E2E real.
- Consolidar clientes HTTP/stores.
- Completar placeholders Super Admin/notificaciones.
- Revisar SVG, CSP, trusted proxy, CORS y headers reales.

## 21. Roadmap

### Fase 0 — Bloqueadores críticos

| Tarea | Dependencia/riesgo | Resultado y criterio de aceptación |
|---|---|---|
| Custodia/rotación de secretos | Acceso DevOps; riesgo compromiso | Ningún secreto en worktree; permisos 600; escaneo limpio; rotación documentada |
| Worker y scheduler | systemd/Supervisor y observabilidad | Jobs procesados, `failed_jobs` monitorizado, tres schedules con evidencia y alertas |
| CI aislado completo | BD efímera y deps dev | 301 tests y PHPStan nivel 8 ejecutados; fallos clasificados |
| Gate Factus sandbox | Credenciales no productivas | Config/test/sync/envío simulado sin 500, sin PII en logs y con idempotencia demostrada |
| Auditoría RBAC | Matriz negocio aprobada | Tests negativos por rol/recurso; ningún acceso por ability genérica no deseada |

### Fase 1 — Estabilización

- **Objetivo:** operación recuperable. Dependencias: Fase 0. Criterios: backups restaurados en ensayo, despliegue atómico, healthchecks, logs correlacionados, migraciones central/tenant verificadas y runbook de incidentes.
- Uniformar timeouts/retries/error mapping Factus.
- Resolver cache taggable o eliminar usos incompatibles.
- Validar todos los estados tenant y límites backend.

### Fase 2 — Calidad funcional

- **Objetivo:** completar permisos, UX y módulos parciales. Criterios: placeholders clasificados o implementados, WCAG básica, matriz responsive/light/dark, recuperación de cuenta y tests frontend críticos.
- Consolidar doble cliente/store de autenticación.
- Unificar contratos API y validación FormRequest.

### Fase 3 — Escalabilidad

- **Objetivo:** operar múltiples tenants con carga. Criterios: pruebas de carga, índices medidos, Redis/cola dimensionados, storage externo evaluado, cuotas reales y observabilidad SLO.
- Provisionamiento tenant asíncrono e idempotente.
- Métricas de colas, Factus, DB por tenant y espacio.

### Fase 4 — Evolución

- **Objetivo:** producto completo. Criterios: roadmap comercial aprobado, suscripciones/pagos/alertas no placeholder, analítica y automatización auditables.

## 22. Procedimiento para continuar

1. Leer este archivo y luego `FACTUS_500_FIX_REPORT.md` y `UI_CONFIG_MODAL_MENU_FIX_REPORT.md` con las salvedades de la sección 19.
2. Ejecutar `git status --short` en ambos repos antes de tocar nada. Los cambios actuales pertenecen a trabajo previo.
3. No abrir, copiar ni committear backups `.env` o material criptográfico. Coordinar su custodia con DevOps.
4. Revisar primero el diff de migraciones/configuración local y compararlo con los 17/75 registros de producción.
5. Crear un entorno CI efímero; nunca apuntar PHPUnit a `josara` ni a la BD tenant existente.
6. Ejecutar composer validate, suite PHP, PHPStan, lint, build y E2E real.
7. Atacar Fase 0 en ramas separadas, una preocupación por PR, con test y rollback.
8. No realizar llamadas Factus de emisión en producción durante diagnóstico.
9. Para cambios tenant, probar tenant A/B y token cruzado; para admin, probar token tenant contra rutas admin.
10. Actualizar este handoff en cada release con commit, migraciones y evidencia real.

## 23. Comandos útiles

Diagnóstico seguro:

```bash
git -C /srv/apps/josara-api status --short
git -C /srv/apps/josara-web status --short
php artisan about
php artisan route:list
php artisan migrate:status
php artisan schedule:list
composer validate --no-check-publish
docker ps
```

Frontend:

```bash
cd /srv/apps/josara-web
npm run lint
npm run build
npm run test:e2e:visual
```

Tests backend, **solo en entorno aislado confirmado**:

```bash
composer install
php artisan test
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

Logs, evitando copiar payloads sensibles:

```bash
tail -n 200 /srv/apps/_logs/josara-api-error.log
tail -n 200 /srv/apps/josara-api/storage/logs/laravel.log
```

## 24. Archivos clave

| Archivo | Responsabilidad | Sensibilidad | Observación |
|---|---|---|---|
| `josara-api/routes/api.php` | API central/admin/login | Media | Super Admin y registro |
| `josara-api/routes/tenant.php` | API tenant | Alta | 200+ endpoints funcionales |
| `config/tenancy.php` | aislamiento | Alta | BD/cache/filesystem/queue bootstrappers |
| `InitializeTenancyByTenantIdentifier.php` | resolución tenant | Alta | acepta slug/código/UUID |
| `AuthController.php` | login/token/abilities | Alta | núcleo de acceso tenant |
| `PlatformAdminAuthController.php` | auth plataforma | Alta | separado de tenant |
| `EnsureTokenCanMutate.php` | bloqueo de mutaciones | Alta | mitigación RBAC global |
| `PlanLimitService.php` | límites SaaS | Alta | cobertura parcial |
| `FactusService.php` | OAuth/emisión/rangos | Crítica | secretos y datos fiscales |
| `ConfigController.php` | configuración/logo/Factus | Crítica | cifrado y upload SVG |
| `AuditLogService.php` | auditoría central hash-chain | Alta | conexión `pgsql` fija |
| `TenancyServiceProvider.php` | creación/eliminación BD | Crítica | síncrono |
| `database/migrations*` | esquema central/tenant | Crítica | cambios locales previos |
| `josara-web/src/App.tsx` | rutas SPA | Media | tenant y admin |
| `src/context/AuthContext.tsx` | sesión legacy | Alta | localStorage |
| `src/shared/api/client.ts` | cliente nuevo tenant | Alta | convive con legacy |
| `src/services/admin.service.ts` | token/admin API | Alta | localStorage separado |
| `src/config/platform.ts` | fallback branding | Media | modificado localmente |
| `_templates/josara-nginx.conf` | proxy/headers | Alta | HTTP local, TLS externo inferido |
| `deploy-josara-api.sh` | despliegue | Crítica | sin tenant migrate/worker/rollback |

## 25. Decisiones arquitectónicas

### Vigentes

- Base de datos independiente por tenant.
- Identidad pública por slug, con compatibilidad código/UUID.
- Tokens Sanctum en cada BD tenant; Super Admin central separado.
- Auditoría central con hash chain.
- Factus config por tenant y secretos cifrados.
- SPA y API bajo mismo dominio/proxy en producción.
- Catálogos DANE/UVT/ICA centrales; catálogos operativos tenant.

### Pendientes

- Store de cache compatible con tags (Redis) versus eliminación de caché tenant.
- Cookies HttpOnly versus Bearer localStorage.
- RBAC fino y sistema de permisos persistente versus roles fijos.
- Provisionamiento síncrono versus cola/orquestación.
- Estrategia de idempotencia Factus.
- Storage local tenant versus objeto externo.
- CI/CD, releases atómicos y rollback.

### Restricciones

- Cumplimiento fiscal colombiano exige trazabilidad e integridad.
- Cambios de migración deben aplicarse central y tenant de forma coordinada.
- No reutilizar tokens ni cache entre tenants.
- No exponer payloads Factus, credenciales ni PII en logs/documentación.

## 26. Criterios de aceptación pendientes

- [ ] Ningún secreto/back-up sensible en los worktrees o rutas servidas.
- [ ] Rotación/custodia y secret scan completados.
- [ ] 301 tests PHP ejecutados en CI aislado y resultados documentados.
- [ ] PHPStan nivel 8 ejecutado.
- [ ] Pruebas cross-tenant e IDOR verdes con dos tenants reales de QA.
- [ ] Matriz RBAC completa y pruebas negativas por rol.
- [ ] Worker y scheduler activos, supervisados y alertados.
- [ ] Backup restaurado exitosamente y rollback ensayado.
- [ ] Factus sandbox validado con credenciales válidas, idempotencia y logs redactados.
- [ ] Sin 500 en config/sync/envío/certificados bajo escenarios controlados.
- [ ] Estados suspendido/cancelado/vencido/bloqueado aplicados consistentemente.
- [ ] Recuperación de cuenta/MFA de admin definidos.
- [ ] CSP, CORS, proxy/HTTPS y headers verificados desde Internet.
- [ ] E2E real en Chrome/Firefox y mobile, light/dark/system.
- [ ] Placeholders Super Admin declarados fuera de alcance o completados.
- [ ] Monitoreo de DB, colas, Factus, disco y errores con SLO.

## 27. Conclusión

JOSARA CLOUD tiene una base arquitectónica seria y una implementación funcional considerable. El aislamiento por base de datos, el uso de Sanctum tenant-aware, la auditoría con hash chain, las migraciones y la amplitud de tests diseñados son avances relevantes. Sin embargo, el estado real no permite afirmar preparación productiva: faltan ejecución reproducible de pruebas, operación de workers/scheduler, saneamiento de secretos, autorización granular, validación Factus end-to-end y recuperación probada.

La prioridad inmediata es Fase 0: secretos, procesos de background, CI aislado, Factus seguro y RBAC. Hasta cerrar esos criterios, el veredicto se mantiene en **NO APTO PARA PRODUCCIÓN**.

## 28. Actualización operativa — 2026-07-14

### Gestión de estado de usuarios

- ✅ Backend commit `161fc9e`: endpoint explícito e idempotente `PATCH /api/v1/{tenant}/users/{id}/status`.
- ✅ Frontend commit `c93b3d1`: controles y confirmación para activar/inactivar usuarios.
- ✅ Solo administradores pueden cambiar el estado; se impide la auto-inactivación y la inactivación del último administrador activo.
- ✅ Al inactivar se revocan todos los tokens del usuario y se registra auditoría central.
- ✅ Payload `activo` validado como booleano; endpoint limitado a 10 solicitudes por minuto.
- ✅ Frontend compilado y desplegado; HTTP local 200.
- ✅ API desplegada: Composer optimizado, migraciones al día, cachés regeneradas, PHP-FPM activo y ruta presente en producción.
- ✅ Build TypeScript/Vite y ESLint ejecutados: 0 errores; permanecen 123 advertencias preexistentes.
- ⚠️ Se añadió `tests/Feature/Users/UserStatusTest.php`, pero no pudo ejecutarse porque el despliegue `--no-dev` no contiene PHPUnit.
- ⚠️ Los commits son locales; no se realizó `git push`.
- ⚠️ Persisten cambios locales anteriores y archivos sensibles no rastreados; no fueron incluidos en estos commits.

### Auditoría de dependencias frontend

`npm audit --json` reportó, antes de la remediación del 2026-07-14, **7 paquetes afectados**: 5 altos, 1 moderado, 1 bajo y 0 críticos. El total no equivale a siete CVE: algunos paquetes agrupan varios avisos. Véase la sección 29 para el resultado posterior.

| Paquete instalado | Tipo | Severidad npm | Riesgo resumido | Versión propuesta por `npm audit fix --dry-run` |
|---|---|---:|---|---:|
| `axios@1.15.2` | Directa, producción | Alta | ReDoS/agotamiento de recursos, fugas de `Proxy-Authorization`, gadgets de prototype pollution, MITM y bypass de `NO_PROXY` | `1.18.1` |
| `form-data@4.0.5` | Transitiva de Axios | Alta | Inyección CRLF mediante nombres de campos/archivos multipart | `4.0.6` |
| `react-router-dom@7.14.2` | Directa, producción | Alta | Hereda los avisos de `react-router` | `7.18.1` |
| `react-router@7.14.2` | Transitiva | Alta | DoS por expansión no acotada y posible CSRF en solicitudes document PUT/PATCH/DELETE | `7.18.1` |
| `vite@8.0.10` | Directa, desarrollo/build | Alta | Bypass de `server.fs.deny` y exposición NTLMv2 en Windows; menor exposición en este host Linux y no forma parte del runtime estático | `8.1.4` |
| `brace-expansion@5.0.5` | Transitiva de ESLint | Moderada | DoS mediante rangos numéricos grandes | `5.0.7` |
| `@babel/core@7.29.0` | Transitiva de ESLint hooks | Baja | Lectura arbitraria local mediante comentarios `sourceMappingURL` bajo condiciones específicas | `7.29.7` |

No se ejecutó `npm audit fix`: la simulación modificaría 42 paquetes y añadiría 35 bindings opcionales. Debe aplicarse en un cambio separado, regenerar `package-lock.json` y repetir build, lint, smoke/E2E y despliegue.

## 29. Remediación profesional NPM — 2026-07-14

### Estrategia y alcance

No se usaron `npm audit fix`, `--force`, overrides ni upgrades mayores. Se aplicaron versiones mínimas corregidas en cuatro grupos independientes y se ejecutaron audit, lint, build y smoke después de cada grupo. Solo cambiaron `josara-web/package.json` y `josara-web/package-lock.json`; no se modificó lógica de negocio, Laravel, TypeScript fuente ni configuración funcional.

### Árbol real de dependencias anterior

```text
josara-web
├── axios@1.15.2
│   └── form-data@4.0.5
├── react-router-dom@7.14.2
│   └── react-router@7.14.2
├── vite@8.0.10 (dev)
├── eslint@10.3.0 (dev)
│   └── minimatch@10.2.5
│       └── brace-expansion@5.0.5
└── eslint-plugin-react-hooks@7.1.1 (dev)
    └── @babel/core@7.29.0
```

### Matriz de advisories y explotabilidad

| Paquete | CVE / GHSA | Severidad | Ruta/tipo | Riesgo y explotabilidad real en JOSARA |
|---|---|---:|---|---|
| Axios | CVE-2026-44496 / GHSA-hfxv-24rg-xrqf | Alta | Directa, runtime browser | ReDoS exige `xsrfCookieName` controlado por atacante; JOSARA no lo configura dinámicamente. No explotable con el uso observado, pero corregido. |
| Axios | CVE-2026-44488 / GHSA-777c-7fjr-54vf | Alta | Directa | Agotamiento de recursos. JOSARA llama API same-origin y no pasa configuración Axios controlada por usuario; exposición baja, corregida. |
| Axios | CVE-2026-44487 / GHSA-p92q-9vqr-4j8v | Alta | Directa; adaptador Node | Fuga de `Proxy-Authorization` en redirect HTTP→HTTPS. El bundle usa adaptador browser, no proxy Node; no explotable en producción SPA. |
| Axios | CVE-2026-44486 / GHSA-j5f8-grm9-p9fc | Alta | Directa; adaptador Node | Fuga de credenciales al reevaluar proxy. No aplica al runtime browser/Nginx estático. |
| Axios | CVE-2026-44494 / GHSA-35jp-ww65-95wh | Alta | Directa; adaptador Node | MITM mediante gadget de prototype pollution en `config.proxy`. JOSARA no usa `config.proxy` ni configuración no confiable; no explotable en el uso observado. |
| Axios | CVE-2026-44490 / GHSA-898c-q2cr-xwhg | Moderada | Directa | DoS/inyección de headers mediante gadgets de merge. Requiere pollution/configuración no confiable; no se observó ese flujo. |
| Axios | CVE-2026-44489 / GHSA-654m-c8p4-x5fp | Baja | Directa; proxy Node | Bypass parcial de parche e inyección `Proxy-Authorization`; no aplica al adaptador browser. |
| Axios | CVE-2026-44492 / GHSA-pjwm-pj3p-43mv | Alta | Directa; proxy Node | Bypass `NO_PROXY` con IPv4-mapped IPv6. No hay Axios server-side en este frontend. |
| form-data | CVE-2026-12143 / GHSA-hmw2-7cc7-3qxx | Alta | `axios → form-data` | CRLF multipart en Node. No se incorpora al camino browser efectivo; corregido transitivamente por higiene de supply chain. |
| React Router | CVE-2026-42342 / GHSA-8x6r-g9mw-2r78 | Alta | `react-router-dom → react-router` | DoS en endpoint `__manifest` de Framework Mode. JOSARA usa BrowserRouter declarativo y no expone ese endpoint; no explotable. |
| React Router | CVE-2026-53663 / GHSA-84g9-w2xq-vcv6 | Baja | `react-router-dom → react-router` | CSRF en document requests PUT/PATCH/DELETE. JOSARA muta con Axios/Bearer, no document requests del Router; no explotable en el flujo observado. |
| Vite | CVE-2026-53632 / GHSA-v6wh-96g9-6wx3 | Moderada | Directa dev/build | Fuga NTLMv2 vía rutas UNC en Windows. Host Linux y producción sirve `dist` con Nginx; no explotable en producción. |
| Vite | CVE-2026-53571 / GHSA-fx2h-pf6j-xcff | Alta | Directa dev/build | Bypass de `server.fs.deny` con rutas alternas Windows. No aplica a Linux ni al artefacto estático. |
| brace-expansion | CVE-2026-45149 / GHSA-jxxr-4gwj-5jf2 | Moderada | `eslint → minimatch → brace-expansion` | DoS por rangos grandes durante tooling. No procesa entrada de clientes ni llega al bundle; riesgo de CI local bajo. |
| @babel/core | CVE-2026-49356 / GHSA-4x5r-pxfx-6jf8 | Baja | `eslint-plugin-react-hooks → @babel/core` | Lectura de source maps exige compilar código malicioso y exponer salida. Solo compila fuentes confiables del repo; no explotable en producción. |

### Compatibilidad

- Node: host `22.23.0`; React Router 7.15.1 requiere Node ≥20 y Vite 8.0.16 requiere `^20.19 || >=22.12`.
- React: `19.2.5`; React Router declara peer React/React DOM ≥18.
- TypeScript: `~6.0.2`; `tsc -b` completó sin errores.
- Vite: se conservó major/minor 8.0 y plugins existentes (`@vitejs/plugin-react@6.0.1`, `@tailwindcss/vite@4.3.0`) resolvieron Vite 8.0.16 sin conflictos.
- Laravel API/multi-tenant: sin cambios; Axios conserva clientes, interceptors, Bearer token y base URLs existentes.

### Versiones y resultados

| Dependencia | Antes | Después | Tipo de cambio |
|---|---:|---:|---|
| axios | 1.15.2 | 1.16.0 | Minor mínimo corregido |
| form-data | 4.0.5 | 4.0.6 | Patch transitivo |
| react-router-dom | 7.14.2 | 7.15.1 | Minor mínimo corregido |
| react-router | 7.14.2 | 7.15.1 | Minor transitivo |
| vite | 8.0.10 | 8.0.16 | Patch mínimo corregido |
| brace-expansion | 5.0.5 | 5.0.7 | Patch transitivo |
| @babel/core | 7.29.0 | 7.29.7 | Patch transitivo |

| Métrica | Antes | Después |
|---|---:|---:|
| Vulnerabilidades npm | 7 (5 altas, 1 moderada, 1 baja) | 0 |
| Entradas `packages` del lock | 284 | 284 |
| `node_modules` | 309 MB | 308 MB |
| Bundle JS | 1,564,956 B | 1,568,314 B (+3,358 B) |
| Bundle JS gzip | 403.35 kB | 404.62 kB (+1.27 kB) |
| CSS | 171,718 B | 171,718 B |
| Build Vite interno | 1.96 s | 2.05 s |

### Gates ejecutados

- ✅ `npm ci`: 250 paquetes instalados reproduciblemente.
- ✅ `npm audit`: 0 vulnerabilidades.
- ✅ `npm run lint`: 0 errores, 123 advertencias preexistentes (no se ocultaron).
- ✅ `npm run build`: TypeScript y Vite correctos.
- ✅ Smoke estático existente: rutas críticas, assets y branding correctos.
- ✅ Vite Preview HTTP: 200 en raíz, login, dashboard, usuarios, terceros, facturas, configuración y admin login; bundle 200.
- ⚠️ No existen tests unitarios ni de integración frontend configurados.
- ⚠️ El script denominado `test:e2e:visual` no controla un navegador: es un smoke estático.
- ⚠️ No hay Chrome/Chromium, Playwright/Cypress ni credenciales QA. No se validaron interacciones reales de login, CRUD, Factus, multi-tenant ni comparación visual.

### Rollback

1. Revertir el commit de remediación de `package.json` y `package-lock.json`.
2. Ejecutar `npm ci`.
3. Ejecutar `npm run build` y el smoke.
4. Restaurar/desplegar el artefacto anterior `index-B4g5axWZ.js` mediante el procedimiento normal.
5. Verificar HTTP 200 y login antes de cerrar el rollback.

### Gate de producción

La remediación elimina todos los advisories conocidos por npm y los gates estáticos son verdes. Sin embargo, **no debe declararse E2E exitoso ni “sin regresiones”** hasta disponer de navegador automatizado, credenciales/tenant QA y casos no destructivos. El despliegue queda condicionado a aceptar explícitamente esta brecha o ejecutar esa suite en CI/staging.

## 30. Continuidad en entorno QA local — 2026-07-14

Se confirmó que existe un equipo local independiente donde se continuará el gate funcional. No es necesario crear otro servidor QA si ese equipo permanece aislado de producción y reproduce versiones/configuración relevantes.

### Preparación mínima

- Clonar `josarabackend` y `josarafrontend` desde GitHub.
- Backend esperado: commit `161fc9e` o posterior.
- Frontend esperado: commits `c93b3d1` y `b497ad9` o posteriores.
- Crear una base central QA nueva y dos tenants descartables: `qa-empresa-a` y `qa-empresa-b`.
- Crear Super Admin central y, por tenant, admin, auxiliar/operador, contador, auditor y readonly.
- Usar claves, tokens, storage, cache, correo y webhooks separados de producción.
- Factus únicamente en sandbox o deshabilitado.
- No copiar datos productivos sin anonimización completa.

### Gate obligatorio

Ejecutar `RELEASE_VALIDATION.md` desde el repositorio backend y registrar evidencia real de navegador para autenticación, navegación, multi-tenant, roles, CRUD, API, multipart, Factus sandbox y visual/responsive.

El dictamen vigente se mantiene:

**NO APTO PARA PRODUCCIÓN POR FALTA DE EVIDENCIA FUNCIONAL.**

Solo debe cambiar después de completar y firmar la matriz QA sin fallos P0/P1.

## 31. Modelo operativo durante la implementación con empresas — 2026-07-14

JOSARA ya está siendo utilizado por varias empresas durante su implementación. El dictamen de release “NO APTO PARA PRODUCCIÓN POR FALTA DE EVIDENCIA FUNCIONAL” no significa que la plataforma sea inutilizable ni que deba detenerse automáticamente. Significa que todavía no existe evidencia suficiente para certificar formalmente todos los flujos, aislamiento y condiciones operativas.

Mientras existan usuarios reales, se trabajará en dos carriles:

### Carril 1 — Producción: estabilización e incidentes

Producción se utilizará únicamente para diagnosticar y corregir problemas que afecten a las empresas activas.

- Admitido: hotfixes pequeños, focalizados, reversibles y respaldados por evidencia.
- Admitido: correcciones P0/P1, errores 500, autenticación, aislamiento, facturación, bloqueos de flujo y defectos que impidan implementación.
- No admitido: refactorizaciones amplias, upgrades masivos, experimentos, cambios cosméticos extensos o nuevas funcionalidades sin pasar por QA.
- Cada incidente debe tener un commit independiente, rollback explícito y verificación posterior.
- Nunca ejecutar `migrate:fresh`, seeders generales o suites de prueba contra bases productivas.
- No modificar/eliminar datos productivos directamente sin backup, diagnóstico, autorización específica y trazabilidad.
- No emitir documentos Factus reales durante diagnóstico. Usar sandbox cuando la prueba implique emisión.
- No copiar en chats o documentos tokens, contraseñas, NIT completos, PII, payloads fiscales ni secretos.

Flujo obligatorio:

```text
Reporte del incidente
→ clasificación P0/P1/P2/P3
→ respaldo y evidencia inicial
→ revisión de logs sanitizados
→ reproducción no destructiva
→ causa raíz
→ parche mínimo
→ validaciones proporcionales al riesgo
→ commit y push
→ despliegue controlado
→ healthcheck y prueba con empresa afectada
→ monitoreo
→ actualización de HANDOFF
```

### Carril 2 — Local/QA: desarrollo y certificación

El equipo local independiente será el entorno preferido para:

- Nuevas funcionalidades.
- Refactorizaciones.
- Cambios de arquitectura.
- Actualizaciones de dependencias.
- Migraciones complejas.
- Automatización de pruebas.
- Pruebas multi-tenant con `qa-empresa-a` y `qa-empresa-b`.
- Validación Factus sandbox.
- Ejecución completa de `RELEASE_VALIDATION.md`.

Los cambios normales deben seguir:

```text
Rama/commit local
→ pruebas unitarias/integración
→ build y lint
→ QA manual/E2E
→ revisión de aislamiento y rollback
→ push
→ despliegue controlado
```

### Clasificación y tiempos de respuesta

| Prioridad | Definición | Ejemplos | Acción |
|---|---|---|---|
| P0 crítica | Riesgo de datos, seguridad, fiscal o caída general | Fuga cross-tenant, pérdida/corrupción, facturación errónea, indisponibilidad total | Contención inmediata; congelar cambios no relacionados |
| P1 alta | Empresa o función principal bloqueada | Login roto, 500 repetido, CRUD crítico inutilizable, emisión bloqueada | Hotfix prioritario con rollback |
| P2 media | Flujo parcial con alternativa segura | Filtro roto, acción secundaria, error con workaround | Corregir primero en local/QA |
| P3 baja | No bloqueante | Visual, texto, ergonomía, mejora | Backlog local/QA |

### Información mínima por incidente

- Tenant/empresa afectada usando identificador no sensible.
- Módulo, pantalla y URL relativa.
- Acción exacta realizada.
- Mensaje visible y status HTTP si está disponible.
- Fecha/hora con zona horaria.
- Frecuencia y cantidad de usuarios afectados.
- Resultado esperado y resultado obtenido.
- Captura sanitizada.
- Request ID o fragmento de log sin secretos/PII.
- Confirmación de si existe workaround.

### Criterios para cerrar un incidente

- Causa raíz identificada o riesgo residual documentado.
- Parche mínimo revisado.
- Sin cambios ajenos mezclados.
- Validaciones relevantes verdes.
- Aislamiento tenant comprobado cuando aplique.
- Backup/rollback disponible.
- Commit y despliegue identificables.
- Healthcheck correcto.
- Empresa afectada confirma el flujo.
- HANDOFF registra resultado y pendientes.

### Estado operativo

- Se permite continuar la estabilización de empresas activas en este servidor bajo el carril de producción.
- El desarrollo evolutivo debe trasladarse al equipo local/QA.
- El dictamen formal de producción no cambia hasta completar la evidencia funcional, pero no impide atender incidentes reales con hotfixes controlados.

## 32. Hotfix parametrización contable al abrir compra — 2026-07-14

### Incidente y causa raíz

- Prioridad: **P1**, porque afectaba la apertura del flujo de creación de factura/compra para un tenant activo.
- Síntoma funcional: `GET /api/v1/{tenant}/parametrizacion-contable/validar/compra` respondía HTTP 422.
- Causa raíz: las firmas de `ParametrizacionContableController` no incluían el parámetro de ruta `{tenant}`. Laravel entregaba el slug del tenant en la variable `$modulo`, por lo que se intentaba validar un módulo inexistente.
- Riesgo adicional corregido: `ParametrizacionGuard` abría el formulario ante cualquier error HTTP (fail-open). Ahora bloquea la apertura, presenta un error controlado y permite cancelar, reintentar o ir a configuración.
- El error CORS de `static.cloudflareinsights.com/beacon.min.js` corresponde a telemetría de Cloudflare y no causa el HTTP 422 ni bloquea el flujo contable. Se mantiene como asunto P3 independiente.

### Cambios aplicados

| Repositorio | Commit | Cambio |
|---|---|---|
| API | `f946450` | Alinea las firmas `index`, `validar`, `update` y `bulk` con `{tenant}` y agrega pruebas de regresión de parámetros de ruta. |
| Web | `2608b31` | Cambia el guard de parametrización a fail-closed y añade recuperación controlada. |

No se modificó lógica de negocio contable, información de tenants, migraciones ni versiones de dependencias.

### Validaciones y despliegue

- Sintaxis PHP del controlador y de la prueba: correcta.
- `route:list`: 4 rutas de parametrización contable registradas después de regenerar la caché.
- Validación directa inicializando el tenant afectado: HTTP 200, módulo `compra`, `valido=true`, 4 cuentas requeridas/configuradas y 0 faltantes.
- PHPUnit no está instalado en producción porque Composer se despliega con `--no-dev`; la prueba quedó versionada para ejecutarse en local/QA.
- ESLint focalizado: 0 errores; permanece 1 advertencia preexistente del efecto React.
- Frontend: `npm ci`, auditoría con 0 vulnerabilidades y Vite 8.0.16 compilado correctamente en 1.92 s.
- Artefactos publicados: `index-BUp4xeWg.js` y `index-CZ31jz7z.css`; raíz pública responde HTTP 200.
- PHP-FPM está activo y fue recargado correctamente a las 22:54:05 UTC. `storage` y `bootstrap/cache` quedaron con propietario `www-data:www-data` y permisos 775.
- Validación posterior al despliegue, inicializando exactamente `comercializadoraaaa`: HTTP 200, `success=true`, módulo `compra`, `valido=true`, 4 de 4 cuentas configuradas y 0 faltantes.
- El usuario informó “listo” después de ejecutar el cierre operativo. Los logs de acceso disponibles no registraron la petición del navegador, por lo que la evidencia objetiva de backend proviene de la validación tenant de solo lectura indicada arriba.

### Rollback

1. Revertir en web el commit `2608b31`, ejecutar `npm ci` y reconstruir con Vite.
2. Revertir en API el commit `f946450`, regenerar las cachés Laravel y recargar PHP-FPM.
3. Verificar HTTP 200 del sitio y registrar el resultado. El rollback reintroduciría el 422 conocido, por lo que solo debe usarse si aparece una regresión más grave.

### Pendientes inmediatos

- Confirmar visualmente con la empresa afectada que la modal abre y permite continuar; si reaparece el problema, capturar hora exacta, pestaña Network y payload sanitizado.
- Monitorear nuevos HTTP 422/500 relacionados durante el uso normal.
- Ejecutar la prueba PHPUnit agregada en el equipo local/QA con dependencias de desarrollo.

## 33. Hotfix persistencia PUC en productos — 2026-07-15

### Diagnóstico

- Síntoma: al abrir un producto existente para editar, los tres selectores de Configuración Contable (PUC) aparecían vacíos.
- Causa raíz: `ProductoController` validaba y enviaba `inventario_cuenta_id`, `ventas_cuenta_id` y `costos_cuenta_id` a Eloquent, pero esos atributos no estaban en `$fillable` de `App\Models\Tenant\Producto`. Laravel los descartaba silenciosamente en creación y actualización.
- Evidencia del producto afectado `019f62de-e14a-72ed-8217-a4e28414ff82`: las tres columnas existen en la base, pero estaban en `null`; el producto tampoco tiene categoría de respaldo.
- Disponibilidad PUC del tenant afectado: 13 cuentas con prefijo 14, 12 con prefijo 41 y 8 con prefijo 61. No era una ausencia del catálogo contable.
- Las selecciones históricas descartadas no pueden recuperarse de forma confiable. Deben seleccionarse nuevamente; no se asignaron cuentas automáticamente para evitar asientos contables incorrectos.

### Solución y validación

- Se añadieron los tres IDs contables al `$fillable` del modelo y una regresión al test de rutas/productos.
- Prueba transaccional con cuentas hoja reales: `update()` respondió HTTP 200 y, al recargar el producto, conservó exactamente los tres UUID.
- La prueba se ejecutó dentro de una transacción con rollback garantizado; no alteró el producto de producción.
- Sintaxis PHP y `git diff --check`: correctos.
- No requiere migración, cambios de datos, cambios frontend ni actualización de dependencias.

### Escalabilidad y riesgo

- El almacenamiento mediante llaves foráneas UUID por tenant es adecuado y el resolver contable ya soporta prioridad bodega → producto → categoría → parametrización.
- El frontend aplana y filtra el árbol PUC en cliente. Es suficiente para el volumen actual, pero como mejora de QA se recomienda migrar la modal al `CuentaAutocomplete` compartido, que cachea el catálogo, limita resultados y permite mostrar errores de carga.
- Riesgo del hotfix: bajo y localizado a la persistencia de tres atributos previamente ignorados.

### Rollback

Revertir el commit del hotfix y recargar PHP-FPM. El rollback vuelve a descartar las cuentas PUC al guardar productos, por lo que solo se justifica ante una regresión más grave.

## 34. Ajuste visual modal de factura de compra — 2026-07-15

- Repositorio web, commit `5211e2e` (`fix(purchases): improve purchase invoice modal layout`).
- Alcance exclusivamente visual: encabezado y descripción claros, cuerpo con scroll interno, jerarquía de secciones, filas de productos, retenciones, resumen y acciones responsive.
- Breakpoints específicos para escritorio, tableta y móvil; en pantallas pequeñas los campos, retenciones y botones se apilan sin conservar anchos fijos.
- Se añadió `type="button"` y etiqueta accesible al cierre de la modal.
- No se modificaron cálculos, retenciones, inventario, validaciones ni payload de creación.
- ESLint focalizado: 0 errores y 2 advertencias React preexistentes.
- Build TypeScript/Vite exitoso; `npm audit` reportó 0 vulnerabilidades.
- Artefactos desplegados: `index-DZIZWUUI.js` y `index-BE7_dUnF.css`; `/documentos-ingreso` responde HTTP 200.
- Rollback: revertir `5211e2e`, ejecutar `npm ci` y reconstruir/desplegar el frontend.
- Pendiente: comprobación visual del usuario en su resolución real antes de registrar la compra.

## 35. Hotfix HTTP 500 al registrar factura de compra — 2026-07-15

- Endpoint afectado: `POST /api/v1/{tenant}/facturas-compra`.
- Cloudflare Insights fue descartado por no guardar relación con el fallo funcional.
- Causa raíz: frontend y controlador admitían `contado_efectivo`/`contado_banco`, mientras PostgreSQL mantenía el CHECK legado de `documentos_ingreso.forma_pago` limitado a `contado`/`credito`.
- Excepción comprobada: SQLSTATE `23514`, constraint `documentos_ingreso_forma_pago_check`.
- Commit API: `085830b` (`fix(purchases): support explicit cash and bank payments`).
- Migración tenant: amplía el CHECK a `contado`, `contado_efectivo`, `contado_banco` y `credito`; no reescribe datos en `up()`.
- Se aplicó correctamente a los 9 tenants; el tenant afectado `19c9113e-371f-4fba-9ec7-012d7aa6593e` fue validado primero.
- Prueba transaccional con rollback: efectivo y banco fueron aceptados; no quedaron registros de prueba.
- El intento fallido con `FACT-001` fue atómico: 0 documentos `FACT-001`, 0 documentos `ING-000001` y 0 movimientos de inventario asociados.
- Se agregaron regresiones de compra de contado en efectivo y por banco. PHPUnit queda pendiente de ejecución local porque producción usa dependencias `--no-dev`.
- Rollback: ejecutar `tenants:rollback` para esta migración. El `down()` normaliza los dos valores nuevos a `contado` antes de restaurar el CHECK legado, perdiendo intencionalmente la distinción caja/banco; solo usar ante una regresión grave.
- Pendiente: reintento funcional por el usuario y verificación posterior de documento, Kardex, costo promedio y asiento.

## 36. Módulo configurable de formas de pago — desplegado — 2026-07-15
- Estado: backend, frontend y migraciones desplegados en producción. Commits API `019d0ee` y web `4787379`.
- Tablas nuevas: `payment_terms`, `payment_methods`, `payment_term_methods` y `payment_accounting_rules`.
- Compras y ventas guardan referencias opcionales `payment_term_id`/`payment_method_id` y conservan los campos heredados.
- El backend deriva contado/crédito, caja/banco, cuenta puente y código DIAN; rechaza condiciones/medios inactivos o incompatibles.
- UI: `Configuración → Formas de Pago`, con condiciones, medios y reglas contables sobre cuentas PUC activas de movimiento.
- Migraciones `2026_07_15_000002_create_payment_configuration_tables.php` y `2026_07_15_000003_link_payment_configuration_to_invoices.php` aplicadas correctamente a los 9 tenants, iniciando con `comercializadoraaaa` como piloto.
- Respaldo previo de los 9 tenants en `/srv/apps/_logs/payment-backups-20260715/`; respaldo adicional del piloto y la base central.
- Validaciones: sintaxis PHP correcta, TypeScript correcto, ESLint focalizado sin errores y build Vite exitoso (`index-BEZOxgHh.js`).
- PHPUnit no se ejecutó porque producción está instalada con `composer --no-dev`; las regresiones quedaron en `tests/Feature/Payments`.
- Documentos de diseño: `PAYMENT_CONFIGURATION_ANALYSIS.md` y `PAYMENT_CONFIGURATION_DESIGN.md`.
- Gate: migrar dos tenants QA, probar roles/aislamiento, compras efectivo-banco-crédito, ventas contado-crédito con Factus sandbox, Kardex, asientos y cartera.
- Validación productiva: cada tenant tiene 2 condiciones, 7 medios, 7 asociaciones y columnas de enlace en compras y ventas. Los endpoints protegidos responden 401 sin token en vez de 404.
- Rollback: revertir web/API y rollback 000003 antes de 000002; no recalcular ni eliminar documentos históricos.
