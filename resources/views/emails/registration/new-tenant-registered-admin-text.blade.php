Nueva empresa registrada en {{ $data['platform_name'] ?? 'JOSARA CLOUD' }}

Empresa: {{ $data['company_name'] }}
NIT: {{ $data['nit'] }}
Slug público: {{ $data['tenant_slug'] }}
Email de contacto: {{ $data['contact_email'] }}
Administrador creado: {{ $data['admin_name'] }} <{{ $data['admin_email'] }}>
Plan asignado: {{ $data['plan_name'] }}
Fecha y hora: {{ $data['registered_at'] }}
Estado: {{ $data['account_status'] }}

Panel SaaS Admin: {{ $data['admin_panel_url'] }}

Esta notificación no contiene contraseñas ni identificadores internos.
