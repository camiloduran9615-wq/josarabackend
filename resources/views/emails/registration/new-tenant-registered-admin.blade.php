@php
    $platform = $data['platform_name'] ?? 'JOSARA CLOUD';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva empresa registrada</title>
</head>
<body style="margin:0;background:#f6f7fb;color:#0D1B2A;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7fb;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background:#0D1B2A;padding:28px 32px;color:#ffffff;">
                            <div style="font-size:20px;font-weight:700;">{{ $platform }}</div>
                            <div style="margin-top:8px;color:#D4AF37;font-size:14px;">Nueva empresa registrada</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 18px;line-height:1.55;color:#374151;">
                                Se registró una nueva empresa en la plataforma. Datos operativos:
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:22px 0;">
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Empresa</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;font-weight:700;">{{ $data['company_name'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">NIT</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['nit'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Slug público</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['tenant_slug'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Email de contacto</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['contact_email'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Administrador</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['admin_name'] }} &lt;{{ $data['admin_email'] }}&gt;</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Plan asignado</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['plan_name'] }}</td></tr>
                                <tr><td style="padding:10px 0;border-bottom:1px solid #eef0f4;color:#6b7280;">Fecha y hora</td><td style="padding:10px 0;border-bottom:1px solid #eef0f4;text-align:right;">{{ $data['registered_at'] }}</td></tr>
                                <tr><td style="padding:10px 0;color:#6b7280;">Estado</td><td style="padding:10px 0;text-align:right;">{{ $data['account_status'] }}</td></tr>
                            </table>
                            <p style="margin:0;">
                                <a href="{{ $data['admin_panel_url'] }}" style="display:inline-block;background:#D4AF37;color:#0D1B2A;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:6px;">Abrir SaaS Admin</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;background:#f9fafb;color:#6b7280;font-size:12px;">
                            Esta notificación no contiene contraseñas ni identificadores internos.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
