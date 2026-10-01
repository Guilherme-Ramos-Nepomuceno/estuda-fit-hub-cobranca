import mysql, { type ResultSetHeader, type RowDataPacket } from "mysql2/promise";

const pool = mysql.createPool({ uri: process.env.DB_URL!, connectionLimit: Number(process.env.POOL ?? 50) });

const json = (body: unknown, status = 200) => Response.json(body, { status });

type WebhookBody = { status: string; amount: number; method: string };

async function webhook(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const ref = url.searchParams.get("reference");
  const evt = url.searchParams.get("event_id");
  const body = (await req.json()) as WebhookBody;
  if (!ref || !evt) return json({ erro: "payload inválido" }, 400);

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [ins] = await conn.execute<ResultSetHeader>("INSERT IGNORE INTO webhook_eventos (event_id, reference) VALUES (?, ?)", [evt, ref]);
    if (ins.affectedRows === 0) {
      await conn.commit();
      return json({ ok: true, duplicado: true });
    }
    const [[fatura]] = await conn.execute<RowDataPacket[]>("SELECT id, status, valor FROM faturas WHERE gateway_ref = ? FOR UPDATE", [ref]);
    if (!fatura) {
      await conn.rollback();
      return json({ erro: "fatura não encontrada" }, 404);
    }
    if (body.status === "approved" && fatura.status !== "paga") {
      await conn.execute("UPDATE faturas SET status = 'paga', pago_em = NOW(), updated_at = NOW() WHERE id = ?", [fatura.id]);
      await conn.execute("INSERT INTO pagamentos (fatura_id, valor, metodo, event_id) VALUES (?, ?, ?, ?)", [fatura.id, body.amount, body.method, evt]);
      await conn.execute("INSERT INTO outbox (tipo, payload) VALUES ('FaturaPaga', ?)", [
        JSON.stringify({ fatura_id: fatura.id, valor: body.amount, event_id: evt }),
      ]);
    }
    await conn.commit();
    return json({ ok: true, duplicado: false });
  } catch (e) {
    await conn.rollback();
    return json({ erro: String(e) }, 500);
  } finally {
    conn.release();
  }
}

async function gerar(competencia: string, concorrencia: number) {
  const inicio = performance.now();
  const [contratos] = await pool.query<RowDataPacket[]>("SELECT matricula_id, aluno_id, valor_mensal, dia_vencimento FROM contratos WHERE ativo = 1");
  let geradas = 0;
  let i = 0;
  const worker = async () => {
    while (i < contratos.length) {
      const c = contratos[i++];
      const vencimento = `${competencia}-${String(c.dia_vencimento).padStart(2, "0")}`;
      const [res] = await pool.execute<ResultSetHeader>(
        "INSERT IGNORE INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento) VALUES (?, ?, ?, ?, ?)",
        [c.matricula_id, c.aluno_id, competencia + "-01", c.valor_mensal, vencimento],
      );
      if (res.affectedRows === 0) continue;
      const resp = await fetch(process.env.GATEWAY_URL!, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fatura_id: res.insertId, valor: c.valor_mensal }),
      });
      const { ref } = (await resp.json()) as { ref: string };
      await pool.execute("UPDATE faturas SET gateway_ref = ? WHERE id = ?", [ref, res.insertId]);
      geradas++;
    }
  };
  await Promise.all(Array.from({ length: concorrencia }, worker));
  console.log(JSON.stringify({ geradas, segundos: +((performance.now() - inicio) / 1000).toFixed(2) }));
  await pool.end();
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
        const [[row]] = await pool.execute<RowDataPacket[]>(
          `SELECT
            EXISTS(SELECT 1 FROM faturas WHERE aluno_id = ? AND status = 'vencida' AND vencimento < CURDATE() - INTERVAL 5 DAY) AS inadimplente,
            (SELECT COUNT(*) FROM faturas WHERE aluno_id = ? AND status IN ('aberta','vencida')) AS abertas`,
          [id, id],
        );
        return json({ aluno_id: id, inadimplente: Boolean(row.inadimplente), faturas_abertas: Number(row.abertas) });
      },
      "/webhooks/pagamento": { POST: webhook },
    },
  });
}
