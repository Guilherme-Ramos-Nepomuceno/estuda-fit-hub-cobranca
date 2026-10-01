// Gerador de carga para os testes de desempenho (PRD etapa 3, critério 10).
//
// Modo taxa fixa (-rps): N requisições por segundo, independentemente da latência.
// Placeholders na URL e no corpo: {id} (inteiro aleatório entre -idmin e -idmax) e
// {cpf} (o mesmo inteiro somado a -cpfbase, com 11 dígitos).
// Sai com código 1 se houver falhas ou se o p95 passar de -max-p95-ms.
//
// Exemplo:
//
//	carga -method POST -url http://monolito:8080/checkins \
//	      -body '{"cpf":"{cpf}","unidade_id":1}' -idmax 2000 -cpfbase 10000000000 \
//	      -rps 40 -d 60s -max-p95-ms 300
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"math/rand/v2"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

func main() {
	url := flag.String("url", "", "URL alvo")
	method := flag.String("method", "GET", "método HTTP")
	body := flag.String("body", "", "corpo da requisição")
	c := flag.Int("c", 32, "concorrência máxima")
	rps := flag.Int("rps", 0, "requisições por segundo (0 = sem limite)")
	d := flag.Duration("d", 60*time.Second, "duração medida")
	warm := flag.Duration("warmup", 5*time.Second, "aquecimento não medido")
	idMin := flag.Int("idmin", 1, "")
	idMax := flag.Int("idmax", 2000, "")
	cpfBase := flag.Int64("cpfbase", 10000000000, "")
	maxP95 := flag.Float64("max-p95-ms", 0, "falha se o p95 passar deste valor (0 = não verifica)")
	flag.Parse()

	client := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{MaxIdleConnsPerHost: *c}}
	preencher := func(s string, id int) string {
		s = strings.ReplaceAll(s, "{id}", strconv.Itoa(id))
		return strings.ReplaceAll(s, "{cpf}", fmt.Sprintf("%011d", *cpfBase+int64(id)))
	}

	run := func(dur time.Duration, medir bool) ([]float64, int, int) {
		fim := time.Now().Add(dur)
		var mu sync.Mutex
		var lat []float64
		ok, falhas := 0, 0
		vez := make(chan struct{})
		go func() {
			defer close(vez)
			if *rps <= 0 {
				for time.Now().Before(fim) {
					vez <- struct{}{}
				}
				return
			}
			t := time.NewTicker(time.Second / time.Duration(*rps))
			defer t.Stop()
			for time.Now().Before(fim) {
				<-t.C
				vez <- struct{}{}
			}
		}()

		var wg sync.WaitGroup
		for i := 0; i < *c; i++ {
			wg.Add(1)
			go func() {
				defer wg.Done()
				for range vez {
					id := *idMin + rand.IntN(*idMax-*idMin+1)
					req, _ := http.NewRequest(*method, preencher(*url, id), bytes.NewBufferString(preencher(*body, id)))
					if *body != "" {
						req.Header.Set("Content-Type", "application/json")
					}
					t := time.Now()
					resp, err := client.Do(req)
					ms := time.Since(t).Seconds() * 1000
					sucesso := err == nil && resp.StatusCode >= 200 && resp.StatusCode < 300
					if err == nil {
						io.Copy(io.Discard, resp.Body)
						resp.Body.Close()
					}
					mu.Lock()
					if sucesso {
						ok++
					} else {
						falhas++
					}
					if medir {
						lat = append(lat, ms)
					}
					mu.Unlock()
				}
			}()
		}
		wg.Wait()
		return lat, ok, falhas
	}

	run(*warm, false)
	lat, ok, falhas := run(*d, true)
	sort.Float64s(lat)
	p := func(q float64) float64 {
		if len(lat) == 0 {
			return 0
		}
		return lat[int(float64(len(lat)-1)*q)]
	}
	resultado := map[string]any{
		"rps": float64(ok+falhas) / d.Seconds(), "ok": ok, "falhas": falhas,
		"p50_ms": p(0.50), "p95_ms": p(0.95), "p99_ms": p(0.99), "max_ms": p(1),
	}
	json.NewEncoder(os.Stdout).Encode(resultado)

	if falhas > 0 || (*maxP95 > 0 && p(0.95) > *maxP95) {
		fmt.Fprintf(os.Stderr, "FALHOU: %d falha(s), p95 %.1f ms (limite %.0f ms)\n", falhas, p(0.95), *maxP95)
		os.Exit(1)
	}
}
