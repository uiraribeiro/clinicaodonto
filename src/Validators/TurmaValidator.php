<?php
declare(strict_types=1);

namespace App\Validators;

class TurmaValidator
{
    /**
     * Valida dados de turma.
     * Retorna array de erros (vazio = válido).
     * Não depende de dados externos — apenas regras de formato e range.
     */
    public static function validate(array $data): array
    {
        $errors = [];

        // Nome
        $nome = trim($data['nome'] ?? '');
        if (empty($nome)) {
            $errors['nome'] = 'O nome é obrigatório.';
        } elseif (mb_strlen($nome) > 100) {
            $errors['nome'] = 'O nome deve ter no máximo 100 caracteres.';
        }

        // Curso
        $cursoId = (int) ($data['curso_id'] ?? 0);
        if ($cursoId <= 0) {
            $errors['curso_id'] = 'Selecione um curso válido.';
        }

        // Período (1–10)
        $periodo = (int) ($data['periodo'] ?? 0);
        if ($periodo < 1 || $periodo > 10) {
            $errors['periodo'] = 'O período deve ser entre 1 e 10.';
        }

        // Número de alunos (1–30: limite da capacidade da clínica)
        $numAlunos = (int) ($data['numero_alunos'] ?? 0);
        if ($numAlunos < 1 || $numAlunos > 30) {
            $errors['numero_alunos'] = 'O número de alunos deve ser entre 1 e 30.';
        }

        // Disciplina obrigatória (quando enviada pelo formulário completo)
        if (array_key_exists('disciplina_id', $data) && empty($data['disciplina_id'])) {
            $errors['disciplina_id'] = 'Selecione a disciplina da turma.';
        }

        return $errors;
    }
}
