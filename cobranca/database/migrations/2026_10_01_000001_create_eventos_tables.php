<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Base de eventos de Cobrança (ADR-005): outbox, inbox, sequência por agregado e posição do feed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('tipo', 60);
            $table->unsignedTinyInteger('versao');
            $table->string('aggregate_id', 64);
            $table->unsignedInteger('sequencia');
            $table->string('correlation_id', 64);
            $table->json('dados');
            $table->dateTime('ocorrido_em', 3);
            $table->index(['aggregate_id', 'sequencia']);
        });

        Schema::create('eventos_processados', function (Blueprint $table) {
            $table->string('event_id', 64)->primary();
            $table->dateTime('processado_em', 3)->useCurrent();
        });

        Schema::create('agregados_sequencia', function (Blueprint $table) {
            $table->string('aggregate_id', 64)->primary();
            $table->unsignedInteger('sequencia');
        });

        Schema::create('consumidor_posicao', function (Blueprint $table) {
            $table->string('feed', 40)->primary();
            $table->unsignedBigInteger('ultimo_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumidor_posicao');
        Schema::dropIfExists('agregados_sequencia');
        Schema::dropIfExists('eventos_processados');
        Schema::dropIfExists('outbox');
    }
};
