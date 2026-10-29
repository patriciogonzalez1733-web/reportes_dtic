// Campo de correo con selector de dominio: arma usuario@dominio en el campo oculto que se envía.
document.querySelectorAll('.correo-campo').forEach(function (campo) {
    const oculto = campo.querySelector('input[type=hidden]');
    const usuario = campo.querySelector('.correo-usuario');
    const dominio = campo.querySelector('.correo-dominio');
    const otro = campo.querySelector('.correo-otro');

    // Arma el correo completo y muestra u oculta el campo "otro dominio".
    function actualizar() {
        const esOtro = dominio.value === 'otro';
        otro.style.display = esOtro ? 'inline-block' : 'none';
        otro.required = esOtro && usuario.required;
        const parteDominio = esOtro ? otro.value.trim().replace(/^@+/, '') : dominio.value;
        const u = usuario.value.trim().toLowerCase();
        oculto.value = u && parteDominio ? u + '@' + parteDominio.toLowerCase() : '';
    }

    usuario.addEventListener('input', function () {
        if (usuario.value.includes('@')) {
            const [u, d] = usuario.value.split('@');
            usuario.value = u;
            const opcion = Array.from(dominio.options).find(function (o) { return o.value === d.toLowerCase(); });
            if (opcion) {
                dominio.value = opcion.value;
            } else {
                dominio.value = 'otro';
                otro.value = d;
            }
        }
        actualizar();
    });
    dominio.addEventListener('change', actualizar);
    otro.addEventListener('input', actualizar);
    actualizar();
});
