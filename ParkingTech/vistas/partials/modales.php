<?php
/**
 * Layout - Modales reutilizables:
 *   - Simulacion de pago (realista)
 *   - Confirmacion de eliminacion de reserva
 *   - Confirmacion de eliminacion de vehiculo
 * Se incluyen antes del footer en las vistas que los necesitan.
 */
?>

<!-- ============================ PAGO ============================ -->
<div class="modal" id="modal-pago" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pago-titulo">
    <div class="modal-head">
      <h3 id="pago-titulo"><i class="bi bi-shield-lock-fill"></i> Pago seguro</h3>
      <button class="modal-x" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>

    <!-- Vista: formulario de pago -->
    <div class="modal-body pago-vista" data-vista="form">
      <div class="pago-total">
        <div>
          <span class="pago-total-label">Total a pagar</span>
          <strong id="pago-monto">$0.00</strong>
        </div>
        <span class="pago-seguro"><i class="bi bi-shield-check"></i> Conexion segura</span>
      </div>

      <div class="pago-minimo" id="pago-minimo" hidden>
        <i class="bi bi-info-circle"></i> Se aplica el monto minimo de 1 hora de estacionamiento.
      </div>

      <label>Numero de tarjeta
        <div class="campo-tarjeta">
          <input id="pago-num" inputmode="numeric" autocomplete="cc-number"
                 placeholder="0000 0000 0000 0000" maxlength="23" value="4242 4242 4242 4242">
          <span class="marca-tarjeta" id="pago-marca"></span>
        </div>
      </label>

      <div class="grid2">
        <label>Vencimiento
          <input id="pago-exp" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" maxlength="5" value="12/30">
        </label>
        <label>Codigo de seguridad
          <input id="pago-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123" maxlength="4" value="123">
        </label>
      </div>

      <label>Titular de la tarjeta
        <input id="pago-titular" autocomplete="cc-name" placeholder="Nombre y apellido" value="Usuario Demo">
      </label>

      <p class="pago-nota"><i class="bi bi-info-circle"></i> Entorno de demostracion: no se realiza ningun cobro real.</p>
      <div class="pago-error" id="pago-error" hidden></div>
    </div>

    <!-- Vista: procesando -->
    <div class="modal-body pago-vista" data-vista="proceso" hidden>
      <div class="pago-proceso">
        <span class="spinner lg"></span>
        <p class="pago-paso" id="pago-paso">Conectando con el banco...</p>
        <div class="pago-barra"><span id="pago-barra-fill"></span></div>
        <p class="pago-proceso-sub">No cierres esta ventana</p>
      </div>
    </div>

    <!-- Vista: aprobado -->
    <div class="modal-body pago-vista" data-vista="ok" hidden>
      <div class="pago-ok">
        <span class="pago-check"><i class="bi bi-check-lg"></i></span>
        <h4>Pago aprobado</h4>
        <p class="muted">Se acredito <strong id="pago-monto-ok">$0.00</strong></p>
        <dl class="pago-recibo">
          <div><dt>Comprobante</dt><dd id="pago-comprobante">-</dd></div>
          <div><dt>Tarjeta</dt><dd id="pago-tarjeta-ok">-</dd></div>
          <div><dt>Fecha</dt><dd id="pago-fecha-ok">-</dd></div>
        </dl>
      </div>
    </div>

    <div class="modal-foot">
      <button class="btn" type="button" data-close data-cancelar-pago>Cancelar</button>
      <button class="btn primario" type="button" id="pago-confirmar"><i class="bi bi-lock-fill"></i> Pagar</button>
      <button class="btn primario" type="button" id="pago-continuar" hidden>Continuar <i class="bi bi-arrow-right"></i></button>
    </div>
  </div>
</div>

<!-- ==================== ELIMINAR RESERVA ==================== -->
<div class="modal" id="modal-eliminar" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="eliminar-titulo">
    <div class="modal-head">
      <h3 id="eliminar-titulo"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Eliminar reserva</h3>
      <button class="modal-x" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body">
      <p>
        Vas a eliminar la reserva <strong id="eliminar-codigo">-</strong>.
        Esta accion no se puede deshacer.
      </p>
      <div class="alerta warning">
        <i class="bi bi-cash-stack"></i>
        El pago realizado <strong>no se devuelve</strong>.
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn peligro" type="button" id="eliminar-confirmar"><i class="bi bi-trash"></i> Si, eliminar</button>
    </div>
  </div>
</div>

<!-- ==================== ELIMINAR VEHICULO ==================== -->
<div class="modal" id="modal-vehiculo" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="veh-titulo">
    <div class="modal-head">
      <h3 id="veh-titulo"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Eliminar vehiculo</h3>
      <button class="modal-x" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body">
      <p>
        Vas a eliminar el vehiculo <strong id="veh-matricula">-</strong>.
        Tambien se borrara su historial de reservas.
      </p>
      <div class="alerta warning">
        <i class="bi bi-exclamation-circle"></i>
        No se puede eliminar un vehiculo con reservas activas.
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn peligro" type="button" id="veh-confirmar"><i class="bi bi-trash"></i> Si, eliminar</button>
    </div>
  </div>
</div>
