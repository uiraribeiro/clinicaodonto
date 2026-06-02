<?php
declare(strict_types=1);

namespace App\Validators;

class PreceptorValidator
{
    /**
     * Valida dados de preceptor.
     * Retorna array de erros (vazio = válido).
     * Não depende de dados externos — só regras de formato e tamanho.
     *
     * @param array    $data        Dados submetidos pelo formulário.
     * @param int|null $idIgnorar   ID do preceptor em edição (reservado para futuros checks de unicidade).
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

        // Máximo de turmas simultâneas
        $maxTurmas = $data['max_turmas_simultaneas'] ?? '';
        if ($maxTurmas === '' || $maxTurmas === null) {
            $errors['max_turmas_simultaneas'] = 'Informe o máximo de turmas simultâneas.';
        } else {
            $maxTurmasInt = (int) $maxTurmas;
            if (!ctype_digit((string) $maxTurmas) && !is_int($maxTurmas)) {
                // accepts "3" or 3, rejects "3.5" or "abc"
                if (!preg_match('/^\d+$/', (string) $maxTurmas)) {
                    $errors['max_turmas_simultaneas'] = 'O máximo de turmas deve ser um número inteiro.';
                } else {
                    $maxTurmasInt = (int) $maxTurmas;
                }
            }
            if (!isset($errors['max_turmas_simultaneas'])) {
                if ($maxTurmasInt < 1 || $maxTurmasInt > 10) {
                    $errors['max_turmas_simultaneas'] = 'O máximo de turmas simultâneas deve ser entre 1 e 10.';
                }
            }
        }

        return $errors;
    }
}
