# Revisión del proyecto y plan de continuación

## Objetivo funcional

El proyecto ya funciona como una base de plataforma PHP 8 + MySQL para conectar sucursales de Mercado Libre, sincronizar ventas, guardar órdenes, productos, envíos y cambios operativos, y consultar/actualizar stock o precio de publicaciones. El siguiente objetivo es convertir esta base en una herramienta operativa para atender ventas de Mercado Libre y registrar información relevante en bases de datos MySQL externas.

## Estado actual del proyecto

### Componentes principales

- `public/index.php`: controlador frontal y vistas del panel administrativo. Maneja dashboard, ventas, detalle de venta, stock, configuración, conexión OAuth y descarga de guías.
- `public/auth_callback.php`: callback limpio para OAuth de Mercado Libre.
- `src/MeliClient.php`: cliente HTTP para Mercado Libre, autorización, tokens, órdenes, publicaciones y guías.
- `src/MeliSyncService.php`: orquesta sincronización de ventas, enriquecimiento de envíos, descarga de etiquetas y actualización de stock/precio.
- `src/SalesRepository.php`: persistencia de ventas, productos, historial y consultas de dashboard/listados.
- `src/MeliBranchRepository.php`: configuración de sucursales y credenciales por sucursal.
- `src/MeliAccountRepository.php`: manejo de cuentas conectadas y refresco de tokens.
- `database/schema.sql`: esquema base local de configuración, sucursales, cuentas, ventas, productos, historial y bitácora de inventario.

### Flujo operativo actual

1. Se registra una sucursal con credenciales de Mercado Libre.
2. Se conecta la cuenta por OAuth.
3. Se sincronizan ventas recientes por vendedor.
4. Se guardan ventas y partidas en la base local.
5. Se visualizan ventas por filtros y estado interno.
6. Se cambia el estado operativo de la venta.
7. Se descarga la guía cuando existe `shipping_id`.
8. Se actualiza stock/precio y se registra la bitácora.

## Brechas para atender ventas de punta a punta

### Integración con bases MySQL externas

Actualmente la aplicación usa una sola conexión local configurada por variables `DB_*`. Para registrar ventas en bases externas conviene agregar una capa explícita de destinos externos en lugar de mezclar esa lógica dentro de los repositorios actuales.

Propuesta:

- Crear una tabla local `external_mysql_connections` para registrar destinos externos por sucursal o por tipo de integración.
- Crear una clase `ExternalDatabaseManager` que construya conexiones PDO hacia esos destinos.
- Crear una interfaz `ExternalSaleExporter` para transformar ventas locales a la estructura requerida por cada base externa.
- Registrar cada exportación en una tabla `external_sync_logs` con resultado, error, fecha y payload resumido.
- Ejecutar exportaciones de forma idempotente usando `meli_order_id` como llave natural.

### Normalización de estados

Los estados internos ya existen, pero faltan reglas automáticas para avanzar estados según Mercado Libre:

- `paid` o venta confirmada -> `new`.
- envío listo para imprimir -> `dispatch`.
- guía descargada o marcada como empacada -> `packed`.
- envío en tránsito -> `shipped`.
- entrega confirmada -> `delivered`.
- devolución/cancelación -> `returned` o `cancelled`.

### Seguridad y operación

- Evitar mostrar o guardar secretos en claro cuando sea posible.
- Agregar usuarios/roles antes de producción multiusuario.
- Mover acciones destructivas o críticas a POST con validación CSRF.
- Agregar logs técnicos separados de mensajes visibles al usuario.
- Configurar respaldos de base local y de copias de guías.

### Calidad técnica

- Separar vistas HTML de `public/index.php` para que el controlador frontal no siga creciendo.
- Agregar pruebas mínimas para repositorios y servicios usando una base de prueba.
- Agregar un comando CLI para sincronización programada por cron.
- Agregar paginación en el listado de ventas si el volumen crece.

## Siguiente incremento recomendado

El siguiente paso más útil es implementar la base de integración externa sin depender todavía de la estructura definitiva del cliente externo:

1. Crear migración para conexiones MySQL externas y bitácora de exportación.
2. Crear clase para abrir conexiones externas de forma segura.
3. Crear un exportador inicial idempotente que escriba ventas sincronizadas en una tabla destino configurable.
4. Agregar botón en detalle de venta y acción masiva para exportar ventas pendientes.
5. Registrar resultado por venta y mostrarlo en la interfaz.

## Preguntas pendientes antes de implementar exportación externa

- ¿Cada sucursal escribirá en una base externa distinta o todas compartirán una sola?
- ¿La base externa ya tiene tablas existentes o se puede crear un esquema nuevo?
- ¿Qué datos deben enviarse obligatoriamente: venta, comprador, partidas, pago, envío, guía, estatus interno o todos?
- ¿La exportación debe ser automática al sincronizar o manual después de validar la venta?
- ¿Qué llave debe usar la base externa para evitar duplicados: `meli_order_id`, `pack_id`, `shipping_id` u otra?
