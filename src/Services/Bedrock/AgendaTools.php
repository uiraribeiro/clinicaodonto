<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use PDO;

/**
 * Ferramentas que a IA pode chamar para consultar e propor alterações na agenda.
 * Cada tool lê dados reais do banco; propostas de escrita exigem aprovação humana.
 */
final class AgendaTools
{
    public function __construct(private readonly PDO $pdo) {}

    // =========================================================================
    // Especificações das ferramentas (formato Amazon Nova)
    // =========================================================================

    public function getSpecs(): array
    {
        return [
            ['toolSpec' => [
                'name'        => 'ver_agenda_semana',
                'description' => 'Retorna todos os agendamentos de uma semana do semestre ativo.',
                'inputSchema' => ['json' => [
                    'type'       => 'object',
                    'properties' => ['numero_semana' => ['type' => 'integer', 'description' => 'Semana 1–20']],
                    'required'   => ['numero_semana'],
                ]],
            ]],
            ['toolSpec' => [
                'name'        => 'listar_turmas',
                'description' => 'Lista turmas ativas com suas disciplinas vinculadas.',
                'inputSchema' => ['json' => ['type' => 'object', 'properties' => new \stdClass()]],
            ]],
            ['toolSpec' => [
                'name'        => 'listar_professores',
                'description' => 'Lista professores e as disciplinas que estão habilitados a lecionar.',
                'inputSchema' => ['json' => ['type' => 'object', 'properties' => new \stdClass()]],
            ]],
            ['toolSpec' => [
                'name'        => 'verificar_disponibilidade',
                'description' => 'Verifica se clínica ou laboratório está livre num dado dia/horário da agenda ativa.',
                'inputSchema' => ['json' => [
                    'type'       => 'object',
                    'properties' => [
                        'espaco_tipo'   => ['type' => 'string', 'enum' => ['clinica', 'laboratorio']],
                        'numero_semana' => ['type' => 'integer'],
                        'dia_semana'    => ['type' => 'integer', 'description' => '1=Segunda … 6=Sábado'],
                        'hora_inicio'   => ['type' => 'string', 'description' => 'HH:MM'],
                        'hora_fim'      => ['type' => 'string', 'description' => 'HH:MM'],
                    ],
                    'required' => ['espaco_tipo', 'numero_semana', 'dia_semana', 'hora_inicio', 'hora_fim'],
                ]],
            ]],
            ['toolSpec' => [
                'name'        => 'propor_agendamento',
                'description' => 'Valida e propõe um NOVO agendamento. O resultado precisa de aprovação humana para ser aplicado.',
                'inputSchema' => ['json' => [
                    'type'       => 'object',
                    'properties' => [
                        'turma_id'      => ['type' => 'integer'],
                        'disciplina_id' => ['type' => 'integer'],
                        'professor_id'  => ['type' => 'integer'],
                        'espaco_tipo'   => ['type' => 'string', 'enum' => ['clinica', 'laboratorio']],
                        'numero_semana' => ['type' => 'integer'],
                        'dia_semana'    => ['type' => 'integer', 'description' => '1=Segunda … 6=Sábado'],
                        'hora_inicio'   => ['type' => 'string', 'description' => 'HH:MM'],
                        'hora_fim'      => ['type' => 'string', 'description' => 'HH:MM'],
                    ],
                    'required' => ['turma_id', 'disciplina_id', 'professor_id', 'espaco_tipo', 'numero_semana', 'dia_semana', 'hora_inicio', 'hora_fim'],
                ]],
            ]],
            ['toolSpec' => [
                'name'        => 'propor_remocao',
                'description' => 'Propõe a remoção de um agendamento existente. Requer aprovação humana.',
                'inputSchema' => ['json' => [
                    'type'       => 'object',
                    'properties' => [
                        'agendamento_id' => ['type' => 'integer'],
                        'motivo'         => ['type' => 'string'],
                    ],
                    'required' => ['agendamento_id', 'motivo'],
                ]],
            ]],
        ];
    }

    // =========================================================================
    // Execução das ferramentas
    // =========================================================================

    public function executar(string $nome, array $input): string
    {
        try {
            $resultado = match ($nome) {
                'ver_agenda_semana'        => $this->verAgendaSemana((int)($input['numero_semana'] ?? 1)),
                'listar_turmas'            => $this->listarTurmas(),
                'listar_professores'       => $this->listarProfessores(),
                'verificar_disponibilidade'=> $this->verificarDisponibilidade($input),
                'propor_agendamento'       => $this->proporAgendamento($input),
                'propor_remocao'           => $this->proporRemocao($input),
                default                    => ['erro' => "Ferramenta desconhecida: {$nome}"],
            };
        } catch (\Throwable $e) {
            $resultado = ['erro' => $e->getMessage()];
        }
        return json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // Aplicação de propostas (após aprovação humana)
    // =========================================================================

    public function aplicar(array $proposta, int $usuarioId): array
    {
        return match ($proposta['tipo'] ?? '') {
            'novo_agendamento'   => $this->aplicarAgendamento($proposta, $usuarioId),
            'remover_agendamento'=> $this->aplicarRemocao($proposta, $usuarioId),
            default => ['erro' => 'Tipo de proposta desconhecido.'],
        };
    }

    // =========================================================================
    // Implementações internas
    // =========================================================================

    private function verAgendaSemana(int $semana): array
    {
        $versaoId = $this->getVersaoAtivaId();
        if (!$versaoId) return ['erro' => 'Nenhuma versão de agenda ativa/publicada.'];

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.dia_semana, a.hora_inicio, a.hora_fim,
                    t.nome AS turma, d.nome AS disciplina,
                    p.nome AS professor, a.espaco_tipo, a.num_alunos, a.status
             FROM agendamentos a
             JOIN turmas t ON t.id = a.turma_id
             JOIN disciplinas d ON d.id = a.disciplina_id
             JOIN professores p ON p.id = a.professor_id
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             WHERE a.versao_id = ? AND ss.numero_semana = ? AND a.status != "cancelado"
             ORDER BY a.dia_semana, a.hora_inicio'
        );
        $stmt->execute([$versaoId, $semana]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dias = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
        foreach ($rows as &$r) {
            $r['dia_nome'] = $dias[(int)$r['dia_semana']] ?? $r['dia_semana'];
        }
        return ['semana' => $semana, 'total' => count($rows), 'agendamentos' => $rows];
    }

    private function listarTurmas(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.id, t.nome, t.numero_alunos, t.periodo,
                    GROUP_CONCAT(d.nome ORDER BY d.nome SEPARATOR ", ") AS disciplinas
             FROM turmas t
             LEFT JOIN turma_disciplina td ON td.turma_id = t.id
             LEFT JOIN disciplinas d ON d.id = td.disciplina_id
             WHERE t.ativo = 1
             GROUP BY t.id ORDER BY t.nome'
        );
        return ['turmas' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function listarProfessores(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.id, p.nome,
                    GROUP_CONCAT(d.nome ORDER BY d.nome SEPARATOR ", ") AS disciplinas_habilitadas
             FROM professores p
             LEFT JOIN professor_disciplina pd ON pd.professor_id = p.id
             LEFT JOIN disciplinas d ON d.id = pd.disciplina_id
             WHERE p.ativo = 1
             GROUP BY p.id ORDER BY p.nome'
        );
        return ['professores' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function verificarDisponibilidade(array $in): array
    {
        $versaoId = $this->getVersaoAtivaId();
        if (!$versaoId) return ['disponivel' => false, 'motivo' => 'Sem versão ativa.'];

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             JOIN agenda_versoes av ON av.semestre_id = ss.semestre_id
             WHERE av.id = ? AND a.espaco_tipo = ? AND ss.numero_semana = ?
               AND a.dia_semana = ? AND a.status != "cancelado"
               AND a.hora_inicio < ? AND a.hora_fim > ?'
        );
        $stmt->execute([$versaoId, $in['espaco_tipo'], $in['numero_semana'], $in['dia_semana'], $in['hora_fim'], $in['hora_inicio']]);
        $conflitos = (int)$stmt->fetchColumn();

        return [
            'disponivel'  => $conflitos === 0,
            'conflitos'   => $conflitos,
            'espaco_tipo' => $in['espaco_tipo'],
            'semana'      => $in['numero_semana'],
            'dia_semana'  => $in['dia_semana'],
            'horario'     => ($in['hora_inicio'] ?? '') . '–' . ($in['hora_fim'] ?? ''),
        ];
    }

    private function proporAgendamento(array $in): array
    {
        $versaoId = $this->getVersaoAtivaId();
        if (!$versaoId) return ['erro' => 'Nenhuma versão de agenda ativa.'];

        $turma      = $this->buscar('turmas', (int)$in['turma_id']);
        $disciplina = $this->buscar('disciplinas', (int)$in['disciplina_id']);
        $professor  = $this->buscar('professores', (int)$in['professor_id']);

        if (!$turma)      return ['erro' => 'Turma não encontrada: id=' . $in['turma_id']];
        if (!$disciplina) return ['erro' => 'Disciplina não encontrada: id=' . $in['disciplina_id']];
        if (!$professor)  return ['erro' => 'Professor não encontrado: id=' . $in['professor_id']];

        // Busca semana_id e data_inicio
        $stmt = $this->pdo->prepare(
            'SELECT ss.id, ss.data_inicio
             FROM semanas_semestre ss
             JOIN agenda_versoes av ON av.semestre_id = ss.semestre_id
             WHERE av.id = ? AND ss.numero_semana = ? LIMIT 1'
        );
        $stmt->execute([$versaoId, $in['numero_semana']]);
        $semana = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$semana) return ['erro' => "Semana {$in['numero_semana']} não encontrada na agenda ativa."];

        // Calcula data_aula a partir do dia da semana
        $dataBase = new \DateTime($semana['data_inicio']);
        $dataBase->modify('+' . ((int)$in['dia_semana'] - 1) . ' days');
        $dataAula = $dataBase->format('Y-m-d');

        // Pega primeiro espaço disponível
        $espacoTable = $in['espaco_tipo'] === 'clinica' ? 'clinicas' : 'laboratorios';
        $espacoRow   = $this->pdo->query("SELECT id FROM {$espacoTable} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $espacoId    = (int)($espacoRow['id'] ?? 1);

        // Verifica conflitos
        $disp = $this->verificarDisponibilidade([
            'espaco_tipo'   => $in['espaco_tipo'],
            'numero_semana' => $in['numero_semana'],
            'dia_semana'    => $in['dia_semana'],
            'hora_inicio'   => $in['hora_inicio'],
            'hora_fim'      => $in['hora_fim'],
        ]);

        $proposta = [
            'tipo'           => 'novo_agendamento',
            'versao_id'      => $versaoId,
            'semana_id'      => (int)$semana['id'],
            'turma_id'       => (int)$in['turma_id'],
            'disciplina_id'  => (int)$in['disciplina_id'],
            'professor_id'   => (int)$in['professor_id'],
            'espaco_tipo'    => $in['espaco_tipo'],
            'espaco_id'      => $espacoId,
            'dia_semana'     => (int)$in['dia_semana'],
            'data_aula'      => $dataAula,
            'hora_inicio'    => $in['hora_inicio'],
            'hora_fim'       => $in['hora_fim'],
            'num_alunos'     => (int)($turma['numero_alunos'] ?? 0),
            'status'         => 'agendado',
            'gerado_por_ia'  => 1,
            // Labels legíveis
            'turma_nome'      => $turma['nome'],
            'disciplina_nome' => $disciplina['nome'],
            'professor_nome'  => $professor['nome'],
            'semana_numero'   => (int)$in['numero_semana'],
            'conflitos_espaco'=> $disp['conflitos'],
        ];

        return ['proposta' => $proposta, 'valida' => $disp['disponivel']];
    }

    private function proporRemocao(array $in): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, t.nome AS turma, d.nome AS disciplina,
                    a.dia_semana, a.hora_inicio, a.hora_fim, ss.numero_semana
             FROM agendamentos a
             JOIN turmas t ON t.id = a.turma_id
             JOIN disciplinas d ON d.id = a.disciplina_id
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             WHERE a.id = ?'
        );
        $stmt->execute([(int)$in['agendamento_id']]);
        $ag = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ag) return ['erro' => 'Agendamento não encontrado: id=' . $in['agendamento_id']];

        $dias = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado'];
        return [
            'proposta' => [
                'tipo'           => 'remover_agendamento',
                'agendamento_id' => (int)$in['agendamento_id'],
                'motivo'         => $in['motivo'] ?? '',
                'descricao'      => sprintf(
                    '%s / %s — Semana %d, %s, %s–%s',
                    $ag['turma'], $ag['disciplina'],
                    $ag['numero_semana'],
                    $dias[(int)$ag['dia_semana']] ?? $ag['dia_semana'],
                    $ag['hora_inicio'], $ag['hora_fim']
                ),
            ],
        ];
    }

    private function aplicarAgendamento(array $p, int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO agendamentos
                (versao_id, semana_id, turma_id, disciplina_id, espaco_tipo, espaco_id,
                 professor_id, dia_semana, data_aula, hora_inicio, hora_fim,
                 num_alunos, status, gerado_por_ia, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $p['versao_id'], $p['semana_id'], $p['turma_id'], $p['disciplina_id'],
            $p['espaco_tipo'], $p['espaco_id'], $p['professor_id'],
            $p['dia_semana'], $p['data_aula'], $p['hora_inicio'], $p['hora_fim'],
            $p['num_alunos'], 'agendado', 1, $usuarioId, $usuarioId,
        ]);
        return ['sucesso' => 'Agendamento criado com sucesso.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function aplicarRemocao(array $p, int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE agendamentos SET status="cancelado", observacoes=?, updated_by=? WHERE id=?'
        );
        $stmt->execute([$p['motivo'] ?? 'Removido por sugestão da IA', $usuarioId, $p['agendamento_id']]);
        return ['sucesso' => 'Agendamento cancelado com sucesso.'];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function getVersaoAtivaId(): ?int
    {
        $stmt = $this->pdo->query(
            'SELECT av.id FROM agenda_versoes av
             JOIN semestres s ON s.id = av.semestre_id
             WHERE s.status = "ativo" AND av.status = "publicada"
             ORDER BY av.numero_versao DESC LIMIT 1'
        );
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function buscar(string $tabela, int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabela} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
