<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Termos de cobrança de cada matrícula, recebidos em MatriculaCriada (ADR-005).
        Schema::create('contratos', function (Blueprint $table) {
            $table->unsignedInteger('matricula_id')->primary();
            $table->unsignedInteger('aluno_id');
            $table->unsignedInteger('unidade_id');
            $table->unsignedInteger('plano_id');
            $table->decimal('valor_mensal', 10, 2);
            $table->unsignedTinyInteger('dia_vencimento');
            $table->date('inicio');
            $table->date('fim');
            $table->dateTime('criado_em')->useCurrent();
            $table->dateTime('atualizado_em')->nullable();
        });

        Schema::create('faturas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('matricula_id');
            $table->unsignedInteger('aluno_id');
            $table->date('competencia');
            $table->decimal('valor', 10, 2);
            $table->date('vencimento');
            $table->enum('status', ['aberta', 'paga', 'vencida', 'cancelada'])->default('aberta');
            $table->dateTime('pago_em')->nullable();
            $table->string('gateway_ref', 64)->nullable()->unique();
            $table->dateTime('criado_em')->useCurrent();
            $table->dateTime('atualizado_em')->nullable();
            $table->unique(['matricula_id', 'competencia']);
            $table->index(['aluno_id', 'status', 'vencimento']);
        });

        // Faturas criadas por Cobrança nunca colidem com as do monólito na cópia de leitura (ADR-006).
        DB::statement('ALTER TABLE faturas AUTO_INCREMENT = 2000000000');
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
        Schema::dropIfExists('contratos');
    }
};
