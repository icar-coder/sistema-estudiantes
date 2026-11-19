-- Revisa un respaldo completo antes de ejecutar.
-- Preparación compatible con MariaDB 10.4 para el cliente offline y api_sync.php.
-- Agrega UUID a los registros existentes y a los nuevos sin modificar sus datos.

CREATE TABLE IF NOT EXISTS `sync_map` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` char(36) NOT NULL,
    `tabla` varchar(50) NOT NULL,
    `id_remoto` int(11) NOT NULL,
    `last_received_at` datetime(6) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid_tabla` (`uuid`, `tabla`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `sync_map`
    ADD COLUMN IF NOT EXISTS `last_received_at` datetime(6) DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid_tabla` (`uuid`, `tabla`);

ALTER TABLE `areas_aprendizaje`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `areas_aprendizaje` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `areas_aprendizaje`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_areas_aprendizaje_bi`;
CREATE TRIGGER `trg_sync_uuid_areas_aprendizaje_bi`
    BEFORE INSERT ON `areas_aprendizaje` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `años_escolares`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `años_escolares` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `años_escolares`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_anios_escolares_bi`;
CREATE TRIGGER `trg_sync_uuid_anios_escolares_bi`
    BEFORE INSERT ON `años_escolares` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `grados`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `grados` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `grados`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_grados_bi`;
CREATE TRIGGER `trg_sync_uuid_grados_bi`
    BEFORE INSERT ON `grados` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `profesores`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `profesores` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `profesores`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_profesores_bi`;
CREATE TRIGGER `trg_sync_uuid_profesores_bi`
    BEFORE INSERT ON `profesores` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `representantes`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `representantes` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `representantes`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_representantes_bi`;
CREATE TRIGGER `trg_sync_uuid_representantes_bi`
    BEFORE INSERT ON `representantes` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `areas_indicadores`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `areas_indicadores` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `areas_indicadores`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_areas_indicadores_bi`;
CREATE TRIGGER `trg_sync_uuid_areas_indicadores_bi`
    BEFORE INSERT ON `areas_indicadores` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `secciones`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `secciones` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `secciones`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_secciones_bi`;
CREATE TRIGGER `trg_sync_uuid_secciones_bi`
    BEFORE INSERT ON `secciones` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `lapsos`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `lapsos` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `lapsos`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_lapsos_bi`;
CREATE TRIGGER `trg_sync_uuid_lapsos_bi`
    BEFORE INSERT ON `lapsos` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `estudiantes`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `estudiantes` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `estudiantes`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_estudiantes_bi`;
CREATE TRIGGER `trg_sync_uuid_estudiantes_bi`
    BEFORE INSERT ON `estudiantes` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `estudiantes_representantes`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `estudiantes_representantes` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `estudiantes_representantes`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_estudiantes_representantes_bi`;
CREATE TRIGGER `trg_sync_uuid_estudiantes_representantes_bi`
    BEFORE INSERT ON `estudiantes_representantes` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `inscripciones`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `inscripciones` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `inscripciones`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_inscripciones_bi`;
CREATE TRIGGER `trg_sync_uuid_inscripciones_bi`
    BEFORE INSERT ON `inscripciones` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `horarios_asignaciones`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `horarios_asignaciones` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `horarios_asignaciones`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_horarios_asignaciones_bi`;
CREATE TRIGGER `trg_sync_uuid_horarios_asignaciones_bi`
    BEFORE INSERT ON `horarios_asignaciones` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `indicadores_seccion`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `indicadores_seccion` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `indicadores_seccion`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_indicadores_seccion_bi`;
CREATE TRIGGER `trg_sync_uuid_indicadores_seccion_bi`
    BEFORE INSERT ON `indicadores_seccion` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `historial_academico`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `historial_academico` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `historial_academico`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_historial_academico_bi`;
CREATE TRIGGER `trg_sync_uuid_historial_academico_bi`
    BEFORE INSERT ON `historial_academico` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `historial_indicadores`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `historial_indicadores` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `historial_indicadores`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_historial_indicadores_bi`;
CREATE TRIGGER `trg_sync_uuid_historial_indicadores_bi`
    BEFORE INSERT ON `historial_indicadores` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `asistencias`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `asistencias` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `asistencias`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_asistencias_bi`;
CREATE TRIGGER `trg_sync_uuid_asistencias_bi`
    BEFORE INSERT ON `asistencias` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `literales_finales`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `literales_finales` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `literales_finales`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_literales_finales_bi`;
CREATE TRIGGER `trg_sync_uuid_literales_finales_bi`
    BEFORE INSERT ON `literales_finales` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `literales_lapso`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `literales_lapso` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `literales_lapso`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_literales_lapso_bi`;
CREATE TRIGGER `trg_sync_uuid_literales_lapso_bi`
    BEFORE INSERT ON `literales_lapso` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `proyectos_lapso`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL,
    ADD COLUMN IF NOT EXISTS `sincronizado` tinyint(4) NOT NULL DEFAULT 0;
UPDATE `proyectos_lapso` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `proyectos_lapso`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_proyectos_lapso_bi`;
CREATE TRIGGER `trg_sync_uuid_proyectos_lapso_bi`
    BEFORE INSERT ON `proyectos_lapso` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);

ALTER TABLE `usuarios`
    ADD COLUMN IF NOT EXISTS `uuid` char(36) NULL;
UPDATE `usuarios` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
ALTER TABLE `usuarios`
    MODIFY `uuid` char(36) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_uuid` (`uuid`);
DROP TRIGGER IF EXISTS `trg_sync_uuid_usuarios_bi`;
CREATE TRIGGER `trg_sync_uuid_usuarios_bi`
    BEFORE INSERT ON `usuarios` FOR EACH ROW
    SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`);
