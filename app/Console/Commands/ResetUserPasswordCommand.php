<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetUserPasswordCommand extends Command
{
    protected $signature = 'user:reset-password {email} {password}';
    protected $description = 'Resetea la contraseña de un usuario dentro del tenant actual';

    public function handle(): int
    {
        $email    = $this->argument('email');
        $password = $this->argument('password');

        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("Usuario no encontrado: $email");
            $this->line('Usuarios en este tenant:');
            foreach (User::all(['id', 'email', 'role']) as $u) {
                $this->line("  - {$u->email} | role={$u->role}");
            }
            return self::FAILURE;
        }

        $user->password = bcrypt($password);
        $user->save();
        $this->info("OK: contraseña actualizada para {$user->email} (role={$user->role})");
        return self::SUCCESS;
    }
}
