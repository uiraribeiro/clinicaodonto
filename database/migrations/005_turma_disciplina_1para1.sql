-- Turma é 1:1 com disciplina: adiciona FK direto na tabela turmas
-- e restringe turma_disciplina a 1 registro por turma por semestre.

ALTER TABLE `turmas`
    ADD COLUMN `disciplina_id` INT UNSIGNED NULL
        COMMENT 'Disciplina à qual esta turma pertence (1:1)'
        AFTER `curso_id`,
    ADD COLUMN `professor_id`  INT UNSIGNED NULL AFTER `disciplina_id`,
    ADD COLUMN `preceptor_id`  INT UNSIGNED NULL AFTER `professor_id`,
    ADD CONSTRAINT `fk_turmas_disciplina`
        FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas`(`id`),
    ADD CONSTRAINT `fk_turmas_professor`
        FOREIGN KEY (`professor_id`)  REFERENCES `professores`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_turmas_preceptor`
        FOREIGN KEY (`preceptor_id`)  REFERENCES `preceptores`(`id`) ON DELETE SET NULL;

-- Remove duplicatas: mantém apenas o registro de menor id por (turma_id, semestre_ref)
DELETE td FROM `turma_disciplina` td
INNER JOIN (
    SELECT MIN(id) AS min_id, turma_id, semestre_ref
    FROM `turma_disciplina`
    GROUP BY turma_id, semestre_ref
) keep_rows ON td.turma_id = keep_rows.turma_id
           AND td.semestre_ref = keep_rows.semestre_ref
           AND td.id > keep_rows.min_id;

-- Sincroniza turmas.disciplina_id / professor_id / preceptor_id a partir do registro existente
UPDATE `turmas` t
INNER JOIN `turma_disciplina` td ON td.turma_id = t.id
SET t.disciplina_id = td.disciplina_id,
    t.professor_id  = td.professor_id,
    t.preceptor_id  = td.preceptor_id;

-- Troca a unique key para 1 disciplina por turma por semestre
ALTER TABLE `turma_disciplina`
    DROP KEY `uk_turma_disc_semestre`,
    ADD UNIQUE KEY `uk_turma_semestre` (`turma_id`, `semestre_ref`);
