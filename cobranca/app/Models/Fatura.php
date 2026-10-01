<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fatura extends Model
{
    const CREATED_AT = 'criado_em';
    const UPDATED_AT = 'atualizado_em';

    protected $fillable = ['matricula_id', 'aluno_id', 'competencia', 'valor', 'vencimento', 'status', 'pago_em', 'gateway_ref'];

    protected $attributes = ['status' => 'aberta'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'competencia' => 'date', 'vencimento' => 'date', 'pago_em' => 'datetime'];
    }
}
