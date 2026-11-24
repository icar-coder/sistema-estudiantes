<?php

function geminiOcrResponseSchema(): array
{
    $campos = [
        'nombre' => 100,
        'apellido' => 100,
        'fecha_nacimiento' => 10,
        'genero' => 10,
        'lugar_nacimiento' => 100,
        'direccion' => 500,
        'escuela_anterior' => 255,
        'email' => 255,
    ];
    $representante = [
        'nombre' => 100,
        'apellido' => 100,
        'cedula' => 20,
        'fecha_nacimiento' => 10,
        'genero' => 10,
        'direccion' => 500,
        'email' => 255,
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

    return [
        'type' => 'object',
        'properties' => [
            'estudiante' => [
                'type' => 'object',
                'properties' => $crearPropiedades($campos),
                'required' => array_keys($campos),
            ],
            'representante' => [
                'type' => 'object',
                'properties' => $crearPropiedades($representante),
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

function geminiOcrValidateResult(array $resultado): array
{
    $limites = [
        'estudiante' => [
            'nombre' => 100,
            'apellido' => 100,
            'fecha_nacimiento' => 10,
            'genero' => 10,
            'lugar_nacimiento' => 100,
            'direccion' => 500,
            'escuela_anterior' => 255,
            'email' => 255,
        ],
        'representante' => [
            'nombre' => 100,
            'apellido' => 100,
            'cedula' => 20,
            'fecha_nacimiento' => 10,
            'genero' => 10,
            'direccion' => 500,
            'email' => 255,
        ],
    ];

    $validado = [];
    foreach ($limites as $grupo => $campos) {
        if (!isset($resultado[$grupo]) || !is_array($resultado[$grupo])) {
            throw new InvalidArgumentException('Falta el grupo de datos esperado.');
        }
        $validado[$grupo] = [];
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
            $validado[$grupo][$campo] = $valor;
        }
    }
    return $validado;
}
