<?php

declare(strict_types=1);

namespace EstudaFitHub\Models;

use EstudaFitHub\Support\Model;

final class Matricula extends Model
{
    protected static string $tabela = 'matriculas';

    public function aluno(): ?Aluno
    {
        return Aluno::find((int) $this->aluno_id);
    }

    public function plano(): ?Plano
    {
        return Plano::find((int) $this->plano_id);
    }
}
