package main

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

var db *sql.DB

func main() {
	var err error
	db, err = sql.Open("mysql", os.Getenv("DSN"))
	if err != nil {
		log.Fatal(err)
	}
	db.SetMaxOpenConns(50)
	db.SetMaxIdleConns(50)
	db.SetConnMaxLifetime(5 * time.Minute)

	if len(os.Args) > 1 && os.Args[1] == "gerar" {
		gerar(os.Args[2], 50)
		return
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, map[string]any{"ok": true})
	})
	mux.HandleFunc("GET /alunos/{id}/situacao", situacao)
	mux.HandleFunc("POST /webhooks/pagamento", webhook)
	log.Fatal(http.ListenAndServe(":8080", mux))
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

// Consulta usada pelo check-in: o aluno está inadimplente há mais de 5 dias?
func situacao(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.Atoi(r.PathValue("id"))
	if err != nil {
		writeJSON(w, 400, map[string]string{"erro": "id inválido"})
		return
	}
	var inadimplente bool
	var abertas int
	err = db.QueryRowContext(r.Context(), `SELECT
		EXISTS(SELECT 1 FROM faturas WHERE aluno_id = ? AND status = 'vencida' AND vencimento < CURDATE() - INTERVAL 5 DAY),
		(SELECT COUNT(*) FROM faturas WHERE aluno_id = ? AND status IN ('aberta','vencida'))`, id, id).Scan(&inadimplente, &abertas)
	if err != nil {
		writeJSON(w, 500, map[string]string{"erro": err.Error()})
		return
	}
	writeJSON(w, 200, map[string]any{"aluno_id": id, "inadimplente": inadimplente, "faturas_abertas": abertas})
}

type webhookBody struct {
	Status string  `json:"status"`
	Amount float64 `json:"amount"`
	Method string  `json:"method"`
}

var errNaoEncontrada = errors.New("fatura não encontrada")

// Webhook idempotente: registra o event_id, baixa a fatura e grava o evento no outbox, tudo na mesma transação.
func webhook(w http.ResponseWriter, r *http.Request) {
	ref, evt := r.URL.Query().Get("reference"), r.URL.Query().Get("event_id")
	var body webhookBody
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || ref == "" || evt == "" {
		writeJSON(w, 400, map[string]string{"erro": "payload inválido"})
		return
	}
	ctx := r.Context()
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		writeJSON(w, 500, map[string]string{"erro": err.Error()})
		return
	}
	defer tx.Rollback()

	duplicado, err := func() (bool, error) {
		res, err := tx.ExecContext(ctx, "INSERT IGNORE INTO webhook_eventos (event_id, reference) VALUES (?, ?)", evt, ref)
		if err != nil {
			return false, err
		}
		if n, _ := res.RowsAffected(); n == 0 {
			return true, nil
		}
		var id int
		var status string
		var valor float64
		err = tx.QueryRowContext(ctx, "SELECT id, status, valor FROM faturas WHERE gateway_ref = ? FOR UPDATE", ref).Scan(&id, &status, &valor)
		if errors.Is(err, sql.ErrNoRows) {
			return false, errNaoEncontrada
		}
		if err != nil {
			return false, err
		}
		if body.Status == "approved" && status != "paga" {
			if _, err := tx.ExecContext(ctx, "UPDATE faturas SET status = 'paga', pago_em = NOW(), updated_at = NOW() WHERE id = ?", id); err != nil {
				return false, err
			}
			if _, err := tx.ExecContext(ctx, "INSERT INTO pagamentos (fatura_id, valor, metodo, event_id) VALUES (?, ?, ?, ?)", id, body.Amount, body.Method, evt); err != nil {
				return false, err
			}
			payload, _ := json.Marshal(map[string]any{"fatura_id": id, "valor": body.Amount, "event_id": evt})
			if _, err := tx.ExecContext(ctx, "INSERT INTO outbox (tipo, payload) VALUES ('FaturaPaga', ?)", string(payload)); err != nil {
				return false, err
			}
		}
		return false, nil
	}()
	if errors.Is(err, errNaoEncontrada) {
		writeJSON(w, 404, map[string]string{"erro": err.Error()})
		return
	}
	if err != nil {
		writeJSON(w, 500, map[string]string{"erro": err.Error()})
		return
	}
	if err := tx.Commit(); err != nil {
		writeJSON(w, 500, map[string]string{"erro": err.Error()})
		return
	}
	writeJSON(w, 200, map[string]any{"ok": true, "duplicado": duplicado})
}

// Cron do dia 1º: gera a fatura da competência para cada contrato ativo, com N chamadas concorrentes ao gateway.
func gerar(competencia string, concorrencia int) {
	inicio := time.Now()
	rows, err := db.Query("SELECT matricula_id, aluno_id, valor_mensal, dia_vencimento FROM contratos WHERE ativo = 1")
	if err != nil {
		log.Fatal(err)
	}
	type contrato struct {
		matricula, aluno, dia int
		valor               float64
	}
	var contratos []contrato
	for rows.Next() {
		var c contrato
		rows.Scan(&c.matricula, &c.aluno, &c.valor, &c.dia)
		contratos = append(contratos, c)
	}
	rows.Close()

	client := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{MaxIdleConnsPerHost: concorrencia}}
	gateway := os.Getenv("GATEWAY_URL")
	fila := make(chan contrato)
	var wg sync.WaitGroup
	var mu sync.Mutex
	geradas := 0
	for i := 0; i < concorrencia; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for c := range fila {
				res, err := db.Exec(`INSERT IGNORE INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento)
					VALUES (?, ?, ?, ?, ?)`, c.matricula, c.aluno, competencia+"-01", c.valor, fmt.Sprintf("%s-%02d", competencia, c.dia))
				if err != nil {
					log.Fatal(err)
				}
				if n, _ := res.RowsAffected(); n == 0 {
					continue
				}
				id, _ := res.LastInsertId()
				body, _ := json.Marshal(map[string]any{"fatura_id": id, "valor": c.valor})
				resp, err := client.Post(gateway, "application/json", bytes.NewReader(body))
				if err != nil {
					log.Fatal(err)
				}
				var out struct{ Ref string `json:"ref"` }
				json.NewDecoder(resp.Body).Decode(&out)
				resp.Body.Close()
				if _, err := db.Exec("UPDATE faturas SET gateway_ref = ? WHERE id = ?", out.Ref, id); err != nil {
					log.Fatal(err)
				}
				mu.Lock()
				geradas++
				mu.Unlock()
			}
		}()
	}
	for _, c := range contratos {
		fila <- c
	}
	close(fila)
	wg.Wait()
	fmt.Printf("{\"geradas\":%d,\"segundos\":%.2f}\n", geradas, time.Since(inicio).Seconds())
}
