// Muestra un contador de caracteres bajo los campos que tienen límite.
document.querySelectorAll('input[maxlength], textarea[maxlength]').forEach(function(campo) {
    if (campo.hasAttribute('data-sin-contador')) return;
    const limite = parseInt(campo.getAttribute('maxlength'), 10);
    const contador = document.createElement('small');
    contador.className = 'contador-caracteres';
    campo.insertAdjacentElement('afterend', contador);

    // Actualiza el contador y lo pone en rojo cerca del límite.
    function actualizar() {
        const usados = campo.value.length;
        contador.textContent = usados + ' / ' + limite;
        contador.style.color = usados >= limite * 0.9 ? '#dc3545' : '#6c757d';
    }

    campo.addEventListener('input', actualizar);
    actualizar();
});