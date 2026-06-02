-- =============================================================================
-- Migration NNN — Descrição curta
-- Versão: 1.x.x
-- Data: AAAA-MM-DD
-- Descrição: O que esta migration faz e por quê
-- Autor: Seu nome
-- =============================================================================

-- Coloque aqui os comandos SQL (CREATE TABLE, ALTER TABLE, INSERT, etc.)
-- SEMPRE use IF NOT EXISTS / IF EXISTS para segurança
-- NUNCA altere migrations anteriores — crie uma nova

-- ALTER TABLE `exemplo`
--     ADD COLUMN `nova_coluna` VARCHAR(100) NULL AFTER `coluna_existente`;

-- Registra esta migration como aplicada (SEMPRE manter no final)
-- INSERT INTO `migrations` (`filename`) VALUES ('NNN_descricao.sql');
