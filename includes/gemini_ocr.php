<?php

function geminiOcrResponseSchema(): array
{
    $campos = [
        'cedula_escolar' => 20,
        'nombre' => 100,
        'apellido' => 100,
        'fecha_nacimiento' => 10,
        'genero' => 10,
        'lugar_nacimiento' => 100,
        'telefono' => 20,
        'direccion' => 500,
        'escuela_anterior' => 255,
        'email' => 255,
        'tipo_vivienda' => 20,
        'personas_conviven' => 2,
        'habilidades_especiales' => 500,
        'enfermedades' => 500,
        'alergias' => 500,
        'medicamentos' => 500,
        'observaciones' => 1000,
    ];
    $representante = [
        'nombre' => 100,
        'apellido' => 100,
        'cedula' => 20,
        'parentesco' => 10,
        'telefono' => 20,
        'telefono_alternativo' => 20,
        'fecha_nacimiento' => 10,
        'genero' => 10,
        'profesion' => 100,
        'lugar_trabajo' => 255,
        'direccion' => 500,
        'email' => 255,
    ];
    $documentosPresentados = [
        'acta_nacimiento',
        'cedula_representante',
        'fotos',
        'certificado_medico',
        'boleta_anterior',
        'partida_bautismo',
    ];
    $crearPropiedades = static function (array $definiciones): array {
        $propiedades = [];
        foreach ($definiciones as $campo => $longitud) {
            $propiedades[$campo] = [
                'type' => 'string',
                'maxLength' => $longitud,
            ];
        }
        $propiedades['genero']['enum'] = ['masculino', 'femenino', ''];
        return $propiedades;
    };
    $propiedadesEstudiante = $crearPropiedades($campos);
    $propiedadesEstudiante['tipo_vivienda']['enum'] = ['', 'Propia', 'Alquilada', 'Familiar', 'Otro'];
    $propiedadesEstudiante['documentos_presentados'] = [
        'type' => 'array',
        'items' => [
            'type' => 'string',
            'enum' => $documentosPresentados,
        ],
        'maxItems' => count($documentosPresentados),
    ];
    $propiedadesRepresentante = $crearPropiedades($representante);
    $propiedadesRepresentante['parentesco']['enum'] = [
        '',
        'madre',
        'padre',
        'abuelo',
        'abuela',
        'tio',
        'tia',
        'hermano',
        'hermana',
        'otro',
    ];

    return [
        'type' => 'object',
        'properties' => [
            'estudiante' => [
                'type' => 'object',
                'properties' => $propiedadesEstudiante,
                'required' => array_merge(array_keys($campos), ['documentos_presentados']),
            ],
            'representante' => [
                'type' => 'object',
                'properties' => $propiedadesRepresentante,
                'required' => array_keys($representante),
            ],
        ],
        'required' => ['estudiante', 'representante'],
    ];
}

function geminiOcrResponseText(array $respuesta): ?string
{
    if (isset($respuesta['output_text']) && is_string($respuesta['output_text'])) {
        return $respuesta['output_text'];
    }

    if (($respuesta['type'] ?? '') === 'text' &&
        isset($respuesta['text']) && is_string($respuesta['text'])) {
        return $respuesta['text'];
    }

    foreach ($respuesta as $valor) {
        if (is_array($valor)) {
            $textoAnidado = geminiOcrResponseText($valor);
            if ($textoAnidado !== null) {
                return $textoAnidado;
            }
        }
    }
    return null;
}

function geminiOcrInteractionErrorCodes(array $errors): array
{
    $codes = [];
    foreach ($errors as $error) {
        if (!is_array($error) || !isset($error['code']) || !is_string($error['code'])) {
            continue;
        }
        $code = $error['code'];
        $separator = strrpos($code, '/');
        if ($separator !== false) {
            $code = substr($code, $separator + 1);
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '_', $code));
        if ($code !== '' && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }
    return $codes;
}

function geminiOcrShouldFallbackToOpenRouter(
    int $httpStatus,
    bool $connectionFailed = false,
    string $interactionStatus = '',
    array $interactionErrorCodes = []
): bool {
    if ($connectionFailed || $httpStatus === 408 || $httpStatus === 429 ||
        $httpStatus >= 500) {
        return true;
    }

    if ($interactionStatus !== 'failed') {
        return false;
    }

    return (bool) array_intersect(
        ['DEADLINE_EXCEEDED', 'INTERNAL', 'RESOURCE_EXHAUSTED', 'UNAVAILABLE'],
        $interactionErrorCodes
    );
}

function geminiOcrModelSequence(array $preferredModels, array $fallbackModels = []): array
{
    $models = [];
    foreach (array_merge($preferredModels, $fallbackModels) as $model) {
        if (!is_string($model)) {
            throw new InvalidArgumentException('Los modelos de Gemini deben ser cadenas.');
        }
        $model = trim($model);
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $model)) {
            throw new InvalidArgumentException('El nombre de un modelo Gemini no es válido.');
        }
        if (!in_array($model, $models, true)) {
            $models[] = $model;
        }
        if (count($models) > 7) {
            throw new InvalidArgumentException('La lista de modelos Gemini supera el máximo permitido.');
        }
    }

    if (!$models) {
        throw new InvalidArgumentException('Configura al menos un modelo Gemini.');
    }
    return $models;
}

function geminiOcrStrictJsonSchema(array $schema): array
{
    if (($schema['type'] ?? '') === 'object') {
        $schema['additionalProperties'] = false;
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = geminiOcrStrictJsonSchema($property);
                }
            }
        }
    }
    if (($schema['type'] ?? '') === 'array' &&
        isset($schema['items']) && is_array($schema['items'])) {
        $schema['items'] = geminiOcrStrictJsonSchema($schema['items']);
    }
    return $schema;
}

function geminiOcrOpenRouterResponseText(array $response): ?string
{
    $message = $response['choices'][0]['message'] ?? null;
    if (!is_array($message)) {
        return null;
    }

    $content = $message['content'] ?? null;
    if (is_string($content)) {
        if (trim($content) !== '') {
            return $content;
        }
    }
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        $texto = $parts ? implode('', $parts) : '';
        if (trim($texto) !== '') {
            return $texto;
        }
    }

    if (isset($message['parsed']) && is_array($message['parsed'])) {
        $textoEstructurado = json_encode(
            $message['parsed'],
            JSON_UNESCAPED_UNICODE
        );
        if (is_string($textoEstructurado)) {
            return $textoEstructurado;
        }
    }
    return null;
}

function geminiOcrOpenRouterOutputDiagnostic(array $response): string
{
    $details = [];
    $error = $response['error'] ?? null;
    if (is_array($error) && isset($error['code']) &&
        (is_string($error['code']) || is_int($error['code']))) {
        $code = preg_replace(
            '/[^A-Za-z0-9_.-]/',
            '',
            (string) $error['code']
        );
        if ($code !== '') {
            $details[] = 'código ' . substr($code, 0, 40);
        }
    }

    $model = $response['model'] ?? '';
    if (is_string($model)) {
        $model = preg_replace('/[^A-Za-z0-9._\/-]/', '', $model);
        if ($model !== '') {
            $details[] = 'modelo ' . substr($model, 0, 100);
        }
    }

    $choice = $response['choices'][0] ?? [];
    if (is_array($choice) && isset($choice['finish_reason']) &&
        is_string($choice['finish_reason'])) {
        $reason = preg_replace('/[^A-Za-z0-9_-]/', '', $choice['finish_reason']);
        if ($reason !== '') {
            $details[] = 'motivo ' . substr($reason, 0, 40);
        }
    }
    $message = is_array($choice) ? ($choice['message'] ?? null) : null;
    if (is_array($message) && !empty($message['refusal'])) {
        $details[] = 'respuesta rechazada';
    }

    return 'OpenRouter respondió sin texto utilizable' .
        ($details ? ' (' . implode('; ', $details) . ')' : '') . '.';
}

function geminiOcrPostJson(string $url, array $headers, string $body, int $timeout): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        return ['body' => false, 'status' => 0, 'error' => 'curl_init_failed'];
    }

    $configured = curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (!$configured) {
        curl_close($curl);
        return ['body' => false, 'status' => 0, 'error' => 'curl_options_failed'];
    }

    $responseBody = curl_exec($curl);
    $error = $responseBody === false ? curl_error($curl) : '';
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ['body' => $responseBody, 'status' => $status, 'error' => $error];
}

function geminiOcrValidateResult(array $resultado): array
{
    $limites = [
        'estudiante' => [
            'cedula_escolar' => 20,
            'nombre' => 100,
            'apellido' => 100,
            'fecha_nacimiento' => 10,
            'genero' => 10,
            'lugar_nacimiento' => 100,
            'telefono' => 20,
            'direccion' => 500,
            'escuela_anterior' => 255,
            'email' => 255,
            'tipo_vivienda' => 20,
            'personas_conviven' => 2,
            'habilidades_especiales' => 500,
            'enfermedades' => 500,
            'alergias' => 500,
            'medicamentos' => 500,
            'observaciones' => 1000,
        ],
        'representante' => [
            'nombre' => 100,
            'apellido' => 100,
            'cedula' => 20,
            'parentesco' => 10,
            'telefono' => 20,
            'telefono_alternativo' => 20,
            'fecha_nacimiento' => 10,
            'genero' => 10,
            'profesion' => 100,
            'lugar_trabajo' => 255,
            'direccion' => 500,
            'email' => 255,
        ],
    ];
    $documentosPermitidos = [
        'acta_nacimiento',
        'cedula_representante',
        'fotos',
        'certificado_medico',
        'boleta_anterior',
        'partida_bautismo',
    ];

    $validado = [];
    foreach ($limites as $grupo => $campos) {
        if (!isset($resultado[$grupo]) || !is_array($resultado[$grupo])) {
            throw new InvalidArgumentException('Falta el grupo de datos esperado.');
        }
        $validado[$grupo] = [];
        if ($grupo === 'estudiante') {
            $documentos = $resultado[$grupo]['documentos_presentados'] ?? null;
            if (!is_array($documentos) || count($documentos) > count($documentosPermitidos)) {
                throw new InvalidArgumentException('La lista de documentos no es válida.');
            }
            $documentosValidados = [];
            foreach ($documentos as $documento) {
                if (!is_string($documento) ||
                    !in_array($documento, $documentosPermitidos, true) ||
                    in_array($documento, $documentosValidados, true)) {
                    throw new InvalidArgumentException('La lista contiene documentos no permitidos.');
                }
                $documentosValidados[] = $documento;
            }
            $validado[$grupo]['documentos_presentados'] = $documentosValidados;
        }
        foreach ($campos as $campo => $maximo) {
            $valor = $resultado[$grupo][$campo] ?? null;
            if (!is_string($valor)) {
                throw new InvalidArgumentException('El campo debe ser texto.');
            }
            $valor = trim($valor);
            $longitud = function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
            if ($longitud > $maximo || preg_match('/[\x00-\x1F\x7F]/', $valor)) {
                throw new InvalidArgumentException('El campo excede los límites permitidos.');
            }
            if ($campo === 'fecha_nacimiento' && $valor !== '') {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) ||
                    !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                    throw new InvalidArgumentException('La fecha no es válida.');
                }
            }
            if ($campo === 'genero' && !in_array($valor, ['', 'masculino', 'femenino'], true)) {
                throw new InvalidArgumentException('El género no está permitido.');
            }
            if ($campo === 'tipo_vivienda' &&
                !in_array($valor, ['', 'Propia', 'Alquilada', 'Familiar', 'Otro'], true)) {
                throw new InvalidArgumentException('El tipo de vivienda no está permitido.');
            }
            if ($campo === 'parentesco' &&
                !in_array($valor, ['', 'madre', 'padre', 'abuelo', 'abuela', 'tio', 'tia', 'hermano', 'hermana', 'otro'], true)) {
                throw new InvalidArgumentException('El parentesco no está permitido.');
            }
            if ($campo === 'personas_conviven' && $valor !== '' &&
                (!ctype_digit($valor) || (int) $valor < 1 || (int) $valor > 20)) {
                throw new InvalidArgumentException('La cantidad de personas que conviven no es válida.');
            }
            if ($campo === 'cedula_escolar' && $valor !== '' &&
                !preg_match('/^[A-Za-z0-9]{1,20}$/', $valor)) {
                throw new InvalidArgumentException('La cédula escolar no es válida.');
            }
            $validado[$grupo][$campo] = $valor;
        }
    }
    return $validado;
}
