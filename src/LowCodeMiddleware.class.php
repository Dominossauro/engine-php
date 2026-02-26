<?php

class LowCodeMiddleware
{
    private LowCodeAPI $api;

    public function __construct(LowCodeAPI $api)
    {
        $this->api = $api;
    }

    public function handle(): void
    {
        if (!$this->api->validateCurrentRequest()) {
            return;
            /*http_response_code(404);
            echo json_encode(["error" => "Endpoint não encontrado"]);
            exit;*/
        }

        $endpoint = $this->api->getCurrentEndpoint();
        $graph = $this->api->getGraph();

        global $flowLog;
        $processor = new FlowProcessor($graph['nodes']);
        $startNodeId = $processor->findStartNodeIdForEndpoint($endpoint ?? []);

        if (!$startNodeId) {
            (new HttpResponse())->statusCode(500)->json([
                "error" => "Nó inicial não encontrado para o endpoint",
                "endpoint" => $endpoint,
                "error_code" => 5342
            ]);
        }

        // Contexto opcional com envs
        $envVars = $this->api->getEnvironmentVars();
        $context = ['env' => $envVars];

        $flowLog->log("Iniciando execução do fluxo para endpoint {$endpoint['method']} {$endpoint['path']} a partir do nó {$startNodeId}...");

        $processor->simulateExecution($startNodeId, $context);

        $flowLog->log("Execução do fluxo finalizada.");
    }
}