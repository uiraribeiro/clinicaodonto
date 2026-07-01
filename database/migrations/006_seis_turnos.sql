-- Migration 006: Expande turno de 3 para 6 valores
-- Novos turnos: matutino1 (7:40-10:10), matutino2 (10:10-12:40), manha (9:20-12:00),
--               tarde (13:10-15:55), vespertino (16:10-18:40), noturno (19:15-21:30)

ALTER TABLE `turmas`
    MODIFY COLUMN `turno`
        ENUM('matutino1','matutino2','manha','tarde','vespertino','noturno')
        NOT NULL DEFAULT 'manha'
        COMMENT 'matutino1=7:40-10:10, matutino2=10:10-12:40, manha=9:20-12:00, tarde=13:10-15:55, vespertino=16:10-18:40, noturno=19:15-21:30';

ALTER TABLE `turma_disciplina`
    MODIFY COLUMN `turno`
        ENUM('matutino1','matutino2','manha','tarde','vespertino','noturno')
        NULL
        COMMENT 'Override de turno para esta disciplina específica. NULL = usa o da turma.';
