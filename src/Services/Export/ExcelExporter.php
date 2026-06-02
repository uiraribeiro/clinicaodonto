<?php
declare(strict_types=1);

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExporter
{
    private const HEADER_STYLE = [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1a56db']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFcccccc']]],
    ];

    public function exportar(string $tipo, mixed $dados): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Odonto Scheduler — ' . ucfirst($tipo))
            ->setCreator('Odonto Scheduler')
            ->setCreated(time());

        match ($tipo) {
            'semana'     => $this->sheetSemana($spreadsheet->getActiveSheet(), $dados),
            'turma'      => $this->sheetTurma($spreadsheet->getActiveSheet(), $dados),
            'disciplina' => $this->sheetDisciplina($spreadsheet->getActiveSheet(), $dados),
            'professor'  => $this->sheetProfessor($spreadsheet, $dados),
            'espaco'     => $this->sheetEspaco($spreadsheet, $dados),
            'completo'   => $this->sheetCompleto($spreadsheet->getActiveSheet(), $dados),
            default      => null,
        };

        $writer = new Xlsx($spreadsheet);
        $temp   = tempnam(sys_get_temp_dir(), 'odonto_');
        $writer->save($temp);
        $content = file_get_contents($temp);
        unlink($temp);

        return $content;
    }

    private function sheetSemana(Worksheet $ws, array $dados): void
    {
        $ws->setTitle('Por Semana');
        $headers = ['Semana','Início','Fim','Slots Clínica','Slots Lab','Total Alunos','Total Min'];
        $this->writeHeaders($ws, $headers);

        $row = 2;
        foreach ($dados as $d) {
            $ws->setCellValue("A{$row}", $d['numero_semana']);
            $ws->setCellValue("B{$row}", $d['data_inicio']);
            $ws->setCellValue("C{$row}", $d['data_fim']);
            $ws->setCellValue("D{$row}", $d['slots_clinica']);
            $ws->setCellValue("E{$row}", $d['slots_lab']);
            $ws->setCellValue("F{$row}", $d['total_alunos']);
            $ws->setCellValue("G{$row}", $d['total_min']);
            $row++;
        }
        $this->autofit($ws, 7);
    }

    private function sheetTurma(Worksheet $ws, array $dados): void
    {
        $ws->setTitle('Por Turma');
        $headers = ['Turma','Alunos','Período','Curso','Disciplina','Tipo','Mín Enc','Professor','Preceptor','Enc Alocados','Horas','Clínica','Lab'];
        $this->writeHeaders($ws, $headers);

        $row = 2;
        foreach ($dados as $d) {
            $ws->setCellValue("A{$row}", $d['turma_nome']);
            $ws->setCellValue("B{$row}", $d['numero_alunos']);
            $ws->setCellValue("C{$row}", $d['periodo']);
            $ws->setCellValue("D{$row}", $d['curso_nome']);
            $ws->setCellValue("E{$row}", $d['disciplina_nome']);
            $ws->setCellValue("F{$row}", $d['disciplina_tipo']);
            $ws->setCellValue("G{$row}", $d['minimo_encontros']);
            $ws->setCellValue("H{$row}", $d['professor_nome'] ?? '');
            $ws->setCellValue("I{$row}", $d['preceptor_nome'] ?? '');
            $ws->setCellValue("J{$row}", $d['encontros_alocados']);
            $ws->setCellValue("K{$row}", round((float)$d['horas_alocadas'], 1));
            $ws->setCellValue("L{$row}", $d['enc_clinica']);
            $ws->setCellValue("M{$row}", $d['enc_lab']);
            $row++;
        }
        $this->autofit($ws, 13);
    }

    private function sheetDisciplina(Worksheet $ws, array $dados): void
    {
        $ws->setTitle('Por Disciplina');
        $headers = ['Código','Disciplina','Tipo','Mín Enc','Duração (min)','Turmas','Total Slots','Horas','Slots Clínica','Slots Lab','Alunos×Slot'];
        $this->writeHeaders($ws, $headers);

        $row = 2;
        foreach ($dados as $d) {
            $ws->setCellValue("A{$row}", $d['codigo']);
            $ws->setCellValue("B{$row}", $d['nome']);
            $ws->setCellValue("C{$row}", $d['tipo']);
            $ws->setCellValue("D{$row}", $d['minimo_encontros']);
            $ws->setCellValue("E{$row}", $d['duracao_encontro_min']);
            $ws->setCellValue("F{$row}", $d['total_turmas']);
            $ws->setCellValue("G{$row}", $d['total_slots']);
            $ws->setCellValue("H{$row}", round((float)$d['total_horas'], 1));
            $ws->setCellValue("I{$row}", $d['slots_clinica']);
            $ws->setCellValue("J{$row}", $d['slots_lab']);
            $ws->setCellValue("K{$row}", $d['total_alunos_x_slot']);
            $row++;
        }
        $this->autofit($ws, 11);
    }

    private function sheetProfessor(Spreadsheet $spreadsheet, array $dados): void
    {
        $ws1 = $spreadsheet->getActiveSheet()->setTitle('Professores');
        $headers = ['Nome','E-mail','Turmas','Disciplinas','Total Slots','Horas','Dias com Aula'];
        $this->writeHeaders($ws1, $headers);

        $row = 2;
        foreach ($dados['professores'] ?? [] as $p) {
            $ws1->setCellValue("A{$row}", $p['nome']);
            $ws1->setCellValue("B{$row}", $p['email']);
            $ws1->setCellValue("C{$row}", $p['turmas']);
            $ws1->setCellValue("D{$row}", $p['disciplinas']);
            $ws1->setCellValue("E{$row}", $p['total_slots']);
            $ws1->setCellValue("F{$row}", round((float)$p['total_horas'], 1));
            $ws1->setCellValue("G{$row}", $p['dias_com_aula']);
            $row++;
        }
        $this->autofit($ws1, 7);

        $ws2 = $spreadsheet->createSheet()->setTitle('Preceptores');
        $headers2 = ['Nome','E-mail','Max Turmas Simult.','Turmas','Disciplinas','Total Slots','Horas','Dias com Aula'];
        $this->writeHeaders($ws2, $headers2);

        $row = 2;
        foreach ($dados['preceptores'] ?? [] as $p) {
            $ws2->setCellValue("A{$row}", $p['nome']);
            $ws2->setCellValue("B{$row}", $p['email']);
            $ws2->setCellValue("C{$row}", $p['max_turmas_simultaneas']);
            $ws2->setCellValue("D{$row}", $p['turmas']);
            $ws2->setCellValue("E{$row}", $p['disciplinas']);
            $ws2->setCellValue("F{$row}", $p['total_slots']);
            $ws2->setCellValue("G{$row}", round((float)$p['total_horas'], 1));
            $ws2->setCellValue("H{$row}", $p['dias_com_aula']);
            $row++;
        }
        $this->autofit($ws2, 8);
    }

    private function sheetEspaco(Spreadsheet $spreadsheet, array $dados): void
    {
        $ws1 = $spreadsheet->getActiveSheet()->setTitle('Clínicas');
        $headers = ['Clínica','Semana','Data Início','Capacidade','Dias Usados','Total Slots','Total Alunos','Horas','% Ocupação'];
        $this->writeHeaders($ws1, $headers);

        $row = 2;
        foreach ($dados['clinicas'] ?? [] as $d) {
            $cap = max(1, (int)$d['capacidade']);
            $pct = $d['dias_usados'] > 0 ? round($d['total_alunos'] / ($cap * $d['dias_usados']) * 100, 1) : 0;
            $ws1->setCellValue("A{$row}", $d['espaco_nome']);
            $ws1->setCellValue("B{$row}", $d['numero_semana']);
            $ws1->setCellValue("C{$row}", $d['data_inicio']);
            $ws1->setCellValue("D{$row}", $cap);
            $ws1->setCellValue("E{$row}", $d['dias_usados']);
            $ws1->setCellValue("F{$row}", $d['total_slots']);
            $ws1->setCellValue("G{$row}", $d['total_alunos']);
            $ws1->setCellValue("H{$row}", round((float)$d['total_horas'], 1));
            $ws1->setCellValue("I{$row}", $pct . '%');
            $row++;
        }
        $this->autofit($ws1, 9);

        $ws2 = $spreadsheet->createSheet()->setTitle('Laboratórios');
        $this->writeHeaders($ws2, $headers);

        $row = 2;
        foreach ($dados['laboratorios'] ?? [] as $d) {
            $cap = max(1, (int)$d['capacidade']);
            $pct = $d['dias_usados'] > 0 ? round($d['total_alunos'] / ($cap * $d['dias_usados']) * 100, 1) : 0;
            $ws2->setCellValue("A{$row}", $d['espaco_nome']);
            $ws2->setCellValue("B{$row}", $d['numero_semana']);
            $ws2->setCellValue("C{$row}", $d['data_inicio']);
            $ws2->setCellValue("D{$row}", $cap);
            $ws2->setCellValue("E{$row}", $d['dias_usados']);
            $ws2->setCellValue("F{$row}", $d['total_slots']);
            $ws2->setCellValue("G{$row}", $d['total_alunos']);
            $ws2->setCellValue("H{$row}", round((float)$d['total_horas'], 1));
            $ws2->setCellValue("I{$row}", $pct . '%');
            $row++;
        }
        $this->autofit($ws2, 9);
    }

    private function sheetCompleto(Worksheet $ws, array $dados): void
    {
        $ws->setTitle('Todos Agendamentos');
        $headers = ['Semana','Data','Dia','Hora Início','Hora Fim','Tipo Espaço','Espaço ID','Turma','Período','Curso','Cód. Disc.','Disciplina','Tipo Disc.','Professor','Preceptor','Alunos','Duração (min)','Status','Gerado IA'];
        $this->writeHeaders($ws, $headers);

        $row = 2;
        foreach ($dados as $d) {
            $ws->setCellValue("A{$row}", $d['numero_semana']);
            $ws->setCellValue("B{$row}", $d['data_aula']);
            $ws->setCellValue("C{$row}", $d['dia_semana']);
            $ws->setCellValue("D{$row}", $d['hora_inicio']);
            $ws->setCellValue("E{$row}", $d['hora_fim']);
            $ws->setCellValue("F{$row}", $d['espaco_tipo']);
            $ws->setCellValue("G{$row}", $d['espaco_id']);
            $ws->setCellValue("H{$row}", $d['turma']);
            $ws->setCellValue("I{$row}", $d['periodo']);
            $ws->setCellValue("J{$row}", $d['curso']);
            $ws->setCellValue("K{$row}", $d['disc_codigo']);
            $ws->setCellValue("L{$row}", $d['disciplina']);
            $ws->setCellValue("M{$row}", $d['disc_tipo']);
            $ws->setCellValue("N{$row}", $d['professor'] ?? '');
            $ws->setCellValue("O{$row}", $d['preceptor'] ?? '');
            $ws->setCellValue("P{$row}", $d['num_alunos']);
            $ws->setCellValue("Q{$row}", $d['duracao_min']);
            $ws->setCellValue("R{$row}", $d['status']);
            $ws->setCellValue("S{$row}", $d['gerado_por_ia'] ? 'Sim' : 'Não');
            $row++;
        }
        $this->autofit($ws, 19);
    }

    private function writeHeaders(Worksheet $ws, array $headers): void
    {
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}1", $h);
            $col++;
        }
        $last = chr(ord('A') + count($headers) - 1);
        $ws->getStyle("A1:{$last}1")->applyFromArray(self::HEADER_STYLE);
        $ws->getRowDimension(1)->setRowHeight(20);
    }

    private function autofit(Worksheet $ws, int $cols): void
    {
        for ($i = 0; $i < $cols; $i++) {
            $ws->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }
    }
}
