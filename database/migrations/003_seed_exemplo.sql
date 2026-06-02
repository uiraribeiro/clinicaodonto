-- =============================================================================
-- Migration 003 — Dados de Exemplo para Desenvolvimento
-- Versão: 1.0.0
-- Descrição: Disciplinas, professores, preceptores, turmas e semestre 2026.1
-- Este arquivo popula o banco com dados realistas para testes do otimizador.
-- =============================================================================

-- Semestre 2026.1
INSERT INTO `semestres` (`referencia`, `data_inicio`, `data_fim`, `num_semanas`, `status`) VALUES
('2026.1', '2026-02-16', '2026-06-27', 20, 'planejamento');

-- Semanas do semestre 2026.1
INSERT INTO `semanas_semestre` (`semestre_id`, `numero_semana`, `data_inicio`, `data_fim`) VALUES
(1, 1,  '2026-02-16', '2026-02-21'),
(1, 2,  '2026-02-23', '2026-02-28'),
(1, 3,  '2026-03-02', '2026-03-07'),
(1, 4,  '2026-03-09', '2026-03-14'),
(1, 5,  '2026-03-16', '2026-03-21'),
(1, 6,  '2026-03-23', '2026-03-28'),
(1, 7,  '2026-03-30', '2026-04-04'),
(1, 8,  '2026-04-06', '2026-04-11'),
(1, 9,  '2026-04-13', '2026-04-18'),
(1, 10, '2026-04-20', '2026-04-25'),
(1, 11, '2026-04-27', '2026-05-02'),
(1, 12, '2026-05-04', '2026-05-09'),
(1, 13, '2026-05-11', '2026-05-16'),
(1, 14, '2026-05-18', '2026-05-23'),
(1, 15, '2026-05-25', '2026-05-30'),
(1, 16, '2026-06-01', '2026-06-06'),
(1, 17, '2026-06-08', '2026-06-13'),
(1, 18, '2026-06-15', '2026-06-20'),
(1, 19, '2026-06-22', '2026-06-27'),
(1, 20, '2026-06-29', '2026-07-04');

-- Feriados e recessos 2026.1
INSERT INTO `dias_bloqueados` (`semestre_id`, `data`, `motivo`, `tipo`) VALUES
(1, '2026-02-17', 'Carnaval',                 'feriado'),
(1, '2026-02-18', 'Carnaval',                 'feriado'),
(1, '2026-04-03', 'Sexta-feira Santa',        'feriado'),
(1, '2026-04-21', 'Tiradentes',               'feriado'),
(1, '2026-05-01', 'Dia do Trabalho',          'feriado'),
(1, '2026-06-11', 'Corpus Christi',           'feriado');

-- Disciplinas práticas
INSERT INTO `disciplinas` (`codigo`, `nome`, `tipo`, `carga_horaria_pratica`, `usa_clinica`, `usa_laboratorio`, `minimo_encontros`, `duracao_encontro_min`, `semana_inicio`, `semana_fim`, `prioridade`, `permite_alternancia`) VALUES
('ODONT-501', 'Clínica Integrada I',          'pratica_comum', 90,  1, 0, 15, 180, 1,  20, 1, 0),
('ODONT-502', 'Clínica Integrada II',         'pratica_comum', 90,  1, 0, 15, 180, 1,  20, 1, 0),
('ODONT-503', 'Clínica Integrada III',        'pratica_comum', 90,  1, 0, 15, 180, 1,  20, 2, 0),
('ODONT-401', 'Endodontia Clínica',           'pratica_comum', 60,  1, 0, 10, 180, 3,  18, 2, 0),
('ODONT-402', 'Periodontia Clínica',          'pratica_comum', 60,  1, 0, 10, 180, 3,  18, 2, 0),
('ODONT-403', 'Prótese Dentária I',           'pratica_comum', 60,  0, 1, 10, 180, 1,  18, 3, 1),
('ODONT-404', 'Prótese Dentária II',          'pratica_comum', 60,  1, 1, 10, 180, 2,  19, 3, 1),
('ODONT-301', 'Dentística Restauradora',      'pratica_comum', 45,  1, 0, 8,  180, 1,  15, 4, 0),
('ODONT-302', 'Cirurgia Oral I',              'pratica_comum', 45,  1, 0, 8,  240, 4,  17, 3, 0),
('ODONT-601', 'Estágio Supervisionado I',     'estagio',       120, 1, 0, 20, 240, 1,  20, 1, 0),
('ODONT-602', 'Estágio Supervisionado II',    'estagio',       120, 1, 0, 20, 240, 1,  20, 1, 0),
('ODONT-701', 'Internato Clínico I',          'estagio',       160, 1, 0, 20, 300, 1,  20, 1, 0),
('ODONT-201', 'Anatomia Dental (Lab)',        'pratica_comum', 30,  0, 1, 6,  120, 1,  10, 5, 0),
('ODONT-202', 'Escultura Dental',             'pratica_comum', 30,  0, 1, 6,  120, 1,  10, 5, 1),
('ODONT-405', 'Odontopediatria Clínica',      'pratica_comum', 60,  1, 0, 10, 180, 2,  18, 2, 0);

-- Professores
INSERT INTO `professores` (`nome`, `email`, `telefone`, `matricula`) VALUES
('Dr. Carlos Andrade',       'carlos.andrade@uni.edu.br',   '(11) 99001-0001', 'PROF-001'),
('Dra. Fernanda Lima',       'fernanda.lima@uni.edu.br',    '(11) 99001-0002', 'PROF-002'),
('Dr. Ricardo Souza',        'ricardo.souza@uni.edu.br',    '(11) 99001-0003', 'PROF-003'),
('Dra. Patrícia Mendes',     'patricia.mendes@uni.edu.br',  '(11) 99001-0004', 'PROF-004'),
('Dr. João Ferreira',        'joao.ferreira@uni.edu.br',    '(11) 99001-0005', 'PROF-005'),
('Dra. Márcia Costa',        'marcia.costa@uni.edu.br',     '(11) 99001-0006', 'PROF-006'),
('Dr. Paulo Carvalho',       'paulo.carvalho@uni.edu.br',   '(11) 99001-0007', 'PROF-007'),
('Dra. Aline Santos',        'aline.santos@uni.edu.br',     '(11) 99001-0008', 'PROF-008');

-- Disponibilidade dos professores (seg-sex 07h-22h por padrão, com variações)
INSERT INTO `professor_disponibilidade` (`professor_id`, `dia_semana`, `hora_inicio`, `hora_fim`) VALUES
-- Dr. Carlos: seg, ter, qui
(1, 1, '07:00', '17:00'), (1, 2, '07:00', '17:00'), (1, 4, '07:00', '17:00'),
-- Dra. Fernanda: seg-sex tarde
(2, 1, '13:00', '22:00'), (2, 2, '13:00', '22:00'), (2, 3, '13:00', '22:00'),
(2, 4, '13:00', '22:00'), (2, 5, '13:00', '22:00'),
-- Dr. Ricardo: seg-sex manhã
(3, 1, '07:00', '13:00'), (3, 2, '07:00', '13:00'), (3, 3, '07:00', '13:00'),
(3, 4, '07:00', '13:00'), (3, 5, '07:00', '13:00'),
-- Dra. Patrícia: seg, qua, sex
(4, 1, '07:00', '22:00'), (4, 3, '07:00', '22:00'), (4, 5, '07:00', '22:00'),
-- Dr. João: ter, qui
(5, 2, '07:00', '22:00'), (5, 4, '07:00', '22:00'),
-- Dra. Márcia: seg-sex
(6, 1, '07:00', '22:00'), (6, 2, '07:00', '22:00'), (6, 3, '07:00', '22:00'),
(6, 4, '07:00', '22:00'), (6, 5, '07:00', '22:00'),
-- Dr. Paulo: qua-sex + sábado
(7, 3, '07:00', '22:00'), (7, 4, '07:00', '22:00'), (7, 5, '07:00', '22:00'),
(7, 6, '07:00', '12:00'),
-- Dra. Aline: seg, ter, qua
(8, 1, '07:00', '22:00'), (8, 2, '07:00', '22:00'), (8, 3, '07:00', '22:00');

-- Vínculo professor × disciplina
INSERT INTO `professor_disciplina` (`professor_id`, `disciplina_id`, `data_inicio`) VALUES
(1, 1,  '2026-01-01'), -- Carlos → Clínica Integrada I
(1, 2,  '2026-01-01'), -- Carlos → Clínica Integrada II
(2, 3,  '2026-01-01'), -- Fernanda → Clínica Integrada III
(2, 9,  '2026-01-01'), -- Fernanda → Cirurgia Oral I
(3, 4,  '2026-01-01'), -- Ricardo → Endodontia
(3, 5,  '2026-01-01'), -- Ricardo → Periodontia
(4, 6,  '2026-01-01'), -- Patrícia → Prótese I
(4, 7,  '2026-01-01'), -- Patrícia → Prótese II
(5, 8,  '2026-01-01'), -- João → Dentística
(5, 15, '2026-01-01'), -- João → Odontopediatria
(6, 13, '2026-01-01'), -- Márcia → Anatomia Dental
(6, 14, '2026-01-01'), -- Márcia → Escultura
(7, 8,  '2026-01-01'), -- Paulo → Dentística (também)
(8, 5,  '2026-01-01'); -- Aline → Periodontia (também)

-- Preceptores de estágio
INSERT INTO `preceptores` (`nome`, `email`, `telefone`, `matricula`, `max_turmas_simultaneas`) VALUES
('Dra. Helena Rocha',    'helena.rocha@uni.edu.br',    '(11) 99002-0001', 'PREC-001', 2),
('Dr. Marcos Vieira',    'marcos.vieira@uni.edu.br',   '(11) 99002-0002', 'PREC-002', 2),
('Dra. Cristina Alves',  'cristina.alves@uni.edu.br',  '(11) 99002-0003', 'PREC-003', 1),
('Dr. André Gomes',      'andre.gomes@uni.edu.br',     '(11) 99002-0004', 'PREC-004', 2);

-- Disponibilidade dos preceptores
INSERT INTO `preceptor_disponibilidade` (`preceptor_id`, `dia_semana`, `hora_inicio`, `hora_fim`) VALUES
-- Helena: seg-sex 07h-17h
(1,1,'07:00','17:00'),(1,2,'07:00','17:00'),(1,3,'07:00','17:00'),
(1,4,'07:00','17:00'),(1,5,'07:00','17:00'),
-- Marcos: seg-sex 13h-22h
(2,1,'13:00','22:00'),(2,2,'13:00','22:00'),(2,3,'13:00','22:00'),
(2,4,'13:00','22:00'),(2,5,'13:00','22:00'),
-- Cristina: ter e qui 07h-22h
(3,2,'07:00','22:00'),(3,4,'07:00','22:00'),
-- André: seg, qua, sex 07h-22h
(4,1,'07:00','22:00'),(4,3,'07:00','22:00'),(4,5,'07:00','22:00');

-- Vínculo preceptor × disciplinas de estágio
INSERT INTO `preceptor_disciplina` (`preceptor_id`, `disciplina_id`) VALUES
(1, 10), (1, 11), -- Helena: Estágio I e II
(2, 10), (2, 12), -- Marcos: Estágio I e Internato
(3, 11), (3, 12), -- Cristina: Estágio II e Internato
(4, 10), (4, 11), (4, 12); -- André: todos os estágios

-- Turmas do semestre
INSERT INTO `turmas` (`curso_id`, `nome`, `periodo`, `numero_alunos`) VALUES
(1, 'ODONTO-3A',  3, 20),
(1, 'ODONTO-3B',  3, 22),
(1, 'ODONTO-4A',  4, 18),
(1, 'ODONTO-4B',  4, 20),
(1, 'ODONTO-5A',  5, 25),
(1, 'ODONTO-5B',  5, 24),
(1, 'ODONTO-6A',  6, 22),
(1, 'ODONTO-6B',  6, 20),
(1, 'ODONTO-7A',  7, 18),
(1, 'ODONTO-7B',  7, 16),
(1, 'ODONTO-8A',  8, 15),
(1, 'ODONTO-8B',  8, 12),
(1, 'ODONTO-9A',  9, 10),
(1, 'ODONTO-10A', 10, 8);

-- Associação turma × disciplina para o semestre 2026.1
-- (simplificado — em produção o coordenador configura via interface)
INSERT INTO `turma_disciplina` (`turma_id`, `disciplina_id`, `professor_id`, `semestre_ref`) VALUES
-- Turmas do 5º período → Clínica Integrada I com Dr. Carlos
(5, 1, 1, '2026.1'), (6, 1, 1, '2026.1'),
-- Turmas do 6º → Clínica Integrada II
(7, 2, 1, '2026.1'), (8, 2, 1, '2026.1'),
-- Turmas do 7º → Clínica Integrada III
(9,  3, 2, '2026.1'), (10, 3, 2, '2026.1'),
-- Endodontia para 6º e 7º
(7, 4, 3, '2026.1'), (8,  4, 3, '2026.1'),
(9, 4, 3, '2026.1'), (10, 4, 3, '2026.1'),
-- Periodontia para 5º e 6º
(5, 5, 3, '2026.1'), (6, 5, 3, '2026.1'),
(7, 5, 8, '2026.1'), (8, 5, 8, '2026.1'),
-- Prótese I para 4º período
(3, 6, 4, '2026.1'), (4, 6, 4, '2026.1'),
-- Prótese II para 5º (usa clínica e lab alternados)
(5, 7, 4, '2026.1'), (6, 7, 4, '2026.1'),
-- Dentística para 3º período
(1, 8, 5, '2026.1'), (2, 8, 5, '2026.1'),
-- Estágios para 8º e 9º com preceptores
(11, 10, NULL, '2026.1'), (12, 10, NULL, '2026.1'),
(13, 11, NULL, '2026.1'),
-- Internato para 10º
(14, 12, NULL, '2026.1'),
-- Lab: Anatomia e Escultura para 3º período
(1, 13, 6, '2026.1'), (2, 13, 6, '2026.1'),
(1, 14, 6, '2026.1'), (2, 14, 6, '2026.1'),
-- Odontopediatria para 6º e 7º
(7, 15, 5, '2026.1'), (8, 15, 5, '2026.1');

-- Atualiza preceptor_id nas turmas de estágio
UPDATE `turma_disciplina` SET `preceptor_id` = 1 WHERE `turma_id` = 11 AND `disciplina_id` = 10;
UPDATE `turma_disciplina` SET `preceptor_id` = 2 WHERE `turma_id` = 12 AND `disciplina_id` = 10;
UPDATE `turma_disciplina` SET `preceptor_id` = 3 WHERE `turma_id` = 13 AND `disciplina_id` = 11;
UPDATE `turma_disciplina` SET `preceptor_id` = 4 WHERE `turma_id` = 14 AND `disciplina_id` = 12;

INSERT INTO `migrations` (`filename`) VALUES ('003_seed_exemplo.sql');
