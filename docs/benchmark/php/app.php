<?php

declare(strict_types=1);

function pdo(): PDO
{
    static $pdo = null;

    return $pdo ??= new PDO(getenv('DB_DSN'), 'bench', 'bench', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => getenv('DB_PERSISTENT') === '1',
    ]);
}

function responder(int $status, array $corpo): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($corpo);
}

function situacao(int $id): void
{
    $stmt = pdo()->prepare("SELECT
        EXISTS(SELECT 1 FROM faturas WHERE aluno_id = ? AND status = 'vencida' AND vencimento < CURDATE() - INTERVAL 5 DAY) AS inadimplente,
        (SELECT COUNT(*) FROM faturas WHERE aluno_id = ? AND status IN ('aberta','vencida')) AS abertas");
    $stmt->execute([$id, $id]);
    $linha = $stmt->fetch();
    responder(200, ['aluno_id' => $id, 'inadimplente' => (bool) $linha['inadimplente'], 'faturas_abertas' => (int) $linha['abertas']]);
}

function webhook(): void
{
    $ref = $_GET['reference'] ?? '';
    $evt = $_GET['event_id'] ?? '';
    $body = json_decode(file_get_contents('php://input'), true);
    if ($ref === '' || $evt === '' || !is_array($body)) {
        responder(400, ['erro' => 'payload inválido']);

        return;
    }

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO webhook_eventos (event_id, reference) VALUES (?, ?)');
        $ins->execute([$evt, $ref]);
        if ($ins->rowCount() === 0) {
            $pdo->commit();
            responder(200, ['ok' => true, 'duplicado' => true]);

            return;
        }

        $sel = $pdo->prepare('SELECT id, status, valor FROM faturas WHERE gateway_ref = ? FOR UPDATE');
        $sel->execute([$ref]);
        $fatura = $sel->fetch();
        if ($fatura === false) {
            $pdo->rollBack();
            responder(404, ['erro' => 'fatura não encontrada']);

            return;
        }

        if ($body['status'] === 'approved' && $fatura['status'] !== 'paga') {
            $pdo->prepare("UPDATE faturas SET status = 'paga', pago_em = NOW(), updated_at = NOW() WHERE id = ?")->execute([$fatura['id']]);
            $pdo->prepare('INSERT INTO pagamentos (fatura_id, valor, metodo, event_id) VALUES (?, ?, ?, ?)')
                ->execute([$fatura['id'], $body['amount'], $body['method'], $evt]);
            $pdo->prepare("INSERT INTO outbox (tipo, payload) VALUES ('FaturaPaga', ?)")
                ->execute([json_encode(['fatura_id' => $fatura['id'], 'valor' => $body['amount'], 'event_id' => $evt])]);
        }
        $pdo->commit();
        responder(200, ['ok' => true, 'duplicado' => false]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        responder(500, ['erro' => $e->getMessage()]);
    }
}

function rotear(): void
{
    $metodo = $_SERVER['REQUEST_METHOD'];
    $caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    match (true) {
        $metodo === 'GET' && $caminho === '/health' => responder(200, ['ok' => true]),
        $metodo === 'GET' && preg_match('#^/alunos/(\d+)/situacao$#', $caminho, $m) === 1 => situacao((int) $m[1]),
        $metodo === 'POST' && $caminho === '/webhooks/pagamento' => webhook(),
        default => responder(404, ['erro' => 'rota não encontrada']),
    };
}
