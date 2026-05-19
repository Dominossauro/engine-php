<?php

class ClassReflector
{
    private string $srcPath;

    public function __construct(?string $srcPath = null)
    {
        $this->srcPath = $srcPath ?? ((new App())->getBasePathFromServer() . '/src');
    }

    /**
     * Escaneia o diretório src/ e retorna todas as classes PHP encontradas.
     * Usa token_get_all para extrair nomes sem executar os arquivos.
     *
     * @return array Lista de classes: [['className' => string, 'file' => string, 'namespace' => string|null]]
     */
    public function getClasses(): array
    {
        $classes = [];
        $files = $this->scanPhpFiles($this->srcPath);

        foreach ($files as $filePath) {
            $found = $this->extractClassesFromFile($filePath);
            foreach ($found as $classInfo) {
                $classes[] = $classInfo;
            }
        }

        usort($classes, fn($a, $b) => strcmp($a['className'], $b['className']));

        return $classes;
    }

    /**
     * Retorna os métodos públicos de uma classe com seus parâmetros.
     *
     * @param string $className Nome completo da classe (com namespace se houver)
     * @param string|null $filePath Caminho do arquivo para incluir se a classe não estiver carregada
     * @return array|null Lista de métodos ou null se a classe não for encontrada
     */
    public function getMethods(string $className, ?string $filePath = null): ?array
    {
        // Carregar arquivo se necessário
        if (!class_exists($className) && $filePath) {
            $fullPath = $this->resolveFilePath($filePath);
            if ($fullPath && file_exists($fullPath)) {
                include_once $fullPath;
            }
        }

        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            return null;
        }

        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Pular métodos mágicos (__construct, __destruct, etc.)
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            $methods[] = [
                'name' => $method->getName(),
                'isStatic' => $method->isStatic(),
                'parameters' => $this->extractParameters($method),
                'returnType' => $this->getReturnType($method),
                'docComment' => $this->parseDocComment($method->getDocComment() ?: ''),
            ];
        }

        usort($methods, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $methods;
    }

    /**
     * Retorna informação completa de uma classe: métodos, propriedades, etc.
     */
    public function getClassInfo(string $className, ?string $filePath = null): ?array
    {
        $methods = $this->getMethods($className, $filePath);
        if ($methods === null) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        return [
            'className' => $className,
            'namespace' => $reflection->getNamespaceName() ?: null,
            'isAbstract' => $reflection->isAbstract(),
            'isInterface' => $reflection->isInterface(),
            'parentClass' => $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null,
            'methods' => $methods,
        ];
    }

    /**
     * Resolve parâmetros de um método usando Reflection e valores configurados.
     * Usado internamente pelo NodeClassCaller para injetar valores nos parâmetros.
     *
     * @param string $className Nome da classe
     * @param string $methodName Nome do método
     * @param array $configuredParams Array de parâmetros configurados: [['name' => string, 'value' => mixed]]
     * @param array $context Contexto de execução do flow
     * @return array Parâmetros resolvidos na ordem correta para chamar o método
     */
    public function resolveParameters(string $className, string $methodName, array $configuredParams, array $context = []): array
    {
        global $flowCtx;

        try {
            $reflection = new ReflectionMethod($className, $methodName);
        } catch (ReflectionException $e) {
            throw new Exception("Método '{$methodName}' não encontrado na classe '{$className}'");
        }

        // Indexar params configurados por nome
        $paramMap = [];
        foreach ($configuredParams as $param) {
            $paramMap[$param['name']] = $param['value'] ?? null;
        }

        $resolvedArgs = [];

        foreach ($reflection->getParameters() as $refParam) {
            $paramName = $refParam->getName();

            // Parâmetros especiais injetados automaticamente
            if ($paramName === 'context' || $paramName === 'flowContext') {
                $resolvedArgs[] = $context;
                continue;
            }
            if ($paramName === 'request') {
                $resolvedArgs[] = $context['request'] ?? null;
                continue;
            }
            if ($paramName === 'response') {
                $resolvedArgs[] = $context['response'] ?? null;
                continue;
            }
            if ($paramName === 'flowCtx') {
                $resolvedArgs[] = $flowCtx;
                continue;
            }

            // Valor configurado no modal
            if (array_key_exists($paramName, $paramMap)) {
                $value = $paramMap[$paramName];
                $resolvedArgs[] = $this->castValue($value, $refParam);
                continue;
            }

            // Valor default do próprio parâmetro PHP
            if ($refParam->isDefaultValueAvailable()) {
                $resolvedArgs[] = $refParam->getDefaultValue();
                continue;
            }

            // Parâmetro obrigatório sem valor → null
            $resolvedArgs[] = null;
        }

        return $resolvedArgs;
    }

    // ---- Métodos internos ----

    /**
     * Escaneia recursivamente o diretório por arquivos PHP.
     */
    private function scanPhpFiles(string $directory): array
    {
        $files = [];

        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Extrai nomes de classes de um arquivo PHP usando tokenizer (sem executar o arquivo).
     */
    private function extractClassesFromFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $tokens = token_get_all($content);
        $classes = [];
        $namespace = '';

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            // Detectar namespace
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                $i++;
                while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$i][1];
                    }
                    $i++;
                }
            }

            // Detectar class/interface/trait (pular abstract/final)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                // Pular se for ::class
                if ($i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
                    continue;
                }

                // Pegar o nome da classe
                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $className = $tokens[$i][1];
                    $fullClassName = $namespace ? $namespace . '\\' . $className : $className;
                    $relativePath = str_replace($this->srcPath, '', $filePath);
                    $relativePath = ltrim($relativePath, '/\\');

                    $classes[] = [
                        'className' => $fullClassName,
                        'shortName' => $className,
                        'namespace' => $namespace ?: null,
                        'file' => 'src/' . $relativePath,
                        'absolutePath' => $filePath,
                    ];
                }
            }
        }

        return $classes;
    }

    /**
     * Extrai informações dos parâmetros de um método.
     */
    private function extractParameters(ReflectionMethod $method): array
    {
        $params = [];

        foreach ($method->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'type' => $this->getParameterType($param),
                'required' => !$param->isOptional(),
                'hasDefault' => $param->isDefaultValueAvailable(),
            ];

            if ($param->isDefaultValueAvailable()) {
                try {
                    $paramInfo['defaultValue'] = $param->getDefaultValue();
                } catch (ReflectionException $e) {
                    $paramInfo['defaultValue'] = null;
                }
            }

            $params[] = $paramInfo;
        }

        return $params;
    }

    /**
     * Obtém o tipo de um parâmetro como string.
     */
    private function getParameterType(ReflectionParameter $param): ?string
    {
        $type = $param->getType();
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $t->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && !$type->isBuiltin() ? '?' : '') . $type->getName();
        }

        return (string) $type;
    }

    /**
     * Obtém o tipo de retorno de um método como string.
     */
    private function getReturnType(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $t->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() ? '?' : '') . $type->getName();
        }

        return (string) $type;
    }

    /**
     * Extrai descrição curta do docblock de um método.
     */
    private function parseDocComment(string $docComment): ?string
    {
        if (empty($docComment)) {
            return null;
        }

        // Remover /** e */ e limpar linhas
        $lines = explode("\n", $docComment);
        $description = '';

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B*/");
            if (empty($line) || str_starts_with($line, '@')) {
                continue;
            }
            $description .= ($description ? ' ' : '') . $line;
        }

        return $description ?: null;
    }

    /**
     * Faz cast do valor para o tipo esperado pelo parâmetro.
     */
    private function castValue(mixed $value, ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type === null || !($type instanceof ReflectionNamedType)) {
            return $value;
        }

        $typeName = $type->getName();

        return match ($typeName) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            'array' => is_string($value) ? (json_decode($value, true) ?? [$value]) : (array) $value,
            default => $value,
        };
    }

    /**
     * Resolve um caminho relativo para absoluto.
     */
    private function resolveFilePath(string $filePath): ?string
    {
        // Se já é absoluto
        if (str_starts_with($filePath, '/')) {
            return $filePath;
        }

        // Relativo ao basePath
        $basePath = (new App())->getBasePathFromServer();
        $fullPath = $basePath . '/' . $filePath;

        return file_exists($fullPath) ? $fullPath : null;
    }
}
