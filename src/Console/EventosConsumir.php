<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Eventos\Consumidor;
use EstudaFitHub\Eventos\EventSource;
use EstudaFitHub\Eventos\Handlers\AplicarFaturaGerada;
use EstudaFitHub\Eventos\Handlers\AplicarFaturaPaga;
use EstudaFitHub\Eventos\Handlers\AplicarFaturaVencida;
use EstudaFitHub\Eventos\Handlers\AplicarSituacaoFinanceira;
use EstudaFitHub\Eventos\HttpFeedSource;
use Throwable;

/**
 * Consome o feed de eventos de Cobrança (ADR-005). Roda continuamente no serviço
 * monolito-consumidor; com --uma-vez processa um lote e sai.
 */
final class EventosConsumir implements Comando
{
    public function __construct(private readonly ?EventSource $fonte = null)
    {
    }

    public static function consumidor(?EventSource $fonte = null): Consumidor
    {
        return new Consumidor(
            $fonte ?? new HttpFeedSource((string) getenv('COBRANCA_EVENTOS_URL'), (string) getenv('COBRANCA_EVENTOS_TOKEN')),
            'cobranca',
            [
                'FaturaGerada' => new AplicarFaturaGerada(),
                'FaturaPaga' => new AplicarFaturaPaga(),
                'FaturaVencida' => new AplicarFaturaVencida(),
                'SituacaoFinanceiraAlterada' => new AplicarSituacaoFinanceira(),
            ],
        );
    }

    public function executar(array $args): int
    {
        $consumidor = self::consumidor($this->fonte);
        if (in_array('--uma-vez', $args, true)) {
            echo $consumidor->processarLote() . " evento(s) lido(s)\n";

            return 0;
        }

        while (true) {
            // Sinal de vida para o healthcheck do container: atualizado a cada volta do laço.
            touch(sys_get_temp_dir() . '/consumidor-vivo');
            try {
                $lidos = $consumidor->processarLote();
            } catch (Throwable $e) {
                fwrite(STDERR, "[consumidor] {$e->getMessage()}\n");
                $lidos = 0;
            }
            if ($lidos === 0) {
                sleep(1);
            }
        }
    }
}
