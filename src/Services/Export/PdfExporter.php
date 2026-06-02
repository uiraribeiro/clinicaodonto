<?php
declare(strict_types=1);

namespace App\Services\Export;

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class PdfExporter
{
    private const TIPOS_LABEL = [
        'semana'     => 'Ocupação por Semana',
        'turma'      => 'Carga por Turma',
        'disciplina' => 'Uso por Disciplina',
        'professor'  => 'Carga de Professores e Preceptores',
        'espaco'     => 'Ocupação de Espaços',
        'completo'   => 'Exportação Completa',
    ];

    public function exportar(string $tipo, mixed $dados, array $versao): string
    {
        $mpdf = new Mpdf([
            'mode'        => 'utf-8',
            'format'      => 'A4',
            'orientation' => 'L', // landscape para tabelas largas
            'margin_top'  => 15,
            'margin_left' => 10,
            'margin_right'=> 10,
            'margin_bottom'=> 15,
            'margin_header'=> 8,
            'margin_footer'=> 8,
        ]);

        $titulo = self::TIPOS_LABEL[$tipo] ?? 'Relatório';
        $data   = date('d/m/Y H:i');
        $sem    = $versao['semestre_ref'] ?? '—';
        $ver    = $versao['numero_versao'] ?? '—';

        $mpdf->SetHTMLHeader(
            "<div style='font-size:9pt;color:#555;border-bottom:1px solid #ccc;padding-bottom:4px;'>
                <strong>Odonto Scheduler</strong> — {$titulo}
                <span style='float:right'>Semestre: {$sem} · Versão #{$ver}</span>
            </div>"
        );
        $mpdf->SetHTMLFooter(
            "<div style='font-size:8pt;color:#888;border-top:1px solid #eee;padding-top:4px;text-align:right;'>
                Gerado em {$data} · Página {PAGENO} de {nbpg}
            </div>"
        );

        $html = $this->buildHtml($tipo, $dados, $titulo);
        $mpdf->WriteHTML($this->css() . $html);

        return $mpdf->Output('', 'S');
    }

    private function buildHtml(string $tipo, mixed $dados, string $titulo): string
    {
        return match ($tipo) {
            'semana'     => $this->htmlSemana($dados, $titulo),
            'turma'      => $this->htmlTurma($dados, $titulo),
            'disciplina' => $this->htmlDisciplina($dados, $titulo),
            'professor'  => $this->htmlProfessor($dados, $titulo),
            'espaco'     => $this->htmlEspaco($dados, $titulo),
            'completo'   => $this->htmlCompleto($dados, $titulo),
            default      => "<h2>{$titulo}</h2><p>Tipo não suportado.</p>",
        };
    }

    private function htmlSemana(array $dados, string $titulo): string
    {
        $rows = '';
        foreach ($dados as $d) {
            $totalSlots = (int)$d['slots_clinica'] + (int)$d['slots_lab'];
            $rows .= "<tr>
                <td class='center'>{$d['numero_semana']}</td>
                <td class='center'>{$d['data_inicio']}</td>
                <td class='center'>{$d['data_fim']}</td>
                <td class='right'>{$d['slots_clinica']}</td>
                <td class='right'>{$d['slots_lab']}</td>
                <td class='right'>{$totalSlots}</td>
                <td class='right'>{$d['total_alunos']}</td>
                <td class='right'>{$d['total_min']}</td>
            </tr>";
        }
        return "<h2>{$titulo}</h2>
        <table>
            <thead><tr>
                <th>Semana</th><th>Início</th><th>Fim</th>
                <th>Slots Clínica</th><th>Slots Lab</th><th>Total Slots</th>
                <th>Total Alunos</th><th>Total Min</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    private function htmlTurma(array $dados, string $titulo): string
    {
        $rows = '';
        foreach ($dados as $d) {
            $pct = $d['minimo_encontros'] > 0
                ? round($d['encontros_alocados'] / $d['minimo_encontros'] * 100)
                : 0;
            $cor = $pct >= 100 ? '#166534' : ($pct >= 60 ? '#854d0e' : '#991b1b');
            $rows .= "<tr>
                <td>{$d['turma_nome']}</td>
                <td>{$d['curso_sigla']}</td>
                <td>{$d['disciplina_nome']}</td>
                <td class='center'>{$d['disciplina_tipo']}</td>
                <td class='center'>{$d['minimo_encontros']}</td>
                <td class='center' style='color:{$cor};font-weight:bold'>{$d['encontros_alocados']} ({$pct}%)</td>
                <td class='right'>" . number_format((float)$d['horas_alocadas'], 1) . "h</td>
                <td class='center'>{$d['enc_clinica']}</td>
                <td class='center'>{$d['enc_lab']}</td>
                <td>" . ($d['professor_nome'] ?? '—') . "</td>
                <td>" . ($d['preceptor_nome'] ?? '—') . "</td>
            </tr>";
        }
        return "<h2>{$titulo}</h2>
        <table>
            <thead><tr>
                <th>Turma</th><th>Curso</th><th>Disciplina</th><th>Tipo</th>
                <th>Mín Enc</th><th>Alocados</th><th>Horas</th>
                <th>Clínica</th><th>Lab</th><th>Professor</th><th>Preceptor</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    private function htmlDisciplina(array $dados, string $titulo): string
    {
        $rows = '';
        foreach ($dados as $d) {
            $rows .= "<tr>
                <td class='mono'>{$d['codigo']}</td>
                <td>{$d['nome']}</td>
                <td class='center'>{$d['tipo']}</td>
                <td class='center'>{$d['duracao_encontro_min']} min</td>
                <td class='center'>{$d['total_turmas']}</td>
                <td class='right'>{$d['total_slots']}</td>
                <td class='right'>" . number_format((float)$d['total_horas'], 1) . "h</td>
                <td class='right'>{$d['slots_clinica']}</td>
                <td class='right'>{$d['slots_lab']}</td>
                <td class='right'>{$d['total_alunos_x_slot']}</td>
            </tr>";
        }
        return "<h2>{$titulo}</h2>
        <table>
            <thead><tr>
                <th>Código</th><th>Disciplina</th><th>Tipo</th><th>Duração</th>
                <th>Turmas</th><th>Total Slots</th><th>Horas</th>
                <th>Slots Clínica</th><th>Slots Lab</th><th>Alunos×Slot</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    private function htmlProfessor(array $dados, string $titulo): string
    {
        $rowsP = '';
        foreach ($dados['professores'] ?? [] as $p) {
            $rowsP .= "<tr>
                <td>{$p['nome']}</td>
                <td>{$p['email']}</td>
                <td class='right'>{$p['turmas']}</td>
                <td class='right'>{$p['disciplinas']}</td>
                <td class='right'>{$p['total_slots']}</td>
                <td class='right'>" . number_format((float)$p['total_horas'], 1) . "h</td>
                <td class='right'>{$p['dias_com_aula']}</td>
            </tr>";
        }

        $rowsR = '';
        foreach ($dados['preceptores'] ?? [] as $p) {
            $rowsR .= "<tr>
                <td>{$p['nome']}</td>
                <td>{$p['email']}</td>
                <td class='center'>{$p['max_turmas_simultaneas']}</td>
                <td class='right'>{$p['turmas']}</td>
                <td class='right'>{$p['total_slots']}</td>
                <td class='right'>" . number_format((float)$p['total_horas'], 1) . "h</td>
                <td class='right'>{$p['dias_com_aula']}</td>
            </tr>";
        }

        return "<h2>{$titulo}</h2>
        <h3>Professores</h3>
        <table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Turmas</th><th>Disciplinas</th><th>Slots</th><th>Horas</th><th>Dias</th></tr></thead>
            <tbody>{$rowsP}</tbody>
        </table>
        <h3 style='margin-top:16px'>Preceptores</h3>
        <table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Max Turm.</th><th>Turmas</th><th>Slots</th><th>Horas</th><th>Dias</th></tr></thead>
            <tbody>{$rowsR}</tbody>
        </table>";
    }

    private function htmlEspaco(array $dados, string $titulo): string
    {
        $makeRows = function (array $lista): string {
            $out = '';
            foreach ($lista as $d) {
                $cap = max(1, (int)$d['capacidade']);
                $pct = $d['dias_usados'] > 0 ? round($d['total_alunos'] / ($cap * $d['dias_usados']) * 100) : 0;
                $cor = $pct >= 80 ? '#991b1b' : ($pct >= 50 ? '#854d0e' : '#166534');
                $out .= "<tr>
                    <td>{$d['espaco_nome']}</td>
                    <td class='center'>{$d['numero_semana']}</td>
                    <td class='center'>{$d['data_inicio']}</td>
                    <td class='center'>{$cap}</td>
                    <td class='center'>{$d['dias_usados']}</td>
                    <td class='right'>{$d['total_slots']}</td>
                    <td class='right'>" . number_format((float)$d['total_horas'], 1) . "h</td>
                    <td class='right' style='color:{$cor};font-weight:bold'>{$pct}%</td>
                </tr>";
            }
            return $out;
        };

        $thStr = '<th>Espaço</th><th>Sem</th><th>Início</th><th>Capacidade</th><th>Dias Usados</th><th>Slots</th><th>Horas</th><th>Ocupação</th>';

        return "<h2>{$titulo}</h2>
        <h3>Clínicas</h3>
        <table><thead><tr>{$thStr}</tr></thead><tbody>" . $makeRows($dados['clinicas'] ?? []) . "</tbody></table>
        <h3 style='margin-top:16px'>Laboratórios</h3>
        <table><thead><tr>{$thStr}</tr></thead><tbody>" . $makeRows($dados['laboratorios'] ?? []) . "</tbody></table>";
    }

    private function htmlCompleto(array $dados, string $titulo): string
    {
        $diasPt = ['','Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
        $rows   = '';
        foreach ($dados as $d) {
            $dia = $diasPt[(int)$d['dia_semana']] ?? $d['dia_semana'];
            $rows .= "<tr>
                <td class='center'>{$d['numero_semana']}</td>
                <td class='center'>{$d['data_aula']}</td>
                <td class='center'>{$dia}</td>
                <td class='center'>{$d['hora_inicio']}</td>
                <td class='center'>{$d['hora_fim']}</td>
                <td class='center'>{$d['espaco_tipo']}</td>
                <td>{$d['turma']}</td>
                <td class='mono'>{$d['disc_codigo']}</td>
                <td>{$d['disciplina']}</td>
                <td>" . ($d['professor'] ?? '—') . "</td>
                <td>" . ($d['preceptor'] ?? '—') . "</td>
                <td class='right'>{$d['num_alunos']}</td>
                <td class='right'>{$d['duracao_min']} min</td>
            </tr>";
        }
        return "<h2>{$titulo}</h2>
        <table>
            <thead><tr>
                <th>Sem</th><th>Data</th><th>Dia</th><th>Início</th><th>Fim</th><th>Tipo</th>
                <th>Turma</th><th>Cód</th><th>Disciplina</th>
                <th>Professor</th><th>Preceptor</th><th>Alunos</th><th>Duração</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    private function css(): string
    {
        return '<style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #222; }
            h2   { font-size: 13pt; color: #1a3a6b; margin: 0 0 8px 0; }
            h3   { font-size: 10pt; color: #1a56db; margin: 0 0 6px 0; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            thead th { background: #1a56db; color: #fff; padding: 4px 5px; font-size: 7.5pt; text-align: center; }
            tbody tr:nth-child(odd)  { background: #f8fafc; }
            tbody tr:nth-child(even) { background: #ffffff; }
            tbody td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; font-size: 7.5pt; }
            .right  { text-align: right; }
            .center { text-align: center; }
            .mono   { font-family: DejaVu Sans Mono, monospace; }
        </style>';
    }
}
