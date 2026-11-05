<?php
require_once 'database.php';

function tablaExiste($nombreTabla) {
    global $pdo;

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $nombreTabla) . "'");
        return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function verificarEsquemaReportes(array $tablasRequeridas = ['notas', 'lapsos', 'inscripciones', 'años_escolares']) {
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $faltantes = [];
    foreach ($tablasRequeridas as $tabla) {
        if (!tablaExiste($tabla)) {
            $faltantes[] = $tabla;
        }
    }

    if (!empty($faltantes)) {
        $_SESSION['alerta_esquema'] = "El esquema está incompleto. Faltan tablas: " . implode(', ', $faltantes) . ". Algunas funciones de reportes no estarán disponibles.";
        error_log("Esquema incompleto para reportes: faltan " . implode(', ', $faltantes));
    } else {
        unset($_SESSION['alerta_esquema']);
    }

    return $faltantes;
}

/**
 * Calcula el promedio de un estudiante en una materia y lapso específicos
 */
function calcularPromedioMateriaLapso($inscripcion_id, $materia_id, $lapso_id) {
    global $pdo;

    if (!tablaExiste('notas')) {
        trigger_error("Esquema incompleto: falta la tabla 'notas'. El reporte de promedios no puede calcularse.", E_USER_WARNING);
        return ['promedio' => null, 'total_evaluaciones' => 0];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT AVG(n.valor_decimal) as promedio,
                   COUNT(n.id) as total_evaluaciones
            FROM notas n
            WHERE n.inscripcion_id = ?
              AND n.materia_id = ?
              AND n.lapso_id = ?
              AND n.es_promedio = 0
        ");
        $stmt->execute([$inscripcion_id, $materia_id, $lapso_id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'promedio' => $resultado && $resultado['promedio'] !== null ? number_format(floatval($resultado['promedio']), 2, '.', '') : null,
            'total_evaluaciones' => $resultado['total_evaluaciones'] ?? 0
        ];
    } catch (PDOException $e) {
        error_log("Error calculando promedio: " . $e->getMessage());
        return ['promedio' => null, 'total_evaluaciones' => 0];
    }
}

/**
 * Obtiene todas las materias de un estudiante con sus promedios por lapso
 */
function obtenerPromediosEstudiante($inscripcion_id, $año_escolar_id = null) {
    global $pdo;

    try {
        if (!tablaExiste('años_escolares') || !tablaExiste('inscripciones') || !tablaExiste('notas')) {
            trigger_error("Esquema incompleto: faltan tablas del módulo de notas. No se puede generar el reporte de promedios.", E_USER_WARNING);
            return ['materias' => [], 'lapsos' => []];
        }

        if (!$año_escolar_id) {
            $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
            $año_activo = $stmt_año ? $stmt_año->fetch(PDO::FETCH_ASSOC) : false;
            $año_escolar_id = $año_activo ? $año_activo['id'] : null;
        }

        if (!$año_escolar_id) {
            return ['materias' => [], 'lapsos' => []];
        }

        $lapsos = [];
        if (tablaExiste('lapsos')) {
            $stmt_lapsos = $pdo->prepare("SELECT id, numero, nombre FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
            $stmt_lapsos->execute([$año_escolar_id]);
            $lapsos = $stmt_lapsos->fetchAll(PDO::FETCH_ASSOC);
        }

        $materias = [];
        if (tablaExiste('inscripciones_materias')) {
            $stmt = $pdo->prepare("
                SELECT m.id, m.nombre, m.codigo, im.estado
                FROM materias m
                JOIN inscripciones_materias im ON m.id = im.materia_id
                WHERE im.inscripcion_id = ? AND im.año_escolar_id = ?
                ORDER BY m.nombre
            ");
            $stmt->execute([$inscripcion_id, $año_escolar_id]);
            $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (tablaExiste('areas_aprendizaje')) {
            $stmt = $pdo->prepare("
                SELECT aa.id, aa.nombre, aa.codigo, aa.estado
                FROM areas_aprendizaje aa
                WHERE aa.estado = 'activa'
                ORDER BY aa.nombre
            ");
            $stmt->execute();
            $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $resultados = [];
        foreach ($materias as $materia) {
            $materia_data = [
                'id' => $materia['id'],
                'nombre' => $materia['nombre'],
                'codigo' => $materia['codigo'],
                'estado' => $materia['estado'] ?? 'activa',
                'lapsos' => []
            ];

            foreach ($lapsos as $lapso) {
                $promedio_data = calcularPromedioMateriaLapso($inscripcion_id, $materia['id'], $lapso['id']);
                $materia_data['lapsos'][$lapso['numero']] = [
                    'lapso_id' => $lapso['id'],
                    'lapso_nombre' => $lapso['nombre'],
                    'promedio' => $promedio_data['promedio'],
                    'total_evaluaciones' => $promedio_data['total_evaluaciones']
                ];
            }

            $resultados[] = $materia_data;
        }

        return ['materias' => $resultados, 'lapsos' => $lapsos];
    } catch (PDOException $e) {
        error_log("Error obteniendo promedios estudiante: " . $e->getMessage());
        return ['materias' => [], 'lapsos' => []];
    }
}

/**
 * Determina si un estudiante aprueba o reprueba una materia
 */
function determinarEstadoMateria($inscripcion_id, $materia_id, $año_escolar_id) {
    global $pdo;

    if (!tablaExiste('lapsos') || !tablaExiste('notas')) {
        trigger_error("Esquema incompleto: faltan tablas del módulo de notas. Estado de materia no disponible.", E_USER_WARNING);
        return 'cursando';
    }

    try {
        $stmt_lapsos = $pdo->prepare("SELECT id FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
        $stmt_lapsos->execute([$año_escolar_id]);
        $lapsos = $stmt_lapsos->fetchAll(PDO::FETCH_COLUMN);

        if (empty($lapsos)) {
            return 'cursando';
        }

        $total_promedio = 0;
        $lapsos_con_nota = 0;

        foreach ($lapsos as $lapso_id) {
            $promedio_data = calcularPromedioMateriaLapso($inscripcion_id, $materia_id, $lapso_id);

            if ($promedio_data['promedio'] !== null) {
                $total_promedio += floatval($promedio_data['promedio']);
                $lapsos_con_nota++;
            }
        }

        if ($lapsos_con_nota === 0) {
            return 'cursando';
        }

        $promedio_general = $total_promedio / $lapsos_con_nota;
        $promedio_minimo = 10.00;

        if (tablaExiste('configuracion_promocion')) {
            $stmt_config = $pdo->prepare("
                SELECT cp.promedio_minimo
                FROM configuracion_promocion cp
                JOIN secciones s ON s.nivel = cp.nivel
                JOIN inscripciones i ON i.seccion_id = s.id
                WHERE i.id = ? AND cp.año_escolar_id = ?
                LIMIT 1
            ");
            $stmt_config->execute([$inscripcion_id, $año_escolar_id]);
            $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
            $promedio_minimo = $config ? floatval($config['promedio_minimo']) : 10.00;
        }

        return $promedio_general >= $promedio_minimo ? 'aprobada' : 'reprobada';
    } catch (PDOException $e) {
        error_log("Error determinando estado materia: " . $e->getMessage());
        return 'cursando';
    }
}

/**
 * Procesa el avance automático de estudiantes al siguiente año escolar
 */
function procesarAvanceAnual($año_origen_id, $año_destino_id, $opcion_sin_literales = 'excluir', $opcion_recuperacion = 'repetir') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        $debug_info = [];
        $resultado = [
            'total_estudiantes' => 0,
            'aprobados' => 0,
            'reprobados' => 0,
            'sin_literales' => 0,
            'recuperacion' => 0,
            'detalles' => []
        ];
        
        // 1. Obtener todos los estudiantes activos del año origen
        $sql_estudiantes = "
            SELECT 
                e.id as estudiante_id,
                e.cedula_escolar,
                e.nombre,
                e.apellido,
                i.id as inscripcion_id,
                s.id as seccion_id,
                s.nombre as seccion_actual,
                s.grado_id as grado_actual_id,
                g.nombre as grado_actual,
                g.orden as orden_grado,
                g.numero as numero_grado,
                lf.literal_final,
                lf.observaciones_finales
            FROM estudiantes e
            JOIN inscripciones i ON e.id = i.estudiante_id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN grados g ON s.grado_id = g.id
            LEFT JOIN literales_finales lf ON i.id = lf.inscripcion_id
            WHERE i.año_escolar_id = ? 
            AND i.status = 'activo'
            ORDER BY g.orden, s.nombre, e.apellido, e.nombre
        ";
        
        $stmt = $pdo->prepare($sql_estudiantes);
        $stmt->execute([$año_origen_id]);
        $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $resultado['total_estudiantes'] = count($estudiantes);
        $debug_info[] = "Estudiantes encontrados: " . count($estudiantes);
        
        // 2. Obtener información del año destino
        $stmt = $pdo->prepare("SELECT nombre FROM años_escolares WHERE id = ?");
        $stmt->execute([$año_destino_id]);
        $año_destino = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$año_destino) {
            throw new Exception("Año escolar destino no encontrado");
        }
        
        // 3. Procesar cada estudiante
        foreach ($estudiantes as $estudiante) {
            $detalle = [
                'estudiante' => $estudiante['nombre'] . ' ' . $estudiante['apellido'],
                'cedula' => $estudiante['cedula_escolar'],
                'seccion_actual' => $estudiante['seccion_actual'],
                'literal_final' => $estudiante['literal_final'],
                'aprueba_ano' => false,
                'nueva_seccion' => '',
                'observaciones' => ''
            ];
            
            // Caso 1: Estudiante sin literal final
            if (empty($estudiante['literal_final'])) {
                $resultado['sin_literales']++;
                $detalle['observaciones'] = 'Sin literal final';
                
                if ($opcion_sin_literales === 'forzar_avance') {
                    // Forzar avance asignando literal C
                    $estudiante['literal_final'] = 'C';
                    $detalle['observaciones'] = 'Forzado avance con literal C';
                    $detalle['literal_final'] = 'C';
                } else {
                    // Excluir - repetirá año
                    $detalle['aprueba_ano'] = false;
                    $detalle['nueva_seccion'] = 'REPITE AÑO';
                    $resultado['reprobados']++;
                    $resultado['detalles'][] = $detalle;
                    continue;
                }
            }
            
            // Caso 2: Estudiante con literal D/E (recuperación)
            if (in_array($estudiante['literal_final'], ['D', 'E'])) {
                $resultado['recuperacion']++;
                
                if ($opcion_recuperacion === 'repetir') {
                    $detalle['aprueba_ano'] = false;
                    $detalle['nueva_seccion'] = 'REPITE AÑO';
                    $detalle['observaciones'] = 'Literal ' . $estudiante['literal_final'] . ' - Repite año';
                    $resultado['reprobados']++;
                    $resultado['detalles'][] = $detalle;
                    continue;
                } elseif ($opcion_recuperacion === 'forzar_promocion') {
                    $detalle['aprueba_ano'] = true;
                    $detalle['observaciones'] = 'Literal ' . $estudiante['literal_final'] . ' - Promoción forzada';
                } else {
                    // recuperación - dar oportunidad
                    $detalle['aprueba_ano'] = false;
                    $detalle['nueva_seccion'] = 'RECUPERACIÓN';
                    $detalle['observaciones'] = 'Literal ' . $estudiante['literal_final'] . ' - En recuperación';
                    $resultado['reprobados']++;
                    $resultado['detalles'][] = $detalle;
                    continue;
                }
            }
            
            // Caso 3: Estudiante con literal A, B, C (APROBADO)
            if (in_array($estudiante['literal_final'], ['A', 'B', 'C'])) {
                $detalle['aprueba_ano'] = true;
                $detalle['observaciones'] = 'Literal ' . $estudiante['literal_final'] . ' - Aprobado';
                $resultado['aprobados']++;
            } else {
                // Cualquier otro caso (debería ser imposible)
                $detalle['aprueba_ano'] = false;
                $detalle['nueva_seccion'] = 'REPITE AÑO';
                $detalle['observaciones'] = 'Literal ' . $estudiante['literal_final'] . ' - Caso no manejado';
                $resultado['reprobados']++;
                $resultado['detalles'][] = $detalle;
                continue;
            }
            
            // 4. Determinar nueva sección para estudiantes que aprueban
            if ($detalle['aprueba_ano']) {
                $nueva_seccion_info = determinarNuevaSeccionConGrados(
                    $estudiante['grado_actual_id'],
                    $estudiante['orden_grado'],
                    $estudiante['numero_grado'],
                    $año_destino_id
                );
                
                if ($nueva_seccion_info) {
                    $detalle['nueva_seccion'] = $nueva_seccion_info['nombre'];
                    
                    // Crear nueva inscripción
                    $stmt_nueva_inscripcion = $pdo->prepare("
                        INSERT INTO inscripciones 
                        (estudiante_id, seccion_id, año_escolar_id, fecha_inscripcion, status)
                        VALUES (?, ?, ?, CURDATE(), 'activo')
                    ");
                    $stmt_nueva_inscripcion->execute([
                        $estudiante['estudiante_id'],
                        $nueva_seccion_info['id'],
                        $año_destino_id
                    ]);
                    
                    // Cerrar inscripción anterior
                    $stmt_cerrar_inscripcion = $pdo->prepare("
                        UPDATE inscripciones SET status = 'graduado' WHERE id = ?
                    ");
                    $stmt_cerrar_inscripcion->execute([$estudiante['inscripcion_id']]);
                    
                } else {
                    $detalle['nueva_seccion'] = 'NO DISPONIBLE';
                    $detalle['observaciones'] .= ' - No se pudo crear nueva sección';
                }
            } else {
                // Estudiantes que repiten año
                $detalle['nueva_seccion'] = $estudiante['seccion_actual'] . ' (REPITE)';
                
                // Crear nueva inscripción en el MISMO grado
                $stmt_repetir = $pdo->prepare("
                    INSERT INTO inscripciones 
                    (estudiante_id, seccion_id, año_escolar_id, fecha_inscripcion, status)
                    VALUES (?, ?, ?, CURDATE(), 'activo')
                ");
                $stmt_repetir->execute([
                    $estudiante['estudiante_id'],
                    $estudiante['seccion_id'], // Misma sección
                    $año_destino_id
                ]);
                
                // Cerrar inscripción anterior
                $stmt_cerrar_inscripcion = $pdo->prepare("
                    UPDATE inscripciones SET status = 'graduado' WHERE id = ?
                ");
                $stmt_cerrar_inscripcion->execute([$estudiante['inscripcion_id']]);
            }
            
            $resultado['detalles'][] = $detalle;
        }
        
        $pdo->commit();
        return $resultado;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error en procesarAvanceAnual: " . $e->getMessage());
        return false;
    }
}

/**
 * Determina la nueva sección basándose en el sistema de GRADOS
 */
function determinarNuevaSeccionConGrados($grado_actual_id, $orden_actual, $numero_actual, $año_destino_id) {
    global $pdo;
    
    try {
        // Buscar el siguiente grado
        $stmt_siguiente_grado = $pdo->prepare("
            SELECT id, nombre, numero, orden 
            FROM grados 
            WHERE orden > ? AND estado = 'activo'
            ORDER BY orden ASC 
            LIMIT 1
        ");
        $stmt_siguiente_grado->execute([$orden_actual]);
        $siguiente_grado = $stmt_siguiente_grado->fetch(PDO::FETCH_ASSOC);
        
        if (!$siguiente_grado) {
            // No hay siguiente grado (último grado)
            return null;
        }
        
        // Buscar secciones disponibles para el siguiente grado en el año destino
        $stmt_secciones = $pdo->prepare("
            SELECT id, nombre, capacidad
            FROM secciones 
            WHERE grado_id = ? AND año_escolar_id = ?
            AND (capacidad IS NULL OR capacidad > (
                SELECT COUNT(*) FROM inscripciones i 
                WHERE i.seccion_id = secciones.id AND i.status = 'activo'
            ))
            ORDER BY nombre ASC
            LIMIT 1
        ");
        $stmt_secciones->execute([$siguiente_grado['id'], $año_destino_id]);
        $seccion_disponible = $stmt_secciones->fetch(PDO::FETCH_ASSOC);
        
        if ($seccion_disponible) {
            return $seccion_disponible;
        }
        
        // Si no hay secciones disponibles, crear una nueva
        $nueva_seccion_nombre = $siguiente_grado['nombre'] . ' A';
        return crearNuevaSeccion($nueva_seccion_nombre, $siguiente_grado['id'], $año_destino_id);
        
    } catch (PDOException $e) {
        error_log("Error determinando nueva sección: " . $e->getMessage());
        return null;
    }
}

/**
 * Crea una nueva sección automáticamente
 */
function crearNuevaSeccion($nombre, $grado_id, $año_escolar_id) {
    global $pdo;
    
    try {
        // Obtener información del grado para determinar nivel
        $stmt_grado = $pdo->prepare("SELECT nivel FROM grados WHERE id = ?");
        $stmt_grado->execute([$grado_id]);
        $grado = $stmt_grado->fetch(PDO::FETCH_ASSOC);
        
        if (!$grado) {
            return null;
        }
        
        $stmt_insert = $pdo->prepare("
            INSERT INTO secciones 
            (nombre, año_escolar_id, grado_id, nivel, tipo_grado, capacidad)
            VALUES (?, ?, ?, ?, 'grado', 25)
        ");
        $stmt_insert->execute([
            $nombre,
            $año_escolar_id,
            $grado_id,
            $grado['nivel']
        ]);
        
        $seccion_id = $pdo->lastInsertId();
        
        return [
            'id' => $seccion_id,
            'nombre' => $nombre,
            'capacidad' => 25
        ];
        
    } catch (PDOException $e) {
        error_log("Error creando nueva sección: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene estudiantes sin literales finales (función auxiliar)
 */
function obtenerEstudiantesSinLiterales($año_escolar_id) {
    global $pdo;
    
    try {
        $sql = "
            SELECT 
                e.id,
                e.cedula_escolar,
                e.nombre,
                e.apellido,
                s.nombre as seccion,
                s.id as seccion_id,
                g.nombre as grado,
                i.id as inscripcion_id
            FROM estudiantes e
            JOIN inscripciones i ON e.id = i.estudiante_id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN grados g ON s.grado_id = g.id
            LEFT JOIN literales_finales lf ON i.id = lf.inscripcion_id
            WHERE i.año_escolar_id = ? 
            AND i.status = 'activo'
            AND lf.id IS NULL
            ORDER BY g.orden, s.nombre, e.apellido, e.nombre
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$año_escolar_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error en obtenerEstudiantesSinLiterales: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene estudiantes que necesitan recuperación (función auxiliar)
 */
function obtenerEstudiantesParaRecuperacion($año_escolar_id) {
    global $pdo;
    
    try {
        $sql = "
            SELECT 
                e.id,
                e.cedula_escolar,
                e.nombre,
                e.apellido,
                s.nombre as seccion,
                s.id as seccion_id,
                g.nombre as grado,
                lf.literal_final,
                lf.observaciones_finales,
                i.id as inscripcion_id
            FROM estudiantes e
            JOIN inscripciones i ON e.id = i.estudiante_id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN grados g ON s.grado_id = g.id
            JOIN literales_finales lf ON i.id = lf.inscripcion_id
            WHERE i.año_escolar_id = ? 
            AND i.status = 'activo'
            AND lf.literal_final IN ('D', 'E')
            ORDER BY g.orden, s.nombre, e.apellido, e.nombre
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$año_escolar_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error en obtenerEstudiantesParaRecuperacion: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene el sufijo para el grado (1er, 2do, 3er, etc.)
 */
function getSufijoGrado($numero) {
    switch ($numero) {
        case 1: return 'er';
        case 2: return 'do';
        case 3: return 'er';
        case 4: return 'to';
        case 5: return 'to';
        case 6: return 'to';
        default: return 'to';
    }
}

/**
 * Obtiene o crea una sección en el nuevo año escolar
 */
function obtenerOCrearSeccion($nombre_seccion, $año_escolar_id, $nivel, $tipo_grado) {
    global $pdo;
    
    // Buscar si ya existe la sección
    $stmt = $pdo->prepare("
        SELECT id FROM secciones 
        WHERE nombre = ? AND año_escolar_id = ?
    ");
    $stmt->execute([$nombre_seccion, $año_escolar_id]);
    $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($seccion) {
        return $seccion['id'];
    }
    
    // Crear nueva sección
    $stmt_insert = $pdo->prepare("
        INSERT INTO secciones (nombre, año_escolar_id, nivel, tipo_grado, capacidad)
        VALUES (?, ?, ?, ?, 30)
    ");
    $stmt_insert->execute([$nombre_seccion, $año_escolar_id, $nivel, $tipo_grado]);
    
    return $pdo->lastInsertId();
}

/**
 * Obtiene las materias por nivel educativo
 */
function obtenerMateriasPorNivel($nivel) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, nombre FROM materias 
        WHERE nivel = ? AND estado = 'activa'
    ");
    $stmt->execute([$nivel]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene el ID de una materia por nombre
 */
function obtenerMateriaPorNombre($nombre_materia) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM materias WHERE nombre = ? LIMIT 1
    ");
    $stmt->execute([$nombre_materia]);
    $materia = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $materia ? $materia['id'] : null;
}
?>