<?php

namespace App\Services\Own;

use App\Support\StudentPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Labels a ticket with Claude using the student's exact prompt (worker/chirak_worker/judge.py, ported).
 * The model rejects `temperature`, so the default sampling is used; two passes that agree make a proposal.
 */
class ClaudeLabeler
{
    private const API = 'https://api.anthropic.com/v1/messages';

    /**
     * @return array{passes: array<int, ?array>, label: ?array, usage: array{requests: int, input_tokens: int, output_tokens: int}}
     */
    public function label(array $spec, string $text): array
    {
        $usage = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
        $passes = [];
        foreach ([1, 2] as $_) {
            [$passes[], $used] = $this->pass($spec, $text);
            foreach ($used as $key => $value) {
                $usage[$key] += $value;
            }
        }

        return ['passes' => $passes, 'label' => $passes[0] !== null && $passes[0] === $passes[1] ? $passes[0] : null, 'usage' => $usage];
    }

    /** @return array{0: ?array, 1: array} */
    private function pass(array $spec, string $text): array
    {
        // The model thinks adaptively; a small max_tokens cut answers short (worker judge, 2026-10-13).
        $body = [
            'model' => config('chirak.orchestrator.model'), 'max_tokens' => 4096,
            'system' => StudentPrompt::system($spec), 'messages' => [['role' => 'user', 'content' => $text]],
        ];

        for ($attempt = 0; ; $attempt++) {
            $response = Http::withHeaders(['x-api-key' => (string) config('chirak.orchestrator.api_key'), 'anthropic-version' => '2023-06-01'])
                ->timeout(60)->post(self::API, $body);

            if (in_array($response->status(), [429, 500, 502, 503, 529], true) && $attempt < 5) {
                Sleep::for(2 ** $attempt)->seconds();

                continue;
            }
            if ($response->failed()) {
                throw new RuntimeException('Anthropic answered HTTP '.$response->status());
            }

            break;
        }

        $usage = [
            'requests' => 1,
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
        if ($response->json('stop_reason') === 'max_tokens') {
            return [null, $usage]; // cut off: no answer
        }
        $raw = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return [StudentPrompt::parse($raw, $spec), $usage];
    }
}
