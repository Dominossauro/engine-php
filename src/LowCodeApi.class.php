<?php
class LowCodeAPI
{
    private array $data;

    public function __construct(string $json)
    {
        $this->data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("JSON inválido: " . json_last_error_msg());
        }
    }

    // -------- Endpoints --------
    public function endpointExists(string $method, string $path): bool
    {
        foreach ($this->data['endpoints'] ?? [] as $endpoint) {
            if (
                strtoupper($endpoint['method'] ?? '') === strtoupper($method) &&
                rtrim($endpoint['path'] ?? '', '/') === rtrim($path, '/')
            ) {
                return true;
            }
        }
        return false;
    }

    public function getEndpoint(string $method, string $path): ?array
    {
        foreach ($this->data['endpoints'] ?? [] as $endpoint) {
            if (
                strtoupper($endpoint['method'] ?? '') === strtoupper($method) &&
                rtrim($endpoint['path'] ?? '', '/') === rtrim($path, '/')
            ) {
                return $endpoint;
            }
        }
        return null;
    }

    public function validateCurrentRequest(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $this->endpointExists($method, $path);
    }

    public function getCurrentEndpoint(): ?array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $this->getEndpoint($method, $path);
    }

    // -------- Grafo (nodes/edges) – aceita ambos formatos --------
    public function getGraph(): array
    {
        // Formato 1: dentro de 'flows'
        if (isset($this->data['flows']) && is_array($this->data['flows'])) {
            return [
                'nodes' => $this->data['flows']['nodes'] ?? [],
                'edges' => $this->data['flows']['edges'] ?? [],
            ];
        }
        // Formato 2: na raiz (como no JSON novo)
        return [
            'nodes' => $this->data['nodes'] ?? [],
            'edges' => $this->data['edges'] ?? [],
        ];
    }

    // -------- Envs opcionais (se quiser usar em contexto) --------
    public function getSelectedEnvironment(): string
    {
        return $this->data['selectedEnvironment'] ?? 'development';
    }

    public function getEnvironmentVars(?string $env = null): array
    {
        $env = $env ?? $this->getSelectedEnvironment();
        $all = $this->data['environmentVariables'] ?? [];
        return $all[$env] ?? [];
    }
}
