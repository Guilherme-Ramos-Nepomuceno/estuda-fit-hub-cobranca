<?php

namespace App\Console\Commands;

use App\Cobranca\GerarPrimeiraFatura;
use App\Eventos\Consumidor;
use App\Eventos\EventSource;
use Illuminate\Console\Command;
use Throwable;

/**
 * Consome o feed de eventos do monólito (ADR-005). Roda continuamente no serviço
 * cobranca-consumidor; com --uma-vez processa um lote e sai.
 */
class EventosConsumir extends Command
{
    protected $signature = 'eventos:consumir {--uma-vez : processa um lote e sai}';

    protected $description = 'Consome o feed de eventos do monólito';

    public function handle(EventSource $fonte, GerarPrimeiraFatura $gerarPrimeiraFatura): int
    {
        $consumidor = new Consumidor($fonte, 'monolito', [
            'MatriculaCriada' => $gerarPrimeiraFatura,
        ]);

        if ($this->option('uma-vez')) {
            $this->line($consumidor->processarLote().' evento(s) lido(s)');

            return self::SUCCESS;
        }

        while (true) {
            // Sinal de vida para o healthcheck do container: atualizado a cada volta do laço.
            touch(storage_path('framework/consumidor-vivo'));
            try {
                $lidos = $consumidor->processarLote();
            } catch (Throwable $e) {
                $this->error("[consumidor] {$e->getMessage()}");
                $lidos = 0;
            }
            if ($lidos === 0) {
                sleep(1);
            }
        }
    }
}
