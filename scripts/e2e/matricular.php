<?php

declare(strict_types=1);

/**
 * E2E: cria um aluno na unidade informada e matricula. Imprime o id do aluno.
 * Para numa unidade migrada, exige 201 com "primeira_fatura": null (PRD etapa 2, critério 1).
 * Uso (dentro do container do monólito): php scripts/e2e/matricular.php <unidade_id>
 */

$base = 'http://localhost:8080';
$unidade = (int) ($argv[1] ?? 0);

function post(string $url, array $corpo): array
{
    // CORRELATION_ID opcional: permite seguir a operação nos logs dos dois serviços.
    $correlacao = getenv('CORRELATION_ID') ? 'X-Correlation-Id: ' . getenv('CORRELATION_ID') . "\r\n" : '';
    $resposta = file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n{$correlacao}",
        'content' => json_encode($corpo),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), json_decode((string) $resposta, true)];
}

$cpf = sprintf('%011d', random_int(60000000000, 69999999999));
[$status, $aluno] = post("{$base}/alunos", ['nome' => 'Aluna E2E', 'email' => "e2e{$cpf}@exemplo.com", 'telefone' => '11977776666', 'cpf' => $cpf, 'unidade_id' => $unidade]);
if ($status !== 201) {
    fwrite(STDERR, "FALHOU: criar aluno ({$status})\n");
    exit(1);
}

[$status, $matricula] = post("{$base}/matriculas", ['aluno_id' => $aluno['id'], 'plano_id' => 2, 'dia_vencimento' => 10]);
if ($status !== 201 || $matricula['primeira_fatura'] !== null || ($matricula['cobranca']['status'] ?? '') !== 'em_processamento') {
    fwrite(STDERR, "FALHOU: matrícula na unidade migrada ({$status}): " . json_encode($matricula) . "\n");
    exit(1);
}

echo $aluno['id'];
