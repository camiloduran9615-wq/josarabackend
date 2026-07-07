<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class SyncPlatformAdminsFromEnvCommand extends Command
{
    protected $signature = 'platform-admin:sync-env';

    protected $description = 'Crea o actualiza dos administradores globales de plataforma desde variables de entorno.';

    public function handle(): int
    {
        $admins = config('platform_admins.seed', []);
        $synced = 0;

        foreach ($admins as $index => $payload) {
            $email = mb_strtolower((string) Arr::get($payload, 'email', ''));
            $name = (string) Arr::get($payload, 'name', '');
            $password = (string) Arr::get($payload, 'password', '');
            $role = (string) Arr::get($payload, 'role', PlatformAdmin::ROLE_SUPPORT_ADMIN);
            $active = filter_var(Arr::get($payload, 'active', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $active = $active ?? true;

            if ($email === '' || $name === '' || $password === '') {
                $this->warn('Omitiendo admin #'.($index + 1).' porque faltan variables de entorno.');
                continue;
            }

            $validator = Validator::make([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'role' => $role,
            ], [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
                'role' => ['required', Rule::in(PlatformAdmin::ROLES)],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error("Admin #".($index + 1).": {$error}");
                }

                return self::FAILURE;
            }

            $admin = PlatformAdmin::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $password,
                    'role' => $role,
                    'active' => $active,
                ],
            );

            $this->info("Sincronizado: {$admin->email} ({$admin->role})");
            $synced++;
        }

        if ($synced === 0) {
            $this->warn('No se sincronizó ningún administrador. Revisa las variables PLATFORM_ADMIN_1_* y PLATFORM_ADMIN_2_*.');
        }

        return self::SUCCESS;
    }
}
