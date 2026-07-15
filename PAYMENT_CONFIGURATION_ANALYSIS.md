# Análisis de configuración de pagos — JOSARA

Fecha: 2026-07-15 UTC

## Estado anterior

- Compras almacenaban `forma_pago` como valor fijo y escogían proveedor, caja o banco desde parametrización global.
- Ventas almacenaban directamente `payment_form`, `payment_method_code` y `payment_due_date` para Factus/DIAN.
- Recibos de caja y comprobantes de egreso mantienen flujos separados para cobros y pagos posteriores.
- Cada empresa utiliza una base tenant independiente; no corresponde duplicar `tenant_id` en tablas tenant.

## Decisiones

- Separar condición (`payment_terms`) de medio (`payment_methods`).
- Conservar los campos heredados para documentos históricos e integraciones existentes.
- Guardar referencias configurables nuevas como UUID opcionales en compras y ventas.
- Derivar en backend contado/crédito, caja/banco y código DIAN; el frontend no es fuente de verdad.
- Las reglas de pago solo resuelven cuentas operativas (caja, banco, puente, comisiones, anticipos). Ingresos, IVA, inventario, costo y retenciones continúan bajo la parametrización documental existente.
- No recalcular documentos históricos.
- No implementar todavía pagos mixtos, cuotas ni libro normalizado de aplicaciones; requieren una fase independiente de cartera/tesorería.

## Compatibilidad

- Solicitudes antiguas sin IDs configurables siguen usando `forma_pago`, `payment_form` y `payment_method_code`.
- Solicitudes nuevas guardan ambas representaciones.
- Condiciones o medios inactivos se rechazan en backend.
- Un medio de compra no clasificable como caja/banco exige una regla contable explícita.
- Ventas exigen código DIAN en el medio seleccionado.

## Riesgos pendientes

- No hay PHPUnit instalado en producción (`composer --no-dev`); las pruebas quedan para local/QA.
- No se han aplicado las nuevas migraciones a producción.
- No existe tenant QA independiente en este servidor.
- Falta evidencia funcional de navegador y Factus sandbox.
- Pagos parciales actuales continúan con las estructuras de recibos/egresos existentes.

## Rollback

1. Revertir frontend y API.
2. Ejecutar rollback tenant de `2026_07_15_000003` antes de `000002`.
3. Los campos heredados permanecen intactos; no se pierden documentos históricos.

