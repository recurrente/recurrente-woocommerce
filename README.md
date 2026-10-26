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

## Cambios de estado de suscripciones

Para suscripciones nuevas con un vínculo remoto verificado por el primer `intent.succeeded`,
los cambios de estado de Woo se envían mediante Action Scheduler (WooCommerce → Estado →
Acciones programadas). La nota de la suscripción distingue el cambio pendiente, confirmado o
sin confirmar. **El estado local por sí solo no confirma que Recurrente haya dejado de cobrar.**

- `on-hold` pausa Recurrente; volver de `on-hold` a `active` reanuda una suscripción remota pausada.
  Reanudar después del período pagado puede intentar un cobro e iniciar un período nuevo.
- `pending-cancel` y `cancelled` cancelan los cobros remotos inmediatamente, sin reembolso.
  Woo conserva `pending-cancel` hasta terminar el plazo prepagado, incluso al recibir la
  confirmación remota. Recurrente no ofrece cancelación al final del período en esta API.
  **Una cancelación remota es irreversible en esta integración**: volver a `active` no recrea
  la suscripción ni restaura los cobros; se necesita una suscripción nueva.
- Los errores de transporte, HTTP 408, 429 y 5xx se reintentan con espera creciente (hasta una hora).
  La reanudación usa una clave de idempotencia estable y deja de reintentarse a las 23 horas;
  HTTP 409, otros errores 4xx o datos faltantes dejan la acción fallida y requieren revisión manual en Recurrente.
- Se usa la llave del ambiente guardado con el pago, sin cambiarla al alternar el modo TEST/LIVE.
  No se adivinan vínculos para suscripciones antiguas o checkouts con varias suscripciones Woo:
  en esos casos administrá la suscripción remota directamente en Recurrente.

Contrato: `GET /api/subscriptions/{id}` consulta el estado, `DELETE` cancela y `PUT` con
`{"act":"pause"}` / `{"act":"unpause"}` pausa/reanuda. No se envían opciones de cancelación,
prorrateo ni reembolso inventadas. A diferencia de [Stripe](https://docs.stripe.com/billing/subscriptions/cancel),
no hay `cancel_at_period_end`; la pausa de Recurrente cambia el estado remoto, mientras que
[pausar la cobranza de Stripe](https://docs.stripe.com/billing/subscriptions/pause-payment)
mantiene el estado y sigue generando facturas.

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
