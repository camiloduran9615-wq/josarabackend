@php
    $platform = $data['platform_name'] ?? 'JOSARA CLOUD';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bienvenido a {{ $platform }}</title>
</head>
<body style="margin:0;background:#f6f7fb;color:#0D1B2A;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background:#0D1B2A;padding:28px 32px;color:#ffffff;">
                            <div style="font-size:20px;font-weight:700;letter-spacing:0;">{{ $platform }}</div>
                            <div style="margin-top:8px;color:#D4AF37;font-size:14px;">Tu empresa fue creada correctamente</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px;font-size:22px;color:#0D1B2A;">Hola {{ $data['admin_name'] ?? 'Administrador' }},</h1>
                            <p style="margin:0 0 18px;line-height:1.55;color:#374151;">
                                Bienvenido a {{ $platform }}. Confirmamos que el entorno de tu empresa quedó creado y listo para iniciar configuración.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:22px 0;">
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Empresa</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;font-weight:700;">{{ $data['company_name'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Slug público</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['tenant_slug'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">URL de acceso</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['access_url'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Email de acceso</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['admin_email'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Plan inicial</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['plan_name'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Fecha de registro</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['registered_at'] }}</td></tr>
                                <tr><td style="padding:10px 0;color:#6b7280;">Estado</td><td style="padding:10px 0;text-align:right;">{{ $data['account_status'] }}</td></tr>
                            </table>

                            <p style="margin:0 0 18px;line-height:1.55;color:#374151;">
                                Próximos pasos: inicia sesión, revisa los datos de tu empresa, configura los parámetros contables iniciales y crea tus usuarios de trabajo.
                            </p>
                            <p style="margin:0 0 22px;line-height:1.55;color:#374151;">
                                Por seguridad, no enviamos contraseñas por correo. Conserva tus credenciales en un lugar seguro.
                            </p>
                            <p style="margin:0;">
                                <a href="{{ $data['login_url'] }}" style="display:inline-block;background:#D4AF37;color:#0D1B2A;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:6px;">Iniciar sesión</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;background:#f9fafb;color:#6b7280;font-size:12px;">
                            Mensaje automático de {{ $platform }}. Si necesitas ayuda, contacta soporte.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
