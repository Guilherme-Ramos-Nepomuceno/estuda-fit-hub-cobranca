<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Termos de cobrança de uma matrícula: valor contratado e dia de vencimento (ADR-005). */
class Contrato extends Model
{
    const CREATED_AT = 'criado_em';
    const UPDATED_AT = 'atualizado_em';

    protected $primaryKey = 'matricula_id';

    public $incrementing = false;

    protected $fillable = ['matricula_id', 'aluno_id', 'unidade_id', 'plano_id', 'valor_mensal', 'dia_vencimento', 'inicio', 'fim'];

    protected function casts(): array
    {
        return ['valor_mensal' => 'decimal:2', 'inicio' => 'date', 'fim' => 'date'];
    }
}
