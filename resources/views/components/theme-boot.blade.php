{{--
    Tema, CSS yüklenmeden önce uygulanır. Aksi halde dark seçiliyken
    her sayfada önce beyaz boyanır. Cookie (sunucu) + localStorage (istemci).
--}}
<script>
(function () {
    var KEY = 'eticart-theme';
    var html = document.documentElement;
    var theme = html.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    try {
        var stored = localStorage.getItem(KEY);
        if (stored === 'dark' || stored === 'light') {
            theme = stored;
        }
    } catch (e) {}
    html.setAttribute('data-bs-theme', theme);
    html.style.colorScheme = theme;
    html.style.backgroundColor = theme === 'dark' ? '#0b1420' : '#f3f6f9';
    try {
        localStorage.setItem(KEY, theme);
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = KEY + '=' + theme + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
    } catch (e) {}
})();
</script>
<style>
    html, body { background-color: #f3f6f9; }
    html[data-bs-theme='dark'], html[data-bs-theme='dark'] body { background-color: #0b1420; }
</style>
