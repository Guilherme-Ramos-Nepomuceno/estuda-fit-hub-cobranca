// Sobe N processos do serviço no mesmo porto (SO_REUSEPORT): o Bun executa JS em uma thread só.
const n = Number(process.env.PROCS ?? navigator.hardwareConcurrency);
for (let i = 0; i < n; i++) {
  Bun.spawn(["bun", "run", process.env.ENTRY!], { stdout: "inherit", stderr: "inherit", env: process.env });
}
