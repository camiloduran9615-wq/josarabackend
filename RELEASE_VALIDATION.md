# JOSARA CLOUD — Validación final de release

Fecha: 2026-07-14 UTC
Commit evaluado: b497ad9 — remediación de dependencias frontend
Alcance: /srv/apps/josara-web
Restricción: sin navegador automatizado, credenciales QA ni ejecución manual autenticada

## 1. Dictamen ejecutivo

## NO APTO PARA PRODUCCIÓN POR FALTA DE EVIDENCIA FUNCIONAL.

La remediación elimina los advisories conocidos y conserva instalación, TypeScript, build, lint sin errores, assets, rutas declaradas y entrega HTTP. No existe evidencia ejecutada de renderizado en navegador, login, navegación autenticada, aislamiento multi-tenant, CRUD, Factus, multipart o interceptores bajo condiciones reales.

npm audit = 0 demuestra ausencia de advisories conocidos en el árbol instalado; no demuestra compatibilidad funcional.

## 2. Evidencia ejecutada

| Control | Resultado | Evidencia |
|---|---|---|
| Commit aislado | OK | b497ad9; solo package.json y package-lock.json |
| Instalación reproducible | OK | npm ci: 250 paquetes, 251 auditados |
| Vulnerabilidades | OK | npm audit: 0 |
| TypeScript | OK | tsc -b dentro del build |
| ESLint | Parcial | Exit 0; 0 errores y 123 advertencias preexistentes |
| Build | OK | Vite 8.0.16; 2.627 módulos; build interno 1,94 s |
| Smoke estático | OK | Rutas críticas, assets y branding presentes |
| HTTP SPA | Parcial | HTTP 200 en rutas públicas/protegidas y catch-all |
| API pública | Parcial | GET /api/platform: HTTP 200 y JSON |
| E2E navegador | No ejecutable | No hay Chrome, Chromium, Playwright ni Cypress |
| Unitarias frontend | Inexistentes | No hay runner ni archivos test/spec |
| Integración autenticada | No ejecutada | No hay credenciales ni tenant QA |
| Visual real | No ejecutada | test:e2e:visual inspecciona archivos; no controla navegador |

## 3. Auditoría de riesgo por dependencia

| Paquete | Uso real | Cambio | Funcionalidad que podría romperse | Probabilidad | Riesgo |
|---|---|---|---|---:|---:|
| Axios 1.15.2 → 1.16.0 | Tres instancias: tenant legacy, tenant nuevo y Super Admin; tokens, interceptores, JSON, blobs y multipart | Minor correctivo | Serialización, headers, errores, interceptores, multipart, blobs, timeout y 401 | Baja-media por gran alcance | Medio |
| form-data 4.0.5 → 4.0.6 | Transitiva de Axios para Node | Patch CRLF | Multipart en adaptador Node | Muy baja: SPA usa Web FormData | Bajo |
| react-router-dom 7.14.2 → 7.15.1 | BrowserRouter, Routes, Route, Navigate, links y hooks | Minor correctivo | Matching, redirects, rutas anidadas, guards, history y deep links | Baja-media por alcance global | Medio |
| react-router 7.14.2 → 7.15.1 | Transitiva del DOM Router | Minor correctivo | Mismo impacto de navegación | Baja: no usa Framework/Data Router | Bajo |
| Vite 8.0.10 → 8.0.16 | Build, plugin React, Tailwind y preview | Patch | Transformación, módulos, CSS, assets y variables VITE | Baja: build verde; no está en runtime | Bajo |
| brace-expansion 5.0.5 → 5.0.7 | ESLint → minimatch | Patch DoS | Globs de lint | Muy baja; no llega al bundle | Bajo |
| @babel/core 7.29.0 → 7.29.7 | eslint-plugin-react-hooks | Patch source maps | Parsing/análisis durante lint | Muy baja; lint verde | Bajo |

Compatibilidad confirmada:

- Node 22.23.0 satisface React Router y Vite.
- React y React DOM 19.2.5 satisfacen el peer >=18.
- TypeScript 6 compila.
- Plugin React 6.0.1 y Tailwind Vite 4.3.0 resuelven Vite 8.0.16.
- Laravel API y lógica de negocio no cambiaron.

## 4. Trazabilidad

| Archivo o superficie | Función | Riesgo | Impacto |
|---|---|---:|---|
| src/lib/api.ts | Axios tenant legacy; token, tenant, interceptores; limpia sesión en 401 | Medio | Login, logout y mayoría de módulos |
| src/shared/api/client.ts | Axios nuevo; tenant/token, timeout 30 s, errores 401/403/409/422/5xx | Medio | Asientos, periodos y auditoría |
| src/services/admin.service.ts | Axios Super Admin y token separado | Medio | Login y panel global |
| src/pages/Register/RegisterPage.tsx | isAxiosError, status y Retry-After | Bajo | Registro/rate limit |
| src/pages/Config/MunicipiosDanePage.tsx | Tipo AxiosError y validaciones | Bajo | Errores DANE |
| src/lib/errors.ts | Normalización de errores Axios | Bajo | Mensajes globales |
| src/App.tsx | Único BrowserRouter, Routes, más de 60 Route y redirects | Alto por alcance | Toda navegación |
| src/components/ProtectedLayout.tsx | Navigate a login sin usuario | Medio | Protección tenant |
| src/pages/Admin/AdminLayout.tsx | Navigate a admin/login sin token | Medio | Protección global |
| src/config/platform.ts | Único window.fetch real; GET /api/platform | Bajo | Branding público |
| src/pages/Conciliacion/ConciliacionPage.tsx | Web FormData multipart | Medio | Importación de extractos |
| src/pages/Config/ConfigPage.tsx | Web FormData multipart | Medio | Logo y configuración |
| src/services/*.service.ts | 20 consumidores indirectos del cliente legacy | Medio | CRUD transversal |
| src/features/asientos/api/asientos.api.ts | Cliente Axios nuevo | Medio | Ciclo contable |
| src/features/auditoria/api/audit.api.ts | Cliente Axios nuevo | Medio | Auditoría |
| src/features/periodos/api/periodos.api.ts | Cliente Axios nuevo | Medio | Periodos |

Inventario exacto:

- Axios directo: lib/api, shared/api/client, services/admin.service y RegisterPage.
- Tipo Axios: MunicipiosDanePage.
- Interceptores: tres clientes Axios.
- Router: App.tsx; guards Navigate en ProtectedLayout y AdminLayout.
- createBrowserRouter: no utilizado.
- fetch global: solo config/platform.ts.
- Web FormData: Conciliación y Configuración.
- Paquete npm form-data: no importado directamente.
- Lazy loading: no hay React.lazy, lazy ni Suspense.
- Cancelación: no hay AbortController ni signal.
- Timeout: solo el cliente nuevo fija 30 segundos.

## 5. Plan manual guiado obligatorio

Ejecutar en staging con dos tenants QA-A/QA-B, usuarios por rol y datos descartables. Registrar capturas, HAR sanitizado, request ID, resultado esperado/obtenido y evidencia de persistencia.

### 5.1 Autenticación

| ID | Caso y validación | Estado |
|---|---|---|
| AUTH-01 | Login válido: 200, token, sede y redirect dashboard | Pendiente |
| AUTH-02 | Login inválido: 401/422, mensaje seguro y sin token residual | Pendiente |
| AUTH-03 | Tenant inválido: 404/422 controlado | Pendiente |
| AUTH-04 | Logout: revocación, limpieza, redirect y back protegido | Pendiente |
| AUTH-05 | Token expirado/revocado: 401, interceptor limpia sesión | Pendiente |
| AUTH-06 | Refresh token: no se observó flujo; confirmar/no implementado | Brecha |
| AUTH-07 | Recuperación de contraseña: no se observó ruta; confirmar | Brecha |
| AUTH-08 | Super Admin válido/inválido, expiración y logout | Pendiente |
| AUTH-09 | Selección/cambio de sede y scoping | Pendiente |

### 5.2 Multi-tenant y roles

1. Iniciar QA-A y comprobar identificador A en cada URL API.
2. Usar UUID de A desde QA-B en GET/PUT/PATCH/DELETE: esperar 404/403.
3. Cruzar token A contra ruta B y token B contra A: rechazo obligatorio.
4. Alternar usuarios y confirmar limpieza de token, tenant y caché.
5. Probar admin, contador, auxiliar, auditor y readonly en lectura, creación, edición, borrado, exportación y acciones contables.
6. Activar/inactivar usuarios, comprobar revocación y último administrador.
7. Confirmar separación de tokens tenant y Super Admin.

Estado: pendiente completo.

### 5.3 CRUD por módulo

Para cada módulo: listar, detalle, crear, editar, eliminar/anular, paginar, buscar, filtrar, ordenar y exportar cuando exista. Validar status, payload, persistencia, auditoría y aislamiento.

| Grupo | Módulos mínimos | Casos adicionales |
|---|---|---|
| Identidad | Usuarios, roles, seguridad | Estado, permisos, contraseña, sesiones |
| Maestros | Terceros, PUC, centros, sucursales, DIAN, DANE | Duplicados, inactivos, catálogos grandes |
| Ventas | Facturas, notas crédito, retenciones, cotizaciones, remisiones | Rangos, Factus sandbox, rechazo, idempotencia |
| Compras/cartera | Compras, ingresos, egresos, recibos, notas débito, ajustes | Saldos, parciales, anulaciones |
| Inventario | Productos, bodegas, movimientos, kardex | Stock, concurrencia, costo |
| Contabilidad | Asientos, periodos, cierres, auditoría | Balanceo, aprobación, bloqueo, hash |
| Reportes | Balances, resultados, mayor, exógena, tributarios, renta, flujo | Filtros, totales y exportaciones |
| Operación | Nómina, CRM, conciliación, activos | Multipart, cálculos y estados |
| Configuración | Empresa, logo, impuestos, resoluciones, Factus | Multipart, secretos y validación |
| Plataforma | Empresas, planes, observabilidad, seguridad, soporte | Paginación y permisos globales |

Estado: pendiente completo.

### 5.4 API y Axios

1. GET lista/detalle: 200, contrato y paginación.
2. POST válido: 200/201; inválido: 422 por campo.
3. PUT válido; UUID inexistente: 404.
4. PATCH estado: 200; sin permiso 403; inválido 422.
5. DELETE/anulación: contrato; conflicto 409/422.
6. Forzar 401 y comprobar cada interceptor.
7. Forzar 403, 409, 422 y 500 controlado; comprobar mensajes y ausencia de secretos.
8. Latencia mayor de 30 s: cliente nuevo debe agotar timeout; legacy/admin deben documentarse.
9. Cambio de pantalla con request activo: revisar requests huérfanos; cancelación no implementada.
10. Multipart: extracto y logo con tipo, tamaño y nombre inválidos/válidos.
11. Blob/exportación: MIME, nombre y contenido.
12. HAR: Authorization solo a API same-origin y sin secretos en URL.

Estado: pendiente completo.

## 6. Gate React Router

| Caso | Esperado | Evidencia |
|---|---|---|
| Menú | URL/pantalla correctas sin reload | Pendiente navegador |
| Deep link | Nginx entrega SPA y Router renderiza | HTTP 200; render pendiente |
| Recarga | Mantiene ruta y restaura auth | Entrega probada; auth pendiente |
| Protegida | Sin sesión redirige a login | Código inspeccionado |
| Admin | Sin token redirige a admin/login | Código inspeccionado |
| Raíz | Redirige dashboard y luego login si aplica | Código inspeccionado |
| Catch-all/404 | Comportamiento explícito | No existe 404; redirige dashboard |
| Post-login | Dashboard o selección de sede | Pendiente |
| Back/forward | Sin loops y estado coherente | Pendiente |
| Lazy loading | Chunks bajo demanda | No implementado |

## 7. Rendimiento

| Métrica | Antes | Después | Resultado |
|---|---:|---:|---|
| JS | 1.564.956 B | 1.568.314 B | +3.358 B (+0,21%) |
| JS gzip | 403,35 kB | 404,62 kB | +1,27 kB (+0,31%) |
| CSS | 171.718 B | 171.718 B | Sin cambio |
| Build interno | 1,96 s | 1,94 s final | Sin degradación |
| Módulos | 2.626 | 2.627 | +1 |
| Chunks JS | 1 | 1 | Bundle >500 kB |
| Lazy loading | No | No | Deuda previa |
| Requests/Web Vitals | Sin baseline | Sin medición | No certificable |

## 8. Riesgos

Mitigados:

- Advisories npm: 7 → 0.
- Compatibilidad Node/React/Vite/TypeScript.
- Build y entrega HTTP reproducibles.

Pendientes que bloquean:

1. Sin interacción real de navegador después del upgrade.
2. Login, logout, expiración, tenants y roles no ejecutados.
3. Ningún CRUD ni método mutante validado.
4. Factus y flujos contables/fiscales no validados.
5. Multipart no validado funcionalmente.
6. Sin unitarias, integración ni E2E frontend.
7. Sin 404 real.
8. Sin lazy loading; JS >1,5 MB.
9. Sin cancelación y timeout inconsistente.
10. Refresh y recuperación no observados.
11. 123 advertencias lint.
12. Sin Web Vitals/HAR.

## 9. Recomendación

1. Desplegar b497ad9 primero en staging idéntico a producción.
2. Proveer dos tenants QA y usuarios por rol.
3. Ejecutar y firmar las matrices anteriores.
4. Capturar HAR sanitizado y evidencia visual.
5. Usar Factus solo en sandbox.
6. Autorizar producción únicamente sin fallos P0/P1 y con rollback preparado.
7. Crear Playwright y unitarias en un cambio posterior separado.

## 10. Dictamen final

## NO APTO PARA PRODUCCIÓN POR FALTA DE EVIDENCIA FUNCIONAL.
