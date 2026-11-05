document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('representante_cedula');
    const result = document.getElementById('representante-busqueda-resultado');

    if (!input) {
        return;
    }

    const buscarRepresentante = async function () {
        const cedula = input.value.trim();
        if (!cedula) {
            return;
        }

        const formData = new FormData();
        formData.append('cedula', cedula);

        try {
            const response = await fetch('../includes/ajax_buscar_representante.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (!result) {
                return;
            }

            if (data.success && data.encontrado) {
                result.innerHTML = '<div class="alert alert-success mb-0">Representante encontrado: ' + data.representante.nombre + ' ' + data.representante.apellido + '</div>';
            } else if (data.success && !data.encontrado) {
                result.innerHTML = '<div class="alert alert-info mb-0">No se encontró el representante. Puede registrar uno nuevo.</div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger mb-0">' + (data.message || 'No se pudo buscar el representante.') + '</div>';
            }
        } catch (error) {
            if (result) {
                result.innerHTML = '<div class="alert alert-danger mb-0">Error al buscar el representante.</div>';
            }
        }
    };

    input.addEventListener('blur', buscarRepresentante);
});
