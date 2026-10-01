package main

// Gerador de carga de concorrência fixa (closed loop), keep-alive, com ids e event_ids aleatórios.
// Uso: loadgen -url 'http://app:8080/alunos/{id}/situacao' -c 64 -d 20s -warmup 5s

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
	url := flag.String("url", "", "URL com {id} e {evt} opcionais")
	method := flag.String("method", "GET", "")
	body := flag.String("body", "", "")
	c := flag.Int("c", 64, "concorrência")
	d := flag.Duration("d", 20*time.Second, "duração medida")
	warm := flag.Duration("warmup", 5*time.Second, "aquecimento não medido")
	idMin := flag.Int("idmin", 1, "")
	idMax := flag.Int("idmax", 250000, "")
	flag.Parse()

	client := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{MaxIdleConnsPerHost: *c, MaxConnsPerHost: *c}}
	var seq uint64
	var seqMu sync.Mutex
	build := func() string {
		u := strings.ReplaceAll(*url, "{id}", strconv.Itoa(*idMin+rand.IntN(*idMax-*idMin+1)))
		if strings.Contains(u, "{evt}") {
			seqMu.Lock()
			seq++
			s := seq
			seqMu.Unlock()
			u = strings.ReplaceAll(u, "{evt}", fmt.Sprintf("evt_%d_%d", time.Now().UnixNano(), s))
		}
		return u
	}

	run := func(dur time.Duration, record bool) ([]float64, int, int) {
		fim := time.Now().Add(dur)
		var mu sync.Mutex
		var all []float64
		ok, fail := 0, 0
		var wg sync.WaitGroup
		for i := 0; i < *c; i++ {
			wg.Add(1)
			go func() {
				defer wg.Done()
				var lat []float64
				o, f := 0, 0
				for time.Now().Before(fim) {
					req, _ := http.NewRequest(*method, build(), bytes.NewBufferString(*body))
					if *body != "" {
						req.Header.Set("Content-Type", "application/json")
					}
					t := time.Now()
					resp, err := client.Do(req)
					el := time.Since(t).Seconds() * 1000
					if err != nil {
						f++
						continue
					}
					io.Copy(io.Discard, resp.Body)
					resp.Body.Close()
					if resp.StatusCode >= 200 && resp.StatusCode < 300 {
						o++
					} else {
						f++
					}
					if record {
						lat = append(lat, el)
					}
				}
				mu.Lock()
				all = append(all, lat...)
				ok += o
				fail += f
				mu.Unlock()
			}()
		}
		wg.Wait()
		return all, ok, fail
	}

	run(*warm, false)
	lat, ok, fail := run(*d, true)
	sort.Float64s(lat)
	p := func(q float64) float64 {
		if len(lat) == 0 {
			return 0
		}
		return lat[int(float64(len(lat)-1)*q)]
	}
	json.NewEncoder(os.Stdout).Encode(map[string]any{
		"rps": float64(ok+fail) / d.Seconds(), "ok": ok, "falhas": fail,
		"p50": p(0.50), "p95": p(0.95), "p99": p(0.99), "max": p(1),
	})
}
