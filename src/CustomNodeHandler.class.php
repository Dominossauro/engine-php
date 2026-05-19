<?php

class CustomNodeHandler {

  private array $customNodesMap = [];

  function __construct(array $customNodesMap = [])
  {
    $this->customNodesMap = $customNodesMap;
  }

  /**
   * Define o mapa de custom nodes (para evitar recarregar do central.dom)
   */
  function setCustomNodesMap(array $map): void
  {
    $this->customNodesMap = $map;
  }

  function extractNodeData($class) {
    $centralDomPath = (new App())->getBasePathFromServer() . '/central.dom';
    if (file_exists($centralDomPath)) {
      $centralDomContent = file_get_contents($centralDomPath);
      $centralDomJson = json_decode($centralDomContent, true);

      // Buscar em 'customNodes' usando 'nodeClass'
      if (isset($centralDomJson['customNodes']) && is_array($centralDomJson['customNodes'])) {
        foreach ($centralDomJson['customNodes'] as $node) {
          if (isset($node['nodeClass']) && $node['nodeClass'] === $class) {
            return $node;
          }
        }
      }
    } else {
      global $flowLog;
      if (is_object($flowLog) && method_exists($flowLog, 'log')) {
        $flowLog->log("Arquivo central.dom não encontrado em: " . $centralDomPath);
      }
    }
    return null;
  }

  function handle($class)
  {
    // Se a classe já existe, não precisa carregar
    if (class_exists($class)) {
      return;
    }

    $centralDomPath = (new App())->getBasePathFromServer() . '/central.dom';
    if (!file_exists($centralDomPath)) {
      return;
    }

    $centralDomContent = file_get_contents($centralDomPath);
    $centralDomJson = json_decode($centralDomContent, true);

    // Buscar em 'customNodes' usando 'nodeClass'
    if (isset($centralDomJson['customNodes']) && is_array($centralDomJson['customNodes'])) {
      foreach ($centralDomJson['customNodes'] as $node) {
        if (isset($node['nodeClass']) && $node['nodeClass'] === $class) {
          $nodeFilePath = (new App())->getBasePathFromServer() . '/' . $node['file'];
          if (file_exists($nodeFilePath)) {
            include_once $nodeFilePath;
            return;
          }
        }
      }
    }
  }

  /**
   * Executa um custom node baseado na definição do central.dom
   * Identifica o input sendo chamado e aciona o classNodeMethod correspondente
   *
   * @param string $type Tipo/nome do custom node
   * @param array $node Dados do node atual
   * @param array $context Contexto de execução
   * @return array Resultado da execução ['output' => string, 'data' => mixed]
   */
  function execute(string $type, array $node, array $context): array
  {
    global $flowLog;

    // Buscar definição do custom node
    $customNodeDef = $this->getCustomNodeDefinition($type);

    if (!$customNodeDef) {
      throw new Exception("Custom node '$type' não encontrado nas definições do central.dom");
    }

    $nodeClass = $customNodeDef['nodeClass'] ?? null;

    if (!$nodeClass) {
      throw new Exception("nodeClass não definido para o custom node '$type'");
    }

    // Carregar o arquivo da classe se necessário
    $this->handle($nodeClass);

    if (!class_exists($nodeClass)) {
      throw new Exception("Classe '$nodeClass' não encontrada após carregar o arquivo");
    }

    // Identificar qual input está sendo chamado
    $inputName = $node['data']['input'] ?? $this->getDefaultInput($customNodeDef);
    $classMethod = $this->getClassMethodForInput($customNodeDef, $inputName);

    if (is_object($flowLog) && method_exists($flowLog, 'log')) {
      $flowLog->log("[CustomNodeHandler] Executando '$type' -> $nodeClass::$classMethod (input: $inputName)");
    }

    // Instanciar a classe e chamar o método
    $instance = new $nodeClass();

    if (!method_exists($instance, $classMethod)) {
      throw new Exception("Método '$classMethod' não encontrado na classe '$nodeClass'");
    }

    // Executar o método com os parâmetros necessários
    $result = $instance->$classMethod($node, $context);

    // Normalizar resultado
    if (!is_array($result)) {
      $result = ['output' => null, 'data' => $result];
    }

    if (!isset($result['output'])) {
      $result['output'] = null;
    }

    if (!isset($result['data'])) {
      $result['data'] = null;
    }

    return $result;
  }

  /**
   * Busca a definição do custom node pelo tipo/nome
   */
  private function getCustomNodeDefinition(string $type): ?array
  {
    // Primeiro tenta no mapa já carregado
    if (isset($this->customNodesMap[$type])) {
      return $this->customNodesMap[$type];
    }

    // Fallback: carregar do central.dom
    $centralDomPath = (new App())->getBasePathFromServer() . '/central.dom';

    if (!file_exists($centralDomPath)) {
      return null;
    }

    $centralDomContent = file_get_contents($centralDomPath);
    $centralDomJson = json_decode($centralDomContent, true);

    if (!isset($centralDomJson['customNodes']) || !is_array($centralDomJson['customNodes'])) {
      return null;
    }

    foreach ($centralDomJson['customNodes'] as $customNode) {
      $name = $customNode['name'] ?? null;
      if ($name === $type) {
        return $customNode;
      }
    }

    return null;
  }

  /**
   * Obtém o input padrão (primeiro da lista) se nenhum for especificado
   */
  private function getDefaultInput(array $customNodeDef): ?string
  {
    $inputs = $customNodeDef['inputs'] ?? [];

    if (empty($inputs)) {
      return null;
    }

    return $inputs[0]['name'] ?? null;
  }

  /**
   * Obtém o classNodeMethod para um input específico
   */
  private function getClassMethodForInput(array $customNodeDef, ?string $inputName): string
  {
    $inputs = $customNodeDef['inputs'] ?? [];

    // Buscar o input pelo nome
    foreach ($inputs as $input) {
      if (($input['name'] ?? null) === $inputName) {
        return $input['classNodeMethod'] ?? 'execute';
      }
    }

    // Se não encontrar, tenta o primeiro input
    if (!empty($inputs) && isset($inputs[0]['classNodeMethod'])) {
      return $inputs[0]['classNodeMethod'];
    }

    // Fallback para método execute padrão
    return 'execute';
  }
}
