<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('payment_form', 5)->default('1')->after('observaciones');
            $table->string('payment_method_code', 5)->default('10')->after('payment_form');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['payment_form', 'payment_method_code']);
        });
    }
};
