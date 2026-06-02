<?php
declare(strict_types=1);

namespace App\Validators;

class ProfessorValidator
{
    /**
     * Valida dados de professor.
     * Retorna array de erros (vazio = válido).
     * Não depende de dados externos — só regras de formato e tamanho.
     *
     * @param array    $data        Dados submetidos pelo formulário.
     * @param int|null $idIgnorar   ID do professor em edição (reservado para futuros checks de unicidade).
     */
    public static function validate(array $data, ?int $idIgnorar = null): array
    {
        $errors = [];

        // Nome
        $nome = trim($data['nome'] ?? '');
        if ($nome === '') {
            $errors['nome'] = 'O nome é obrigatório.';
        } elseif (mb_strlen($nome) > 150) {
            $errors['nome'] = 'O nome deve ter no máximo 150 caracteres.';
        }

        // E-mail
        $email = trim($data['email'] ?? '');
        if ($email === '') {
            $errors['email'] = 'O e-mail é obrigatório.';
        } elseif (mb_strlen($email) > 150) {
            $errors['email'] = 'O e-mail deve ter no máximo 150 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Informe um e-mail válido.';
        }

        // Telefone (opcional)
        $telefone = trim($data['telefone'] ?? '');
        if ($telefone !== '') {
            if (mb_strlen($telefone) > 20) {
                $errors['telefone'] = 'O telefone deve ter no máximo 20 caracteres.';
            } elseif (!preg_match('/^[\d()\s\-]+$/', $telefone)) {
                $errors['telefone'] = 'Telefone inválido. Use apenas números, parênteses, espaço e hífen.';
            }
        }

        // Matrícula (opcional)
        $matricula = trim($data['matricula'] ?? '');
        if ($matricula !== '') {
            if (mb_strlen($matricula) > 30) {
                $errors['matricula'] = 'A matrícula deve ter no máximo 30 caracteres.';
            } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $matricula)) {
                $errors['matricula'] = 'Matrícula inválida. Use apenas letras, números e hífens.';
            }
        }

        return $errors;
    }
}
