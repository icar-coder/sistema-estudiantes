/**
 * validaciones-formularios.js
 * Sistema unificado de validaciones de formularios
 */

(function() {
    'use strict';

    const REGLAS = {
        texto: {
            maxlength: 100,
            regex: /^[A-Za-zÁÉÍÓÚáéíóúÑñÜü\s'.-]*$/,
            limpiar: (v) => v.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñÜü\s'.-]/g, ''),
            mensaje: 'Solo se permiten letras, espacios y guiones'
        },
        'texto-corto': {
            maxlength: 50,
            regex: /^[A-Za-zÁÉÍÓÚáéíóúÑñÜü\s'.-]*$/,
            limpiar: (v) => v.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñÜü\s'.-]/g, ''),
            mensaje: 'Máx. 50 caracteres alfabéticos'
        },
        'texto-largo': {
            maxlength: 500,
            regex: null,
            limpiar: null,
            mensaje: 'Máx. 500 caracteres'
        },
        cedula: {
            maxlength: 10,
            regex: /^[0-9]*$/,
            limpiar: (v) => v.replace(/[^0-9]/g, ''),
            min: 6,
            mensaje: 'Solo números (6-10 dígitos)'
        },
        telefono: {
            maxlength: 15,
            regex: /^[0-9+\-\s]*$/,
            limpiar: (v) => v.replace(/[^0-9+\-\s]/g, ''),
            mensaje: 'Solo números, +, - y espacios'
        },
        numero: {
            maxlength: 10,
            regex: /^[0-9]*$/,
            limpiar: (v) => v.replace(/[^0-9]/g, ''),
            mensaje: 'Solo números'
        },
        'cedula-escolar': {
            maxlength: 20,
            regex: /^[A-Za-z0-9]*$/,
            limpiar: (v) => v.replace(/[^A-Za-z0-9]/g, '').toUpperCase(),
            mensaje: 'Solo letras y números (se convierte a mayúsculas)'
        },
        email: {
            maxlength: 100,
            regex: /^[a-zA-Z0-9._%+-]*@?[a-zA-Z0-9.-]*\.?[a-zA-Z]*$/,
            limpiar: null,
            mensaje: 'Formato de email inválido'
        },
        password: {
            maxlength: 100,
            regex: null,
            limpiar: null,
            mensaje: 'Máx. 100 caracteres'
        },
        fecha: {
            maxlength: null,
            regex: null,
            limpiar: null,
            validar: (input) => {
                if (!input.value) return true;
                const fecha = new Date(input.value);
                const hoy = new Date();
                if (input.dataset.maxHoy === 'true' && fecha > hoy) {
                    return false;
                }
                const minFecha = input.dataset.minFecha;
                if (minFecha && input.value < minFecha) {
                    return false;
                }
                return !Number.isNaN(fecha.getTime());
            },
            mensaje: 'Fecha inválida o fuera de rango'
        },
        textarea: {
            maxlength: 500,
            regex: null,
            limpiar: null,
            mensaje: 'Máx. 500 caracteres'
        }
    };

    function actualizarFeedback(input, mensaje) {
        let feedback = input.parentElement.querySelector('.invalid-feedback[data-auto]');
        if (!feedback) {
            feedback = input.parentElement.querySelector('.form-text[data-auto]');
        }

        if (!feedback) {
            if (mensaje) {
                const div = document.createElement('div');
                div.className = 'invalid-feedback d-block';
                div.setAttribute('data-auto', 'true');
                div.textContent = mensaje;
                input.parentElement.appendChild(div);
            }
            return;
        }

        feedback.textContent = mensaje;
        feedback.style.display = mensaje ? 'block' : 'none';
    }

    function validarInput(input, regla, mostrarError = false) {
        let esValido = true;
        let mensajeError = '';

        if (regla.min && input.value.length > 0 && input.value.length < regla.min) {
            esValido = false;
            mensajeError = 'Mínimo ' + regla.min + ' caracteres';
        }

        if (regla.regex && input.value.length > 0 && !regla.regex.test(input.value)) {
            esValido = false;
            mensajeError = regla.mensaje;
        }

        if (regla.validar && !regla.validar(input)) {
            esValido = false;
            mensajeError = regla.mensaje;
        }

        if (mostrarError || input.value.length > 0) {
            input.classList.toggle('is-invalid', !esValido);
            input.classList.toggle('is-valid', esValido && input.value.length > 0);
        }

        actualizarFeedback(input, esValido ? '' : mensajeError);
        return esValido;
    }

    function aplicarReglas(input) {
        const tipo = input.dataset.tipo;
        if (!tipo || !REGLAS[tipo]) return;

        const regla = REGLAS[tipo];

        if (regla.maxlength && !input.getAttribute('maxlength')) {
            input.setAttribute('maxlength', regla.maxlength);
        }

        if (regla.regex && !input.getAttribute('pattern')) {
            input.setAttribute('pattern', regla.regex.source.replace(/^\^|\$$/g, ''));
        }

        input.addEventListener('input', function() {
            if (regla.limpiar) {
                const valorLimpio = regla.limpiar(this.value);
                if (valorLimpio !== this.value) {
                    const cursorPos = this.selectionStart;
                    const delta = this.value.length - valorLimpio.length;
                    this.value = valorLimpio;
                    this.selectionStart = this.selectionEnd = Math.max(0, cursorPos - delta);
                }
            }
            validarInput(this, regla);
        });

        input.addEventListener('blur', function() {
            validarInput(this, regla, true);
        });
    }

    function validarFormulario(form) {
        let todoValido = true;
        const inputs = form.querySelectorAll('[data-tipo]');

        inputs.forEach(input => {
            const regla = REGLAS[input.dataset.tipo];
            if (!regla) return;
            const ok = validarInput(input, regla, true);
            if (!ok) todoValido = false;
        });

        const requireds = form.querySelectorAll('[required]');
        requireds.forEach(input => {
            if (!input.value.trim()) {
                input.classList.add('is-invalid');
                todoValido = false;
            }
        });

        const fechaInicio = form.querySelector('[name*="fecha_inicio"]');
        const fechaFin = form.querySelector('[name*="fecha_fin"]');
        if (fechaInicio && fechaFin && fechaInicio.value && fechaFin.value) {
            if (fechaFin.value <= fechaInicio.value) {
                fechaFin.classList.add('is-invalid');
                actualizarFeedback(fechaFin, 'La fecha fin debe ser posterior a la fecha inicio');
                todoValido = false;
            }
        }

        return todoValido;
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-tipo]').forEach(aplicarReglas);

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!validarFormulario(this)) {
                    e.preventDefault();
                    const primerError = this.querySelector('.is-invalid');
                    if (primerError) {
                        primerError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        primerError.focus();
                    }
                    alert('Por favor corrija los campos marcados en rojo antes de continuar.');
                    return false;
                }
            });
        });
    });

    window.ValidadorFormularios = {
        validarFormulario,
        aplicarReglas,
        REGLAS
    };
})();
