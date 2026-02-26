<?php
enum OutputSetVariableValue: string
{
    case OUT = 'out';
}

class NodeSetVariableValue
{
  public function execute(array $config, array $context): array
  {
      self::handleVariable($config, $context);
      return [
            'output' => OutputSetVariableValue::OUT->value
        ];
  }

    public function handleVariable(array $config, array $context): void
    {
        global $flowCtx;
        $flowCtx->setVariable($config['variableName'], $config['value']);
    }
}
