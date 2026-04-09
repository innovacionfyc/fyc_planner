</main><!-- /.admin-content -->

<script>
(function () {
    var btn   = document.getElementById('themeToggle');
    var icon  = document.getElementById('themeIcon');
    var label = document.getElementById('themeLabel');
    function apply(t) {
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('fyc-theme', t);
        if (icon)  icon.textContent  = t === 'dark' ? '🌙' : '☀️';
        if (label) label.textContent = t === 'dark' ? 'Oscuro' : 'Claro';
    }
    apply(localStorage.getItem('fyc-theme') || 'dark');
    if (btn) btn.addEventListener('click', function () {
        apply(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
})();
</script>
</body>
</html>
