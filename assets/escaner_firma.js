// Escáner de firma: toma una foto de una firma y deja solo los trazos sobre fondo blanco.
(function (global) {
    // Lado máximo (en píxeles) de la foto con la que se trabaja.
    const MAX_LADO = 900;

    // Pasa la imagen a escala de grises.
    function aGrises(data, w, h) {
        const gris = new Float32Array(w * h);
        for (let i = 0, j = 0; i < w * h; i++, j += 4) {
            gris[i] = 0.299 * data[j] + 0.587 * data[j + 1] + 0.114 * data[j + 2];
        }
        return gris;
    }

    // Suaviza la imagen promediando cada punto con sus vecinos (quita el grano de la foto).
    function desenfoqueCaja(src, w, h, radio) {
        const ancho = w + 1;
        const integral = new Float64Array(ancho * (h + 1));
        for (let y = 0; y < h; y++) {
            let fila = 0;
            for (let x = 0; x < w; x++) {
                fila += src[y * w + x];
                integral[(y + 1) * ancho + (x + 1)] = integral[y * ancho + (x + 1)] + fila;
            }
        }
        const salida = new Float32Array(w * h);
        for (let y = 0; y < h; y++) {
            const y0 = Math.max(0, y - radio), y1 = Math.min(h, y + radio + 1);
            for (let x = 0; x < w; x++) {
                const x0 = Math.max(0, x - radio), x1 = Math.min(w, x + radio + 1);
                const suma = integral[y1 * ancho + x1] - integral[y0 * ancho + x1] - integral[y1 * ancho + x0] + integral[y0 * ancho + x0];
                salida[y * w + x] = suma / ((x1 - x0) * (y1 - y0));
            }
        }
        return salida;
    }

    // Marca los puntos más oscuros que sus vecinos a ambos lados, que son las líneas de tinta.
    function detectarTrazos(suave, w, h) {
        const escalas = [2, 4, 7];
        const direcciones = [[1, 0], [0, 1], [1, 1], [1, -1]];
        const margen = 7;
        const salida = new Float32Array(w * h);
        for (let y = margen; y < h - margen; y++) {
            for (let x = margen; x < w - margen; x++) {
                const centro = suave[y * w + x];
                let mejor = 0;
                for (let e = 0; e < escalas.length; e++) {
                    const d = escalas[e];
                    for (let k = 0; k < direcciones.length; k++) {
                        const dx = direcciones[k][0] * d, dy = direcciones[k][1] * d;
                        const a = suave[(y - dy) * w + (x - dx)];
                        const b = suave[(y + dy) * w + (x + dx)];
                        const r = (a < b ? a : b) - centro;
                        if (r > mejor) mejor = r;
                    }
                }
                salida[y * w + x] = mejor;
            }
        }
        return salida;
    }

    // Procesa la foto una sola vez.
    function preparar(imagen) {
        const w = imagen.width, h = imagen.height;
        const gris = aGrises(imagen.data, w, h);
        const suave = desenfoqueCaja(gris, w, h, 1);
        const dif = detectarTrazos(suave, w, h);
        const orden = Float32Array.from(dif).sort();
        const p99 = orden[Math.floor(orden.length * 0.995)] || 1;
        return { w, h, dif, p99 };
    }

    // Elige qué es firma según la sensibilidad y descarta las manchas pequeñas.
    function extraer(prep, sensibilidad) {
        const { w, h, dif, p99 } = prep;
        const k = 0.6 - 0.48 * (sensibilidad / 100);
        const umbral = Math.max(8, p99 * k);
        const alfa = new Float32Array(w * h);
        const margenX = Math.round(w * 0.02), margenY = Math.round(h * 0.02);
        for (let y = margenY; y < h - margenY; y++) {
            for (let x = margenX; x < w - margenX; x++) {
                const i = y * w + x;
                const a = (dif[i] - umbral) / (umbral * 0.8);
                alfa[i] = a < 0 ? 0 : a > 1 ? 1 : a;
            }
        }
        const etiqueta = new Int32Array(w * h);
        const componentes = [];
        const pila = [];
        for (let i = 0; i < w * h; i++) {
            if (alfa[i] < 0.5 || etiqueta[i]) continue;
            const id = componentes.length + 1;
            const comp = { id, area: 0, x0: w, y0: h, x1: 0, y1: 0 };
            pila.push(i);
            etiqueta[i] = id;
            while (pila.length) {
                const p = pila.pop();
                const px = p % w, py = (p - px) / w;
                comp.area++;
                if (px < comp.x0) comp.x0 = px;
                if (px > comp.x1) comp.x1 = px;
                if (py < comp.y0) comp.y0 = py;
                if (py > comp.y1) comp.y1 = py;
                for (let dy = -1; dy <= 1; dy++) {
                    for (let dx = -1; dx <= 1; dx++) {
                        const nx = px + dx, ny = py + dy;
                        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                        const n = ny * w + nx;
                        if (alfa[n] >= 0.5 && !etiqueta[n]) {
                            etiqueta[n] = id;
                            pila.push(n);
                        }
                    }
                }
            }
            componentes.push(comp);
        }
        const mayor = componentes.reduce(function (m, c) { return c.area > m ? c.area : m; }, 0);
        if (!mayor) return null;
        const minimo = Math.max(25, mayor * 0.01);
        const validos = componentes.filter(function (c) { return c.area >= minimo; });
        if (!validos.length) return null;
        const ids = new Set(validos.map(function (c) { return c.id; }));
        const caja = validos.reduce(function (b, c) {
            return { x0: Math.min(b.x0, c.x0), y0: Math.min(b.y0, c.y0), x1: Math.max(b.x1, c.x1), y1: Math.max(b.y1, c.y1) };
        }, { x0: w, y0: h, x1: 0, y1: 0 });
        const zona = new Uint8Array(w * h);
        for (let i = 0; i < w * h; i++) {
            if (!etiqueta[i] || !ids.has(etiqueta[i])) continue;
            const px = i % w, py = (i - px) / w;
            for (let dy = -2; dy <= 2; dy++) {
                for (let dx = -2; dx <= 2; dx++) {
                    const nx = px + dx, ny = py + dy;
                    if (nx >= 0 && ny >= 0 && nx < w && ny < h) zona[ny * w + nx] = 1;
                }
            }
        }
        const limpio = new Float32Array(w * h);
        for (let i = 0; i < w * h; i++) {
            if (zona[i]) limpio[i] = alfa[i];
        }
        return { w, h, alfa: limpio, caja };
    }

    // Recorta y centra la firma en el recuadro final.
    function componer(res, ancho, alto) {
        const { w, h, alfa, caja } = res;
        const cw = caja.x1 - caja.x0 + 1, ch = caja.y1 - caja.y0 + 1;
        const escala = Math.min(ancho * 0.92 / cw, alto * 0.84 / ch, 4);
        const offX = (ancho - cw * escala) / 2, offY = (alto - ch * escala) / 2;
        const salida = new Uint8ClampedArray(ancho * alto * 4);
        for (let oy = 0; oy < alto; oy++) {
            for (let ox = 0; ox < ancho; ox++) {
                const sx = caja.x0 + (ox - offX) / escala, sy = caja.y0 + (oy - offY) / escala;
                if (sx < caja.x0 - 1 || sx > caja.x1 + 1 || sy < caja.y0 - 1 || sy > caja.y1 + 1) continue;
                const radio = Math.max(0, Math.round(0.5 / escala));
                let suma = 0, n = 0;
                for (let dy = -radio; dy <= radio; dy++) {
                    for (let dx = -radio; dx <= radio; dx++) {
                        const ix = Math.round(sx) + dx, iy = Math.round(sy) + dy;
                        if (ix < 0 || iy < 0 || ix >= w || iy >= h) continue;
                        suma += alfa[iy * w + ix];
                        n++;
                    }
                }
                const a = n ? Math.pow(suma / n, 0.75) : 0;
                const o = (oy * ancho + ox) * 4;
                salida[o + 3] = Math.round(a * 255);
            }
        }
        return salida;
    }

    // Se exponen las funciones para la parte visual y para las pruebas.
    global.EscanerFirma = { preparar, extraer, componer, MAX_LADO };
    if (typeof module !== 'undefined') module.exports = global.EscanerFirma;
})(typeof window !== 'undefined' ? window : globalThis);

// Parte visual: agrega el botón "Escanear firma" junto al recuadro de firma.
(function () {
    if (typeof document === 'undefined') return;
    const lienzo = document.getElementById('firma-canvas');
    if (!lienzo) return;
    const acciones = lienzo.parentElement.querySelector('.firma-acciones');
    if (!acciones) return;

    const boton = document.createElement('button');
    boton.type = 'button';
    boton.textContent = 'Escanear firma';
    const entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.accept = 'image/*';
    entrada.setAttribute('capture', 'environment');
    entrada.style.display = 'none';
    acciones.appendChild(boton);
    acciones.appendChild(entrada);

    const vista = document.createElement('div');
    vista.className = 'escaner-vista';
    vista.style.display = 'none';
    vista.innerHTML =
        '<p class="escaner-ayuda">Fotografía la firma sobre papel blanco, con buena luz y de frente.</p>' +
        '<canvas class="escaner-previa" width="' + lienzo.width + '" height="' + lienzo.height + '"></canvas>' +
        '<p class="escaner-aviso" style="display:none"></p>' +
        '<label>Sensibilidad <input type="range" min="0" max="100" value="50" class="escaner-sens"></label>' +
        '<div class="escaner-botones"><button type="button" class="escaner-usar">Usar esta firma</button> ' +
        '<button type="button" class="escaner-cancelar">Cancelar</button></div>';
    acciones.insertAdjacentElement('afterend', vista);

    const previa = vista.querySelector('.escaner-previa');
    const aviso = vista.querySelector('.escaner-aviso');
    const deslizador = vista.querySelector('.escaner-sens');
    let prep = null;

    // Genera la firma limpia con la sensibilidad actual.
    function firmaLimpia() {
        const res = window.EscanerFirma.extraer(prep, Number(deslizador.value));
        if (!res) return null;
        const rgba = window.EscanerFirma.componer(res, lienzo.width, lienzo.height);
        const c = document.createElement('canvas');
        c.width = lienzo.width;
        c.height = lienzo.height;
        c.getContext('2d').putImageData(new ImageData(rgba, lienzo.width, lienzo.height), 0, 0);
        return c;
    }

    // Dibuja la vista previa.
    function mostrar() {
        const ctx = previa.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, previa.width, previa.height);
        const c = firmaLimpia();
        aviso.style.display = c ? 'none' : 'block';
        aviso.textContent = c ? '' : 'No se encontró la firma. Prueba con más luz o sube la sensibilidad.';
        if (c) ctx.drawImage(c, 0, 0);
    }

    // Al pulsar el botón se abre la cámara o el selector de archivos.
    boton.addEventListener('click', function () { entrada.click(); });

    // Al elegir o tomar la foto.
    entrada.addEventListener('change', function () {
        const archivo = entrada.files[0];
        if (!archivo) return;
        const url = URL.createObjectURL(archivo);
        const img = new Image();
        img.onload = function () {
            const e = Math.min(1, window.EscanerFirma.MAX_LADO / Math.max(img.naturalWidth, img.naturalHeight));
            const t = document.createElement('canvas');
            t.width = Math.round(img.naturalWidth * e);
            t.height = Math.round(img.naturalHeight * e);
            const tc = t.getContext('2d');
            tc.drawImage(img, 0, 0, t.width, t.height);
            prep = window.EscanerFirma.preparar(tc.getImageData(0, 0, t.width, t.height));
            URL.revokeObjectURL(url);
            vista.style.display = 'block';
            mostrar();
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            alert('No se pudo leer la imagen. Prueba con otra foto.');
        };
        img.src = url;
        entrada.value = '';
    });

    // Al mover la sensibilidad se recalcula la vista previa.
    deslizador.addEventListener('input', function () { if (prep) mostrar(); });

    // Cancelar: cierra la vista previa.
    vista.querySelector('.escaner-cancelar').addEventListener('click', function () {
        vista.style.display = 'none';
        prep = null;
    });

    // Usar esta firma: la copia al recuadro de firma.
    vista.querySelector('.escaner-usar').addEventListener('click', function () {
        const c = prep ? firmaLimpia() : null;
        if (!c) {
            alert('Primero toma una foto en la que se vea la firma.');
            return;
        }
        const ctx = lienzo.getContext('2d');
        ctx.save();
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, lienzo.width, lienzo.height);
        ctx.restore();
        ctx.drawImage(c, 0, 0);
        if (typeof mostrarFirmaTab === 'function') mostrarFirmaTab('dibujar');
        vista.style.display = 'none';
        prep = null;
    });
})();
