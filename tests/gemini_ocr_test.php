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
        'nombre' => 'María',
        'apellido' => 'Pérez',
        'fecha_nacimiento' => '2018-03-09',
        'genero' => 'femenino',
        'lugar_nacimiento' => '',
        'direccion' => '',
        'escuela_anterior' => '',
        'email' => '',
    ],
    'representante' => [
        'nombre' => '',
        'apellido' => '',
        'cedula' => '',
        'fecha_nacimiento' => '',
        'genero' => '',
        'direccion' => '',
        'email' => '',
    ],
];

$validado = geminiOcrValidateResult($resultado);
assertGeminiOcrTest(
    $validado['estudiante']['nombre'] === 'María' &&
    $validado['estudiante']['fecha_nacimiento'] === '2018-03-09',
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

echo "Pruebas OCR Gemini completadas.\n";
