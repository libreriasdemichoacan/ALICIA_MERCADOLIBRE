# MercadoLibre Alicia

Aplicación PHP 8 + MySQL para conectar una cuenta de Mercado Libre, sincronizar ventas, controlar el flujo operativo de despacho y actualizar stock de publicaciones.

## Requisitos

- PHP 8.0 o superior con extensiones `pdo_mysql` y `curl`.
- MySQL 8 o MariaDB compatible.
- Una aplicación creada en el portal de desarrolladores de Mercado Libre con redirect URI configurada.

## Instalación rápida

1. Crear la base de datos e importar el esquema:

   ```bash
   mysql -u root -p -e "CREATE DATABASE mercadolibre_alicia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p mercadolibre_alicia < database/schema.sql
   ```

2. Copiar variables de entorno y completar credenciales:

   ```bash
   cp .env.example .env
   ```

3. Levantar el servidor local:

   ```bash
   php -S localhost:8000 -t public
   ```

4. En el panel de Mercado Libre Developers registra exactamente la misma Redirect URI configurada en la app. Para local debe ser `http://localhost:8000/auth_callback.php`; en producción, por ejemplo `https://bookitech.mx/alicia/mercadol/0temp/public/auth_callback.php`. No uses `?page=auth_callback` ni parámetros variables en la Redirect URI.

5. Entrar a `http://localhost:8000`, abrir **Configuración**, guardar credenciales si no se usan variables de entorno y conectar la cuenta desde **Conectar Mercado Libre**.


## Migraciones

Si ya habías importado una versión anterior del esquema y al resincronizar ventas se duplicaron productos en `sale_items`, ejecuta la migración correctiva:

```bash
mysql -u root -p mercadolibre_alicia < database/migrations/2026_06_04_fix_sale_items_duplicates.sql
```

La aplicación también reconstruye los productos de cada venta durante la sincronización para mantener `sale_items` idempotente.

Para habilitar sucursales en una instalación existente, ejecuta también:

```bash
mysql -u root -p mercadolibre_alicia < database/migrations/2026_06_04_add_meli_branches.sql
```

Para habilitar el registro de precio actualizado y permitir stock opcional en la bitácora, ejecuta:

```bash
mysql -u root -p mercadolibre_alicia < database/migrations/2026_06_04_add_price_and_label_storage.sql
```

Para habilitar la nueva sección **Sucursales** con conexiones MySQL remotas para funciones futuras, ejecuta:

```bash
mysql -u root -p mercadolibre_alicia < database/migrations/2026_07_03_add_remote_mysql_branches.sql
```

## Funcionalidades incluidas

- Sucursales configurables, cada una con credenciales OAuth independientes para Mercado Libre México (`auth.mercadolibre.com.mx` para `MLM`), parámetro `state`, callback limpio y refresco de token.
- Sincronización manual de ventas recientes por seller con paginación de 50 resultados por consulta (`limit`/`offset`).
- Registro de ventas, detalle de productos, pagos, envíos, guía/tracking y comprador.
- Filtros de ventas por rango de fechas, estatus y búsqueda rápida por datos visibles del listado.
- Resaltado de ventas nuevas vs. ventas ya existentes después de sincronizar.
- Control interno de estatus: venta nueva, en despacho, empacada, enviada, entregada, devuelta y cancelada.
- Actualización de stock disponible y precio de publicaciones por item y variación.
- Actualización masiva de stock/precio desde una o varias sucursales MySQL remotas consultando la tabla `libro` con columnas `MLM`, `cantidad` y `precio3`, reservando piezas antes de enviar el stock a Mercado Libre.
- Descarga de guía PDF desde el detalle de venta cuando Mercado Libre ya generó un `shipping_id` con etiqueta disponible; además se guarda una copia local en `storage/labels` con el número de venta.
- Interfaz moderna, responsive y sin dependencias externas obligatorias.

## Notas API Mercado Libre

La integración usa endpoints REST actuales de Mercado Libre para autorización, órdenes, publicaciones y envíos. La búsqueda de órdenes respeta la paginación estándar (`limit` y `offset`) porque Mercado Libre devuelve bloques de 50 resultados por defecto; la descarga de guías usa el recurso de etiquetas de envío cuando el estado logístico permite imprimirla. Mercado Libre exige que `redirect_uri` coincida exactamente con la URI registrada en la aplicación y recomienda usar `state` para validar la respuesta. Si aparece “Lo sentimos, la aplicación no puede conectarse a tu cuenta”, revisa que la Redirect URI no tenga parámetros variables, que el vendedor sea la cuenta principal y que la cuenta no tenga validaciones KYC o bloqueos pendientes. Dependiendo del país, permisos de la app y modalidad logística, algunos campos pueden venir vacíos o requerir scopes/permisos adicionales.
