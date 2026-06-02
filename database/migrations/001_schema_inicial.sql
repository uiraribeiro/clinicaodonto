-- =============================================================================
-- Migration 001 — Schema Inicial do Odonto Scheduler
-- Versão: 1.0.0
-- Data: 2026-06-02
-- Descrição: Criação completa das tabelas do sistema
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- Tabela de controle de migrations
CREATE TABLE IF NOT EXISTS `migrations` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename`   VARCHAR(255) NOT NULL UNIQUE,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- USUÁRIOS E AUTENTICAÇÃO
-- =============================================================================

CREATE TABLE `perfis` (
    `id`        TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug`      VARCHAR(50)  NOT NULL UNIQUE COMMENT 'admin, coordenador_curso, coordenador_clinica, professor, preceptor, secretaria',
    `nome`      VARCHAR(100) NOT NULL,
    `descricao` VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `perfil_id`            TINYINT UNSIGNED NOT NULL,
    `nome`                 VARCHAR(150) NOT NULL,
    `email`                VARCHAR(150) NOT NULL UNIQUE,
    `senha_hash`           VARCHAR(255) NOT NULL,
    `ativo`                TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_login`         DATETIME     NULL,
    `token_recuperacao`    VARCHAR(100) NULL,
    `token_expira_em`      DATETIME     NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_usuarios_perfil` FOREIGN KEY (`perfil_id`) REFERENCES `perfis`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proteção brute force: registra tentativas de login
CREATE TABLE `login_tentativas` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`       VARCHAR(150) NOT NULL,
    `ip`          VARCHAR(45)  NOT NULL,
    `sucesso`     TINYINT(1)   NOT NULL DEFAULT 0,
    `tentado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_ip` (`email`, `ip`),
    INDEX `idx_tentado_em` (`tentado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- INFRAESTRUTURA FÍSICA
-- =============================================================================

CREATE TABLE `clinicas` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`                  VARCHAR(150) NOT NULL,
    `quantidade_cadeiras`   TINYINT UNSIGNED NOT NULL DEFAULT 15,
    `capacidade_por_cadeira`TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `capacidade_total`      SMALLINT UNSIGNED GENERATED ALWAYS AS (`quantidade_cadeiras` * `capacidade_por_cadeira`) VIRTUAL,
    `hora_abertura`         TIME     NOT NULL DEFAULT '07:00:00',
    `hora_fechamento`       TIME     NOT NULL DEFAULT '22:00:00',
    `hora_abertura_sabado`  TIME     NOT NULL DEFAULT '07:00:00',
    `hora_fechamento_sabado`TIME     NOT NULL DEFAULT '12:00:00',
    `observacoes`           TEXT     NULL,
    `ativo`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED NULL,
    `updated_by`            INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `laboratorios` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`                  VARCHAR(150) NOT NULL,
    `quantidade_assentos`   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `hora_abertura`         TIME     NOT NULL DEFAULT '07:00:00',
    `hora_fechamento`       TIME     NOT NULL DEFAULT '22:00:00',
    `hora_abertura_sabado`  TIME     NOT NULL DEFAULT '07:00:00',
    `hora_fechamento_sabado`TIME     NOT NULL DEFAULT '12:00:00',
    `observacoes`           TEXT     NULL,
    `ativo`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED NULL,
    `updated_by`            INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueios de espaço (feriados, manutenção, eventos)
CREATE TABLE `bloqueios_espaco` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `espaco_tipo`  ENUM('clinica','laboratorio') NOT NULL,
    `espaco_id`    INT UNSIGNED NOT NULL,
    `data_inicio`  DATE     NOT NULL,
    `data_fim`     DATE     NOT NULL,
    `hora_inicio`  TIME     NULL COMMENT 'NULL = dia inteiro',
    `hora_fim`     TIME     NULL,
    `motivo`       VARCHAR(255) NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED NULL,
    INDEX `idx_espaco` (`espaco_tipo`, `espaco_id`),
    INDEX `idx_datas`  (`data_inicio`, `data_fim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- CADASTRO ACADÊMICO
-- =============================================================================

CREATE TABLE `disciplinas` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `codigo`                VARCHAR(20)  NOT NULL UNIQUE,
    `nome`                  VARCHAR(200) NOT NULL,
    `tipo`                  ENUM('estagio','pratica_comum') NOT NULL,
    `carga_horaria_pratica` SMALLINT UNSIGNED NOT NULL COMMENT 'em horas',
    `usa_clinica`           TINYINT(1) NOT NULL DEFAULT 0,
    `usa_laboratorio`       TINYINT(1) NOT NULL DEFAULT 0,
    `minimo_encontros`      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'encontros mínimos no semestre',
    `duracao_encontro_min`  SMALLINT UNSIGNED NOT NULL DEFAULT 180 COMMENT 'duração padrão em minutos',
    `semana_inicio`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `semana_fim`            TINYINT UNSIGNED NOT NULL DEFAULT 20,
    `prioridade`            TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=máxima, 10=mínima',
    `permite_alternancia`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'pode alternar semanas pares/ímpares',
    `observacoes`           TEXT NULL,
    `ativo`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED NULL,
    `updated_by`            INT UNSIGNED NULL,
    INDEX `idx_tipo` (`tipo`),
    INDEX `idx_prioridade` (`prioridade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `professores` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`  INT UNSIGNED NULL COMMENT 'NULL = professor sem acesso ao sistema',
    `nome`        VARCHAR(150) NOT NULL,
    `email`       VARCHAR(150) NOT NULL UNIQUE,
    `telefone`    VARCHAR(20)  NULL,
    `matricula`   VARCHAR(30)  NULL UNIQUE,
    `ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED NULL,
    `updated_by`  INT UNSIGNED NULL,
    CONSTRAINT `fk_professores_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disponibilidade semanal do professor (por dia da semana + horário)
CREATE TABLE `professor_disponibilidade` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `professor_id`   INT UNSIGNED NOT NULL,
    `dia_semana`     TINYINT UNSIGNED NOT NULL COMMENT '1=segunda, 6=sábado',
    `hora_inicio`    TIME NOT NULL,
    `hora_fim`       TIME NOT NULL,
    `semana_inicio`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `semana_fim`     TINYINT UNSIGNED NOT NULL DEFAULT 20,
    CONSTRAINT `fk_prof_disp_professor` FOREIGN KEY (`professor_id`) REFERENCES `professores`(`id`) ON DELETE CASCADE,
    INDEX `idx_professor_dia` (`professor_id`, `dia_semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo professor × disciplina com período de validade
CREATE TABLE `professor_disciplina` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `professor_id`    INT UNSIGNED NOT NULL,
    `disciplina_id`   INT UNSIGNED NOT NULL,
    `data_inicio`     DATE NOT NULL,
    `data_fim`        DATE NULL COMMENT 'NULL = sem prazo definido',
    `observacoes`     TEXT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    CONSTRAINT `fk_pd_professor`  FOREIGN KEY (`professor_id`)  REFERENCES `professores`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pd_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_professor_disciplina` (`professor_id`, `disciplina_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `preceptores` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`              INT UNSIGNED NULL,
    `nome`                    VARCHAR(150) NOT NULL,
    `email`                   VARCHAR(150) NOT NULL UNIQUE,
    `telefone`                VARCHAR(20)  NULL,
    `matricula`               VARCHAR(30)  NULL UNIQUE,
    `max_turmas_simultaneas`  TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `ativo`                   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`              INT UNSIGNED NULL,
    `updated_by`              INT UNSIGNED NULL,
    CONSTRAINT `fk_preceptores_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horários de trabalho dos preceptores
CREATE TABLE `preceptor_disponibilidade` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `preceptor_id`   INT UNSIGNED NOT NULL,
    `dia_semana`     TINYINT UNSIGNED NOT NULL COMMENT '1=segunda, 6=sábado',
    `hora_inicio`    TIME NOT NULL,
    `hora_fim`       TIME NOT NULL,
    `semana_inicio`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `semana_fim`     TINYINT UNSIGNED NOT NULL DEFAULT 20,
    CONSTRAINT `fk_prec_disp_preceptor` FOREIGN KEY (`preceptor_id`) REFERENCES `preceptores`(`id`) ON DELETE CASCADE,
    INDEX `idx_preceptor_dia` (`preceptor_id`, `dia_semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo preceptor × disciplinas de estágio
CREATE TABLE `preceptor_disciplina` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `preceptor_id`   INT UNSIGNED NOT NULL,
    `disciplina_id`  INT UNSIGNED NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_precd_preceptor`  FOREIGN KEY (`preceptor_id`)  REFERENCES `preceptores`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_precd_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_preceptor_disciplina` (`preceptor_id`, `disciplina_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cursos` (
    `id`    TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`  VARCHAR(150) NOT NULL,
    `sigla` VARCHAR(20)  NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `turmas` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `curso_id`        TINYINT UNSIGNED NOT NULL,
    `nome`            VARCHAR(100) NOT NULL,
    `periodo`         TINYINT UNSIGNED NOT NULL COMMENT 'semestre do curso (1-10)',
    `numero_alunos`   TINYINT UNSIGNED NOT NULL,
    `restricoes`      JSON NULL COMMENT 'restrições de horário {"dias_bloqueados": [3,4], "horarios_bloqueados": [...]}'
    ,
    `ativo`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    `updated_by`      INT UNSIGNED NULL,
    CONSTRAINT `fk_turmas_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disciplinas associadas a uma turma no semestre
CREATE TABLE `turma_disciplina` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `turma_id`        INT UNSIGNED NOT NULL,
    `disciplina_id`   INT UNSIGNED NOT NULL,
    `professor_id`    INT UNSIGNED NULL,
    `preceptor_id`    INT UNSIGNED NULL,
    `semestre_ref`    VARCHAR(10) NOT NULL COMMENT 'ex: 2026.1',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_td_turma`       FOREIGN KEY (`turma_id`)       REFERENCES `turmas`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_td_disciplina`  FOREIGN KEY (`disciplina_id`)  REFERENCES `disciplinas`(`id`),
    CONSTRAINT `fk_td_professor`   FOREIGN KEY (`professor_id`)   REFERENCES `professores`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_td_preceptor`   FOREIGN KEY (`preceptor_id`)   REFERENCES `preceptores`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_turma_disc_semestre` (`turma_id`, `disciplina_id`, `semestre_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- AGENDA SEMESTRAL
-- =============================================================================

CREATE TABLE `semestres` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `referencia`     VARCHAR(10) NOT NULL UNIQUE COMMENT 'ex: 2026.1',
    `data_inicio`    DATE NOT NULL,
    `data_fim`       DATE NOT NULL,
    `num_semanas`    TINYINT UNSIGNED NOT NULL DEFAULT 20,
    `status`         ENUM('planejamento','ativo','encerrado') NOT NULL DEFAULT 'planejamento',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semanas do semestre com datas e feriados
CREATE TABLE `semanas_semestre` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `semestre_id`     INT UNSIGNED NOT NULL,
    `numero_semana`   TINYINT UNSIGNED NOT NULL,
    `data_inicio`     DATE NOT NULL,
    `data_fim`        DATE NOT NULL,
    `tem_feriado`     TINYINT(1) NOT NULL DEFAULT 0,
    `observacoes`     VARCHAR(255) NULL,
    CONSTRAINT `fk_ss_semestre` FOREIGN KEY (`semestre_id`) REFERENCES `semestres`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_semestre_semana` (`semestre_id`, `numero_semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dias específicos com bloqueio (feriados, recessos)
CREATE TABLE `dias_bloqueados` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `semestre_id`  INT UNSIGNED NOT NULL,
    `data`         DATE NOT NULL,
    `motivo`       VARCHAR(255) NOT NULL,
    `tipo`         ENUM('feriado','recesso','evento','manutencao') NOT NULL,
    CONSTRAINT `fk_db_semestre` FOREIGN KEY (`semestre_id`) REFERENCES `semestres`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_semestre_data` (`semestre_id`, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versões da agenda (histórico + modo simulação)
CREATE TABLE `agenda_versoes` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `semestre_id`     INT UNSIGNED NOT NULL,
    `numero_versao`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `status`          ENUM('rascunho','simulacao','publicada','arquivada') NOT NULL DEFAULT 'rascunho',
    `descricao`       VARCHAR(255) NULL,
    `score_ocupacao`  DECIMAL(5,2) NULL COMMENT 'percentual de ocupação médio',
    `total_conflitos` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `gerada_por_ia`   TINYINT(1) NOT NULL DEFAULT 0,
    `publicada_em`    DATETIME NULL,
    `publicada_por`   INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    CONSTRAINT `fk_av_semestre` FOREIGN KEY (`semestre_id`) REFERENCES `semestres`(`id`),
    UNIQUE KEY `uk_semestre_versao` (`semestre_id`, `numero_versao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slots de agendamento (coração do sistema)
CREATE TABLE `agendamentos` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `versao_id`       INT UNSIGNED NOT NULL,
    `semana_id`       INT UNSIGNED NOT NULL,
    `turma_id`        INT UNSIGNED NOT NULL,
    `disciplina_id`   INT UNSIGNED NOT NULL,
    `espaco_tipo`     ENUM('clinica','laboratorio') NOT NULL,
    `espaco_id`       INT UNSIGNED NOT NULL,
    `professor_id`    INT UNSIGNED NULL,
    `preceptor_id`    INT UNSIGNED NULL,
    `dia_semana`      TINYINT UNSIGNED NOT NULL COMMENT '1=segunda ... 6=sábado',
    `data_aula`       DATE NOT NULL,
    `hora_inicio`     TIME NOT NULL,
    `hora_fim`        TIME NOT NULL,
    `num_alunos`      TINYINT UNSIGNED NOT NULL,
    `status`          ENUM('agendado','confirmado','cancelado','realizado') NOT NULL DEFAULT 'agendado',
    `gerado_por_ia`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'slot sugerido pelo Bedrock',
    `sugestao_id`     INT UNSIGNED NULL COMMENT 'FK para sugestão original do Bedrock',
    `observacoes`     TEXT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    `updated_by`      INT UNSIGNED NULL,
    CONSTRAINT `fk_ag_versao`      FOREIGN KEY (`versao_id`)     REFERENCES `agenda_versoes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ag_semana`      FOREIGN KEY (`semana_id`)     REFERENCES `semanas_semestre`(`id`),
    CONSTRAINT `fk_ag_turma`       FOREIGN KEY (`turma_id`)      REFERENCES `turmas`(`id`),
    CONSTRAINT `fk_ag_disciplina`  FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas`(`id`),
    CONSTRAINT `fk_ag_professor`   FOREIGN KEY (`professor_id`)  REFERENCES `professores`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ag_preceptor`   FOREIGN KEY (`preceptor_id`)  REFERENCES `preceptores`(`id`) ON DELETE SET NULL,
    INDEX `idx_versao_semana`  (`versao_id`, `semana_id`),
    INDEX `idx_espaco_data`    (`espaco_tipo`, `espaco_id`, `data_aula`),
    INDEX `idx_turma_data`     (`turma_id`, `data_aula`),
    INDEX `idx_professor_data` (`professor_id`, `data_aula`),
    INDEX `idx_preceptor_data` (`preceptor_id`, `data_aula`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- OTIMIZADOR E CONFLITOS
-- =============================================================================

-- Conflitos detectados pelo sistema
CREATE TABLE `conflitos` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `versao_id`       INT UNSIGNED NOT NULL,
    `tipo`            VARCHAR(50) NOT NULL COMMENT 'capacidade_clinica, disponibilidade_professor, etc.',
    `severidade`      ENUM('critico','alto','medio','baixo') NOT NULL,
    `descricao`       TEXT NOT NULL,
    `agendamento_ids` JSON NULL COMMENT 'IDs dos agendamentos envolvidos',
    `resolvido`       TINYINT(1) NOT NULL DEFAULT 0,
    `resolvido_em`    DATETIME NULL,
    `resolvido_por`   INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_conf_versao` FOREIGN KEY (`versao_id`) REFERENCES `agenda_versoes`(`id`) ON DELETE CASCADE,
    INDEX `idx_versao_tipo`     (`versao_id`, `tipo`),
    INDEX `idx_severidade`      (`severidade`),
    INDEX `idx_resolvido`       (`resolvido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log auditável do otimizador
CREATE TABLE `optimization_logs` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `versao_id`       INT UNSIGNED NOT NULL,
    `acao`            VARCHAR(100) NOT NULL COMMENT 'tentativa_alocacao, backtrack, conflito_detectado...',
    `turma_id`        INT UNSIGNED NULL,
    `disciplina_id`   INT UNSIGNED NULL,
    `slot_tentado`    JSON NULL COMMENT 'detalhes do slot tentado',
    `resultado`       ENUM('sucesso','falha','backtrack') NOT NULL,
    `motivo_falha`    VARCHAR(255) NULL,
    `duracao_ms`      INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_versao` (`versao_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- BEDROCK / IA
-- =============================================================================

-- Sugestões geradas pelo Bedrock
CREATE TABLE `sugestoes_ia` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `versao_id`             INT UNSIGNED NOT NULL,
    `tipo_sugestao`         VARCHAR(50) NOT NULL,
    `prioridade`            ENUM('critica','alta','media','baixa') NOT NULL,
    `problema_identificado` TEXT NOT NULL,
    `sugestao`              TEXT NOT NULL,
    `impacto_esperado`      TEXT NULL,
    `restricoes_respeitadas`JSON NULL,
    `payload_completo`      JSON NOT NULL COMMENT 'resposta bruta do Bedrock',
    `status`                ENUM('pendente','aceita','rejeitada','editada','aplicada','invalida') NOT NULL DEFAULT 'pendente',
    `validada_pelo_sistema` TINYINT(1) NOT NULL DEFAULT 0,
    `motivo_invalidade`     TEXT NULL,
    `editada_para`          JSON NULL COMMENT 'versão editada pelo usuário antes de aplicar',
    `acao_por`              INT UNSIGNED NULL,
    `acao_em`               DATETIME NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_si_versao` FOREIGN KEY (`versao_id`) REFERENCES `agenda_versoes`(`id`) ON DELETE CASCADE,
    INDEX `idx_versao_status` (`versao_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de todas as chamadas ao Bedrock (auditoria e custo)
CREATE TABLE `bedrock_logs` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `versao_id`       INT UNSIGNED NULL,
    `tipo_chamada`    VARCHAR(50) NOT NULL COMMENT 'sugestao_agenda, chat, analise_conflito',
    `model_id`        VARCHAR(100) NOT NULL,
    `tokens_entrada`  INT UNSIGNED NULL,
    `tokens_saida`    INT UNSIGNED NULL,
    `custo_estimado`  DECIMAL(10,6) NULL COMMENT 'em USD',
    `duracao_ms`      INT UNSIGNED NULL,
    `cache_hit`       TINYINT(1) NOT NULL DEFAULT 0,
    `status`          ENUM('sucesso','erro','timeout') NOT NULL,
    `erro_mensagem`   TEXT NULL,
    `prompt_hash`     VARCHAR(64) NULL COMMENT 'SHA-256 do prompt para cache',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    INDEX `idx_tipo_data`  (`tipo_chamada`, `created_at`),
    INDEX `idx_prompt_hash`(`prompt_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico do chat em linguagem natural com o Bedrock
CREATE TABLE `bedrock_chat` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sessao_id`   VARCHAR(64) NOT NULL COMMENT 'agrupa mensagens da mesma conversa',
    `versao_id`   INT UNSIGNED NULL,
    `usuario_id`  INT UNSIGNED NOT NULL,
    `papel`       ENUM('user','assistant') NOT NULL,
    `mensagem`    TEXT NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sessao` (`sessao_id`),
    INDEX `idx_usuario`(`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- AUDITORIA
-- =============================================================================

CREATE TABLE `audit_logs` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`  INT UNSIGNED NULL,
    `ip`          VARCHAR(45) NOT NULL,
    `acao`        VARCHAR(100) NOT NULL COMMENT 'create, update, delete, login, logout, publish...',
    `tabela`      VARCHAR(100) NULL,
    `registro_id` VARCHAR(50) NULL,
    `dados_antes` JSON NULL,
    `dados_depois`JSON NULL,
    `user_agent`  VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_usuario`    (`usuario_id`),
    INDEX `idx_tabela`     (`tabela`, `registro_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- Registra esta migration como aplicada
INSERT INTO `migrations` (`filename`) VALUES ('001_schema_inicial.sql');
