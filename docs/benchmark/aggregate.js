// Mediana das rodadas por stack/cenário. Uso: node aggregate.js
const fs = require("fs");
const path = require("path");
const dir = path.join(__dirname, "results");
const med = (xs) => {
  const s = xs.filter((x) => x != null).sort((a, b) => a - b);
  return s.length ? s[Math.floor((s.length - 1) / 2)] : null;
};
const mib = (v) => (v ? parseFloat(v) * (v.includes("GiB") ? 1024 : v.includes("KiB") ? 1 / 1024 : 1) : null);

const dados = {};
for (const f of fs.readdirSync(dir).filter((f) => f.endsWith(".jsonl"))) {
  const [, stack] = f.match(/^(.*)-r\d+\.jsonl$/) ?? [];
  if (!stack) continue;
  const d = (dados[stack] ??= {});
  for (const linha of fs.readFileSync(path.join(dir, f), "utf8").split("\n").filter(Boolean)) {
    let o;
    try { o = JSON.parse(linha); } catch { continue; }
    const push = (k, v) => (d[k] ??= []).push(v);
    if (o.res?.rps != null) {
      push(`${o.cenario}.rps`, o.res.rps);
      push(`${o.cenario}.p95`, o.res.p95);
      push(`${o.cenario}.p99`, o.res.p99);
      push(`${o.cenario}.falhas`, o.res.falhas);
    }
    if (o.cenario === "cron_5000" && o.res?.segundos != null) push("cron.s", o.res.segundos), push("cron.geradas", o.res.geradas);
    if (o.cenario === "mem_idle") push("mem_idle", mib(o.valor));
    if (o.cenario === "mem_carga") push("mem_carga", mib(o.valor)), push("cpu", parseFloat(o.cpu));
    if (o.cenario === "consistencia") push("consistente", o.pagamentos === o.outbox ? 1 : 0);
  }
}

const f0 = (x) => (x == null ? "-" : Math.round(x).toLocaleString("pt-BR"));
const f1 = (x) => (x == null ? "-" : x.toFixed(1));
const linhas = [];
linhas.push("| Stack | /health rps | Leitura c8 rps (p95) | Leitura c64 rps (p95) | Webhook c64 rps (p95/p99) | Cron 5000 (s) | RAM ociosa / sob carga (MiB) | Falhas | Rodadas |");
linhas.push("|---|---|---|---|---|---|---|---|---|");
for (const [stack, d] of Object.entries(dados).sort((a, b) => (med(b[1]["situacao_c64.rps"]) ?? 0) - (med(a[1]["situacao_c64.rps"]) ?? 0))) {
  const m = (k) => med(d[k] ?? []);
  const falhas = Object.entries(d).filter(([k]) => k.endsWith(".falhas")).flatMap(([, v]) => v).reduce((a, b) => a + b, 0);
  const rodadas = (d["situacao_c64.rps"] ?? []).length;
  const consist = (d.consistente ?? []).every(Boolean) ? "" : " (inconsistente!)";
  linhas.push(
    `| ${stack} | ${f0(m("health.rps"))} | ${f0(m("situacao_c8.rps"))} (${f1(m("situacao_c8.p95"))} ms) | ${f0(m("situacao_c64.rps"))} (${f1(m("situacao_c64.p95"))} ms) | ${f0(m("webhook_c64.rps"))} (${f1(m("webhook_c64.p95"))}/${f1(m("webhook_c64.p99"))} ms) | ${f1(m("cron.s"))} | ${f0(m("mem_idle"))} / ${f0(m("mem_carga"))} | ${falhas}${consist} | ${rodadas} |`,
  );
}
console.log(linhas.join("\n"));
console.log("\nFaixa (min–max) da leitura c64 e do webhook por stack:");
for (const [stack, d] of Object.entries(dados)) {
  const r = (k) => (d[k]?.length ? `${f0(Math.min(...d[k]))}–${f0(Math.max(...d[k]))}` : "-");
  console.log(`  ${stack}: leitura ${r("situacao_c64.rps")} | webhook ${r("webhook_c64.rps")} | cron ${d["cron.s"]?.join(", ") ?? "-"} s`);
}
