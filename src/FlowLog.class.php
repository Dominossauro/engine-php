<?php

class FlowLog
{
    private $logs = [];

    public function log($message)
    {
        $this->logs[] = $message;
    }

    public function getLogs()
    {
        return $this->logs;
    }
}

function getFlowLogger() {
    global $flowLog;
    if (!isset($flowLog) || !is_object($flowLog)) {
        $flowLog = new FlowLog();
    }
    return $flowLog;
}

function logFlow($message) {
    getFlowLogger()->log($message);
}
