-- Migration: allow canonical grade names such as "Primer Grado A".
-- Review the counts and back up the database before applying this change.
SELECT MAX(CHAR_LENGTH(nombre)) AS longitud_maxima_actual
FROM secciones;

SELECT COUNT(*) AS secciones_sin_grado
FROM secciones
WHERE grado_id IS NULL;

ALTER TABLE secciones
    MODIFY nombre VARCHAR(50) NOT NULL;
