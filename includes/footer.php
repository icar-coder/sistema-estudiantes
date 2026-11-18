    </div>
    
    <footer class="bg-dark text-white py-3 mt-auto">
        <div class="container text-center">
            <p class="mb-0">&copy; 2024 Sistema de Gestión Estudiantil. Todos los derechos reservados.</p>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo htmlspecialchars(app_url('js/main.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>

    <?php if (isset($_SESSION['user_id'])): ?>
    <script>
    (function() {
        const TIMEOUT_MS = <?php echo SESSION_TIMEOUT * 1000; ?>;
        const WARNING_MS = <?php echo SESSION_WARNING * 1000; ?>;
        const LOGOUT_URL = <?php echo json_encode(app_url('logout.php?expired=1')); ?>;
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
    <?php if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite' &&
        ($_SESSION['rol'] ?? null) === 'admin'): ?>
    <?php
    if (!isset($_SESSION['csrf_offline_sync'])) {
        $_SESSION['csrf_offline_sync'] = bin2hex(random_bytes(32));
    }
    ?>
    <div id="offline-sync-status" class="alert alert-info position-fixed shadow"
         role="status" aria-live="polite" hidden
         style="right: 1rem; bottom: 1rem; z-index: 1100; max-width: 24rem;"></div>
    <script>
    (function() {
        const endpoint = <?php echo json_encode(app_url('admin/sincronizar.php')); ?>;
        const csrfToken = <?php echo json_encode($_SESSION['csrf_offline_sync']); ?>;
        const status = document.getElementById('offline-sync-status');
        let sincronizando = false;

        const mostrarEstado = function(message, className) {
            status.textContent = message;
            status.className = 'alert ' + className + ' position-fixed shadow';
            status.hidden = false;
        };

        const sincronizar = async function() {
            if (sincronizando) {
                return;
            }
            if (!navigator.onLine) {
                mostrarEstado('Sin conexión. Los cambios se guardan en este dispositivo.', 'alert-warning');
                return;
            }

            sincronizando = true;
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: '{}'
                });
                if (response.status === 202) {
                    return;
                }

                const data = await response.json();
                if (!response.ok || !data.summary) {
                    throw new Error('sync_failed');
                }

                const summary = data.summary;
                if (summary.blocked > 0) {
                    mostrarEstado(
                        'La sincronización requiere revisar ' + summary.blocked + ' conflicto(s).',
                        'alert-danger'
                    );
                } else if (summary.pending > 0) {
                    mostrarEstado(
                        'Quedan ' + summary.pending + ' cambio(s) pendientes de enviar.',
                        'alert-warning'
                    );
                } else if (summary.requests > 0) {
                    mostrarEstado('Sincronización completada.', 'alert-success');
                } else {
                    status.hidden = true;
                }
            } catch (error) {
                mostrarEstado(
                    'No se pudo sincronizar. Los cambios siguen guardados localmente.',
                    'alert-warning'
                );
            } finally {
                sincronizando = false;
            }
        };

        window.addEventListener('online', sincronizar);
        window.addEventListener('offline', function() {
            mostrarEstado('Sin conexión. Los cambios se guardan en este dispositivo.', 'alert-warning');
        });
        sincronizar();
        window.setInterval(sincronizar, 30000);
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