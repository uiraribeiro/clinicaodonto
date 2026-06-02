-- =============================================================================
-- Migration 004: Adiciona turno e dia preferencial às turmas
-- As turmas têm turno fixo (manhã 9:20-12:05, tarde 13:10-15:55, noturno 19:15-22:00)
-- e um dia da semana preferencial para as aulas práticas.
-- =============================================================================

ALTER TABLE `turmas`
    ADD COLUMN `turno` ENUM('manha','tarde','noturno') NOT NULL DEFAULT 'manha'
        COMMENT 'Turno da turma: manha=9:20-12:05, tarde=13:10-15:55, noturno=19:15-22:00'
        AFTER `numero_alunos`,
    ADD COLUMN `dia_semana_preferencial` TINYINT UNSIGNED NULL
        COMMENT '1=Segunda...6=Sábado. Dia preferencial para aulas práticas.'
        AFTER `turno`;

-- Adiciona turno e dia ao turma_disciplina para permitir override por disciplina
ALTER TABLE `turma_disciplina`
    ADD COLUMN `turno` ENUM('manha','tarde','noturno') NULL
        COMMENT 'Override de turno para esta disciplina específica. NULL = usa o da turma.'
        AFTER `semestre_ref`,
    ADD COLUMN `dia_semana_preferencial` TINYINT UNSIGNED NULL
        COMMENT 'Override do dia preferencial. NULL = usa o da turma.'
        AFTER `turno`;
