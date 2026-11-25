<?php

require_once dirname(__DIR__) . '/includes/gemini_ocr.php';

function assertGeminiOcrTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$resultado = [
    'estudiante' => [
        'cedula_escolar' => 'EST2026002',
        'nombre' => 'María',
        'apellido' => 'Pérez',
        'fecha_nacimiento' => '2018-03-09',
        'genero' => 'femenino',
        'lugar_nacimiento' => '',
        'telefono' => '04121234567',
        'direccion' => '',
        'escuela_anterior' => '',
        'email' => '',
        'tipo_vivienda' => 'Familiar',
        'personas_conviven' => '4',
        'documentos_presentados' => ['acta_nacimiento', 'cedula_representante'],
        'habilidades_especiales' => '',
        'enfermedades' => '',
        'alergias' => '',
        'medicamentos' => '',
        'observaciones' => '',
    ],
    'representante' => [
        'nombre' => '',
        'apellido' => '',
        'cedula' => '',
        'parentesco' => 'madre',
        'telefono' => '04141234567',
        'telefono_alternativo' => '',
        'fecha_nacimiento' => '',
        'genero' => '',
        'profesion' => 'Contador',
        'lugar_trabajo' => '',
        'direccion' => '',
        'email' => '',
    ],
];

$validado = geminiOcrValidateResult($resultado);
assertGeminiOcrTest(
    $validado['estudiante']['nombre'] === 'María' &&
    $validado['estudiante']['fecha_nacimiento'] === '2018-03-09' &&
    $validado['representante']['profesion'] === 'Contador' &&
    $validado['estudiante']['documentos_presentados'] === ['acta_nacimiento', 'cedula_representante'],
    'La respuesta estructurada debe conservar campos válidos.'
);

$texto = geminiOcrResponseText([
    'interaction' => [
        'steps' => [
            [
                'type' => 'model_output',
                'content' => [
                    ['type' => 'text', 'text' => '{"ok":true}'],
                ],
            ],
        ],
    ],
]);
assertGeminiOcrTest($texto === '{"ok":true}', 'Debe extraer texto de los pasos de salida REST.');

$textoDirecto = geminiOcrResponseText([
    'output' => [
        ['type' => 'text', 'text' => '{"ok":true}'],
    ],
]);
assertGeminiOcrTest($textoDirecto === '{"ok":true}', 'Debe extraer texto de la respuesta REST directa.');

$codigosError = geminiOcrInteractionErrorCodes([
    ['code' => 'https://ai.google.dev/errors/RESOURCE_EXHAUSTED'],
    ['code' => 'https://ai.google.dev/errors/RESOURCE_EXHAUSTED'],
    ['message' => 'Este mensaje no debe tratarse como código.'],
    ['code' => 'INVALID ARGUMENT'],
]);
assertGeminiOcrTest(
    $codigosError === ['RESOURCE_EXHAUSTED', 'INVALID_ARGUMENT'],
    'Debe reducir los errores a códigos seguros y únicos, sin exponer mensajes remotos.'
);

assertGeminiOcrTest(
    geminiOcrShouldFallbackToOpenRouter(408) &&
    geminiOcrShouldFallbackToOpenRouter(503) &&
    geminiOcrShouldFallbackToOpenRouter(429) &&
    geminiOcrShouldFallbackToOpenRouter(0, true) &&
    geminiOcrShouldFallbackToOpenRouter(200, false, 'failed', ['UNAVAILABLE']) &&
    !geminiOcrShouldFallbackToOpenRouter(401) &&
    !geminiOcrShouldFallbackToOpenRouter(403) &&
    !geminiOcrShouldFallbackToOpenRouter(400) &&
    !geminiOcrShouldFallbackToOpenRouter(404) &&
    !geminiOcrShouldFallbackToOpenRouter(200, false, 'failed', ['SAFETY']),
    'Solo los errores temporales de Gemini deben activar el fallback.'
);

$textoOpenRouter = geminiOcrOpenRouterResponseText([
    'choices' => [
        ['message' => ['content' => [
            ['type' => 'text', 'text' => '{"estudiante":'],
            ['type' => 'text', 'text' => '{"nombre":"Ana"}}'],
        ]]],
    ],
]);
assertGeminiOcrTest(
    $textoOpenRouter === '{"estudiante":{"nombre":"Ana"}}',
    'Debe extraer texto de la respuesta compatible de OpenRouter.'
);

$schemaEstricta = geminiOcrStrictJsonSchema(geminiOcrResponseSchema());
assertGeminiOcrTest(
    $schemaEstricta['additionalProperties'] === false &&
    $schemaEstricta['properties']['estudiante']['additionalProperties'] === false &&
    $schemaEstricta['properties']['representante']['additionalProperties'] === false,
    'El esquema OpenRouter debe cerrar las propiedades de todos los objetos.'
);

$resultadoInvalido = $resultado;
$resultadoInvalido['estudiante']['fecha_nacimiento'] = '2018-02-30';
$rechazado = false;
try {
    geminiOcrValidateResult($resultadoInvalido);
} catch (InvalidArgumentException $exception) {
    $rechazado = true;
}
assertGeminiOcrTest($rechazado, 'Debe rechazar fechas inexistentes.');

$resultadoInvalido = $resultado;
$resultadoInvalido['representante']['genero'] = 'otro';
$rechazado = false;
try {
    geminiOcrValidateResult($resultadoInvalido);
} catch (InvalidArgumentException $exception) {
    $rechazado = true;
}
assertGeminiOcrTest($rechazado, 'Debe rechazar valores de género fuera del catálogo.');

$resultadoInvalido = $resultado;
$resultadoInvalido['estudiante']['nombre'] = str_repeat('a', 101);
$rechazado = false;
try {
    geminiOcrValidateResult($resultadoInvalido);
} catch (InvalidArgumentException $exception) {
    $rechazado = true;
}
assertGeminiOcrTest($rechazado, 'Debe rechazar campos que excedan la longitud permitida.');

$resultadoInvalido = $resultado;
$resultadoInvalido['estudiante']['documentos_presentados'] = ['documento_no_permitido'];
$rechazado = false;
try {
    geminiOcrValidateResult($resultadoInvalido);
} catch (InvalidArgumentException $exception) {
    $rechazado = true;
}
assertGeminiOcrTest($rechazado, 'Debe rechazar documentos que no pertenecen a las opciones del formulario.');

$schema = geminiOcrResponseSchema();
assertGeminiOcrTest(
    $schema['properties']['estudiante']['properties']['documentos_presentados']['type'] === 'array' &&
    in_array('enfermedades', $schema['properties']['estudiante']['required'], true) &&
    in_array('lugar_trabajo', $schema['properties']['representante']['required'], true),
    'El esquema debe incluir los campos de ambas secciones y las casillas de documentos.'
);

echo "Pruebas OCR Gemini completadas.\n";
