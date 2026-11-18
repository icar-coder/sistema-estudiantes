-- Revisa y conserva un respaldo antes de aplicar esta migración manualmente.
-- Permite que la última solicitud recibida prevalezca incluso si las solicitudes
-- de sincronización llegan a procesarse concurrentemente.
ALTER TABLE sync_map
    ADD COLUMN last_received_at DATETIME(6) NULL DEFAULT NULL AFTER id_remoto;
