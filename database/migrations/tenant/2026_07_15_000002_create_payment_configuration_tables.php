<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('timing', 20); // immediate|credit
            $table->unsignedSmallInteger('default_credit_days')->default(0);
            $table->unsignedSmallInteger('maximum_installments')->default(1);
            $table->boolean('allows_partial_payment')->default(false);
            $table->boolean('allows_mixed_payment')->default(false);
            $table->boolean('applies_to_sales')->default(true);
            $table->boolean('applies_to_purchases')->default(true);
            $table->boolean('requires_due_date')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('type', 30);
            $table->string('dian_code', 5)->nullable();
            $table->boolean('requires_cash_account')->default(false);
            $table->boolean('requires_bank_account')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('allows_sales')->default(true);
            $table->boolean('allows_purchases')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_term_methods', function (Blueprint $table): void {
            $table->foreignUuid('payment_term_id')->constrained('payment_terms')->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->primary(['payment_term_id', 'payment_method_id']);
        });

        Schema::create('payment_accounting_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('operation_type', 20); // sale|purchase
            $table->string('account_role', 50);
            $table->foreignUuid('accounting_account_id')->constrained('cuentas_contables')->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['operation_type', 'account_role', 'is_active'], 'payment_rules_resolution_idx');
        });

        DB::statement("ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_timing_check CHECK (timing IN ('immediate','credit'))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ('cash','bank','card','check','credit','advance','compensation','other'))");
        DB::statement("ALTER TABLE payment_accounting_rules ADD CONSTRAINT payment_rules_operation_check CHECK (operation_type IN ('sale','purchase'))");
        DB::statement('ALTER TABLE payment_accounting_rules ADD CONSTRAINT payment_rules_context_check CHECK (payment_term_id IS NOT NULL OR payment_method_id IS NOT NULL)');
        DB::statement('ALTER TABLE payment_accounting_rules ADD CONSTRAINT payment_rules_dates_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');

        $now = now();
        $terms = [
            ['code' => 'CONTADO', 'name' => 'Contado', 'timing' => 'immediate', 'days' => 0, 'due' => false, 'order' => 10],
            ['code' => 'CREDITO', 'name' => 'Crédito', 'timing' => 'credit', 'days' => 30, 'due' => true, 'order' => 20],
        ];
        $termIds = [];
        foreach ($terms as $term) {
            $id = (string) Str::uuid();
            $termIds[$term['code']] = $id;
            DB::table('payment_terms')->insert([
                'id' => $id, 'code' => $term['code'], 'name' => $term['name'],
                'timing' => $term['timing'], 'default_credit_days' => $term['days'],
                'maximum_installments' => 1, 'allows_partial_payment' => $term['code'] === 'CREDITO',
                'allows_mixed_payment' => false, 'applies_to_sales' => true,
                'applies_to_purchases' => true, 'requires_due_date' => $term['due'],
                'is_active' => true, 'display_order' => $term['order'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $methods = [
            ['code' => 'EFECTIVO', 'name' => 'Efectivo', 'type' => 'cash', 'dian' => '10', 'cash' => true, 'bank' => false, 'ref' => false, 'order' => 10],
            ['code' => 'TRANSFERENCIA', 'name' => 'Transferencia bancaria', 'type' => 'bank', 'dian' => '42', 'cash' => false, 'bank' => true, 'ref' => true, 'order' => 20],
            ['code' => 'CONSIGNACION', 'name' => 'Consignación bancaria', 'type' => 'bank', 'dian' => '42', 'cash' => false, 'bank' => true, 'ref' => true, 'order' => 30],
            ['code' => 'TARJETA_DEBITO', 'name' => 'Tarjeta débito', 'type' => 'card', 'dian' => '49', 'cash' => false, 'bank' => true, 'ref' => true, 'order' => 40],
            ['code' => 'TARJETA_CREDITO', 'name' => 'Tarjeta crédito', 'type' => 'card', 'dian' => '48', 'cash' => false, 'bank' => true, 'ref' => true, 'order' => 50],
            ['code' => 'CHEQUE', 'name' => 'Cheque', 'type' => 'check', 'dian' => '20', 'cash' => false, 'bank' => true, 'ref' => true, 'order' => 60],
            ['code' => 'OTRO', 'name' => 'Otro', 'type' => 'other', 'dian' => null, 'cash' => false, 'bank' => false, 'ref' => true, 'order' => 100],
        ];
        foreach ($methods as $method) {
            $id = (string) Str::uuid();
            DB::table('payment_methods')->insert([
                'id' => $id, 'code' => $method['code'], 'name' => $method['name'],
                'type' => $method['type'], 'dian_code' => $method['dian'],
                'requires_cash_account' => $method['cash'], 'requires_bank_account' => $method['bank'],
                'requires_reference' => $method['ref'], 'allows_sales' => true,
                'allows_purchases' => true, 'is_active' => true, 'display_order' => $method['order'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payment_term_methods')->insert([
                'payment_term_id' => $termIds['CONTADO'], 'payment_method_id' => $id,
                'is_default' => $method['code'] === 'EFECTIVO', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounting_rules');
        Schema::dropIfExists('payment_term_methods');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_terms');
    }
};
