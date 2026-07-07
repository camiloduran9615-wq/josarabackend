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
            if (! Schema::hasColumn('tenants', 'company_code')) {
                $table->string('company_code', 80)->nullable()->after('id');
            }
        });

        $used = [];
        $tenants = DB::table('tenants')
            ->select(['id', 'razon_social', 'nit', 'company_code'])
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            if (is_string($tenant->company_code) && $tenant->company_code !== '') {
                $used[$tenant->company_code] = true;

                continue;
            }

            $base = Str::slug((string) ($tenant->razon_social ?: $tenant->nit ?: $tenant->id));
            $base = trim(substr($base !== '' ? $base : 'empresa', 0, 48), '-');
            $candidate = $base;
            $counter = 2;

            while (isset($used[$candidate]) || DB::table('tenants')->where('company_code', $candidate)->exists()) {
                $suffix = '-'.$counter;
                $candidate = substr($base, 0, 80 - strlen($suffix)).$suffix;
                $counter++;
            }

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update(['company_code' => $candidate]);

            $used[$candidate] = true;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('company_code', 'tenants_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_company_code_unique');
            $table->dropColumn('company_code');
        });
    }
};
