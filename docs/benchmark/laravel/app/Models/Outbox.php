<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    const CREATED_AT = 'criado_em';
    const UPDATED_AT = null;

    protected $table = 'outbox';

    protected $fillable = ['tipo', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
