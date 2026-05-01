<?php

namespace App\Services\Secrets;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InfisicalResolver
{
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    /**
     * In-memory per-instance cache of resolved secrets. The resolver is a
     * singleton — one instance per request — so this is effectively
     * per-request memoization. We deliberately avoid Cache::remember()
     * because it requires the cache backend (Redis/database) to be reachable
     * during boot, and during `php artisan config:cache` the cache backend
     * is being initialised by the very config we're trying to resolve.
     *
     * @var array<string, string>
     */
    private array $resolvedCache = [];

    /**
     * @param  array<string, mixed>  $config  Values from config('secrets.infisical')
     */
    public function __construct(
        private readonly array $config,
        private readonly ?Client $http = null,
    ) {}

    /**
     * Resolve a `secret://project/env/path/NAME` handle to its real value.
     */
    public function resolve(string $handle): string
    {
        if (! str_starts_with($handle, 'secret://')) {
            return $handle;
        }

        if (isset($this->resolvedCache[$handle])) {
            return $this->resolvedCache[$handle];
        }

        $parsed = self::parseHandle($handle);
        if ($parsed === null) {
            Log::warning('InfisicalResolver: malformed handle', ['handle' => $handle]);
            return $handle;
        }

        try {
            $value = $this->fetch($parsed['environment'], $parsed['path'], $parsed['name']);
            $this->resolvedCache[$handle] = $value;
            return $value;
        } catch (\Throwable $e) {
            Log::error('InfisicalResolver: fetch failed', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @return array{project: string, environment: string, path: string, name: string}|null
     */
    public static function parseHandle(string $handle): ?array
    {
        if (! preg_match('#^secret://([^/]+)/([^/]+)(/.*)?/([A-Z0-9_]+)$#', $handle, $m)) {
            return null;
        }
        return [
            'project' => $m[1],
            'environment' => $m[2],
            'path' => ($m[3] ?? '') === '' ? '/' : $m[3],
            'name' => $m[4],
        ];
    }

    private function ensureToken(): void
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 30) {
            return;
        }

        $clientId = $this->config['client_id'] ?? null;
        $clientSecret = $this->config['client_secret'] ?? null;
        if (empty($clientId) || empty($clientSecret)) {
            throw new RuntimeException('Infisical credentials are not configured.');
        }

        $response = $this->client()->post('/api/v1/auth/universal-auth/login', [
            'json' => [
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
            ],
            'timeout' => (int) ($this->config['request_timeout'] ?? 5),
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body) || ! isset($body['accessToken'])) {
            throw new RuntimeException('Infisical universal-auth did not return an access token.');
        }

        $this->accessToken = (string) $body['accessToken'];
        $expiresIn = (int) ($body['expiresIn'] ?? 3600);
        $this->accessTokenExpiresAt = time() + $expiresIn;
    }

    private function fetch(string $environment, string $path, string $name): string
    {
        $this->ensureToken();

        $projectId = $this->config['project_id'] ?? null;
        if (empty($projectId)) {
            throw new RuntimeException('INFISICAL_PROJECT_ID is not configured.');
        }

        $response = $this->client()->get('/api/v3/secrets/raw/'.rawurlencode($name), [
            'query' => [
                'workspaceId' => $projectId,
                'environment' => $environment,
                'secretPath' => $path,
            ],
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
            ],
            'timeout' => (int) ($this->config['request_timeout'] ?? 5),
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $value = $body['secret']['secretValue'] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException("Infisical returned no value for {$name} in {$environment}.");
        }
        return $value;
    }

    private function client(): Client
    {
        if ($this->http !== null) return $this->http;
        return new Client([
            'base_uri' => rtrim((string) ($this->config['site_url'] ?? 'https://app.infisical.com'), '/'),
            'http_errors' => true,
        ]);
    }

    /** @throws GuzzleException */
    public function healthCheck(): bool
    {
        $this->ensureToken();
        return $this->accessToken !== null;
    }
}
