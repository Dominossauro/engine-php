<?php

class CustomNodeHandler
{
  function handle($class)
  {
    if (class_exists($class)) {
      return;
    }

    $basePath = (new App())->getBasePathFromServer();
    $centralDomPath = $basePath . '/central.dom';

    if (!file_exists($centralDomPath)) {
      return;
    }

    $centralDomJson = json_decode(file_get_contents($centralDomPath), true);

    if (!isset($centralDomJson['nodes']) || !is_array($centralDomJson['nodes'])) {
      return;
    }

    foreach ($centralDomJson['nodes'] as $node) {
      if (isset($node['class']) && $node['class'] === $class) {
        $nodeFilePath = $basePath . '/' . $node['file'];
        if (file_exists($nodeFilePath)) {
          include_once $nodeFilePath;
          return;
        }
      }
    }
  }
}
