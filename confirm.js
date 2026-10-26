// Підтвердження небезпечних дій (видалення) без inline-JavaScript:
// текст береться з атрибута data-confirm, тож назва товару ніколи не потрапляє в код скрипта.
// Це лише зручність для користувача, а не захист: справжній захист — POST + CSRF-токен на сервері.
document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
