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
`success_url` nunca marca una orden como pagada. Un reembolso en Recurrente reembolsa y reabastece la
orden.

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
