<?php
/**
 * includes/validaciones.php
 * Validaciones server-side reutilizables
 */

class Validador
{
    private array $errores = [];
    private array $datos = [];

    public function __construct(array $datos)
    {
        $this->datos = $datos;
    }

    public function texto($campo, $etiqueta, $min = 1, $max = 100, $requerido = true): string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatorio";
            }
            return '';
        }

        if (mb_strlen($valor) < $min) {
            $this->errores[] = "$etiqueta debe tener al menos $min caracteres";
        }
        if (mb_strlen($valor) > $max) {
            $this->errores[] = "$etiqueta no puede exceder $max caracteres";
        }
        if (!preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñÜü\s\'.-]+$/u', $valor)) {
            $this->errores[] = "$etiqueta solo puede contener letras, espacios y guiones";
        }

        return $valor;
    }

    public function cedula($campo, $etiqueta = 'Cédula', $min = 6, $max = 10, $requerido = true): string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatoria";
            }
            return '';
        }

        if (!preg_match('/^[0-9]+$/', $valor)) {
            $this->errores[] = "$etiqueta solo puede contener números";
        }
        if (strlen($valor) < $min || strlen($valor) > $max) {
            $this->errores[] = "$etiqueta debe tener entre $min y $max dígitos";
        }

        return $valor;
    }

    public function cedulaEscolar($campo, $etiqueta = 'Cédula escolar', $requerido = true): string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatoria";
            }
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9]+$/', $valor)) {
            $this->errores[] = "$etiqueta solo puede contener letras y números";
        }
        if (strlen($valor) < 3 || strlen($valor) > 20) {
            $this->errores[] = "$etiqueta debe tener entre 3 y 20 caracteres";
        }

        return strtoupper($valor);
    }

    public function telefono($campo, $etiqueta = 'Teléfono', $requerido = false): string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatorio";
            }
            return '';
        }

        if (!preg_match('/^[0-9+\-\s]+$/', $valor)) {
            $this->errores[] = "$etiqueta contiene caracteres inválidos";
        }

        return $valor;
    }

    public function email($campo, $etiqueta = 'Email', $requerido = false): string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatorio";
            }
            return '';
        }

        if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            $this->errores[] = "$etiqueta no tiene un formato válido";
        }

        return $valor;
    }

    public function fecha($campo, $etiqueta = 'Fecha', $requerido = true, $noFutura = false, $minFecha = null): ?string
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatoria";
            }
            return null;
        }

        $fecha = DateTime::createFromFormat('Y-m-d', $valor);
        if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
            $this->errores[] = "$etiqueta no tiene un formato válido";
            return null;
        }

        if ($noFutura && $fecha > new DateTime()) {
            $this->errores[] = "$etiqueta no puede ser futura";
        }

        if ($minFecha && $valor < $minFecha) {
            $this->errores[] = "$etiqueta no puede ser anterior a $minFecha";
        }

        return $valor;
    }

    public function entero($campo, $etiqueta, $min = null, $max = null, $requerido = false): ?int
    {
        $valor = $this->datos[$campo] ?? null;

        if ($valor === '' || $valor === null) {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatorio";
            }
            return null;
        }

        if (!is_numeric($valor) || (int)$valor != $valor) {
            $this->errores[] = "$etiqueta debe ser un número entero";
            return null;
        }

        $valor = (int)$valor;

        if ($min !== null && $valor < $min) {
            $this->errores[] = "$etiqueta debe ser mayor o igual a $min";
        }
        if ($max !== null && $valor > $max) {
            $this->errores[] = "$etiqueta debe ser menor o igual a $max";
        }

        return $valor;
    }

    public function opcion($campo, $etiqueta, array $opcionesPermitidas, $requerido = true)
    {
        $valor = trim((string)($this->datos[$campo] ?? ''));

        if ($valor === '') {
            if ($requerido) {
                $this->errores[] = "$etiqueta es obligatorio";
            }
            return null;
        }

        if (!in_array($valor, $opcionesPermitidas, true)) {
            $this->errores[] = "$etiqueta tiene un valor no válido";
            return null;
        }

        return $valor;
    }

    public function agregarError($mensaje): void
    {
        $this->errores[] = $mensaje;
    }

    public function hayErrores(): bool
    {
        return !empty($this->errores);
    }

    public function getErrores(): array
    {
        return $this->errores;
    }

    public function getMensajeErrores(): string
    {
        return implode('<br>', $this->errores);
    }
}
