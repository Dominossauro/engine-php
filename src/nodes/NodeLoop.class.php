<?php

enum OutputLoop: string
{
    case AFTER_LOOP = 'afterLoop';
    case ERROR = 'error';
    case IN_LOOP = 'inLoop';
    case BEFORE_LOOP = 'beforeLoop';
}

class NodeLoop
{
    public function execute(array $config, array $context): ?array
    {
        global $flowProcessor;
        global $flowCtx;
        global $flowLog;

        $nodeId = $config['id'] ?? 'node-loop';
        $data = $config['data'] ?? [];
        $outputs = $config['outputs'] ?? [];

        // Resolve items to iterate
        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $itemVarName = $data['itemVarName'] ?? 'loopItem';

        // 1) beforeLoop subflow
        try {
            if (isset($outputs['beforeLoop'])) {
                $flowProcessor->runSubflowFromOutput($config, 'beforeLoop', $context);
            }
        } catch (\Throwable $e) {
            $flowLog && $flowLog->log("[NodeLoop] Erro em beforeLoop: " . $e->getMessage());
            return [
                'output' => OutputLoop::ERROR->value,
                'data' => ['message' => 'Erro no beforeLoop', 'error' => $e->getMessage()]
            ];
        }

        // 2) inLoop subflow per item
        $processed = 0;
        foreach ($items as $idx => $item) {
            // expose item to context so subflow nodes can use {{...}} or flowCtx vars
            $flowCtx->setVariable($itemVarName, $item);
            $flowCtx->setNodeData($nodeId, 'currentItem', ['index' => $idx, 'value' => $item]);
            try {
                if (isset($outputs['inLoop'])) {
                    $flowProcessor->runSubflowFromOutput($config, OutputLoop::IN_LOOP->value, $context);
                }
                $processed++;
            } catch (\Throwable $e) {
                $flowLog && $flowLog->log("[NodeLoop] Erro em inLoop item={$idx}: " . $e->getMessage());
                return [
                    'output' => OutputLoop::ERROR->value,
                    'data' => ['message' => 'Erro no inLoop', 'index' => $idx, 'error' => $e->getMessage()]
                ];
            }
        }

        /*// 3) afterLoop subflow
        try {
            if (isset($outputs['afterLoop'])) {
                $flowProcessor->runSubflowFromOutput($config, OutputLoop::AFTER_LOOP->value, $context);
            }
        } catch (\Throwable $e) {
            $flowLog && $flowLog->log("[NodeLoop] Erro em afterLoop: " . $e->getMessage());
            return [
                'output' => OutputLoop::ERROR->value,
                'data' => ['message' => 'Erro no afterLoop', 'error' => $e->getMessage()]
            ];
        }*/

        // Final: continue o fluxo principal via 'out'
        return [
            'output' => OutputLoop::AFTER_LOOP->value,
            'data' => [
                'message' => 'Loop node executed successfully',
                'count' => $processed,
                'itemVarName' => $itemVarName
            ]
        ];
    }
}
