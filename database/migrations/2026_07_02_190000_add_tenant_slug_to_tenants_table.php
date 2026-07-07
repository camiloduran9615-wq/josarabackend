<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'tenant_slug')) {
                $table->string('tenant_slug', 80)->nullable()->after('id');
            }
        });

        $used = [];
        $tenants = DB::table('tenants')
            ->select(['id', 'razon_social', 'nit', 'company_code', 'tenant_slug'])
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $current = is_string($tenant->tenant_slug) && $tenant->tenant_slug !== ''
                ? $tenant->tenant_slug
                : (is_string($tenant->company_code) && $tenant->company_code !== ''
                    ? $tenant->company_code
                    : (string) ($tenant->razon_social ?: $tenant->nit ?: $tenant->id));

            $base = Str::slug(trim($current));
            $base = trim(substr($base !== '' ? $base : 'empresa', 0, 48), '-');
            $candidate = $base;
            $counter = 2;

            while (isset($used[$candidate]) || DB::table('tenants')->where('tenant_slug', $candidate)->exists()) {
                $suffix = '-'.$counter;
                $candidate = substr($base, 0, 80 - strlen($suffix)).$suffix;
                $counter++;
            }

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'tenant_slug' => $candidate,
                    'company_code' => is_string($tenant->company_code) && $tenant->company_code !== ''
                        ? $tenant->company_code
                        : $candidate,
                ]);

            $used[$candidate] = true;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('tenant_slug', 'tenants_tenant_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_tenant_slug_unique');
            $table->dropColumn('tenant_slug');
        });
    }
};
