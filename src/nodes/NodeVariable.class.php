<?php

enum OutputVariable: string
{
    case OUT = 'out';
}

class NodeVariable
{
    public function execute(array $config, array $context): array
    {
        self::handleVariable($config, $context);
        return [
            'output' => OutputVariable::OUT->value
        ];
    }

    public function handleVariable(array $config, array $context): void
    {
        global $flowCtx;
        $data = $config['data'] ?? $config;
        $flowCtx->setVariable($data['variableName'] ?? '', $data['value'] ?? null);
    }
}
