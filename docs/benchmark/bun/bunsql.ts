import { SQL } from "bun";

const sql = new SQL({ url: process.env.DB_URL!, adapter: "mysql", max: Number(process.env.POOL ?? 50) });

const json = (body: unknown, status = 200) => Response.json(body, { status });

type WebhookBody = { status: string; amount: number; method: string };

async function webhook(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const ref = url.searchParams.get("reference");
  const evt = url.searchParams.get("event_id");
  const body = (await req.json()) as WebhookBody;
  if (!ref || !evt) return json({ erro: "payload inválido" }, 400);

  return sql.begin(async (tx) => {
    const ins = await tx`INSERT IGNORE INTO webhook_eventos (event_id, reference) VALUES (${evt}, ${ref})`;
    if (ins.affectedRows === 0) return json({ ok: true, duplicado: true });

    const [fatura] = await tx`SELECT id, status, valor FROM faturas WHERE gateway_ref = ${ref} FOR UPDATE`;
    if (!fatura) throw new NaoEncontrada();

    if (body.status === "approved" && fatura.status !== "paga") {
      await tx`UPDATE faturas SET status = 'paga', pago_em = NOW(), updated_at = NOW() WHERE id = ${fatura.id}`;
      await tx`INSERT INTO pagamentos (fatura_id, valor, metodo, event_id) VALUES (${fatura.id}, ${body.amount}, ${body.method}, ${evt})`;
      const payload = JSON.stringify({ fatura_id: fatura.id, valor: body.amount, event_id: evt });
      await tx`INSERT INTO outbox (tipo, payload) VALUES ('FaturaPaga', ${payload})`;
    }
    return json({ ok: true, duplicado: false });
  }).catch((e) => (e instanceof NaoEncontrada ? json({ erro: "fatura não encontrada" }, 404) : json({ erro: String(e) }, 500)));
}

class NaoEncontrada extends Error {}

async function gerar(competencia: string, concorrencia: number) {
  const inicio = performance.now();
  const contratos = await sql`SELECT matricula_id, aluno_id, valor_mensal, dia_vencimento FROM contratos WHERE ativo = 1`;
  let geradas = 0;
  let i = 0;
  const worker = async () => {
    while (i < contratos.length) {
      const c = contratos[i++];
      const vencimento = `${competencia}-${String(c.dia_vencimento).padStart(2, "0")}`;
      const res = await sql`INSERT IGNORE INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento)
        VALUES (${c.matricula_id}, ${c.aluno_id}, ${competencia + "-01"}, ${c.valor_mensal}, ${vencimento})`;
      if (res.affectedRows === 0) continue;
      const id = res.lastInsertRowid;
      const resp = await fetch(process.env.GATEWAY_URL!, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fatura_id: Number(id), valor: c.valor_mensal }),
      });
      const { ref } = (await resp.json()) as { ref: string };
      await sql`UPDATE faturas SET gateway_ref = ${ref} WHERE id = ${id}`;
      geradas++;
    }
  };
  await Promise.all(Array.from({ length: concorrencia }, worker));
  console.log(JSON.stringify({ geradas, segundos: +((performance.now() - inicio) / 1000).toFixed(2) }));
  await sql.close();
}

if (process.argv[2] === "gerar") {
  await gerar(process.argv[3], 50);
} else {
  Bun.serve({
    port: 8080,
    reusePort: true,
    routes: {
      "/health": () => json({ ok: true }),
      "/alunos/:id/situacao": async (req) => {
        const id = Number(req.params.id);
        const [row] = await sql`SELECT
          EXISTS(SELECT 1 FROM faturas WHERE aluno_id = ${id} AND status = 'vencida' AND vencimento < CURDATE() - INTERVAL 5 DAY) AS inadimplente,
          (SELECT COUNT(*) FROM faturas WHERE aluno_id = ${id} AND status IN ('aberta','vencida')) AS abertas`;
        return json({ aluno_id: id, inadimplente: Boolean(row.inadimplente), faturas_abertas: Number(row.abertas) });
      },
      "/webhooks/pagamento": { POST: webhook },
    },
  });
}
