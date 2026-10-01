<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pagamento extends Model
{
    const CREATED_AT = 'criado_em';
    const UPDATED_AT = null;

    protected $fillable = ['fatura_id', 'valor', 'metodo', 'event_id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }
}
