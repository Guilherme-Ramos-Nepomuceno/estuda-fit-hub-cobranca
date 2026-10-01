<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fatura extends Model
{
    const CREATED_AT = 'criado_em';

    protected $fillable = ['matricula_id', 'aluno_id', 'competencia', 'valor', 'vencimento', 'status', 'pago_em', 'gateway_ref'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'pago_em' => 'datetime'];
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class);
    }
}
