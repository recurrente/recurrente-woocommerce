# Recurrente para WooCommerce

Acepta pagos con tarjeta de crédito y débito directamente en tu tienda WordPress con WooCommerce.
Instalación en minutos, sin código.

Plugin de pagos oficial de [Recurrente](https://recurrente.com): el comprador paga en el checkout
hospedado de Recurrente (tarjeta, transferencia, cuotas y suscripciones) y la orden se concilia
automáticamente en WooCommerce por webhook. Para comercios en Guatemala — monedas GTQ y USD.

> **Instalación y configuración:** seguí la guía oficial →
> [Cómo instalar el plugin de Recurrente en WooCommerce](https://ayuda.recurrente.com/es/articles/15984301-como-instalo-el-plugin-de-recurrente-en-woocommerce)

## Cómo funciona

WooCommerce es dueño de la orden de punta a punta; el plugin solo aporta la pasarela. Al **"Realizar
pedido"**, arma un checkout de Recurrente **itemizado** a partir de los **totales reales de la orden**
(nunca de precios del navegador, así que el total no se puede manipular) y redirige al comprador. El
pago se marca **únicamente por webhook**, con la firma Svix verificada; el regreso del comprador por
`success_url` nunca marca una orden como pagada. Un reembolso en Recurrente (total o parcial) reembolsa
y reabastece la orden por el monto exacto; cada reembolso se aplica una sola vez.

Soporta suscripciones (requiere **WooCommerce Subscriptions**): Recurrente es dueño del ciclo de cobro
y sincroniza los estados por webhook. Compatible con HPOS y con el checkout por bloques (el default
desde WooCommerce 8.3), además del checkout clásico.

## Pruebas y producción

El plugin mantiene separados los dos ambientes:

1. En **WooCommerce → Ajustes → Pagos → Recurrente**, guardá la llave secreta de pruebas y la de
   producción.
2. Al guardar, el plugin registra un webhook independiente con cada llave configurada y conserva por
   separado sus secretos de firma.
3. Activá **Modo de pruebas** para crear checkouts con la llave TEST. Desactivalo para usar la llave
   LIVE y procesar cobros reales.

Los pagos de prueba con `4242 4242 4242 4242` envían webhooks simulados con `live_mode: false` al
endpoint TEST. Para validar la conciliación completa, WordPress debe tener una URL pública que
Recurrente pueda alcanzar; un sitio disponible solo en `localhost` no recibirá el webhook.

## Conciliación e identidad del pago

La creación del checkout y la validación/aplicación del webhook comparten un bloqueo por orden.
Se recargan sus propiedades y metadata después de adquirirlo; un intento nuevo no puede reemplazar
la identidad entre la validación y la confirmación. Si el bloqueo no está disponible, el webhook
responde 503 sin marcarse procesado y el inicio del pago permite reintentar sin llamadas a la API.
Una orden ya pagada vuelve a su confirmación sin abrir otro checkout. El bloqueo cubre las
operaciones del plugin; no serializa cambios manuales de otros plugins o del administrador.

Cada intento nuevo conserva el checkout esperado, un identificador de intento en su metadata,
el ambiente de la llave utilizada, el monto base y la moneda. Un webhook firmado con la llave
de pruebas no paga una orden de producción, aunque incluya su ID y clave. Cambiar el modo en
los ajustes no cambia la identidad de órdenes existentes. Una firma que coincide con ambos
secretos se rechaza: cada ambiente debe conservar su propio secreto de webhook.

El primer pago requiere el checkout y el intento actuales, el monto y la moneda de la orden.
Con precio dinámico activado en el plugin, se guarda además el total devuelto **al crear** el
checkout: ese monto base debe coincidir con WooCommerce. El total cobrado por webhook puede
ser mayor por el recargo del comprador, pero nunca menor; debe coincidir con el total del
checkout firmado. La orden conserva su monto comercial y no se le resta la comisión del procesador.

Las renovaciones pueden usar otro checkout, incluidos los enlaces recordatorios. Requieren un
padre pagado, la referencia válida de la misma suscripción en `payment.paymentable`, el intento
original cuando existe y el monto recurrente actual de las suscripciones de WooCommerce, en
la misma moneda. Se compara el cobro recurrente, no el total histórico del checkout con su cuota
de inscripción. El ID remoto de suscripción se conserva al procesar el pago validado. Los dos
formatos de evento y sus reintentos siguen conciliándose una sola vez por cobro.

En instalaciones existentes se sigue usando el checkout guardado y el monto/moneda de WooCommerce,
sin exigir metadata nueva. Un padre que ya estaba pagado antes de actualizar y aún no tiene
ambiente guardado requiere una consulta de solo lectura de su checkout original con la llave del
ambiente que firmó el recordatorio; un fallo temporal responde 503 para que se reintente. No se
infiere el ambiente del ajuste actual. Una consulta 404 o una identidad incompatible no crea una renovación.

**Órdenes antiguas pendientes con recargo:** no existe una captura confiable de su monto base.
Si el cobro supera el monto de WooCommerce, queda sin conciliar automáticamente: verificá el
pago antes de actualizar la orden manualmente o generar un intento nuevo. Lo mismo aplica si
el precio dinámico se hereda de la cuenta, pero no está activado en el plugin. Los cambios de
monto o moneda de una orden después de crear su checkout requieren un intento nuevo. Diferencias
rechazadas se responden 200 y no producen efectos; una nueva entrega requiere un nuevo `svix-id`.

## Telemetría

El plugin reporta a Recurrente su propio ciclo de vida (`POST /api/plugin_events`, autenticado con
la llave secreta de la cuenta) para que el equipo vea qué tiendas están conectadas, con qué versión
y si algo falla:

- **Conexión** al guardar una llave válida y al activar el plugin; **desconexión** al borrar una
  llave o desactivarlo.
- **Latido diario** (`wp_schedule_event`) con la versión del plugin. Si desde el último latido hubo
  un error (llave rechazada, API caída, webhook con firma inválida) se reporta con su código y, al
  siguiente latido sin errores, la recuperación.
- Las versiones de WordPress, WooCommerce y PHP viajan en el `User-Agent` de cada reporte.

Solo se envía el dominio y el nombre de la tienda: nunca montos, órdenes ni datos de compradores.
Cada envío es no bloqueante y un fallo se registra en el log sin afectar el checkout.

## Desarrollo

Requisitos: WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

Enlazá el repo a la carpeta de plugins de un WordPress local y activá el plugin:

```shell
ln -s /ruta/a/recurrente-woocommerce <wordpress>/wp-content/plugins/recurrente-for-woocommerce
```

Tests:

```shell
php tests/run.php
```

## Licencia

[GPLv2 or later](LICENSE).

Los recordatorios con checkout distinto no recuperan automáticamente un padre que nunca se pagó.
Las renovaciones con prorrateos, descuentos o precios cambiados solo en Recurrente, o calendarios
separados cuyo cobro difiera del total recurrente de WooCommerce, requieren conciliación manual.
