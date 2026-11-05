/**
 * Compatibilidad: reemplaza la validación antigua por la centralizada.
 */
(function() {
    'use strict';
    if (!window.ValidadorFormularios) {
        const script = document.createElement('script');
        script.src = '../../js/validaciones-formularios.js';
        document.head.appendChild(script);
    }
})();