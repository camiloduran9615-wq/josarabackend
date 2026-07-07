-- =====================================================================
-- secure_audit_logs.sql
-- Endurecimiento de privilegios sobre la tabla audit_logs (BD central).
-- Ejecutar como SUPERUSUARIO PostgreSQL después de `php artisan migrate`.
-- Idempotente: puede correrse en cada deploy sin efectos secundarios.
-- =====================================================================

-- Reemplazar 'saas_app' por el nombre real del rol de aplicación.
-- (Definido en config/database.php → connections.pgsql.username)

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'saas_app') THEN
        RAISE NOTICE 'El rol saas_app no existe; salteando endurecimiento.';
        RETURN;
    END IF;

    -- 1) Garantizar SELECT, INSERT (lo demás es prohibido)
    REVOKE ALL ON TABLE audit_logs FROM saas_app;
    GRANT SELECT, INSERT ON TABLE audit_logs TO saas_app;

    -- 2) NO hay secuencias asociadas (audit_logs.id es UUID generado en app),
    --    por lo que no hace falta GRANT USAGE ON SEQUENCES.

    RAISE NOTICE 'Privilegios de saas_app sobre audit_logs ajustados a SELECT,INSERT.';
END
$$;

-- 3) Verificación (solo informativa; no falla si todo está bien)
SELECT
    grantee,
    privilege_type
FROM information_schema.role_table_grants
WHERE table_name = 'audit_logs'
  AND grantee = 'saas_app'
ORDER BY privilege_type;
