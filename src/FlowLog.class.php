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
