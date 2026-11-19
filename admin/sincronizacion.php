<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isSqliteDatabase($pdo)) {
    header('Location: ' . app_url('admin/dashboard.php'));
    exit;
}
$sincronizacionHabilitada = offlineSyncIsEnabled();

if (empty($_SESSION['csrf_offline_conflicts'])) {
    $_SESSION['csrf_offline_conflicts'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$tipoMensaje = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = $_SESSION['csrf_offline_conflicts'];
    $submittedToken = $_POST['csrf_token'] ?? '';
    $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $mensaje = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
        $tipoMensaje = 'danger';
    } elseif (!$sincronizacionHabilitada) {
        $mensaje = 'La sincronización está desactivada en esta instalación portable.';
        $tipoMensaje = 'warning';
    } elseif (($_POST['action'] ?? '') !== 'retry' || $entryId === false || $entryId < 1) {
        $mensaje = 'La solicitud de reintento no es válida.';
        $tipoMensaje = 'danger';
    } elseif (offlineSyncRetryConflict($pdo, $entryId)) {
        $_SESSION['mensaje_sincronizacion'] = 'El conflicto volvió a la cola. Se enviará en el próximo intento de sincronización.';
        $_SESSION['tipo_mensaje_sincronizacion'] = 'success';
        header('Location: ' . app_url('admin/sincronizacion.php'));
        exit;
    } else {
        $mensaje = 'El conflicto ya no está pendiente de reintento.';
        $tipoMensaje = 'warning';
    }
}

if (isset($_SESSION['mensaje_sincronizacion'])) {
    $mensaje = $_SESSION['mensaje_sincronizacion'];
    $tipoMensaje = $_SESSION['tipo_mensaje_sincronizacion'] ?? 'info';
    unset($_SESSION['mensaje_sincronizacion'], $_SESSION['tipo_mensaje_sincronizacion']);
}

$pendientes = offlineSyncPendingCount($pdo);
$totalProblemas = (int) $pdo->query(
    'SELECT COUNT(*) FROM `offline_sync_outbox`
     WHERE `estado` IN ("conflict", "blocked")'
)->fetchColumn();
$problemas = $pdo->query(
    'SELECT `id`, `tabla`, `uuid`, `operacion`, `estado`, `attempts`, `last_error`, `updated_at`
     FROM `offline_sync_outbox`
     WHERE `estado` IN ("conflict", "blocked")
     ORDER BY `updated_at` DESC, `id` DESC
     LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar_admin.php'; ?>

<main class="content-wrapper">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h2><i class="fas fa-sync-alt me-2"></i>Estado de sincronización</h2>
                <p class="text-muted mb-0">Cambios pendientes: <?php echo $pendientes; ?>. Problemas: <?php echo $totalProblemas; ?>.</p>
            </div>
            <a href="<?php echo htmlspecialchars(app_url('admin/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>"
               class="btn btn-outline-secondary">Volver</a>
        </div>

        <?php if (!$sincronizacionHabilitada): ?>
            <div class="alert alert-info" role="status">
                Esta instalación portable guarda los cambios solo en este archivo SQLite; no los envía a Alwaysdata.
            </div>
        <?php endif; ?>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipoMensaje, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($problemas === []): ?>
            <div class="alert alert-success" role="status">
                No hay conflictos ni cambios bloqueados.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                Los conflictos indican que el servidor recibió una actualización más reciente. Reintentar enviará los datos locales de nuevo y puede reemplazar la versión del servidor. Los registros bloqueados requieren corregir el problema indicado antes de volver a editarlos.
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Tabla</th>
                            <th>UUID</th>
                            <th>Operación</th>
                            <th>Estado</th>
                            <th>Intentos</th>
                            <th>Motivo</th>
                            <th>Actualizado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problemas as $problema): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($problema['tabla'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars($problema['uuid'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo htmlspecialchars($problema['operacion'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($problema['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) $problema['attempts']; ?></td>
                                <td><?php echo htmlspecialchars($problema['last_error'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($problema['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!$sincronizacionHabilitada): ?>
                                        <span class="text-muted">Sincronización desactivada.</span>
                                    <?php elseif ($problema['estado'] === 'conflict'): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?php echo htmlspecialchars($_SESSION['csrf_offline_conflicts'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="entry_id" value="<?php echo (int) $problema['id']; ?>">
                                            <input type="hidden" name="action" value="retry">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                Reenviar versión local
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Corrige el registro y vuelve a guardarlo.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
