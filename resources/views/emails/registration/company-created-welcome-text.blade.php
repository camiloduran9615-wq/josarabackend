Bienvenido a {{ $data['platform_name'] ?? 'JOSARA CLOUD' }}

Hola {{ $data['admin_name'] ?? 'Administrador' }},

Confirmamos que tu empresa fue creada correctamente.

Empresa: {{ $data['company_name'] }}
Slug público: {{ $data['tenant_slug'] }}
URL de acceso: {{ $data['access_url'] }}
Email de acceso: {{ $data['admin_email'] }}
Plan inicial: {{ $data['plan_name'] }}
Fecha de registro: {{ $data['registered_at'] }}
Estado: {{ $data['account_status'] }}

Próximos pasos: inicia sesión, revisa los datos de tu empresa, configura los parámetros contables iniciales y crea tus usuarios de trabajo.

Por seguridad, no enviamos contraseñas por correo. Conserva tus credenciales en un lugar seguro.

Iniciar sesión: {{ $data['login_url'] }}
