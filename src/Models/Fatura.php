<?php

declare(strict_types=1);

namespace EstudaFitHub\Models;

use EstudaFitHub\Support\Model;

final class Fatura extends Model
{
    protected static string $tabela = 'faturas';

    public function aluno(): ?Aluno
    {
        return Aluno::find((int) $this->aluno_id);
    }

    public function matricula(): ?Matricula
    {
        return Matricula::find((int) $this->matricula_id);
    }
}
