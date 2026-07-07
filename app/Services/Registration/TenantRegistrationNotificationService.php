<?php

namespace App\Services\Registration;

use App\Mail\CompanyCreatedWelcomeMail;
use App\Mail\NewTenantRegisteredAdminMail;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TenantRegistrationNotificationService
{
    /**
     * @param  array<string, mixed>  $adminUser
     */
    public function send(Tenant $tenant, array $adminUser): void
    {
        $data = $this->buildMailData($tenant, $adminUser);

        $this->sendSafely(
            $data['admin_email'],
            $data['admin_name'],
            new CompanyCreatedWelcomeMail($data),
            'tenant_registration.welcome_mail_failed',
            $tenant,
        );

        foreach ($this->platformRecipients() as $recipient) {
            $this->sendSafely(
                $recipient['email'],
                $recipient['name'],
                new NewTenantRegisteredAdminMail($data),
                'tenant_registration.platform_mail_failed',
                $tenant,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $adminUser
     * @return array<string, mixed>
     */
    public function buildMailData(Tenant $tenant, array $adminUser): array
    {
        $frontendUrl = rtrim((string) config('platform.frontend_url', config('app.url')), '/');
        $plan = $this->planName($tenant);

        return [
            'platform_name' => (string) config('platform.name', 'JOSARA CLOUD'),
            'platform_short_name' => (string) config('platform.short_name', 'JOSARA'),
            'support_email' => config('platform.support_email'),
            'company_name' => $tenant->razon_social,
            'nit' => $tenant->nit,
            'tenant_slug' => $tenant->publicIdentifier(),
            'access_url' => $frontendUrl,
            'login_url' => $frontendUrl.'/login',
            'admin_panel_url' => $frontendUrl.'/admin/empresas',
            'contact_email' => $tenant->email_contacto,
            'admin_name' => trim((string) (Arr::get($adminUser, 'name') ?: Arr::get($adminUser, 'admin_name'))),
            'admin_email' => (string) (Arr::get($adminUser, 'email') ?: Arr::get($adminUser, 'admin_email')),
            'plan_name' => $plan,
            'registered_at' => optional($tenant->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'account_status' => $tenant->status ?: ($tenant->activo ? Tenant::STATUS_TRIAL : Tenant::STATUS_SUSPENDED),
            'trial_ends_at' => optional($tenant->trial_ends_at)->timezone(config('app.timezone'))->format('Y-m-d'),
        ];
    }

    private function planName(Tenant $tenant): string
    {
        $planId = trim((string) $tenant->plan_id);

        if ($planId !== '') {
            $planName = Plan::query()
                ->when(Str::isUuid($planId), fn ($query) => $query->where('id', $planId))
                ->when(! Str::isUuid($planId), fn ($query) => $query->where('code', $planId))
                ->value('name');

            if (is_string($planName) && $planName !== '') {
                return $planName;
            }

            return ucfirst(str_replace(['_', '-'], ' ', $planId));
        }

        return 'Trial';
    }

    /**
     * @return array<int, array{name: string|null, email: string}>
     */
    private function platformRecipients(): array
    {
        $recipients = config('platform_admins.notification_recipients', []);

        if (! is_array($recipients)) {
            return [];
        }

        $validRecipients = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }

            $email = $recipient['email'] ?? null;
            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalizedEmail = mb_strtolower($email);
            if (isset($seen[$normalizedEmail])) {
                continue;
            }

            $name = $recipient['name'] ?? null;
            $validRecipients[] = [
                'name' => is_string($name) && $name !== '' ? $name : null,
                'email' => $email,
            ];
            $seen[$normalizedEmail] = true;
        }

        return $validRecipients;
    }

    private function sendSafely(string $email, ?string $name, \Illuminate\Mail\Mailable $mail, string $event, Tenant $tenant): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $mailer = Mail::to($email);

            if (config('queue.default') === 'sync') {
                $mailer->send($mail);
            } else {
                $mailer->queue($mail);
            }
        } catch (\Throwable $e) {
            Log::warning($event, [
                'tenant_slug' => $tenant->publicIdentifier(),
                'mail_to_hash' => hash('sha256', mb_strtolower($email)),
                'exception' => $e::class,
            ]);
        }
    }
}
