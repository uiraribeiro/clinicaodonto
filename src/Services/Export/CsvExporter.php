<?php
declare(strict_types=1);

namespace App\Services\Export;

class CsvExporter
{
    private const HEADERS = [
        'semana' => [
            'numero_semana','data_inicio','data_fim',
            'slots_clinica','slots_lab','total_alunos','total_min',
        ],
        'turma' => [
            'turma_nome','numero_alunos','periodo','curso_nome','curso_sigla',
            'disciplina_nome','disciplina_tipo','minimo_encontros',
            'professor_nome','preceptor_nome',
            'encontros_alocados','horas_alocadas','enc_clinica','enc_lab',
        ],
        'disciplina' => [
            'codigo','nome','tipo','minimo_encontros','duracao_encontro_min',
            'total_turmas','total_slots','total_horas',
            'slots_clinica','slots_lab','total_alunos_x_slot',
        ],
        'professor' => [
            'tipo','nome','email','turmas','disciplinas',
            'total_slots','total_horas','dias_com_aula','max_turmas_simultaneas',
        ],
        'espaco' => [
            'tipo_espaco','espaco_nome','numero_semana','data_inicio',
            'capacidade','dias_usados','total_slots','total_alunos','total_horas',
        ],
        'completo' => [
            'numero_semana','data_aula','dia_semana','hora_inicio','hora_fim',
            'espaco_tipo','espaco_id','turma','periodo','curso',
            'disc_codigo','disciplina','disc_tipo',
            'professor','preceptor',
            'num_alunos','duracao_min','status','gerado_por_ia',
        ],
    ];

    public function exportar(string $tipo, mixed $dados): string
    {
        $rows    = $this->normalizar($tipo, $dados);
        $headers = self::HEADERS[$tipo] ?? array_keys($rows[0] ?? []);

        $out = fopen('php://temp', 'r+');
        // BOM UTF-8 para Excel abrir corretamente
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';');

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($out, $line, ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function normalizar(string $tipo, mixed $dados): array
    {
        return match ($tipo) {
            'professor' => $this->normalizarProfessores($dados),
            'espaco'    => $this->normalizarEspacos($dados),
            default     => is_array($dados) ? $dados : [],
        };
    }

    private function normalizarProfessores(array $dados): array
    {
        $rows = [];
        foreach ($dados['professores'] ?? [] as $p) {
            $rows[] = array_merge($p, ['max_turmas_simultaneas' => '']);
        }
        foreach ($dados['preceptores'] ?? [] as $p) {
            $rows[] = $p;
        }
        return $rows;
    }

    private function normalizarEspacos(array $dados): array
    {
        $rows = [];
        foreach ($dados['clinicas'] ?? [] as $r) {
            $rows[] = array_merge(['tipo_espaco' => 'clinica'], $r);
        }
        foreach ($dados['laboratorios'] ?? [] as $r) {
            $rows[] = array_merge(['tipo_espaco' => 'laboratorio'], $r);
        }
        return $rows;
    }
}
