-- =============================================================================
-- Migration 002 — Dados Iniciais Obrigatórios
-- Versão: 1.0.0
-- Descrição: Perfis de usuário, admin padrão, curso e infraestrutura base
-- ATENÇÃO: Troque a senha do admin imediatamente após o primeiro login!
-- =============================================================================

-- Perfis de usuário
INSERT INTO `perfis` (`slug`, `nome`, `descricao`) VALUES
('admin',              'Administrador',         'Acesso total ao sistema, incluindo configurações e usuários'),
('coordenador_curso',  'Coordenador de Curso',  'Gerencia cadastros e agenda do seu curso'),
('coordenador_clinica','Coordenador de Clínica','Gerencia clínica, laboratório e agenda completa'),
('professor',          'Professor',             'Visualiza agenda própria e solicita ajustes'),
('preceptor',          'Preceptor',             'Visualiza agenda própria e confirma disponibilidade'),
('secretaria',         'Secretaria / Apoio',    'Cadastros e relatórios, sem acesso a otimização');

-- Usuário administrador padrão
-- Senha: Admin@1234 (hash bcrypt)
-- TROQUE IMEDIATAMENTE APÓS O PRIMEIRO LOGIN
INSERT INTO `usuarios` (`perfil_id`, `nome`, `email`, `senha_hash`, `ativo`) VALUES
(1, 'Administrador', 'admin@odonto.local',
 '$2y$12$ugmcy3VEY.MScXVPRQkYvepytIlZVJxaDTDv5cPGYJGtAI8ZMlBn.',
 1);

-- Curso padrão
INSERT INTO `cursos` (`nome`, `sigla`) VALUES
('Odontologia', 'ODONTO');

-- Clínica padrão
INSERT INTO `clinicas` (`nome`, `quantidade_cadeiras`, `capacidade_por_cadeira`, `hora_abertura`, `hora_fechamento`) VALUES
('Clínica Odontológica Central', 15, 2, '07:00:00', '22:00:00');

-- Laboratório de boquinha padrão
INSERT INTO `laboratorios` (`nome`, `quantidade_assentos`, `hora_abertura`, `hora_fechamento`) VALUES
('Laboratório de Boquinha', 30, '07:00:00', '22:00:00');

INSERT INTO `migrations` (`filename`) VALUES ('002_seed_perfis.sql');
