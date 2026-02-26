<?php

class FlowProcessor
{
    private array $nodes;
    // Controller registry: [controllerName => ['endpoints' => [], 'nodes' => []]]
    private array $controllers = [];

    public function __construct(array $nodes)
    {
        $this->nodes = $nodes;
        // Expose a global reference so Node classes can orchestrate subflows
        $GLOBALS['flowProcessor'] = $this;
    }

    // Register controllers loaded from files
    public function setControllers(array $controllers): void
    {
        // Expecting array like: ['users' => ['type' => 'controller', 'endpoints' => [...], 'nodes' => [...]], ...]
        $this->controllers = $controllers;
    }

    /**
     * Registra um controller a partir de um JSON string
     * @return bool true se registrou com sucesso
     */
    public function registerControllerFromJson(array $jsonContent, string $controllerName): bool
    {
        $data = $jsonContent;

        $this->controllers[$controllerName] = [
            'type' => 'controller',
            'endpoints' => $data['endpoints'] ?? [],
            'nodes' => $data['nodes'] ?? [],
        ];

        return true;
    }

    /**
     * Encontra um endpoint que corresponda ao método e path da requisição atual
     * Delega a responsabilidade de matching para a classe Router
     * @param string $controllerName Nome do controller
     * @param string $method Método HTTP
     * @param string $path Path da requisição
     * @param array|null &$pathParams Referência para armazenar os parâmetros de path extraídos
     * @return array|null O endpoint encontrado ou null
     */
    public function findMatchingEndpoint(string $controllerName, string $method, string $path, ?array &$pathParams = null): ?array
    {
        if (!isset($this->controllers[$controllerName])) {
            return null;
        }

        $controller = $this->controllers[$controllerName];
        $endpoints = $controller['endpoints'] ?? [];
        
        // Delega para o Router fazer o matching e extração de path params
        $router = new Router();
        return $router->findMatchingEndpoint($endpoints, $method, $path, $pathParams);
    }

    /**
     * Executa um controller completo: encontra o endpoint, localiza o nó inicial e executa o fluxo
     * @return void Retorna resposta HTTP ou throw exception
     */
    public function executeController(string $controllerName, string $method, string $path): void
    {
        global $flowLog;
        
        // Encontra o endpoint correspondente e extrai os parâmetros de path
        $pathParams = [];
        $endpoint = $this->findMatchingEndpoint($controllerName, $method, $path, $pathParams);
        
        if (!$endpoint) {
            $availableEndpoints = [];
            if (isset($this->controllers[$controllerName])) {
                $availableEndpoints = array_map(function($ep) {
                    return ['method' => $ep['method'], 'path' => $ep['path']];
                }, $this->controllers[$controllerName]['endpoints'] ?? []);
            }

            $response = [
                'error' => 'Endpoint não encontrado',
                'code' => 4004
            ];
            if(isset($_ENV['SHOW_EXCEPTIONS']) && $_ENV['SHOW_EXCEPTIONS']) {
                $response['exception'] = $flowLog->getLastException();
                $response['method'] = $method;
                $response['path'] = $path;
                $response['availableEndpoints'] = $availableEndpoints;
            }

            (new HttpResponse())->statusCode(404)->json($response);
            return;
        }

        // Encontra o nó inicial
        $startNodeId = $this->findStartNodeIdForEndpoint($endpoint);
        
        if (!$startNodeId) {
            (new HttpResponse())->statusCode(500)->json([
                'error' => 'Nó inicial não encontrado para o endpoint',
                'endpoint' => $endpoint,
                'controller' => $controllerName,
                'logs' => $flowLog->getLogs(),
                'code' => 5007
            ]);
            return;
        }

        if ($flowLog) {
            $flowLog->log("✓ Executando fluxo a partir do nó: {$startNodeId}");
            if (!empty($pathParams)) {
                $flowLog->log("✓ Parâmetros de path extraídos: " . json_encode($pathParams));
            }
        }

        // Executa o fluxo
        $request = new HttpRequest();
        $request->loadPathParams($pathParams);
        
        $context = [
            'request' => $request,
            'response' => new HttpResponse(),
        ];
        
        // Torna o contexto global para acesso em toda a aplicação
        $GLOBALS['flowContext'] = $context;

        try {
            $this->simulateExecution($startNodeId, $context);
            $response = [
                'message' => 'Executado com sucesso e não finalizado por nenhum nó.',
            ];

            if(isset($_ENV['SHOW_EXCEPTIONS']) && $_ENV['SHOW_EXCEPTIONS'])
            {
                $response['logs'] = $flowLog->getLogs();
            }
            // Se chegou aqui sem resposta, retorna os logs
            (new HttpResponse())->statusCode(200)->json([
                'message' => 'Executado com sucesso',
            ]);
        } catch (Exception $e) {
            $response = [
                'error' => 'Erro ao executar fluxo',
                'message' => $e->getMessage(),
                'code' => 5008
            ];
            if(isset($_ENV['SHOW_EXCEPTIONS']) && $_ENV['SHOW_EXCEPTIONS'])
            {
                $response['exception'] = $flowLog->getLastException();
            }
            (new HttpResponse())->statusCode(500)->json($response);
        }
    }

    public function getNode(string $nodeId): ?array
    {
        // Prefer controller-scoped nodes only (no legacy global scan)
        foreach ($this->controllers as $controller) {
            foreach (($controller['nodes'] ?? []) as $node) {
                if (($node['id'] ?? '') === $nodeId) return $node;
            }
        }
        return null;
    }

    // -------- Util: localizar nó inicial pelo endpoint (controller obrigatório) --------
    public function findStartNodeIdForEndpoint(array $endpoint): ?string
    {
        global $flowLog;
        
        $endpointId = $endpoint['id'] ?? null;
        $method = strtoupper($endpoint['method'] ?? 'GET');
        $path = $endpoint['path'] ?? ($endpoint['url'] ?? null);
        
        if ($flowLog) {
            $flowLog->log("→ Buscando nó inicial para endpoint: method={$method}, path={$path}, endpointId={$endpointId}");
            $flowLog->log("  Controllers disponíveis: " . implode(', ', array_keys($this->controllers)));
        }
        
        if (!$path) {
            if ($flowLog) $flowLog->log("  ✗ Path não fornecido");
            return null;
        }

        $type = match ($method) {
            'GET' => 'get',
            'POST' => 'post',
            'PUT' => 'put',
            'PATCH' => 'patch',
            'DELETE' => 'delete',
            default => strtolower($method),
        };

        if ($flowLog) {
            $flowLog->log("  Tipo de nó esperado: {$type}");
        }

        // Controller name must be the first path segment
        $controllerName = $this->extractControllerFromPath($path);
        
        if ($flowLog) {
            $flowLog->log("  Controller extraído do path: {$controllerName}");
        }
        
        if (!$controllerName || !isset($this->controllers[$controllerName])) {
            if ($flowLog) {
                $flowLog->log("  ✗ Controller '{$controllerName}' não encontrado ou não registrado");
            }
            return null;
        }
        
        $controller = $this->controllers[$controllerName];
        
        if ($flowLog) {
            $flowLog->log("  ✓ Controller encontrado. Nodes disponíveis: " . count($controller['nodes'] ?? []));
        }

        // 1) Try direct candidate pattern within controller: "{type}-{endpointId}"
        if ($endpointId) {
            $candidate = "{$type}-{$endpointId}";
            if ($flowLog) {
                $flowLog->log("  Tentando candidato direto: {$candidate}");
            }
            foreach (($controller['nodes'] ?? []) as $n) {
                if (($n['id'] ?? '') === $candidate) {
                    if ($flowLog) {
                        $flowLog->log("  ✓ Nó inicial encontrado (candidato direto): {$candidate}");
                    }
                    return $candidate;
                }
            }
            if ($flowLog) {
                $flowLog->log("  ✗ Candidato direto não encontrado");
            }
        }

        // 2) Fallback: scan controller nodes by (data.endpointId + data.type) and path prefix "/{controllerName}"
        if ($flowLog) {
            $flowLog->log("  Fazendo scan nos nodes do controller...");
        }
        
        foreach (($controller['nodes'] ?? []) as $n) {
            $data = $n['data'] ?? [];
            $nodeType = $data['type'] ?? null;
            $nodeEndpointId = $data['endpointId'] ?? null;
            
            if ($flowLog) {
                $flowLog->log("    Analisando node: id={$n['id']}, type={$nodeType}, endpointId={$nodeEndpointId}");
            }
            
            if ($nodeType !== $type) {
                if ($flowLog) {
                    $flowLog->log("      ✗ Tipo não corresponde (esperado: {$type}, encontrado: {$nodeType})");
                }
                continue;
            }

            // Ensure node path respects controller prefix if declared
            if (!$this->endpointMatchesControllerPath($controllerName, $path, $data)) {
                if ($flowLog) {
                    $flowLog->log("      ✗ Path não corresponde ao controller");
                }
                continue;
            }

            if ($endpointId && (($data['endpointId'] ?? null) === $endpointId)) {
                if ($flowLog) {
                    $flowLog->log("      ✓ Nó inicial encontrado (scan): {$n['id']}");
                }
                return $n['id'];
            }
            // If no endpointId provided or not matched, accept the first matching node
            if (!$endpointId) {
                if ($flowLog) {
                    $flowLog->log("      ✓ Nó inicial encontrado (primeiro match): {$n['id']}");
                }
                return $n['id'];
            }
        }

        if ($flowLog) {
            $flowLog->log("  ✗ Nenhum nó inicial encontrado após scan completo");
        }
        
        return null;
    }

    // Extract first path segment (controller name). Examples: "/users/owejwe" => "users"; "users" => "users"
    private function extractControllerFromPath(?string $path): ?string
    {
        if (!$path) return null;
        $trim = ltrim($path, '/');
        $parts = explode('/', $trim);
        return $parts[0] ?? null;
    }

    // Check whether node data matches the controller and path constraint
    private function endpointMatchesControllerPath(?string $controllerName, string $path, array $nodeData): bool
    {
        // Must start with "/{controllerName}"
        $first = $this->extractControllerFromPath($path);
        if (!$first || ($controllerName && $first !== $controllerName)) return false;
        // If the node declares a path, ensure it starts with "/{controllerName}"
        $nodePath = $nodeData['path'] ?? null;
        if ($nodePath) {
            $nodeFirst = $this->extractControllerFromPath($nodePath);
            if ($nodeFirst !== $first) return false;
        }
        return true;
    }

    // -------- Simulação --------
    public function simulateExecution(string $startNodeId, array $context = [])
    {
        global $flowLog;
        $currentNodeId = $startNodeId;
        $visited = [];

        while ($currentNodeId && !isset($visited[$currentNodeId])) {
            $visited[$currentNodeId] = true;
            $node = $this->getNode($currentNodeId);
            if (!$node)
                break;

            $type = $node['data']['type'] ?? ($node['type'] ?? 'unknown');
            $label = $node['data']['label'] ?? ($node['id'] ?? 'node');
            $flowLog->log("Executando node [{$node['id']}] tipo={$type} label={$label}");

            $node['data'] = $this->replaceVariablesInNodeData($node['data'] ?? []);
            $outputType = $this->callNodeExecute($type, $node, $context);
            if ($outputType === null) {
                $flowLog->log("  [Aviso] Node [{$type}][{$node['id']}] não retornou outputType. Fluxo pode parar aqui.");
            }

            // Busca o próximo node baseado no output retornado
            $currentNodeId = $this->findNextNodeIdByOutput($node['outputs'], $outputType);
        }

    }

    private function callNodeExecute(string $type, array $node, array $context): ?string
    {
        global $flowLog;
        $outputType = null;
        if (class_exists('Node' . ucfirst($type))) {
            $className = 'Node' . ucfirst($type);
            if (method_exists($className, 'execute')) {
                // O execute deve retornar o output desejado (ex: 'out', 'error', etc)
                $returnedData = (new $className())->execute($node ?? [], $context);
                $outputType = $returnedData['output'] ?? null;
                $nodeData = $returnedData['data'] ?? null;
                if ($nodeData !== null) {
                    global $flowCtx;
                    $flowCtx->setNodeData($node['id'], $outputType, $nodeData);
                }
            } else {
                $flowLog->log('Método execute() não encontrado na classe Node' . ucfirst($type) . '.');
            }
        } else {
            //check insider the folder central.dom.
            //$flowLog->log('Classe Node' . ucfirst($type) . ' não encontrada.');
            (new CustomNodeHandler())->handle(ucfirst($type));
            if (class_exists(ucfirst($type))) {
                $className = ucfirst($type);
                if (method_exists($className, 'execute')) {
                    // O execute deve retornar o output desejado (ex: 'out', 'error', etc)
                    $returnedData = (new $className())->execute($node ?? [], $context);
                    $outputType = $returnedData['output'] ?? null;
                    $nodeData = $returnedData['data'] ?? null;
                    if ($nodeData !== null) {
                        global $flowCtx;
                        $flowCtx->setNodeData($node['id'], $outputType, $nodeData);
                    }
                } else {
                    $flowLog->log('Método execute() não encontrado na classe Node' . ucfirst($type) . '.');
                }
            } else {
                $flowLog->log('Classe Node' . ucfirst($type) . ' não encontrada.');
            }
        }
        return $outputType;
    }

    /**
     * Busca o próximo nodeId a partir do node atual e do output retornado.
     */
    private function findNextNodeIdByOutput(array $outputs, ?string $output): ?string
    {
        if (!$outputs || !$output) {
            return null;
        }

        // verifica se a chave existe antes de acessar
        if (!isset($outputs[$output])) {
            return null;
        }

        return $outputs[$output]['toNodeId'] ?? null;
    }

    /**
     * Substitui variáveis no formato {{nodeId.output.variableName}} pelos valores do flowCtx
     */
    private function replaceVariablesInNodeData(array $nodeData): array
    {
        global $flowCtx;

        $replacedData = [];
        foreach ($nodeData as $key => $value) {
            if (is_string($value)) {
                $value = preg_replace_callback(
                    // Regex: {{nodeId.output.var1(.var2...)?}}
                    '/{{([\w-]+)\.([\w-]+)\.([\w.-]+)}}/',
                    function ($matches) use ($flowCtx) {
                        $nodeId = $matches[1];  // ex: auth-1756961658756
                        $output = $matches[2];  // ex: out
                        $path = $matches[3];  // ex: variable1.variable2.variable3

                        // pega dados do output
                        $nodeOutput = $flowCtx->getNodeData($nodeId, $output);

                        // navega no path
                        $parts = explode('.', $path);
                        $current = $nodeOutput;
                        foreach ($parts as $part) {
                            if (is_array($current) && array_key_exists($part, $current)) {
                                $current = $current[$part];
                            } else {
                                // se não encontrar, retorna a string original
                                return $matches[0];
                            }
                        }

                        // transforma arrays/objetos em JSON, senão retorna string
                        if (is_array($current) || is_object($current)) {
                            return json_encode($current, JSON_UNESCAPED_UNICODE);
                        }

                        return (string) $current;
                    },
                    $value
                );
            } elseif (is_array($value)) {
                // Recursão para arrays aninhadas
                $value = $this->replaceVariablesInNodeData($value);
            }
            $replacedData[$key] = $value;
        }
        return $replacedData;
    }

    /**
     * Executa um subfluxo partindo do output de um nó atual (ex.: 'beforeLoop', 'inLoop', 'afterLoop').
     * Não altera o ponteiro do fluxo principal. Percorre até não haver próximo nó ou detectar ciclo local.
     */
    public function runSubflowFromOutput(array $currentNode, string $outputKey, array $context = []): void
    {
        global $flowLog;
        $startNodeId = $this->findNextNodeIdByOutput($currentNode['outputs'] ?? [], $outputKey);
        if (!$startNodeId) {
            return; // nada conectado a este output
        }

        $visited = [];
        $nodeId = $startNodeId;
        while ($nodeId && !isset($visited[$nodeId])) {
            $visited[$nodeId] = true;
            $node = $this->getNode($nodeId);
            if (!$node) break;

            $type = $node['data']['type'] ?? ($node['type'] ?? 'unknown');
            $label = $node['data']['label'] ?? ($node['id'] ?? 'node');
            $flowLog && $flowLog->log("[Subflow {$outputKey}] Executando node [{$node['id']}] tipo={$type} label={$label}");

            $node['data'] = $this->replaceVariablesInNodeData($node['data'] ?? []);
            $out = $this->callNodeExecute($type, $node, $context);

            // avança no subfluxo conforme o output retornado
            $nodeId = $this->findNextNodeIdByOutput($node['outputs'] ?? [], $out);
        }
    }
}
