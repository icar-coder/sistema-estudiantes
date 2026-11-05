    </div>
    
    <footer class="bg-dark text-white py-3 mt-auto">
        <div class="container text-center">
            <p class="mb-0">&copy; 2024 Sistema de Gestión Estudiantil. Todos los derechos reservados.</p>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php $asset_base = defined('BASE_URL') ? BASE_URL : 'http://localhost/sistemaestudiantes2'; ?>
    <script src="<?php echo $asset_base; ?>/js/main.js"></script>

    <?php if (isset($_SESSION['user_id'])): ?>
    <script>
    (function() {
        const TIMEOUT_MS = <?php echo SESSION_TIMEOUT * 1000; ?>;
        const WARNING_MS = <?php echo SESSION_WARNING * 1000; ?>;
        const basePath = '<?php echo defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_PATH) ?: '/SistemaEstudiantes' : '/SistemaEstudiantes'; ?>';
        const LOGOUT_URL = basePath + '/logout.php?expired=1';
        let ultimaActividad = Date.now();
        let avisoMostrado = false;

        const refrescarActividad = function() {
            ultimaActividad = Date.now();
            avisoMostrado = false;
        };

        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function(evento) {
            document.addEventListener(evento, refrescarActividad, { passive: true });
        });

        setInterval(function() {
            const inactivo = Date.now() - ultimaActividad;

            if (inactivo >= TIMEOUT_MS) {
                window.location.href = LOGOUT_URL;
                return;
            }

            if (inactivo >= TIMEOUT_MS - WARNING_MS && !avisoMostrado) {
                avisoMostrado = true;
                const minutos = Math.max(1, Math.round(WARNING_MS / 60000));

                if (confirm('Tu sesión expirará por inactividad en ' + minutos + ' minutos.\n\n¿Deseas seguir conectado?')) {
                    refrescarActividad();
                    avisoMostrado = false;
                }
            }
        }, 30000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
<style>
/* Estilos para el footer fijo al fondo */
html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}

body {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.content-wrapper {
    flex: 1 0 auto;
}

footer {
    flex-shrink: 0;
}
</style>