(function () {
    'use strict';

    const VALORACIONES = ['LE', 'L', 'PA', 'IP', 'NL'];

    function normalizar(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .trim()
            .replace(/\s+/g, ' ');
    }

    function centroPalabra(palabra) {
        if (!palabra || !palabra.bbox) {
            return null;
        }
        const { x0, y0, x1, y1 } = palabra.bbox;
        return {
            x: (x0 + x1) / 2,
            y: (y0 + y1) / 2,
            height: Math.max(1, y1 - y0),
            confidence: Number(palabra.confidence) || 0,
            text: String(palabra.text || '')
        };
    }

    function prepararImagen(file, grados) {
        return createImageBitmap(file).then((bitmap) => {
            const angulo = ((grados % 360) + 360) % 360;
            const intercambiarDimensiones = angulo === 90 || angulo === 270;
            const anchoOriginal = intercambiarDimensiones ? bitmap.height : bitmap.width;
            const altoOriginal = intercambiarDimensiones ? bitmap.width : bitmap.height;
            const escala = Math.min(1, 2400 / Math.max(anchoOriginal, altoOriginal));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(anchoOriginal * escala);
            canvas.height = Math.round(altoOriginal * escala);
            const contexto = canvas.getContext('2d');
            if (!contexto) {
                bitmap.close();
                throw new Error('El navegador no pudo preparar la imagen para OCR.');
            }
            contexto.translate(canvas.width / 2, canvas.height / 2);
            contexto.rotate(angulo * Math.PI / 180);
            contexto.drawImage(
                bitmap,
                -bitmap.width * escala / 2,
                -bitmap.height * escala / 2,
                bitmap.width * escala,
                bitmap.height * escala
            );
            bitmap.close();
            return canvas;
        });
    }

    function encontrarEncabezados(palabras, anchoImagen) {
        const etiquetas = palabras
            .map((palabra) => {
                const centro = centroPalabra(palabra);
                const etiqueta = normalizar(palabra.text).replace(/\s/g, '').toUpperCase();
                return centro && VALORACIONES.includes(etiqueta)
                    ? { ...centro, etiqueta }
                    : null;
            })
            .filter(Boolean)
            .sort((a, b) => a.y - b.y || a.x - b.x);
        const grupos = [];
        const saltoMaximo = Math.max(24, anchoImagen * 0.055);

        for (const inicial of etiquetas.filter((etiqueta) => etiqueta.etiqueta === 'LE')) {
            const encontrados = [inicial];
            let anterior = inicial;
            let valido = true;
            for (const etiquetaNecesaria of VALORACIONES.slice(1)) {
                const siguiente = etiquetas
                    .filter((etiqueta) =>
                        etiqueta.etiqueta === etiquetaNecesaria &&
                        etiqueta.x > anterior.x &&
                        Math.abs(etiqueta.y - inicial.y) <= Math.max(18, inicial.height * 1.8) &&
                        etiqueta.x - anterior.x <= saltoMaximo
                    )
                    .sort((a, b) =>
                        Math.abs(a.y - inicial.y) - Math.abs(b.y - inicial.y) ||
                        a.x - b.x
                    )[0];
                if (!siguiente) {
                    valido = false;
                    break;
                }
                encontrados.push(siguiente);
                anterior = siguiente;
            }
            if (!valido) {
                continue;
            }
            const grupo = { y: inicial.y, columnas: encontrados };
            const duplicado = grupos.some((existente) =>
                Math.abs(existente.y - grupo.y) < 8 &&
                Math.abs(existente.columnas[0].x - grupo.columnas[0].x) < 12
            );
            if (!duplicado) {
                grupos.push(grupo);
            }
        }
        return grupos;
    }

    function coberturaTexto(indicador, textoReconocido) {
        const buscado = normalizar(indicador).replace(/\s/g, '');
        const reconocido = normalizar(textoReconocido).replace(/\s/g, '');
        if (buscado.length < 4 || reconocido.length < 4) {
            return 0;
        }
        const ngramas = new Set();
        for (let indice = 0; indice <= buscado.length - 3; indice += 1) {
            ngramas.add(buscado.slice(indice, indice + 3));
        }
        let coincidencias = 0;
        for (const ngrama of ngramas) {
            if (reconocido.includes(ngrama)) {
                coincidencias += 1;
            }
        }
        return ngramas.size ? coincidencias / ngramas.size : 0;
    }

    function buscarIndicador(palabras, marca, encabezado, indicadores) {
        const toleranciaY = Math.max(18, Math.min(42, marca.height * 2.7));
        const textoFila = palabras
            .filter((palabra) =>
                palabra.x < encabezado.columnas[0].x &&
                Math.abs(palabra.y - marca.y) <= toleranciaY
            )
            .sort((a, b) => a.y - b.y || a.x - b.x)
            .map((palabra) => palabra.text)
            .join(' ');
        if (!textoFila) {
            return null;
        }

        const resultados = indicadores
            .map((indicador) => ({
                indicador,
                puntuacion: coberturaTexto(indicador.text, textoFila)
            }))
            .sort((a, b) => b.puntuacion - a.puntuacion);
        const mejor = resultados[0];
        const segundo = resultados[1];
        if (!mejor || mejor.puntuacion < 0.24 ||
            (segundo && mejor.puntuacion - segundo.puntuacion < 0.055)) {
            return null;
        }
        return { ...mejor, textoFila };
    }

    function extraerSugerencias(resultado, canvas, indicadores) {
        const palabras = (resultado.data.words || [])
            .map((palabra) => {
                const centro = centroPalabra(palabra);
                return centro ? { ...centro, raw: palabra } : null;
            })
            .filter(Boolean);
        const encabezados = encontrarEncabezados(palabras.map((palabra) => palabra.raw), canvas.width);
        if (!encabezados.length) {
            return { sugerencias: [], marcasSinCoincidencia: 0 };
        }

        const marcas = palabras.filter((palabra) =>
            normalizar(palabra.text).replace(/\s/g, '') === 'x' &&
            palabra.confidence >= 10
        );
        const detectadas = new Map();
        const conflictos = new Set();
        let marcasSinCoincidencia = 0;

        for (const marca of marcas) {
            const encabezado = encabezados
                .filter((grupo) =>
                    grupo.y < marca.y &&
                    marca.x >= grupo.columnas[0].x - 12 &&
                    marca.x <= grupo.columnas[grupo.columnas.length - 1].x + 12
                )
                .sort((a, b) => b.y - a.y)[0];
            if (!encabezado) {
                continue;
            }
            const indiceColumna = encabezado.columnas
                .map((columna, indice) => ({ indice, distancia: Math.abs(columna.x - marca.x) }))
                .sort((a, b) => a.distancia - b.distancia)[0];
            const separacion = encabezado.columnas[1].x - encabezado.columnas[0].x;
            if (!indiceColumna || indiceColumna.distancia > separacion * 0.48) {
                continue;
            }

            const coincidencia = buscarIndicador(palabras, marca, encabezado, indicadores);
            if (!coincidencia) {
                marcasSinCoincidencia += 1;
                continue;
            }
            const id = String(coincidencia.indicador.id);
            if (conflictos.has(id)) {
                continue;
            }
            const anterior = detectadas.get(id);
            const valoracion = VALORACIONES[indiceColumna.indice];
            if (anterior && anterior.valoracion !== valoracion) {
                detectadas.delete(id);
                conflictos.add(id);
                marcasSinCoincidencia += 2;
                continue;
            }
            detectadas.set(id, {
                id,
                text: coincidencia.indicador.text,
                valoracion,
                puntuacion: Math.round(coincidencia.puntuacion * 100)
            });
        }
        return {
            sugerencias: Array.from(detectadas.values()),
            marcasSinCoincidencia
        };
    }

    function inicializar() {
        const panel = document.getElementById('historial-ocr');
        if (!panel) {
            return;
        }

        const archivos = document.getElementById('historial-ocr-files');
        const rotacion = document.getElementById('historial-ocr-rotation');
        const botonLeer = document.getElementById('historial-ocr-read');
        const botonAplicar = document.getElementById('historial-ocr-apply');
        const progreso = document.getElementById('historial-ocr-progress');
        const estado = document.getElementById('historial-ocr-status');
        const resultados = document.getElementById('historial-ocr-results');
        const tablaSugerencias = document.getElementById('historial-ocr-suggestions');
        let sugerenciasActuales = [];

        function mostrarSugerencias(sugerencias) {
            tablaSugerencias.replaceChildren();
            for (const sugerencia of sugerencias) {
                const fila = document.createElement('tr');
                const celdaIndicador = document.createElement('td');
                const celdaValoracion = document.createElement('td');
                const celdaCoincidencia = document.createElement('td');
                celdaIndicador.textContent = sugerencia.text;
                celdaValoracion.textContent = sugerencia.valoracion;
                celdaCoincidencia.textContent = `${sugerencia.puntuacion}%`;
                fila.append(celdaIndicador, celdaValoracion, celdaCoincidencia);
                tablaSugerencias.appendChild(fila);
            }
            resultados.hidden = false;
            botonAplicar.disabled = sugerencias.length === 0;
        }

        botonLeer.addEventListener('click', async () => {
            const seleccionados = Array.from(archivos.files || []);
            const tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp'];
            if (seleccionados.length === 0) {
                estado.textContent = 'Selecciona al menos una imagen.';
                return;
            }
            if (seleccionados.length > 5 ||
                seleccionados.some((archivo) =>
                    !tiposPermitidos.includes(archivo.type) ||
                    archivo.size > 12 * 1024 * 1024
                )) {
                estado.textContent = 'Selecciona hasta 5 imágenes JPG, PNG o WebP, de máximo 12 MB cada una.';
                return;
            }
            if (!window.Tesseract) {
                estado.textContent = 'No se pudo cargar el motor OCR local. Verifica que los recursos de js/ocr/assets estén publicados.';
                return;
            }

            let indicadores;
            try {
                indicadores = JSON.parse(panel.dataset.indicators || '[]');
            } catch (error) {
                estado.textContent = 'No se pudo cargar el catálogo de indicadores del año.';
                return;
            }
            if (!indicadores.length) {
                estado.textContent = 'El plan de este año no tiene indicadores para comparar.';
                return;
            }

            botonLeer.disabled = true;
            botonAplicar.disabled = true;
            progreso.value = 0;
            resultados.hidden = true;
            estado.textContent = 'Iniciando OCR local en este dispositivo…';
            let worker = null;
            let indiceActual = 0;
            const acumuladas = new Map();
            const conflictosEntreImagenes = new Set();
            let marcasSinCoincidencia = 0;
            try {
                worker = await Tesseract.createWorker('spa', Tesseract.OEM.LSTM_ONLY, {
                    workerPath: panel.dataset.workerUrl,
                    corePath: panel.dataset.coreUrl,
                    langPath: panel.dataset.languageUrl,
                    gzip: true,
                    logger: (evento) => {
                        if (evento.status === 'recognizing text' && Number.isFinite(evento.progress)) {
                            progreso.value = Math.round(
                                ((indiceActual + evento.progress) / seleccionados.length) * 100
                            );
                        }
                    }
                });

                for (let indice = 0; indice < seleccionados.length; indice += 1) {
                    indiceActual = indice;
                    estado.textContent = `Analizando imagen ${indice + 1} de ${seleccionados.length}…`;
                    const canvas = await prepararImagen(seleccionados[indice], Number(rotacion.value));
                    const resultado = await worker.recognize(canvas);
                    const lectura = extraerSugerencias(resultado, canvas, indicadores);
                    marcasSinCoincidencia += lectura.marcasSinCoincidencia;
                    for (const sugerencia of lectura.sugerencias) {
                        if (conflictosEntreImagenes.has(sugerencia.id)) {
                            continue;
                        }
                        const previa = acumuladas.get(sugerencia.id);
                        if (previa && previa.valoracion !== sugerencia.valoracion) {
                            acumuladas.delete(sugerencia.id);
                            conflictosEntreImagenes.add(sugerencia.id);
                            marcasSinCoincidencia += 2;
                        } else {
                            acumuladas.set(sugerencia.id, sugerencia);
                        }
                    }
                    progreso.value = Math.round(((indice + 1) / seleccionados.length) * 100);
                }

                sugerenciasActuales = Array.from(acumuladas.values());
                mostrarSugerencias(sugerenciasActuales);
                estado.textContent = sugerenciasActuales.length
                    ? `Se encontraron ${sugerenciasActuales.length} sugerencias. Verifica cada una; ${marcasSinCoincidencia} marca(s) no pudieron asociarse con seguridad.`
                    : 'No se encontraron coincidencias suficientemente claras. Prueba otra rotación o una foto más nítida.';
            } catch (error) {
                estado.textContent = `No se pudo completar el OCR local: ${error.message || 'error inesperado'}`;
                sugerenciasActuales = [];
            } finally {
                if (worker) {
                    await worker.terminate();
                }
                botonLeer.disabled = false;
            }
        });

        botonAplicar.addEventListener('click', () => {
            for (const sugerencia of sugerenciasActuales) {
                const campo = document.querySelector(`[name="indicadores[${sugerencia.id}]"]`);
                if (campo) {
                    campo.value = sugerencia.valoracion;
                    campo.dispatchEvent(new Event('input', { bubbles: true }));
                    campo.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            botonAplicar.disabled = true;
            estado.textContent = 'Sugerencias aplicadas. Revisa las valoraciones en el formulario antes de guardar.';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
}());
