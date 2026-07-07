<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantAdminSeeder extends Seeder
{
    /**
     * Crea el usuario administrador por defecto cuando se provisiona un nuevo tenant.
     * Las credenciales deben cambiarse en el primer acceso.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@empresa.com'],
            [
                'nombre'   => 'Administrador',
                'apellido' => 'Principal',
                'password' => Hash::make('Admin@12345!'),
                'role'     => User::ROLE_ADMIN,
                'activo'   => true,
            ]
        );

        $this->command->info('✅ Usuario admin creado: admin@empresa.com / Admin@12345!');
        $this->command->warn('⚠️  Recuerda cambiar la contraseña en el primer acceso.');
    }
}
