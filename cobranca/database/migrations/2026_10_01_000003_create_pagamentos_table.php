<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('fatura_id')->index();
            $table->decimal('valor', 10, 2);
            $table->string('metodo', 30);
            // Defesa em profundidade: o mesmo evento do gateway nunca vira dois pagamentos.
            $table->string('event_id', 64)->unique();
            $table->dateTime('criado_em')->useCurrent();
        });

        // Mesma faixa das faturas: a cópia no monólito mantém o id (ADR-006).
        DB::statement('ALTER TABLE pagamentos AUTO_INCREMENT = 2000000000');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
