# Diseño del núcleo configurable de pagos

Fecha: 2026-07-15 UTC

## Modelo implementado

```text
payment_terms
    └── payment_term_methods ── payment_methods
              │                       │
              └── payment_accounting_rules

documentos_ingreso ── payment_term_id / payment_method_id
facturas            ── payment_term_id / payment_method_id
```

Todas las claves primarias son UUID y viven dentro de la base tenant.

## Resolución

### Compra

```text
Condición crédito → forma heredada credito → cuenta por pagar existente
Condición contado + efectivo → contado_efectivo → regla CASH o cuenta maestra de caja
Condición contado + banco/cheque → contado_banco → regla BANK o cuenta maestra de banco
Tarjeta → regla CLEARING_ACCOUNT, luego BANK como fallback
```

### Venta

```text
timing immediate → Factus payment_form 1
timing credit    → Factus payment_form 2 + vencimiento obligatorio
payment_method.dian_code → Factus payment_method_code
```

La factura de venta conserva su contabilización actual. El cobro posterior continúa cancelando cartera mediante recibo de caja; no se vuelven a reconocer ingresos ni IVA.

## Prioridad de reglas

1. Condición + medio exactos.
2. Regla específica del medio.
3. Regla específica de la condición.
4. Parametrización contable maestra existente.

Dentro del mismo nivel gana el menor número de prioridad. El backend rechaza reglas activas superpuestas con igual contexto, prioridad y vigencia.

## Seguridad

- Lectura: usuarios autenticados del tenant.
- Escritura: administrador o contador.
- Validación de UUID y existencia dentro de la conexión tenant.
- Cuenta PUC obligatoriamente activa y con `acepta_movimientos=true`.
- Auditoría central de creación, modificación, activación e inactivación.

## Próxima fase

Normalizar pagos reales y aplicaciones en `payments`, asignaciones de venta/compra, saldo no aplicado, idempotencia y reversión. Solo después habilitar pagos mixtos, anticipos y cuotas.

