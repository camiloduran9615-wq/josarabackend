<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_ingreso', function (Blueprint $table): void {
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
        });

        Schema::table('facturas', function (Blueprint $table): void {
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('payment_term_id');
        });
        Schema::table('documentos_ingreso', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('payment_term_id');
        });
    }
};
