<?php

declare(strict_types=1);

/**
 * E2E: simula o gateway enviando o webhook de pagamento aprovado, duas vezes com o mesmo
 * event_id (duplicado). Exige 204 nas duas.
 * Uso (dentro do container do monólito): php scripts/e2e/pagar.php <gateway_ref>
 */

$referencia = $argv[1] ?? '';
$corpo = json_encode([
    'reference' => $referencia, 'status' => 'approved', 'amount' => 119.90, 'method' => 'pix',
    'event_id' => 'evt_e2e_' . bin2hex(random_bytes(6)),
]);

foreach ([1, 2] as $tentativa) {
    @file_get_contents('http://localhost:8080/webhooks/pagamento', false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $corpo, 'ignore_errors' => true, 'timeout' => 10,
    ]]));
    if (!str_contains($http_response_header[0] ?? '', ' 204')) {
        fwrite(STDERR, "FALHOU: webhook (tentativa {$tentativa}) respondeu " . ($http_response_header[0] ?? 'nada') . "\n");
        exit(1);
    }
}
fwrite(STDERR, "webhook aceito (204) e reenviado em duplicidade\n");
