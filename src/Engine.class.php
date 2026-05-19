<?php

include_once 'FlowLog.class.php';
include_once 'FlowContext.class.php';
include_once 'CustomNodeHandler.class.php';
include_once 'ClassReflector.class.php';
include_once 'FlowProcessor.class.php';
include_once 'LowCodeMiddleware.class.php';
include_once 'LowCodeApi.class.php';

// Inicializa o logger global usando a função segura
getFlowLogger()->log('Starting Engine...');

global $flowCtx;
$flowCtx = new FlowContext();

/*
    global $flowCtx;
    $flowCtx->setVariable('api', 'test');
    echo $flowCtx->getVariable('api');
*/
class Engine
{
    public array $systemNodeFiles = [
        'NodeGet' => '/router/src/nodes/NodeGet.class.php',
        'NodeResponse' => '/httpresponse/src/nodes/NodeResponse.class.php',
        'NodeQuery' => '/router/src/nodes/NodeQuery.class.php',
        'NodeAuth' => '/router/src/nodes/NodeAuth.class.php',
        'NodeVariable' => '/engine/src/nodes/NodeVariable.class.php',
        'NodeClassCaller' => '/engine/src/nodes/NodeClassCaller.class.php'
    ];

    public function handleEndpointRequest(array $apiJson, array $requestInfo) {

        // Extract request information provided by the Router
        $controllerName = $requestInfo['controllerName'];
        $currentMethod = $requestInfo['method'];
        $currentPath = $requestInfo['path'];

        // Create FlowProcessor and register the controller
        $flowProcessor = new FlowProcessor([]);

        // Configurar contexto de debug com o arquivo .dom (para breakpoints visuais)
        $domFilePath = $requestInfo['domFilePath'] ?? null;
        if ($domFilePath) {
            $flowProcessor->setCurrentDebugContext($domFilePath, $currentPath, $currentMethod);
        }

        if (!$flowProcessor->registerControllerFromJson($apiJson, $controllerName)) {
            (new HttpResponse())->statusCode(500)->json([
                'error' => 'Failed to load controller',
                'controller' => $controllerName,
                'code' => 5006
            ]);
            return;
        }

        //all nodes from system
        $this->includeSystemNodes($this->systemNodeFiles);

        // Execute the controller
        $flowProcessor->executeController($controllerName, $currentMethod, $currentPath);
    }

    public function includeSystemNodes(array $nodeFiles): void
    {
        $path = (new App())->getDominossauroComposerPath();
        foreach ($nodeFiles as $className => $filePath) {
            if(class_exists($className)) {
                continue;
            }
            if (file_exists($path . $filePath)) {
                include_once $path . $filePath;
            }
        }
    }
}
