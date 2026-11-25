(function () {
    'use strict';

    function normalizar(texto) {
        return texto
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function seccionEntre(lineas, inicio, finales) {
        const indiceInicio = lineas.findIndex((linea) => inicio.test(normalizar(linea)));
        if (indiceInicio < 0) {
            return lineas;
        }
        const indiceFinRelativo = lineas.slice(indiceInicio + 1)
            .findIndex((linea) => finales.some((final) => final.test(normalizar(linea))));
        const indiceFin = indiceFinRelativo < 0
            ? lineas.length
            : indiceInicio + 1 + indiceFinRelativo;
        return lineas.slice(indiceInicio + 1, indiceFin);
    }

    function extraerValor(lineas, etiqueta) {
        for (let indice = 0; indice < lineas.length; indice += 1) {
            const linea = lineas[indice];
            const coincidencia = normalizar(linea).match(etiqueta);
            if (!coincidencia) {
                continue;
            }

            let valor = linea.slice(coincidencia.index + coincidencia[0].length)
                .replace(/^[\s:;#.,_-]+/, '')
                .trim();
            if (!valor && lineas[indice + 1]) {
                const siguiente = lineas[indice + 1].trim();
                if (siguiente && !/^(?:datos|documentos|observaciones|firma|nombres?|apellidos?|fecha|sexo|c\.?\s*i\.?|cedula|telefono|direccion|correo)\b/i.test(normalizar(siguiente))) {
                    valor = siguiente;
                }
            }
            if (valor) {
                return valor.replace(/\s+/g, ' ').trim();
            }
        }
        return '';
    }

    function dividirNombreCompleto(nombre, ordenApellidosPrimero) {
        const partes = nombre
            .replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s'-]/g, ' ')
            .split(/\s+/)
            .filter(Boolean);
        if (partes.length < 2) {
            return {};
        }

        const corte = Math.min(2, partes.length - 1);
        const primeraParte = partes.slice(0, corte).join(' ');
        const segundaParte = partes.slice(corte).join(' ');
        return ordenApellidosPrimero
            ? { apellido: primeraParte, nombre: segundaParte }
            : { nombre: primeraParte, apellido: segundaParte };
    }

    function normalizarFecha(valor) {
        const coincidencia = valor.match(/\b(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})\b/);
        if (!coincidencia) {
            return '';
        }
        const dia = Number(coincidencia[1]);
        const mes = Number(coincidencia[2]);
        let año = Number(coincidencia[3]);
        if (año < 100) {
            año += año < 40 ? 2000 : 1900;
        }
        const fecha = new Date(Date.UTC(año, mes - 1, dia));
        if (fecha.getUTCFullYear() !== año || fecha.getUTCMonth() !== mes - 1 ||
            fecha.getUTCDate() !== dia) {
            return '';
        }
        return `${String(año).padStart(4, '0')}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
    }

    function sugerenciasDeSeccion(lineas, inicio, finales) {
        const seccion = seccionEntre(lineas, inicio, finales);
        const sugerencias = {};
        const nombreCompuesto = extraerValor(
            seccion,
            /(?:apellidos?\s+y\s+nombres?|nombres?\s+y\s+apellidos?)/
        );
        if (nombreCompuesto) {
            const ordenApellidosPrimero = /apellidos?\s+y\s+nombres?/.test(
                normalizar(seccion.join(' '))
            );
            Object.assign(
                sugerencias,
                dividirNombreCompleto(nombreCompuesto, ordenApellidosPrimero)
            );
        } else {
            const nombres = extraerValor(seccion, /^\s*nombres?\b/);
            const apellidos = extraerValor(seccion, /^\s*apellidos?\b/);
            if (nombres) {
                sugerencias.nombre = nombres;
            }
            if (apellidos) {
                sugerencias.apellido = apellidos;
            }
        }

        const fechaNacimiento = extraerValor(seccion, /fecha\s+de\s+nacimiento/);
        const fecha = normalizarFecha(fechaNacimiento);
        if (fecha) {
            sugerencias.fecha_nacimiento = fecha;
        }

        const genero = extraerValor(seccion, /\bsexo\b/);
        const generoNormalizado = normalizar(genero);
        if (/^(m|masculino)\b/.test(generoNormalizado)) {
            sugerencias.genero = 'masculino';
        } else if (/^(f|femenino)\b/.test(generoNormalizado)) {
            sugerencias.genero = 'femenino';
        }

        const lugarNacimiento = extraerValor(seccion, /lugar\s+de\s+nacimiento/);
        if (lugarNacimiento) {
            sugerencias.lugar_nacimiento = lugarNacimiento;
        }

        const direccion = extraerValor(seccion, /direccion\s+(?:de\s+)?residencia/);
        if (direccion) {
            sugerencias.direccion = direccion;
        }

        const escuela = extraerValor(seccion, /institucion\s+de\s+procedencia|escuela\s+anterior/);
        if (escuela) {
            sugerencias.escuela_anterior = escuela;
        }

        const telefono = extraerValor(seccion, /telefono\s+(?:celular|residencia)?/);
        const numeroTelefono = telefono.replace(/\D/g, '').match(/\d{11}/);
        if (numeroTelefono) {
            const codigo = numeroTelefono[0].slice(0, 4);
            if (/^04(?:12|14|16|24|26)$/.test(codigo)) {
                sugerencias.telefono_codigo = codigo;
                sugerencias.telefono_numero = numeroTelefono[0].slice(4);
            }
        }

        const correo = seccion.join(' ').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
        if (correo) {
            sugerencias.email = correo[0];
        }
        return sugerencias;
    }

    function extraerSugerencias(texto, tipoForzado) {
        const lineas = texto.split(/\r?\n/).map((linea) => linea.trim()).filter(Boolean);
        const textoNormalizado = normalizar(texto);
        let tipo = tipoForzado;
        if (tipo === 'auto') {
            if (/tarjeta\s+de\s+vacunacion|control\s+de\s+vacunas|biologicos/.test(textoNormalizado)) {
                tipo = 'vacunas';
            } else if (/registro\s+de\s+nacimiento|certificado\s+medico\s+de\s+nacimiento|hijo\s+o\s+hija/.test(textoNormalizado)) {
                tipo = 'nacimiento';
            } else {
                tipo = 'inscripcion';
            }
        }

        if (tipo === 'nacimiento') {
            if (!lineas.some((linea) => /hijo\s*(?:o|\/)?\s*hija|datos\s+del\s+nacido/i.test(normalizar(linea)))) {
                return {};
            }
            const datosHijo = seccionEntre(
                lineas,
                /hijo\s*(?:o|\/)?\s*hija|datos\s+del\s+nacido/,
                [/datos\s+de\s+la\s+madre/, /datos\s+del\s+padre/, /datos\s+del\s+declarante/]
            );
            const nombres = extraerValor(datosHijo, /^\s*nombres?\b/);
            const apellidos = extraerValor(datosHijo, /^\s*apellidos?\b/);
            const sugerencias = {};
            if (nombres) {
                sugerencias.nombre = nombres;
            }
            if (apellidos) {
                sugerencias.apellido = apellidos;
            }
            const fechaNacimiento = normalizarFecha(
                extraerValor(datosHijo, /fecha\s+de\s+nacimiento|fecha\s+de\s+expedicion/)
            );
            if (fechaNacimiento) {
                sugerencias.fecha_nacimiento = fechaNacimiento;
            }
            const lugar = extraerValor(datosHijo, /lugar\s+de\s+nacimiento/);
            if (lugar) {
                sugerencias.lugar_nacimiento = lugar;
            }
            return sugerencias;
        }

        const datosEstudiante = sugerenciasDeSeccion(
            lineas,
            /datos\s+de\s+identificacion\s+del|datos\s+del\s+estudiante|datos\s+del\s+nacido|nombre\s+del\s+usuario/,
            [/datos\s+del\s+representante/, /datos\s+de\s+la\s+madre/, /datos\s+del\s+padre/, /datos\s+del\s+grupo\s+familiar/]
        );

        if (tipo === 'vacunas') {
            const nombreUsuario = extraerValor(
                lineas,
                /nombre\s+del\s+usuario(?:\s*\(?a\)?)?/
            );
            if (nombreUsuario) {
                Object.assign(datosEstudiante, dividirNombreCompleto(nombreUsuario, false));
            }
            const fechaUsuario = normalizarFecha(
                extraerValor(lineas, /fecha\s+de\s+nacimiento/)
            );
            if (fechaUsuario) {
                datosEstudiante.fecha_nacimiento = fechaUsuario;
            }
            const cedulaEstudiante = extraerValor(
                lineas,
                /\b(?:c\.?\s*i\.?|cedula(?:\s+de\s+identidad)?)\s*[:#.-]?\s*/
            ).match(/\b\d{6,10}\b/);
            if (cedulaEstudiante) {
                datosEstudiante.cedula_estudiante_sugerida = cedulaEstudiante[0];
            }
            return datosEstudiante;
        }

        const existeSeccionRepresentante = lineas.some((linea) =>
            /datos\s+del\s+representante\s+legal/i.test(normalizar(linea))
        );
        const datosRepresentante = existeSeccionRepresentante
            ? seccionEntre(
                lineas,
                /datos\s+del\s+representante\s+legal/,
                [/datos\s+de\s+la\s+madre/, /datos\s+del\s+padre/, /datos\s+del\s+grupo\s+familiar/, /documentos\s+consignados/]
            )
            : [];
        const cedula = extraerValor(datosRepresentante, /\b(?:c\.?\s*i\.?|cedula(?:\s+de\s+identidad)?)\s*[:#.-]?\s*/);
        const numeroCedula = cedula.match(/\b\d{6,10}\b/);
        if (numeroCedula) {
            datosEstudiante.representante_cedula_sugerida = numeroCedula[0];
        }
        const datosRepresentanteExtraidos = sugerenciasDeSeccion(
            datosRepresentante,
            /datos\s+del\s+representante\s+legal/,
            []
        );
        for (const [campo, valor] of Object.entries(datosRepresentanteExtraidos)) {
            const campoRepresentante = `representante_${campo}`;
            if (!datosEstudiante[campoRepresentante]) {
                datosEstudiante[campoRepresentante] = valor;
            }
        }
        return datosEstudiante;
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

    function establecerValorSiVacio(nombre, valor) {
        const campo = document.querySelector(`[name="${nombre}"]`);
        if (!campo || !valor || campo.value.trim() !== '') {
            return false;
        }
        campo.value = valor;
        campo.dispatchEvent(new Event('input', { bubbles: true }));
        campo.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function aplicarTelefono(prefijo, valor, alternativo) {
        let digitos = String(valor || '').replace(/\D/g, '');
        if (digitos.length === 12 && digitos.startsWith('58')) {
            digitos = `0${digitos.slice(2)}`;
        } else if (digitos.length === 10 && /^4/.test(digitos)) {
            digitos = `0${digitos}`;
        }

        let codigo = '';
        let numero = '';
        const telefonoValido = digitos.match(/^(04(?:12|14|16|24|26))(\d{7})$/);
        if (telefonoValido) {
            codigo = telefonoValido[1];
            numero = telefonoValido[2];
        } else if (/^\d{7}$/.test(digitos)) {
            numero = digitos;
        } else {
            return 0;
        }

        const base = prefijo ? `${prefijo}_telefono` : 'telefono';
        const sufijo = alternativo ? '_alternativo' : '';
        let aplicados = 0;
        if (codigo && establecerValorSiVacio(`${base}${sufijo}_codigo`, codigo)) {
            aplicados += 1;
        }
        if (numero && establecerValorSiVacio(`${base}${sufijo}_numero`, numero)) {
            aplicados += 1;
        }
        return aplicados;
    }

    function inicializar() {
        const panel = document.getElementById('ocr-registro');
        if (!panel) {
            return;
        }

        const archivos = document.getElementById('ocr-archivos');
        const tipoDocumento = document.getElementById('ocr-tipo');
        const rotacion = document.getElementById('ocr-rotacion');
        const botonLeer = document.getElementById('ocr-leer');
        const botonGemini = document.getElementById('ocr-gemini');
        const botonAplicar = document.getElementById('ocr-aplicar');
        const consentimientoGemini = document.getElementById('ocr-gemini-consentimiento');
        const salida = document.getElementById('ocr-texto');
        const estado = document.getElementById('ocr-estado');
        const progreso = document.getElementById('ocr-progreso');
        let sugerenciasActuales = {};
        let procesoActivo = false;

        function actualizarBotonGemini() {
            botonGemini.disabled = procesoActivo ||
                !consentimientoGemini.checked ||
                !archivos.files ||
                archivos.files.length < 1 ||
                archivos.files.length > 5;
        }

        archivos.addEventListener('change', () => {
            consentimientoGemini.checked = false;
            actualizarBotonGemini();
        });
        consentimientoGemini.addEventListener('change', actualizarBotonGemini);
        actualizarBotonGemini();

        botonLeer.addEventListener('click', async () => {
            const seleccionados = Array.from(archivos.files || []);
            if (seleccionados.length === 0) {
                estado.textContent = 'Selecciona al menos una imagen.';
                return;
            }
            const tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp'];
            if (seleccionados.length > 5 ||
                seleccionados.some((archivo) => !tiposPermitidos.includes(archivo.type) || archivo.size > 12 * 1024 * 1024)) {
                estado.textContent = 'Selecciona hasta 5 imágenes JPG, PNG o WebP, de máximo 12 MB cada una.';
                return;
            }
            if (!window.Tesseract) {
                const scriptMotor = document.getElementById('ocr-tesseract-library');
                const rutaMotor = scriptMotor ? scriptMotor.src : 'js/ocr/assets/tesseract.min.js';
                estado.textContent = `No se pudo cargar el motor OCR local (${rutaMotor}). Verifica que el recurso esté publicado y sea accesible.`;
                return;
            }

            procesoActivo = true;
            botonLeer.disabled = true;
            actualizarBotonGemini();
            botonAplicar.disabled = true;
            progreso.value = 0;
            estado.textContent = 'Iniciando OCR local en este dispositivo…';
            let worker = null;
            let indiceActual = 0;
            sugerenciasActuales = {};
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
                    const archivo = seleccionados[indice];
                    estado.textContent = `Leyendo imagen ${indice + 1} de ${seleccionados.length}…`;
                    const imagen = await prepararImagen(archivo, Number(rotacion.value));
                    const resultado = await worker.recognize(imagen);
                    const texto = resultado.data.text.trim();
                    salida.value = `${salida.value}${salida.value ? '\n\n' : ''}--- ${archivo.name} (${Math.round(resultado.data.confidence)}% OCR) ---\n${texto}`;
                    Object.assign(
                        sugerenciasActuales,
                        extraerSugerencias(texto, tipoDocumento.value)
                    );
                    progreso.value = Math.round(((indice + 1) / seleccionados.length) * 100);
                }

                const cantidad = Object.keys(sugerenciasActuales).length;
                botonAplicar.disabled = cantidad === 0;
                estado.textContent = cantidad
                    ? `Lectura terminada. Hay ${cantidad} sugerencia(s); revísalas antes de aplicarlas.`
                    : 'Lectura terminada. Revisa el texto reconocido y completa los campos manualmente.';
            } catch (error) {
                estado.textContent = `No se pudo leer el documento: ${error.message}`;
                botonAplicar.disabled = Object.keys(sugerenciasActuales).length === 0;
            } finally {
                if (worker) {
                    try {
                        await worker.terminate();
                    } catch (error) {
                        estado.textContent = `No se pudo cerrar el motor OCR correctamente: ${error.message}`;
                    }
                }
                botonLeer.disabled = false;
                procesoActivo = false;
                actualizarBotonGemini();
            }
        });

        botonGemini.addEventListener('click', async () => {
            if (procesoActivo || panel.dataset.geminiRequestActive === 'true') {
                return;
            }
            const seleccionados = Array.from(archivos.files || []);
            if (!consentimientoGemini.checked) {
                estado.textContent = 'Confirma que la imagen es sintética o está completamente anonimizada.';
                return;
            }
            if (seleccionados.length < 1 || seleccionados.length > 5) {
                estado.textContent = 'Selecciona entre 1 y 5 imágenes del mismo estudiante para evaluar con Gemini.';
                return;
            }
            if (seleccionados.some((archivo) =>
                !['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type) ||
                archivo.size > 12 * 1024 * 1024
            )) {
                estado.textContent = 'Selecciona imágenes JPG, PNG o WebP, de máximo 12 MB cada una.';
                return;
            }

            procesoActivo = true;
            panel.dataset.geminiRequestActive = 'true';
            botonLeer.disabled = true;
            archivos.disabled = true;
            rotacion.disabled = true;
            consentimientoGemini.disabled = true;
            botonGemini.disabled = true;
            actualizarBotonGemini();
            botonAplicar.disabled = true;
            progreso.value = 0;
            estado.textContent = `Preparando ${seleccionados.length} imagen(es) para enviarlas juntas a Gemini…`;
            try {
                const imagenesPreparadas = [];
                let tamanoTotal = 0;
                for (let indice = 0; indice < seleccionados.length; indice += 1) {
                    estado.textContent = `Preparando imagen ${indice + 1} de ${seleccionados.length}…`;
                    const canvas = await prepararImagen(
                        seleccionados[indice],
                        Number(rotacion.value)
                    );
                    const imagenPreparada = await new Promise((resolve) => {
                        canvas.toBlob(resolve, 'image/jpeg', 0.88);
                    });
                    if (!imagenPreparada) {
                        throw new Error(`El navegador no pudo preparar la imagen ${indice + 1}.`);
                    }
                    tamanoTotal += imagenPreparada.size;
                    if (tamanoTotal > 3 * 1024 * 1024) {
                        throw new Error('Las imágenes preparadas superan el límite combinado de 3 MB.');
                    }
                    imagenesPreparadas.push(imagenPreparada);
                }

                const datos = new FormData();
                datos.append('confirmacion_sintetica', '1');
                imagenesPreparadas.forEach((imagenPreparada, indice) => {
                    datos.append('imagenes[]', imagenPreparada, `documento-${indice + 1}.jpg`);
                });
                estado.textContent = `Enviando ${imagenesPreparadas.length} imagen(es) juntas a Gemini…`;
                const respuesta = await fetch(panel.dataset.geminiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': panel.dataset.csrfToken },
                    body: datos
                });
                let contenido;
                try {
                    contenido = await respuesta.json();
                } catch (error) {
                    throw new Error('El servidor devolvió una respuesta que no se pudo leer.');
                }
                if (!respuesta.ok) {
                    throw new Error(contenido.message || 'No se pudo completar la evaluación Gemini.');
                }
                if (!contenido || !contenido.result ||
                    !contenido.result.estudiante || !contenido.result.representante) {
                    throw new Error('El servidor devolvió sugerencias incompletas.');
                }

                const nombresCampos = {
                    estudiante: {
                        cedula_escolar: 'cedula_escolar',
                        nombre: 'nombre',
                        apellido: 'apellido',
                        fecha_nacimiento: 'fecha_nacimiento',
                        genero: 'genero',
                        lugar_nacimiento: 'lugar_nacimiento',
                        telefono: 'telefono_completo',
                        direccion: 'direccion',
                        escuela_anterior: 'escuela_anterior',
                        email: 'email',
                        tipo_vivienda: 'tipo_vivienda',
                        personas_conviven: 'personas_conviven',
                        documentos_presentados: 'documentos_presentados',
                        habilidades_especiales: 'habilidades_especiales',
                        enfermedades: 'enfermedades',
                        alergias: 'alergias',
                        medicamentos: 'medicamentos',
                        observaciones: 'observaciones'
                    },
                    representante: {
                        nombre: 'representante_nombre',
                        apellido: 'representante_apellido',
                        cedula: 'representante_cedula',
                        parentesco: 'representante_parentesco',
                        telefono: 'representante_telefono_completo',
                        telefono_alternativo: 'representante_telefono_alternativo_completo',
                        fecha_nacimiento: 'representante_fecha_nacimiento',
                        genero: 'representante_genero',
                        profesion: 'representante_profesion',
                        lugar_trabajo: 'representante_lugar_trabajo',
                        direccion: 'representante_direccion',
                        email: 'representante_email'
                    }
                };
                sugerenciasActuales = {};
                for (const [grupo, campos] of Object.entries(nombresCampos)) {
                    for (const [campo, nombreFormulario] of Object.entries(campos)) {
                        const valor = contenido.result[grupo][campo];
                        if (Array.isArray(valor) ? valor.length > 0 : valor) {
                            sugerenciasActuales[nombreFormulario] = valor;
                        }
                    }
                }

                const cantidad = Object.keys(sugerenciasActuales).length;
                salida.value = `${salida.value}${salida.value ? '\n\n' : ''}--- Gemini (${contenido.model}) ---\n${JSON.stringify(contenido.result, null, 2)}`;
                progreso.value = 100;
                botonAplicar.disabled = cantidad === 0;
                estado.textContent = cantidad
                    ? `Gemini terminó. Hay ${cantidad} sugerencia(s); compáralas y revísalas antes de aplicarlas.`
                    : 'Gemini no encontró datos utilizables. Completa los campos manualmente.';
            } catch (error) {
                estado.textContent = `No se pudieron evaluar las imágenes con Gemini: ${error.message}`;
            } finally {
                consentimientoGemini.checked = false;
                archivos.disabled = false;
                rotacion.disabled = false;
                consentimientoGemini.disabled = false;
                botonLeer.disabled = false;
                procesoActivo = false;
                panel.dataset.geminiRequestActive = 'false';
                actualizarBotonGemini();
            }
        });

        botonAplicar.addEventListener('click', () => {
            let aplicadas = 0;
            const representantes = {
                representante_nombre: sugerenciasActuales.representante_nombre,
                representante_apellido: sugerenciasActuales.representante_apellido,
                representante_fecha_nacimiento: sugerenciasActuales.representante_fecha_nacimiento,
                representante_genero: sugerenciasActuales.representante_genero,
                representante_lugar_nacimiento: sugerenciasActuales.representante_lugar_nacimiento,
                representante_direccion: sugerenciasActuales.representante_direccion
            };
            const sugerencias = Object.assign({}, sugerenciasActuales, representantes);
            delete sugerencias.representante_cedula_sugerida;
            delete sugerencias.representante_telefono;
            for (const [nombre, valor] of Object.entries(sugerencias)) {
                if (nombre === 'documentos_presentados' && Array.isArray(valor)) {
                    for (const documento of valor) {
                        const casilla = document.querySelector(
                            `input[name="documentos_presentados[]"][value="${documento}"]`
                        );
                        if (casilla && !casilla.checked) {
                            casilla.checked = true;
                            casilla.dispatchEvent(new Event('change', { bubbles: true }));
                            aplicadas += 1;
                        }
                    }
                    continue;
                }
                if (nombre === 'telefono_completo') {
                    aplicadas += aplicarTelefono('', valor, false);
                    continue;
                }
                if (nombre === 'representante_telefono_completo') {
                    aplicadas += aplicarTelefono('representante', valor, false);
                    continue;
                }
                if (nombre === 'representante_telefono_alternativo_completo') {
                    aplicadas += aplicarTelefono('representante', valor, true);
                    continue;
                }
                if (establecerValorSiVacio(nombre, valor)) {
                    aplicadas += 1;
                }
            }
            const cedulaRepresentante = sugerenciasActuales.representante_cedula_sugerida;
            const cedulaEstudiante = sugerenciasActuales.cedula_estudiante_sugerida;
            const detalles = [];
            if (cedulaRepresentante) {
                detalles.push(`cédula del representante detectada: ${cedulaRepresentante}`);
            }
            if (cedulaEstudiante) {
                detalles.push(`cédula del estudiante detectada: ${cedulaEstudiante}`);
            }
            const detalleCedulas = detalles.length
                ? ` Confirma la ${detalles.join(' y ')}.`
                : '';
            estado.textContent = `${aplicadas} campo(s) vacío(s) completado(s). Verifica los datos antes de guardar.${detalleCedulas}`;
            botonAplicar.disabled = true;
        });
    }

    window.registroOcr = { extraerSugerencias: extraerSugerencias };
    document.addEventListener('DOMContentLoaded', inicializar);
})();
