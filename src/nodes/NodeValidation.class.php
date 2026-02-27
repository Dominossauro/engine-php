<?php
enum OutputValidation: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';
}

class NodeValidation
{
    public function execute(array $config, array $context): array
    {
        $validationResult = $this->validateData($config, $context);
        
        if ($validationResult['isValid']) {
            $this->handleSuccess($config, $context, $validationResult);
            return [
                'output' => OutputValidation::SUCCESS->value,
                'data' => $validationResult['data'],
                'isValid' => true
            ];
        } else {
            $this->handleFailure($config, $context, $validationResult);
            return [
                'output' => OutputValidation::FAILURE->value,
                'errors' => $validationResult['errors'],
                'isValid' => false
            ];
        }
    }

    private function validateData(array $config, array $context): array
    {
        $rules = $config['rules'] ?? [];
        $valueToValidate = $this->getValue($config, $context);
        $errors = [];
        $isValid = true;

        foreach ($rules as $rule) {
            $ruleResult = $this->applyRule($rule, $valueToValidate, $context);
            if (!$ruleResult['valid']) {
                $isValid = false;
                $errors[] = $ruleResult['message'];
            }
        }

        return [
            'isValid' => $isValid,
            'data' => $valueToValidate,
            'errors' => $errors
        ];
    }

    private function getValue(array $config, array $context): mixed
    {
        global $flowCtx;
        
        // Se tem uma variável específica para validar
        if (isset($config['variableName'])) {
            $variableName = $this->parseVariableName($config['variableName']);
            return $flowCtx->getVariable($variableName);
        }
        
        // Se tem um valor direto
        if (isset($config['value'])) {
            return $config['value'];
        }
        
        // Se tem um caminho no contexto
        if (isset($config['contextPath'])) {
            return $this->getValueFromPath($context, $config['contextPath']);
        }
        
        return null;
    }

    private function parseVariableName(string $variableName): string
    {
        // Remove a sintaxe de template {{variableName}} se presente
        $cleaned = trim($variableName);
        
        // Se tem {{ e }}, extrai o conteúdo
        if (preg_match('/^\{\{(.+?)\}\}$/', $cleaned, $matches)) {
            return trim($matches[1]);
        }
        
        // Se não tem a sintaxe de template, retorna como está
        return $cleaned;
    }

    private function getValueFromPath(array $context, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $context;
        
        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        
        return $value;
    }

    private function applyRule(array $rule, mixed $value, array $context): array
    {
        $type = $rule['type'] ?? 'required';
        
        switch ($type) {
            case 'required':
                return $this->validateRequired($value, $rule);
            case 'minLength':
                return $this->validateMinLength($value, $rule);
            case 'maxLength':
                return $this->validateMaxLength($value, $rule);
            case 'email':
                return $this->validateEmail($value, $rule);
            case 'numeric':
                return $this->validateNumeric($value, $rule);
            case 'min':
                return $this->validateMin($value, $rule);
            case 'max':
                return $this->validateMax($value, $rule);
            case 'regex':
                return $this->validateRegex($value, $rule);
            case 'custom':
                return $this->validateCustom($value, $rule, $context);
            default:
                return ['valid' => true, 'message' => ''];
        }
    }

    private function validateRequired(mixed $value, array $rule): array
    {
        // Considera válido se:
        // - Não é null
        // - Não é string vazia
        // - Não é array vazio
        // - Pode ser 0 ou '0' (valores válidos)
        $isValid = $value !== null && 
                   $value !== '' && 
                   (!is_array($value) || !empty($value)) &&
                   (!is_string($value) || trim($value) !== '');
        
        // Exceções: 0 e '0' são considerados válidos
        if ($value === 0 || $value === '0') {
            $isValid = true;
        }
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Campo obrigatório')
        ];
    }

    private function validateMinLength(mixed $value, array $rule): array
    {
        $length = is_string($value) ? strlen($value) : 0;
        $minLength = $rule['value'] ?? 0;
        $isValid = $length >= $minLength;
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Mínimo de {$minLength} caracteres")
        ];
    }

    private function validateMaxLength(mixed $value, array $rule): array
    {
        $length = is_string($value) ? strlen($value) : 0;
        $maxLength = $rule['value'] ?? 0;
        $isValid = $length <= $maxLength;
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Máximo de {$maxLength} caracteres")
        ];
    }

    private function validateEmail(mixed $value, array $rule): array
    {
        $isValid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Email inválido')
        ];
    }

    private function validateNumeric(mixed $value, array $rule): array
    {
        $isValid = is_numeric($value);
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Deve ser um número')
        ];
    }

    private function validateMin(mixed $value, array $rule): array
    {
        $min = $rule['value'] ?? 0;
        $isValid = is_numeric($value) && $value >= $min;
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Valor mínimo: {$min}")
        ];
    }

    private function validateMax(mixed $value, array $rule): array
    {
        $max = $rule['value'] ?? 0;
        $isValid = is_numeric($value) && $value <= $max;
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Valor máximo: {$max}")
        ];
    }

    private function validateRegex(mixed $value, array $rule): array
    {
        $pattern = $rule['pattern'] ?? '';
        
        if (empty($pattern)) {
            return [
                'valid' => false,
                'message' => $rule['message'] ?? 'Padrão regex não definido'
            ];
        }
        
        // Tenta executar a regex com tratamento de erro
        $isValid = false;
        try {
            $isValid = preg_match($pattern, (string)$value) === 1;
        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => $rule['message'] ?? 'Padrão regex inválido'
            ];
        }
        
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Formato inválido')
        ];
    }

    private function validateCustom(mixed $value, array $rule, array $context): array
    {
        // Para validações customizadas, pode chamar uma função específica
        $function = $rule['function'] ?? null;
        
        if ($function && is_callable($function)) {
            return $function($value, $rule, $context);
        }
        
        return ['valid' => true, 'message' => ''];
    }

    private function handleSuccess(array $config, array $context, array $validationResult): void
    {
        global $flowCtx;
        
        // Salva o resultado da validação em uma variável se especificado
        if (isset($config['successVariable'])) {
            $flowCtx->setVariable($config['successVariable'], $validationResult['data']);
        }
        
        // Executa ações de sucesso se especificadas
        if (isset($config['onSuccess'])) {
            $this->executeActions($config['onSuccess'], $context);
        }
    }

    private function handleFailure(array $config, array $context, array $validationResult): void
    {
        global $flowCtx;
        
        // Salva os erros em uma variável se especificado
        if (isset($config['errorVariable'])) {
            $flowCtx->setVariable($config['errorVariable'], $validationResult['errors']);
        }
        
        // Executa ações de falha se especificadas
        if (isset($config['onFailure'])) {
            $this->executeActions($config['onFailure'], $context);
        }
    }

    private function executeActions(array $actions, array $context): void
    {
        global $flowCtx;
        
        foreach ($actions as $action) {
            switch ($action['type'] ?? '') {
                case 'setVariable':
                    $flowCtx->setVariable($action['name'], $action['value']);
                    break;
                case 'log':
                    error_log($action['message'] ?? 'Validation action executed');
                    break;
                // Adicione mais tipos de ações conforme necessário
            }
        }
    }
}
