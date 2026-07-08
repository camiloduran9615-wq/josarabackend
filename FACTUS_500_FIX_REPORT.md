# FACTUS 500 Fix Report

Fecha: 2026-07-08

## 1. Diagnostico exacto

- Cloudflare beacon: el error CORS de `static.cloudflareinsights.com/beacon.min.js` es una advertencia externa no critica. No se trato como fallo principal de la aplicacion.
- `POST /api/v1/colombiaapp/configs/factus` fallaba con HTTP 500 por `BadMethodCallException: This cache store does not support tagging`.
- `POST /api/v1/colombiaapp/resoluciones/sync` fallaba con HTTP 500 por el mismo origen: Stancl Tenancy envuelve las llamadas a cache con tags y `CACHE_STORE=database` no soporta tags.
- Logs reales revisados:
  - `storage/logs/laravel.log`: excepciones en `ConfigController::updateFactus()` y `FactusService::getAccessToken()`.
  - `/srv/apps/_logs/josara-api-access.log`: 500 en `13:25:57`, `13:28:50`, `13:29:25`.
  - `/srv/apps/_logs/josara-api-error.log`: sin errores adicionales.
  - Docker: `josara-db` PostgreSQL activo y healthy.

## 2. Causa raiz

La integracion Factus usaba cache dentro de un contexto tenant. Stancl `CacheManager` ejecuta internamente `store()->tags(...)` para aislar cache por tenant. Con `CACHE_STORE=database`, Laravel no soporta tags, por eso cualquier llamada `Cache::remember()`/`Cache::forget()` terminaba en 500.

Adicionalmente, Factus esta respondiendo HTTP 401 al autenticar las credenciales actuales del tenant `colombiaapp`. Ese caso ahora se clasifica como 422 con mensaje seguro.

## 3. Archivos modificados

- `app/Exceptions/FactusIntegrationException.php`
- `app/Services/FactusService.php`
- `app/Http/Controllers/Api/ConfigController.php`
- `app/Http/Controllers/Api/ResolucionController.php`
- `app/Providers/AppServiceProvider.php`
- `config/cors.php`
- `tests/Feature/FactusIntegrationTest.php`
- `/srv/apps/josara-web/src/pages/Config/ResolucionesPage.tsx`
- `/srv/apps/josara-web/src/pages/Config/TipoComprobantesPage.tsx`

## 4. Cambios realizados

- Se elimino el uso de cache tenant para el token Factus, evitando `Cache::tags()` con store `database`.
- Se agrego `FactusIntegrationException` para mapear errores esperados a codigos seguros.
- Se agregaron logs seguros con `tenant_id`, `tenant_slug`, `user_id`, endpoint, tipo de error y estado externo cuando aplica.
- No se registran tokens, passwords, client_secret ni api_key.
- `configs/factus` mantiene validaciones existentes y conserva secretos si no se envian.
- `resoluciones/sync` conserva la logica de sincronizacion y `updateOrCreate` por `factus_id`.
- Errores de credenciales Factus devuelven 422; configuracion faltante devuelve 404; fallos externos/conectividad devuelven 502; inesperados siguen en 500 con mensaje generico.
- CORS se restringio a `https://josara.colombiaapp.fun`/`APP_URL`, con headers `Authorization`, `Content-Type`, `X-Requested-With`, `Accept`.
- Frontend muestra mensajes amigables del backend y deja de sincronizar Factus silenciosamente al abrir tipos de comprobante.

## 5. Evidencia de pruebas

- `composer validate`: OK.
- `php artisan route:list --path=factus`: 3 rutas registradas.
- `php artisan route:list --path=resoluciones`: 6 rutas registradas.
- `php artisan migrate:status`: migraciones centrales ejecutadas.
- `php -l` en archivos PHP modificados: OK.
- `npm run build`: OK.
- `npm run lint`: OK, 124 warnings preexistentes, 0 errores.
- Smoke sin token:
  - `POST /configs/factus`: 401 JSON `No autenticado.`
  - `POST /resoluciones/sync`: 401 JSON `No autenticado.`
- Smoke autenticado con token temporal revocado al finalizar:
  - `POST /configs/factus` con payload invalido: 422 JSON de validacion.
  - `POST /resoluciones/sync`: 422 JSON `No fue posible autenticar con Factus. Verifica las credenciales configuradas.`
- Preflight CORS:
  - `OPTIONS /configs/factus`: 204 con `Access-Control-Allow-Origin: https://josara.colombiaapp.fun`.
- Verificacion DB:
  - Tenant `colombiaapp` existe, `activo=true`, `status=activa`.
  - DB tenant: `tenant5ba6439b-bfff-4e5a-8632-577bd636549e`.
  - Tablas `configs` y `resoluciones` existen.
  - `configs.key` tiene indice unico.

No se pudo ejecutar `php artisan test` porque el comando no esta disponible en esta instalacion. Tampoco se pudo ejecutar PHPUnit/PHPStan porque las dependencias dev (`phpunit`, `phpstan`/Larastan) no estan instaladas en `vendor`.

## 6. Riesgos pendientes

- Las credenciales Factus reales de `colombiaapp` estan siendo rechazadas por Factus con HTTP 401. La app ya no retorna 500, pero se debe corregir la configuracion operativa.
- `resoluciones.factus_id` no tiene indice unico en DB. No produjo el 500, pero seria recomendable agregarlo en una migracion controlada si se confirma que Factus IDs son unicos por tenant.
- `bootstrap/cache` y `storage` pertenecen a `www-data`; sin sudo no se pudo regenerar `config:cache`/`route:cache`. Se agrego override runtime de CORS para no depender de esa regeneracion.

## 7. Recomendaciones

- Corregir credenciales Factus del tenant `colombiaapp` desde la pantalla de integracion.
- Instalar dependencias dev en entorno de CI/staging para ejecutar `php artisan test`, PHPUnit y PHPStan.
- Considerar Redis como `CACHE_STORE` si se desea cache tenant con tags.
- Agregar migracion futura para indice unico parcial o compuesto en `resoluciones.factus_id`, si aplica al modelo de Factus.

## 8. Confirmacion de logica de negocio

No se cambio la logica de negocio existente. No se eliminaron validaciones, autenticacion ni autorizacion. No se modificaron rutas publicas. No se expusieron credenciales. Los cambios se limitaron a manejo de errores, logging seguro, CORS y correccion tecnica del uso de cache incompatible con multi-tenancy.
