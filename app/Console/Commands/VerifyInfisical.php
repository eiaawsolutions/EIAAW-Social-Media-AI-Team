<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Verifies the Infisical resolver pulled the configured API keys at boot.
 * Used during deploy verification — never logs the actual key value, only
 * the prefix + length for sanity check.
 */
class VerifyInfisical extends Command
{
    protected $signature = 'eiaaw:verify-infisical';
    protected $description = 'Verify Infisical resolver successfully resolved secret:// handles';

    public function handle(): int
    {
        $resolverEnabled = (bool) config('secrets.infisical.enabled');
        $this->line('Resolver enabled: '.($resolverEnabled ? 'YES' : 'NO'));

        $bootstrapOk = config('secrets.infisical.client_id')
            && config('secrets.infisical.client_secret')
            && config('secrets.infisical.project_id');
        $this->line('Bootstrap creds present: '.($bootstrapOk ? 'YES' : 'NO'));

        $checks = [
            'services.anthropic.api_key' => 'sk-ant-',
            'services.voyage.api_key' => '',
            'services.fal.api_key' => '',
            'services.blotato.api_key' => '',
        ];

        foreach ($checks as $path => $expectedPrefix) {
            $value = (string) config($path);
            if ($value === '') {
                $this->line("  $path: <empty>");
                continue;
            }
            if (str_starts_with($value, 'secret://')) {
                $this->error("  $path: STILL A HANDLE — resolver did not run for this key");
                continue;
            }
            $prefix = substr($value, 0, max(7, strlen($expectedPrefix)));
            $matches = $expectedPrefix === '' || str_starts_with($value, $expectedPrefix);
            $tag = $matches ? 'OK' : 'UNEXPECTED FORMAT';
            $this->line("  $path: $tag ({$prefix}... ".strlen($value)." chars)");
        }

        // Real Claude ping — proves the resolved key actually works end-to-end.
        $apiKey = (string) config('services.anthropic.api_key');
        if (str_starts_with($apiKey, 'sk-ant-')) {
            $this->newLine();
            $this->line('Pinging Anthropic with resolved key...');
            try {
                $client = new \Anthropic\Client(apiKey: $apiKey);
                $response = $client->messages->create(
                    maxTokens: 50,
                    messages: [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                    model: 'claude-haiku-4-5-20251001',
                );
                $text = $response->content[0]->text ?? '(no text)';
                $tokens = ($response->usage->inputTokens ?? 0) + ($response->usage->outputTokens ?? 0);
                $this->info("  Anthropic OK — model={$response->model}, tokens={$tokens}, reply=\"".trim($text).'"');
            } catch (\Throwable $e) {
                $this->error('  Anthropic call FAILED: '.$e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
