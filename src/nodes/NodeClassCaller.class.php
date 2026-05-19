<?php

enum OutputClassCaller: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
}

class NodeClassCaller
{
    public function execute(array $config, array $context): array
    {
        global $flowCtx;

        $data = $config['data'] ?? [];
        $nodeId = $config['id'] ?? 'classCaller';

        $className = $data['className'] ?? null;
        $methodName = $data['methodName'] ?? null;
        $isStatic = $data['isStatic'] ?? false;
        $configuredParams = $data['parameters'] ?? [];
        $filePath = $data['file'] ?? null;

        if (!$className || !$methodName) {
            return [
                'output' => OutputClassCaller::ERROR->value,
                'data' => [
                    'error' => 'className e methodName são obrigatórios',
                    'className' => $className,
                    'methodName' => $methodName,
                ]
            ];
        }

        try {
            // Carregar arquivo da classe se necessário
            if (!class_exists($className) && $filePath) {
                $this->loadClassFile($filePath);
            }

            if (!class_exists($className)) {
                throw new Exception("Classe '{$className}' não encontrada");
            }

            // Resolver variáveis {{nodeId.output.var}} nos valores dos parâmetros
            $resolvedParams = $this->resolveParamVariables($configuredParams);

            // Usar ClassReflector para resolver parâmetros na ordem correta
            $reflector = new ClassReflector();
            $args = $reflector->resolveParameters($className, $methodName, $resolvedParams, $context);

            // Chamar o método
            if ($isStatic) {
                $result = call_user_func_array([$className, $methodName], $args);
            } else {
                $instance = new $className();
                $result = call_user_func_array([$instance, $methodName], $args);
            }

            // Normalizar retorno
            $outputData = $this->normalizeResult($result);

            return [
                'output' => OutputClassCaller::SUCCESS->value,
                'data' => $outputData,
            ];

        } catch (\Throwable $e) {
            getFlowLogger()->log("❌ [ClassCaller] {$className}::{$methodName} — " . $e->getMessage());

            return [
                'output' => OutputClassCaller::ERROR->value,
                'data' => [
                    'error' => $e->getMessage(),
                    'className' => $className,
                    'methodName' => $methodName,
                    'trace' => $e->getTraceAsString(),
                ]
            ];
        }
    }

    /**
     * Carrega o arquivo PHP da classe.
     */
    private function loadClassFile(string $filePath): void
    {
        // Caminho absoluto
        if (str_starts_with($filePath, '/') && file_exists($filePath)) {
            include_once $filePath;
            return;
        }

        // Caminho relativo ao projeto
        $basePath = (new App())->getBasePathFromServer();
        $fullPath = $basePath . '/' . ltrim($filePath, '/');

        if (file_exists($fullPath)) {
            include_once $fullPath;
        }
    }

    /**
     * Resolve variáveis {{nodeId.output.var}} nos valores dos parâmetros configurados.
     */
    private function resolveParamVariables(array $configuredParams): array
    {
        global $flowCtx;

        $resolved = [];

        foreach ($configuredParams as $param) {
            $value = $param['value'] ?? null;

            if (is_string($value)) {
                $value = $this->replaceVariables($value);
            }

            $resolved[] = [
                'name' => $param['name'],
                'value' => $value,
            ];
        }

        return $resolved;
    }

    /**
     * Substitui variáveis no formato {{nodeId.output.variableName}} pelos valores reais.
     */
    private function replaceVariables(string $value): mixed
    {
        global $flowCtx;

        if (!is_object($flowCtx) || !method_exists($flowCtx, 'getNodeData')) {
            return $value;
        }

        // Se o valor inteiro é uma variável, retorna o valor tipado (não como string)
        if (preg_match('/^\{\{([\w-]+)\.([\w-]+)\.([\w.-]+)\}\}$/', $value, $matches)) {
            $resolved = $this->resolveVariablePath($matches[1], $matches[2], $matches[3]);
            if ($resolved !== null) {
                return $resolved;
            }
            return $value;
        }

        // Se contém variáveis junto com texto, faz substituição como string
        return preg_replace_callback(
            '/\{\{([\w-]+)\.([\w-]+)\.([\w.-]+)\}\}/',
            function ($matches) {
                $resolved = $this->resolveVariablePath($matches[1], $matches[2], $matches[3]);
                if ($resolved === null) {
                    return $matches[0];
                }
                if (is_array($resolved) || is_object($resolved)) {
                    return json_encode($resolved, JSON_UNESCAPED_UNICODE);
                }
                return (string) $resolved;
            },
            $value
        );
    }

    /**
     * Resolve um caminho de variável do flowCtx.
     */
    private function resolveVariablePath(string $nodeId, string $output, string $path): mixed
    {
        global $flowCtx;

        $nodeOutput = $flowCtx->getNodeData($nodeId, $output);
        if ($nodeOutput === null) {
            return null;
        }

        $parts = explode('.', $path);
        $current = $nodeOutput;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Normaliza o retorno do método para o formato padrão de dados do node.
     * O dev pode retornar qualquer coisa — array, string, int, objeto, null, bool.
     */
    private function normalizeResult(mixed $result): array
    {
        if ($result === null) {
            return ['result' => null];
        }

        if (is_array($result)) {
            return $result;
        }

        if (is_object($result)) {
            // Tentar converter para array
            if (method_exists($result, 'toArray')) {
                return $result->toArray();
            }
            return ['result' => (array) $result];
        }

        // Scalar: string, int, float, bool
        return ['result' => $result];
    }
}
