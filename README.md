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
pedido"**, arma un checkout de Recurrente a partir de los **totales reales de la orden**
(nunca de precios del navegador, así que el total no se puede manipular) y redirige al comprador.

Normalmente conserva el detalle de productos, impuestos, envío y cargos.
Cuando una orden de pago único incluye un cargo negativo, se envía una sola línea
con el importe neto de WooCommerce: por ejemplo, Q100 de producto menos Q10 de ajuste cobra
Q90. Se usa la misma representación si el redondeo de líneas difiere del total por centavos dentro
del margen de redondeo (medio centavo por línea más medio centavo por el total). El detalle original
se conserva en WooCommerce. Los cupones ya están incluidos en los totales de los productos y no se
restan otra vez. Las órdenes de total cero o negativo y las diferencias que exceden ese margen se
rechazan antes de crear un cliente o un checkout en Recurrente; una orden gratuita debe seguir el
flujo sin pago de WooCommerce. Esta conciliación no modifica las suscripciones.

El pago se marca por un webhook con firma Svix verificada o una consulta autenticada a Recurrente
que confirma el checkout y su intent. El regreso por `success_url` solo dispara esa consulta;
los parámetros del navegador nunca prueban que la orden está pagada. Un reembolso confirmado
en Recurrente se sincroniza por el monto exacto del cliente; cada reembolso se aplica una sola vez.

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

La creación del checkout, la validación/aplicación del webhook y la recuperación comparten un bloqueo por orden.
Se recargan sus propiedades y metadata después de adquirirlo; un intento nuevo no puede reemplazar
la identidad entre la validación y la confirmación. Si el bloqueo no está disponible, el webhook
responde 503 sin marcarse procesado y el inicio del pago permite reintentar sin llamadas a la API.
Después de adquirir el bloqueo se invalidan las cachés de post, metadatos y almacenamiento HPOS
antes de releer la orden. Así, una solicitud que esperó con un estado viejo no vuelve a abrir
una orden que el webhook ya pagó. Una orden ya pagada vuelve a su confirmación sin abrir otro checkout. El bloqueo cubre las
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

Los recordatorios con checkout distinto no recuperan automáticamente un padre que nunca se pagó.
Las renovaciones con prorrateos, descuentos o precios cambiados solo en Recurrente, o calendarios
separados cuyo cobro difiera del total recurrente de WooCommerce, requieren conciliación manual.

Antes de conciliar manualmente, compará la referencia del pago en Recurrente con la orden y
la suscripción en WooCommerce, el ambiente, el monto y la moneda. Si el resultado es incierto,
contactá a soporte con esos identificadores y el mensaje del log, sin llaves ni secretos.
No abras otro cobro solo porque el webhook no actualizó la orden.

## Recuperar un pago sin webhook aplicado

Esta recuperación requiere publicar e instalar la versión del plugin que la incluye;
las instalaciones anteriores conservan su comportamiento. Las pruebas locales CPT/HPOS
no sustituyen la aceptación en una tienda alojada ni una medición de la latencia real.

Si un comprador pagó Q100 pero WooCommerce sigue mostrando la orden pendiente, el plugin consulta
`GET /checkouts/{id}` y después `GET /intents/{id}` con la llave secreta del ambiente original.
Solo concilia si el checkout está pagado, el intent está confirmado y ambos conservan la misma
identidad, monto, moneda y metadata. Se aplican las mismas validaciones de orden, intento, ambiente
y precio dinámico del webhook. La confirmación vuelve a adquirir el bloqueo y recarga la orden
invalidando las cachés CPT y HPOS; un webhook simultáneo o un intento reemplazado no produce una
segunda confirmación. La referencia persistida del cobro también deduplica los eventos que lleguen después.

La consulta se puede iniciar de tres formas:

- Al regresar a la confirmación de WooCommerce con la clave correcta de la orden. Para órdenes de
  clientes registrados también se exige su sesión o un usuario con permiso para editar la orden.
- Desde **Acciones de la orden → Consultar pago en Recurrente**, con permiso de edición y el nonce
  del formulario de WooCommerce. Mientras consulta, bloquea un segundo envío y muestra
  **Consultando a Recurrente…**. El aviso final aparece una sola vez en esa orden para el
  usuario que hizo la consulta. Los resultados quedan como notas privadas atribuidas a ese
  usuario; una consulta demasiado reciente o una orden que ya dejó de estar pendiente no
  agrega una nota permanente.
- Cada cinco minutos, con Action Scheduler o WP-Cron como respaldo. Cada revisión busca hasta 20
  órdenes pendientes o en espera, de entre dos minutos y siete días de antigüedad, y programa una
  tarea por orden. La paginación avanza aunque una orden siga impaga. El retorno y la acción
  administrativa también permiten revisar órdenes más antiguas.

Las consultas por orden tienen un intervalo mínimo de un minuto, incluso si la API falla; el
retorno repetido no genera llamadas ilimitadas. El retorno espera la consulta antes de mostrar
la confirmación y los permisos de descarga. Cada GET tiene un timeout de diez segundos; ese
límite por llamada no es un presupuesto total del retorno ni de la espera por los bloqueos. Un
error o una identidad incompatible deja la orden pendiente para un intento posterior. No se crea
otro checkout, no se vuelve a cobrar ni se cambia una orden cancelada, fallida o reembolsada.
Las renovaciones de suscripciones siguen dependiendo de sus webhooks. Action Scheduler y WP-Cron
requieren ejecución de tareas en el sitio; en tiendas sin tráfico conviene configurar un cron real.

**Límite TEST:** los pagos de prueba simulados no crean un intent persistido consultable. Aunque
el checkout diga `paid`, el plugin deja esa orden pendiente si falta `latest_intent.id`; no inventa
una referencia ni deduplica contra un ID ficticio. Esos pagos siguen requiriendo el webhook TEST
con firma válida. Un intent consultable sin `legacy_id` tampoco se recupera: esa referencia es
necesaria para coincidir con la deduplicación histórica del plugin. No se hicieron pagos reales
para validar este cambio.

Las órdenes antiguas sin ambiente guardado se buscan con las llaves configuradas; solo un 404
permite probar la otra llave y la respuesta debe declarar el ambiente esperado. Los límites de
monto base de precio dinámico descritos arriba también se conservan en esta recuperación.

Contrato: [consulta de checkout](https://docs.recurrente.com/referencia-api/api-reference/checkouts/get-checkout)
y [consulta de intent](https://docs.recurrente.com/referencia-api/api-reference/intents/get-intent).
El patrón sigue la [guía de fulfillment de Stripe](https://docs.stripe.com/checkout/fulfillment):
webhook y retorno llaman una confirmación idempotente con datos del servidor. Recurrente requiere
dos consultas para recuperar el intent normalizado y su ID histórico; un checkout TEST pagado no
ofrece el mismo contrato de consulta que una Checkout Session de Stripe. La revisión programada
es un respaldo adicional y no reemplaza los webhooks de pagos y suscripciones.

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
npm install --ignore-scripts
npm test
```

Contrato y pruebas de la iniciación de reembolsos desde la orden (implementación en Draft):
[docs/refund-operations.md](docs/refund-operations.md).

**Reembolsar vía Recurrente** usa el formulario nativo de WooCommerce;
**Consultar reembolso de Recurrente** permite revisar la misma operación desde las acciones
del pedido. Solo una devolución confirmada con el importe y la moneda originales confirma
la contabilidad. Un resultado pendiente o desconocido conserva su identidad: no borres
el journal ni solicites otra devolución para desbloquearlo. Una solicitud interrumpida
puede conservar un registro provisional que necesita revisión.

Su publicación requiere desplegar y verificar primero el contrato Rails `require_new_refund`
de [#7311](https://github.com/recurrente/recurrente-app/pull/7311); no publicar este cambio de forma independiente.

## Licencia

[GPLv2 or later](LICENSE).
