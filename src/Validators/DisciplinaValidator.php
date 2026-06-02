<?php
declare(strict_types=1);

namespace App\Validators;

class DisciplinaValidator
{
    /**
     * Valida dados de disciplina.
     * Retorna array de erros (vazio = válido).
     * Não depende de dados externos — só regras de formato e range.
     */
    public static function validate(array $data, ?int $idIgnorar = null): array
    {
        $errors = [];

        // Nome
        $nome = trim($data['nome'] ?? '');
        if (empty($nome)) {
            $errors['nome'] = 'O nome é obrigatório.';
        } elseif (mb_strlen($nome) > 200) {
            $errors['nome'] = 'O nome deve ter no máximo 200 caracteres.';
        }

        // Código
        $codigo = strtoupper(trim($data['codigo'] ?? ''));
        if (empty($codigo)) {
            $errors['codigo'] = 'O código é obrigatório.';
        } elseif (!preg_match('/^[A-Z0-9\-]{2,20}$/', $codigo)) {
            $errors['codigo'] = 'Código inválido. Use apenas letras maiúsculas, números e hífens (2-20 chars).';
        }

        // Tipo
        if (!in_array($data['tipo'] ?? '', ['estagio', 'pratica_comum'], true)) {
            $errors['tipo'] = 'Tipo inválido. Escolha "Estágio" ou "Prática Comum".';
        }

        // Carga horária
        $carga = (int) ($data['carga_horaria_pratica'] ?? 0);
        if ($carga < 1 || $carga > 400) {
            $errors['carga_horaria_pratica'] = 'Carga horária deve ser entre 1 e 400 horas.';
        }

        // Espaço (ao menos um deve ser marcado)
        if (empty($data['usa_clinica']) && empty($data['usa_laboratorio'])) {
            $errors['espaco'] = 'Selecione ao menos um espaço: Clínica ou Laboratório.';
        }

        // Duração do encontro (em minutos)
        $duracao = (int) ($data['duracao_encontro_min'] ?? 0);
        if ($duracao < 30 || $duracao > 480) {
            $errors['duracao_encontro_min'] = 'Duração do encontro deve ser entre 30 e 480 minutos.';
        }

        // Semanas
        $semanaInicio = (int) ($data['semana_inicio'] ?? 0);
        $semanaFim    = (int) ($data['semana_fim'] ?? 0);
        if ($semanaInicio < 1 || $semanaInicio > 20) {
            $errors['semana_inicio'] = 'Semana inicial deve ser entre 1 e 20.';
        }
        if ($semanaFim < 1 || $semanaFim > 20) {
            $errors['semana_fim'] = 'Semana final deve ser entre 1 e 20.';
        }
        if (empty($errors['semana_inicio']) && empty($errors['semana_fim']) && $semanaFim < $semanaInicio) {
            $errors['semana_fim'] = 'Semana final deve ser maior ou igual à semana inicial.';
        }

        // Prioridade
        $prioridade = (int) ($data['prioridade'] ?? 5);
        if ($prioridade < 1 || $prioridade > 10) {
            $errors['prioridade'] = 'Prioridade deve ser entre 1 (máxima) e 10 (mínima).';
        }

        // Mínimo de encontros
        $minEncontros = (int) ($data['minimo_encontros'] ?? 0);
        if ($minEncontros < 1 || $minEncontros > 40) {
            $errors['minimo_encontros'] = 'Mínimo de encontros deve ser entre 1 e 40.';
        }

        return $errors;
    }
}
