<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use PDO;

/**
 * Constrói prompts contextualizados em português para o Bedrock.
 * Lê dados do banco para enriquecer o contexto da IA com informações reais do semestre.
 */
final class PromptBuilder
{
    public function __construct(private readonly PDO $pdo) {}

    // =========================================================================
    // System prompts
    // =========================================================================

    public function systemSugestoes(): string
    {
        return <<<SYSTEM
        Você é um assistente especializado em otimização de agendas acadêmicas de clínicas odontológicas universitárias.
        Seu papel é analisar conflitos e gargalos no cronograma e sugerir redistribuições que respeitem as restrições operacionais.

        IMPORTANTE: Responda SOMENTE com um objeto JSON válido no formato especificado. Não inclua texto fora do JSON.

        Formato de resposta obrigatório:
        {
          "sugestoes": [
            {
              "tipo": "string",
              "prioridade": "critica|alta|media|baixa",
              "problema_identificado": "string descritivo",
              "sugestao": "string com ação concreta",
              "impacto_esperado": "string com resultado esperado",
              "acao": {
                "tipo": "mover_agendamento|redistribuir_turma|sugerir_novo_slot|remover_conflito",
                "descricao": "string detalhada"
              }
            }
          ],
          "resumo_analise": "string com visão geral"
        }
        SYSTEM;
    }

    public function systemChat(): string
    {
        return <<<SYSTEM
        Você é o assistente de planejamento acadêmico do sistema Odonto Scheduler.
        Auxilia coordenadores e secretarias na gestão de horários da clínica e laboratório odontológico.
        Responda sempre em português brasileiro, de forma objetiva e prática.
        Quando sugerir mudanças concretas de agenda, deixe claro que são sugestões que precisam de aprovação humana antes de serem aplicadas.
        SYSTEM;
    }

    // =========================================================================
    // Prompts para análise de conflitos
    // =========================================================================

    /**
     * Prompt completo para análise de conflitos de uma versão de agenda.
     */
    public function promptAnaliseConflitos(int $versaoId): string
    {
        $conflitos    = $this->loadConflitos($versaoId);
        $gargalos     = $this->loadGargalos($versaoId);
        $estatisticas = $this->loadEstatisticasVersao($versaoId);
        $semestre     = $this->loadSemestreVersao($versaoId);

        $conflitosJson    = json_encode($conflitos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $gargalosJson     = json_encode($gargalos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $estatisticasJson = json_encode($estatisticas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        Analise a agenda do semestre {$semestre} e gere sugestões para resolver os conflitos e gargalos detectados.

        CONFIGURAÇÃO DA CLÍNICA:
        - 15 cadeiras × 2 alunos = 30 alunos máximo por turno
        - Laboratório: 30 assentos

        ESTATÍSTICAS DA VERSÃO:
        {$estatisticasJson}

        CONFLITOS DETECTADOS ({$conflitos['total']} conflitos):
        {$conflitosJson}

        GARGALOS POR SEMANA ({$gargalos['total']} semanas com sobrecarga):
        {$gargalosJson}

        Gere sugestões priorizando:
        1. Conflitos críticos de sobreposição de espaço
        2. Semanas com sobrecarga acima de 100% da capacidade
        3. Distribuição mais uniforme entre semanas
        PROMPT;
    }

    /**
     * Prompt para chat livre sobre a agenda.
     */
    public function promptContextoChat(int $versaoId, string $pergunta): string
    {
        $resumo  = $this->loadResumoParaChat($versaoId);
        $resumoJ = json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        Contexto da agenda atual:
        {$resumoJ}

        Pergunta do usuário:
        {$pergunta}
        PROMPT;
    }

    // =========================================================================
    // Carregamento de dados do banco
    // =========================================================================

    private function loadConflitos(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo, severidade, descricao
             FROM conflitos
             WHERE versao_id = ? AND resolvido = 0
             ORDER BY FIELD(severidade, "critico","alto","medio","baixo")
             LIMIT 30'
        );
        $stmt->execute([$versaoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => count($rows), 'lista' => $rows];
    }

    private function loadGargalos(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ss.numero_semana, ss.data_inicio,
                    SUM(a.num_alunos) AS total_alunos,
                    COUNT(DISTINCT a.turma_id) AS turmas
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             WHERE a.versao_id = ? AND a.espaco_tipo = "clinica" AND a.status != "cancelado"
             GROUP BY ss.numero_semana, ss.data_inicio
             HAVING total_alunos > 30
             ORDER BY total_alunos DESC
             LIMIT 10'
        );
        $stmt->execute([$versaoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => count($rows), 'semanas' => $rows];
    }

    private function loadEstatisticasVersao(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT score_ocupacao, total_conflitos, numero_versao, status
             FROM agenda_versoes WHERE id = ?'
        );
        $stmt->execute([$versaoId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $contagem = $this->pdo->prepare(
            'SELECT
               COUNT(*)                                               AS total_agendamentos,
               COUNT(DISTINCT turma_id)                              AS turmas,
               COUNT(DISTINCT disciplina_id)                         AS disciplinas,
               SUM(CASE WHEN espaco_tipo="clinica"     THEN 1 END)   AS slots_clinica,
               SUM(CASE WHEN espaco_tipo="laboratorio" THEN 1 END)   AS slots_lab,
               SUM(num_alunos)                                       AS total_alunos_alocados
             FROM agendamentos WHERE versao_id = ? AND status != "cancelado"'
        );
        $contagem->execute([$versaoId]);
        $c = $contagem->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_merge($v, $c);
    }

    private function loadSemestreVersao(int $versaoId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.referencia FROM agenda_versoes av JOIN semestres s ON s.id = av.semestre_id WHERE av.id = ?'
        );
        $stmt->execute([$versaoId]);
        return (string)($stmt->fetchColumn() ?: 'desconhecido');
    }

    private function loadResumoParaChat(int $versaoId): array
    {
        return [
            'versao'       => $this->loadEstatisticasVersao($versaoId),
            'semestre'     => $this->loadSemestreVersao($versaoId),
            'conflitos'    => $this->loadConflitos($versaoId),
            'top_gargalos' => $this->loadGargalos($versaoId),
        ];
    }
}
