<?php

declare(strict_types=1);

namespace EstudaFitHub\Models;

use EstudaFitHub\Support\Model;

final class Aluno extends Model
{
    protected static string $tabela = 'alunos';

    public function unidade(): ?Unidade
    {
        return Unidade::find((int) $this->unidade_id);
    }

    public function matriculaAtiva(): ?Matricula
    {
        /** @var ?Matricula $m */
        $m = Matricula::where('aluno_id', $this->id)->where('status', 'ativa')->first();

        return $m;
    }
}
