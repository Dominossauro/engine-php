<?php

class FlowProcessor
{
    private array $nodes;
    // Controller registry: [controllerName => ['endpoints' => [], 'nodes' => []]]
    private array $controllers = [];

    // Informações do arquivo/endpoint sendo processado (para debug)
    private ?string $currentDomFilePath = null;
    private ?string $currentEndpoint = null;
    private ?string $currentMethod = null;
    private array $customNodes = [];

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
     * Define o arquivo .dom e endpoint sendo processado (para debug)
     */
    public function setCurrentDebugContext(string $domFilePath, string $endpoint, string $method): void
    {
        $this->currentDomFilePath = $domFilePath;
        $this->currentEndpoint = $endpoint;
        $this->currentMethod = $method;

        // Tornar global para acesso pelo Debugger
        $GLOBALS['__DEBUG_DOM_FILE__'] = $domFilePath;
        $GLOBALS['__DEBUG_ENDPOINT__'] = $endpoint;
        $GLOBALS['__DEBUG_METHOD__'] = $method;
    }

    /**
     * Registra um controller a partir de um JSON string
     * @return bool true se registrou com sucesso
     */
    public function registerControllerFromJson(array $jsonContent, string $controllerName): bool
    {
        $data = $jsonContent;

        // Enriquecer nodes com metadados de customNodes
        $enrichedNodes = $this->enrichNodesWithCustomNodeMetadata($data['nodes'] ?? []);

        $this->controllers[$controllerName] = [
            'type' => 'controller',
            'endpoints' => $data['endpoints'] ?? [],
            'nodes' => $enrichedNodes,
        ];

        return true;
    }

    /**
     * Enriquece os nodes com metadados de customNodes do central.dom
     * @param array $nodes Array de nodes
     * @return array Nodes enriquecidos
     */
    private function enrichNodesWithCustomNodeMetadata(array $nodes): array
    {
        // Carregar customNodes do central.dom
        $customNodesMap = $this->loadCustomNodesFromCentral();

        if (empty($customNodesMap)) {
            return $nodes;
        }

        // Enriquecer cada node que seja um customNode
        foreach ($nodes as &$node) {
            $type = $node['data']['type'] ?? ($node['type'] ?? null);

            if ($type && isset($customNodesMap[$type])) {
                $customNodeDef = $customNodesMap[$type];

                // Adicionar metadados do customNode ao node
                $node['_customNode'] = [
                    'nodeClass' => $customNodeDef['nodeClass'] ?? null,
                    'file' => $customNodeDef['file'] ?? null,
                    'label' => $customNodeDef['label'] ?? null,
                    'description' => $customNodeDef['description'] ?? null,
                ];
            }
        }

        return $nodes;
    }

    /**
     * Carrega customNodes do central.dom e retorna um map por name
     * @return array Map de customNodes indexado por name
     */
    private function loadCustomNodesFromCentral(): array
    {
        $centralDomPath = (new App())->getBasePathFromServer() . '/central.dom';

        if (!file_exists($centralDomPath)) {
            return [];
        }

        $centralDomContent = file_get_contents($centralDomPath);
        $centralDomJson = json_decode($centralDomContent, true);

        if (!isset($centralDomJson['customNodes']) || !is_array($centralDomJson['customNodes'])) {
            return [];
        }

        $map = [];
        foreach ($centralDomJson['customNodes'] as $customNode) {
            $name = $customNode['name'] ?? null;
            if ($name) {
                $map[$name] = $customNode;
            }
        }

        $this->customNodes = $map;

        return $map;
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

        global $httpResponse;

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
                $response['exception'] = (is_object($flowLog) && method_exists($flowLog, 'getLastException')) ? $flowLog->getLastException() : null;
                $response['method'] = $method;
                $response['path'] = $path;
                $response['availableEndpoints'] = $availableEndpoints;
            }
            if (class_exists('\PhpDebugger\\Debugger')) {
                try { \PhpDebugger\Debugger::onRequestEnd(); } catch (\Throwable $e) {}
            }
            $httpResponse->statusCode(404)->json($response);
            return;
        }

        // Encontra o nó inicial
        $startNodeId = $this->findStartNodeIdForEndpoint($endpoint);

        if (!$startNodeId) {
            $httpResponse->statusCode(500)->json([
                'error' => 'Nó inicial não encontrado para o endpoint',
                'endpoint' => $endpoint,
                'controller' => $controllerName,
                'logs' => getFlowLogger()->getLogs(),
                'code' => 5007
            ]);
            return;
        }

        getFlowLogger()->log("✓ Executando fluxo a partir do nó: {$startNodeId}");
        if (!empty($pathParams)) {
            getFlowLogger()->log("✓ Parâmetros de path extraídos: " . json_encode($pathParams));
        }

        // Executa o fluxo
        $request = new HttpRequest();
        $request->loadPathParams($pathParams);

        $context = [
            'request' => $request,
            'response' => $httpResponse,
        ];

        // Torna o contexto global para acesso em toda a aplicação
        $GLOBALS['flowContext'] = $context;

        // Inicializa o FlowContext para armazenar variáveis e dados dos nodes
        $GLOBALS['flowCtx'] = new FlowContext();

        try {
            $this->simulateExecution($startNodeId, $context);

            // Se chegou aqui sem resposta, retorna os logs
            $httpResponse->statusCode(200)->json([
                'message' => 'Executado com sucesso',
            ]);
            return;

        } catch (Exception $e) {
            $response = [
                'error' => 'Erro ao executar fluxo',
                'message' => $e->getMessage(),
                'code' => 5008
            ];
            if(isset($_ENV['SHOW_EXCEPTIONS']) && $_ENV['SHOW_EXCEPTIONS']) {
                $response['exception'] = (is_object($flowLog) && method_exists($flowLog, 'getLastException')) ? $flowLog->getLastException() : null;
            }

            $httpResponse->statusCode(500)->json($response);
            return;
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

        getFlowLogger()->log("→ Buscando nó inicial para endpoint: method={$method}, path={$path}, endpointId={$endpointId}");


        if (!$path) {
            getFlowLogger()->log("  ✗ Path não fornecido");
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

        getFlowLogger()->log("  Tipo de nó esperado: {$type}");

        // Controller name must be the first path segment
        $controllerName = $this->extractControllerFromPath($path);

        getFlowLogger()->log("  Controller extraído do path: {$controllerName}");

        if (!$controllerName || !isset($this->controllers[$controllerName])) {
            getFlowLogger()->log("  ✗ Controller '{$controllerName}' não encontrado ou não registrado");
            return null;
        }

        $controller = $this->controllers[$controllerName];

        getFlowLogger()->log("  ✓ Controller encontrado. Nodes disponíveis: " . count($controller['nodes'] ?? []));

        // 1) Try direct candidate pattern within controller: "{type}-{endpointId}"
        if ($endpointId) {
            $candidate = "{$type}-{$endpointId}";
            getFlowLogger()->log("  Tentando candidato direto: {$candidate}");
            foreach (($controller['nodes'] ?? []) as $n) {
                if (($n['id'] ?? '') === $candidate) {
                    getFlowLogger()->log("  ✓ Nó inicial encontrado (candidato direto): {$candidate}");
                    return $candidate;
                }
            }
            getFlowLogger()->log("  ✗ Candidato direto não encontrado");
        }

        // 2) Fallback: scan controller nodes by (data.endpointId + data.type) and path prefix "/{controllerName}"
        getFlowLogger()->log("  Fazendo scan nos nodes do controller...");

        foreach (($controller['nodes'] ?? []) as $n) {
            $data = $n['data'] ?? [];
            $nodeType = $data['type'] ?? null;
            $nodeEndpointId = $data['endpointId'] ?? null;

            getFlowLogger()->log("    Analisando node: id={$n['id']}, type={$nodeType}, endpointId={$nodeEndpointId}");

            if ($nodeType !== $type) {
                getFlowLogger()->log("      ✗ Tipo não corresponde (esperado: {$type}, encontrado: {$nodeType})");
                continue;
            }

            // Ensure node path respects controller prefix if declared
            if (!$this->endpointMatchesControllerPath($controllerName, $path, $data)) {
                getFlowLogger()->log("      ✗ Path não corresponde ao controller");
                continue;
            }

            if ($endpointId && (($data['endpointId'] ?? null) === $endpointId)) {
                getFlowLogger()->log("      ✓ Nó inicial encontrado (scan): {$n['id']}");
                return $n['id'];
            }
            // If no endpointId provided or not matched, accept the first matching node
            if (!$endpointId) {
                getFlowLogger()->log("      ✓ Nó inicial encontrado (primeiro match): {$n['id']}");
                return $n['id'];
            }
        }

        getFlowLogger()->log("  ✗ Nenhum nó inicial encontrado após scan completo");
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
        $nodeCounter = 0;

        while ($currentNodeId && !isset($visited[$currentNodeId])) {
            $visited[$currentNodeId] = true;
            $node = $this->getNode($currentNodeId);
            if (!$node)
                break;

            $nodeCounter++;
            $type = $node['data']['type'] ?? ($node['type'] ?? 'unknown');
            $label = $node['data']['label'] ?? ($node['id'] ?? 'node');

            getFlowLogger()->log("Executando node [{$node['id']}] tipo={$type} label={$label}");

            $shouldPause = false;
            $stepModeBeforePause = false;

            // Verificar se está em step mode
            if ($this->isDebugStepMode()) {
                $stepModeBeforePause = true;
                $shouldPause = true;
                getFlowLogger()->log("DEBUG STEP: Pausando no node [{$node['id']}]");
            }
            elseif (isset($node['data']['breakpoint']) && $node['data']['breakpoint'] === true) {
                getFlowLogger()->log("BREAKPOINT ATIVO no node [{$node['id']}]");
                $shouldPause = true;
            }

            if ($shouldPause) {
                $this->handleBreakpoint($node, $context);
            }

            $node['data'] = $this->replaceVariablesInNodeData($node['data'] ?? []);
            $outputType = $this->callNodeExecute($type, $node, $context);

            if ($outputType === null) {
                getFlowLogger()->log("  [Aviso] Node [{$type}][{$node['id']}] não retornou outputType. Fluxo pode parar aqui.");
            }

            $nextConnections = $this->findNextNodesByOutput($node['outputs'], $outputType);

            if (empty($nextConnections)) {
                getFlowLogger()->log("🏁 FIM DO FLUXO (sem próximo node)");
                $currentNodeId = null;
            } else {
                $totalConns = count($nextConnections);

                if ($totalConns > 1) {
                    getFlowLogger()->log("🔀 Output '{$outputType}' tem {$totalConns} conexões");
                }

                // Conexões adicionais (order 2, 3, ...): executar como subflows sequenciais
                for ($i = 1; $i < $totalConns; $i++) {
                    $conn = $nextConnections[$i];
                    $order = $conn['order'] ?? ($i + 1);
                    getFlowLogger()->log("  ↳ Executando branch {$order}/{$totalConns} → {$conn['toNodeId']}");
                    $this->executeBranch($conn['toNodeId'], $context);
                }

                // Primeira conexão (order 1): continua o loop principal
                $currentNodeId = $nextConnections[0]['toNodeId'];
                getFlowLogger()->log("➡️  Próximo node: $currentNodeId");
            }
        }

        getFlowLogger()->log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        getFlowLogger()->log("✅ FLUXO COMPLETO");
        getFlowLogger()->log("   Total de nodes executados: $nodeCounter");
        getFlowLogger()->log("   Nodes: " . implode(' → ', array_keys($visited)));
        getFlowLogger()->log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    /**
     * Verifica se o debugger está em modo step
     * @return bool
     */
    private function isDebugStepMode(): bool
    {
        if (!class_exists('PhpDebugger\\Debugger')) {
            return false;
        }

        if (!method_exists('PhpDebugger\\Debugger', 'isStepMode')) {
            return false;
        }

        return \PhpDebugger\Debugger::isStepMode();
    }

    /**
     * Manipula a pausa de execução quando um breakpoint é atingido
     */
    private function handleBreakpoint(array $node, array $context): void
    {
        global $flowLog, $flowCtx;

        // Verificar se o Debugger está disponível
        if (!class_exists('PhpDebugger\\Debugger')) {
            getFlowLogger()->log("⚠️ Debugger não disponível - ignorando breakpoint");
            return;
        }

        // Preparar contexto do node para o debugger
        $debugContext = [
            'nodeId' => $node['id'],
            'nodeType' => $node['data']['type'] ?? 'unknown',
            'nodeLabel' => $node['data']['label'] ?? $node['id'],
            'nodeSummary' => $node['data']['summary'] ?? '',
            'nodeData' => $node['data'] ?? [],
            'nodePosition' => [
                'x' => $node['position']['x'] ?? 0,
                'y' => $node['position']['y'] ?? 0,
            ],
            'file' => $GLOBALS['__DEBUG_DOM_FILE__'] ?? __FILE__,
            'line' => __LINE__,
            'domFilePath' => $GLOBALS['__DEBUG_DOM_FILE__'] ?? null,
            'endpoint' => $GLOBALS['__DEBUG_ENDPOINT__'] ?? null,
            'method' => $GLOBALS['__DEBUG_METHOD__'] ?? null,
        ];

        // Coletar variáveis disponíveis no contexto
        $availableVariables = [
            'node' => $node,
            'context' => $context,
            'request' => $context['request'] ?? null,
            'response' => $context['response'] ?? null,
        ];

        // Se tiver flowCtx, adicionar dados dos nodes anteriores
        if (isset($flowCtx) && is_object($flowCtx) && method_exists($flowCtx, 'getAllNodeData')) {
            $availableVariables['flowData'] = $flowCtx->getAllNodeData();
        }

        getFlowLogger()->log("🔴 Pausando execução no node: {$debugContext['nodeId']}");
        getFlowLogger()->log("   Tipo: {$debugContext['nodeType']}");
        getFlowLogger()->log("   Label: {$debugContext['nodeLabel']}");

        // Definir contexto atual no Debugger
        \PhpDebugger\Debugger::setCurrentNode($debugContext);

        // Salvar referências antes do breakpoint (debug_capture.php pode corromper globals)
        $__savedFlowCtx__ = $flowCtx;
        $__savedFlowLog__ = $flowLog;

        // Pausar execução e aguardar comando do VS Code
        include BREAKPOINT;

        // Restaurar globals após o breakpoint
        global $flowLog, $flowCtx;
        $flowCtx = $__savedFlowCtx__;
        $flowLog = $__savedFlowLog__;
        unset($__savedFlowCtx__, $__savedFlowLog__);
    }

    private function callNodeExecute(string $type, array $node, array $context): ?string
    {
        global $flowLog;
        $outputType = null;

        if (class_exists('Node' . ucfirst($type))) {
            $className = 'Node' . ucfirst($type);
            if (method_exists($className, 'execute')) {
                $returnedData = (new $className())->execute($node ?? [], $context);
                getFlowLogger()->log("🔧 [{$type}] Retorno bruto: " . json_encode($returnedData, JSON_UNESCAPED_UNICODE));
                $outputType = $returnedData['output'] ?? null;
                $nodeData = $returnedData['data'] ?? null;
                if ($nodeData !== null) {
                    global $flowCtx;
                    $flowCtx->setNodeData($node['id'], $outputType, $nodeData);
                }
            } else {
                getFlowLogger()->log('Método execute() não encontrado na classe Node' . ucfirst($type) . '.');
            }
        } else {
            // Usar CustomNodeHandler com o mapa de customNodes já carregado
            $customNodeHandler = new CustomNodeHandler($this->customNodes);

            try {
                $returnedData = $customNodeHandler->execute($type, $node, $context);

                getFlowLogger()->log("🔧 [Custom {$type}] Retorno bruto: " . json_encode($returnedData, JSON_UNESCAPED_UNICODE));

                $outputType = $returnedData['output'] ?? null;
                $nodeData = $returnedData['data'] ?? null;
            } catch (Exception $e) {
                getFlowLogger()->log("❌ ERRO ao executar custom node '$type': " . $e->getMessage());
                throw $e;
            }
        }
        return $outputType;
    }

    /**
     * Retorna array de conexões ordenadas por 'order' para um dado output type.
     * Suporta tanto o formato novo (connections[]) quanto o legado (toNodeId direto).
     * @return array<array{toNodeId: string, inputType: string, order: int}>
     */
    private function findNextNodesByOutput(array $outputs, ?string $output): array
    {
        if (!$outputs || !$output || !isset($outputs[$output])) {
            return [];
        }

        $outputData = $outputs[$output];

        // Novo formato: connections[]
        if (isset($outputData['connections']) && is_array($outputData['connections'])) {
            $connections = $outputData['connections'];
            usort($connections, fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
            return $connections;
        }

        // Formato legado: toNodeId direto
        if (isset($outputData['toNodeId']) && $outputData['toNodeId'] !== null) {
            return [
                [
                    'toNodeId' => $outputData['toNodeId'],
                    'inputType' => $outputData['inputType'] ?? 'in',
                    'order' => 1,
                ]
            ];
        }

        return [];
    }

    /**
     * Atalho de compatibilidade: retorna o primeiro toNodeId de um output.
     * Usado internamente quando só se precisa do próximo node (ex: subflows simples).
     */
    private function findNextNodeIdByOutput(array $outputs, ?string $output): ?string
    {
        $connections = $this->findNextNodesByOutput($outputs, $output);
        return $connections[0]['toNodeId'] ?? null;
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
                        // Verificar se $flowCtx é um objeto válido
                        if (!is_object($flowCtx) || !method_exists($flowCtx, 'getNodeData')) {
                            return $matches[0]; // Retorna string original se $flowCtx inválido
                        }

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
        $startNodeId = $this->findNextNodeIdByOutput($currentNode['outputs'] ?? [], $outputKey);
        if (!$startNodeId) {
            return; // nada conectado a este output
        }

        $this->executeBranch($startNodeId, $context, "[Subflow {$outputKey}]");
    }

    /**
     * Executa uma branch (subfluxo) a partir de um node, compartilhando o mesmo FlowContext.
     * Usado quando um output tem múltiplas conexões e para subflows (loop, etc.).
     * Cada branch tem seu próprio controle de ciclo (visited).
     */
    private function executeBranch(string $startNodeId, array $context, string $logPrefix = '[Branch]'): void
    {
        $visited = [];
        $nodeId = $startNodeId;

        while ($nodeId && !isset($visited[$nodeId])) {
            $visited[$nodeId] = true;
            $node = $this->getNode($nodeId);
            if (!$node) break;

            $type = $node['data']['type'] ?? ($node['type'] ?? 'unknown');
            $label = $node['data']['label'] ?? ($node['id'] ?? 'node');
            getFlowLogger()->log("{$logPrefix} Executando node [{$node['id']}] tipo={$type} label={$label}");

            $node['data'] = $this->replaceVariablesInNodeData($node['data'] ?? []);
            $out = $this->callNodeExecute($type, $node, $context);

            if ($out === null) {
                getFlowLogger()->log("{$logPrefix} Node [{$node['id']}] não retornou output. Branch encerrada.");
                break;
            }

            $nextConnections = $this->findNextNodesByOutput($node['outputs'] ?? [], $out);

            if (empty($nextConnections)) {
                getFlowLogger()->log("{$logPrefix} 🏁 Fim da branch (sem próximo node)");
                break;
            }

            // Branches adicionais deste node (recursivo)
            $totalConns = count($nextConnections);
            for ($i = 1; $i < $totalConns; $i++) {
                $conn = $nextConnections[$i];
                $order = $conn['order'] ?? ($i + 1);
                getFlowLogger()->log("{$logPrefix}   ↳ Sub-branch {$order}/{$totalConns} → {$conn['toNodeId']}");
                $this->executeBranch($conn['toNodeId'], $context, "{$logPrefix}>{$order}");
            }

            // Continua a branch principal (primeira conexão)
            $nodeId = $nextConnections[0]['toNodeId'] ?? null;
        }
    }
}
