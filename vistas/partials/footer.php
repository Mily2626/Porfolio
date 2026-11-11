<?php
/**
 * Layout - pie comun (vistas autenticadas)
 * Variable opcional: $scripts (html a inyectar antes de </body>)
 */
$raiz = raiz_web();
?>
</main>
<footer class="pie">
  <span>Parking Tech · Estacionamiento inteligente por reservas</span>
</footer>
<script src="<?= htmlspecialchars(asset('assets/js/app.js')) ?>"></script>
<?php if (!empty($scripts)) { echo $scripts; } ?>
</body>
</html>
