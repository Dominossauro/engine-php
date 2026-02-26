<?php

class FlowContext
{
    private $variables = [];
    private $nodeData = [];

    public function setVariable($name, $value)
    {
        $this->variables[$name] = $value;
    }

    public function getVariable($name)
    {
        return $this->variables[$name] ?? null;
    }

    public function getLastNodeData()
    {
        if (empty($this->nodeData)) return null;
        $lastNodeId = array_key_last($this->nodeData);
        return $this->nodeData[$lastNodeId] ?? null;
    }

    public function setNodeData($nodeId, $output, $data)
    {
        $this->nodeData[$nodeId][$output] = $data;
    }

    public function getNodeData($nodeId, $output = null)
    {
        if ($output !== null) {
            return $this->nodeData[$nodeId][$output] ?? null;
        }
        return $this->nodeData[$nodeId] ?? null;
    }
}
