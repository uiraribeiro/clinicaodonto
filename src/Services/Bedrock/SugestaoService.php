<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use App\Repositories\SugestaoRepository;

/**
 * Orquestra a geração, validação e persistência de sugestões da IA.
 */
final class SugestaoService
{
    public function __construct(
        private readonly BedrockClient       $client,
        private readonly PromptBuilder       $prompts,
        private readonly SuggestionValidator $validator,
        private readonly SugestaoRepository  $repo,
    ) {}

    /**
     * Solicita sugestões ao Bedrock para uma versão de agenda e persiste no banco.
     *
     * @return array{geradas:int, validas:int, invalidas:int, cache_hit:bool}
     */
    public function solicitar(int $versaoId, int $usuarioId): array
    {
        $system  = $this->prompts->systemSugestoes();
        $user    = $this->prompts->promptAnaliseConflitos($versaoId);

        $result = $this->client->invocar(
            $system,
            $user,
            [],
            'sugestao_agenda',
            $versaoId,
            $usuarioId,
        );

        $sugestoes = $this->validator->parsearEValidar($result['texto'], $versaoId);

        if (!empty($sugestoes)) {
            $this->repo->salvarLote($versaoId, $sugestoes);
        }

        $validas   = count(array_filter($sugestoes, fn($s) => $s['valida']));
        $invalidas = count($sugestoes) - $validas;

        return [
            'geradas'   => count($sugestoes),
            'validas'   => $validas,
            'invalidas' => $invalidas,
            'cache_hit' => $result['cache_hit'],
        ];
    }
}
