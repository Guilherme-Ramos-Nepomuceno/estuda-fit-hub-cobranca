package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"time"
)

// Gateway de pagamento simulado: cada registro de cobrança leva LATENCIA_MS.
func main() {
	ms, _ := strconv.Atoi(os.Getenv("LATENCIA_MS"))
	http.HandleFunc("POST /cobrancas", func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			FaturaID int `json:"fatura_id"`
		}
		json.NewDecoder(r.Body).Decode(&in)
		time.Sleep(time.Duration(ms) * time.Millisecond)
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"ref":"gwc_%d"}`, in.FaturaID)
	})
	log.Fatal(http.ListenAndServe(":9000", nil))
}
