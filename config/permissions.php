<?php
/**
 * Mapa de permissões por perfil.
 * Usado pelo PermissionMiddleware e nos templates para ocultar/mostrar elementos.
 *
 * Formato: 'perfil' => ['permissao1', 'permissao2', ...]
 */
return [
    'admin' => [
        'agenda.gerar', 'agenda.publicar', 'agenda.simular',
        'cadastros.*',
        'ia.sugestoes', 'ia.aplicar', 'ia.chat',
        'relatorios.*',
        'usuarios.*',
        'configuracoes.*',
    ],

    'coordenador_clinica' => [
        'agenda.gerar', 'agenda.publicar', 'agenda.simular',
        'cadastros.disciplinas', 'cadastros.professores', 'cadastros.preceptores',
        'cadastros.turmas', 'cadastros.clinica', 'cadastros.laboratorio',
        'cadastros.horarios', 'cadastros.semestres',
        'ia.sugestoes', 'ia.aplicar', 'ia.chat',
        'relatorios.*',
    ],

    'coordenador_curso' => [
        'agenda.simular',
        'cadastros.disciplinas', 'cadastros.professores', 'cadastros.preceptores',
        'cadastros.turmas', 'cadastros.horarios',
        'ia.sugestoes', 'ia.chat',
        'relatorios.*',
    ],

    'professor' => [
        'agenda.ver',
        'ia.chat',
        'relatorios.proprio',
    ],

    'preceptor' => [
        'agenda.ver',
        'ia.chat',
        'relatorios.proprio',
    ],

    'secretaria' => [
        'cadastros.disciplinas', 'cadastros.professores', 'cadastros.preceptores',
        'cadastros.turmas', 'cadastros.horarios', 'cadastros.semestres',
        'relatorios.*',
    ],
];
