<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform-admin:create
        {email : Email del administrador}
        {--name= : Nombre visible}
        {--role=super_admin : Rol global}
        {--password= : Password inicial; si se omite se solicita oculto}';

    protected $description = 'Crea un administrador global de plataforma sin sembrar credenciales por defecto.';

    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $password = (string) ($this->option('password') ?: $this->secret('Password inicial'));

        $data = [
            'email' => $email,
            'name' => (string) ($this->option('name') ?: $email),
            'role' => (string) $this->option('role'),
            'password' => $password,
        ];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:255', 'unique:platform_admins,email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(PlatformAdmin::ROLES)],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = PlatformAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'active' => true,
        ]);

        $this->info("Administrador global creado: {$admin->email} ({$admin->role})");

        return self::SUCCESS;
    }
}
