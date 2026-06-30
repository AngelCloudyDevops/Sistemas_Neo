# GESTION_INF como base unica

`ti_neo` debe operar contra SQL Server, pero separando ambientes:

- Local/desarrollo: `GESTION_INF_DEV`
- Servidor/produccion: `GESTION_INF`

MySQL `sibd_firmas` queda solo como origen historico si necesitas copiar datos antiguos.

## Operacion normal local
- Base: SQL Server `GESTION_INF_DEV`
- Host: `localhost` o tu instancia local, por ejemplo `localhost\SQLEXPRESS`
- Configuracion local: `config/config.local.php`
- Si `username` y `password` estan vacios, PHP intentara autenticacion de Windows.

## Operacion normal servidor
- Base: SQL Server `GESTION_INF`
- Configuracion servidor: `config/config.local.php` en el servidor
- Para empaquetar, crea `config/config.local.server.php`; el script lo copiara al ZIP como `config.local.php`.

## Instalacion limpia local en SQL Server
Ejecutar en Navicat conectado a tu SQL Server local:

1. `crear_gestion_inf_dev_local.sql`
2. `st_schema_dev.sql`
3. `st_seed_dev.sql`

Credencial inicial del sistema:
- Usuario: `admin@sibd.local`
- Clave: `Admin123*`

## Instalacion limpia en produccion
Ejecutar en Navicat sobre la base `GESTION_INF`:

1. `st_schema.sql`
2. `st_seed.sql`
3. Migraciones SQL Server importantes, si aplica.

Credencial inicial del sistema:
- Usuario: `admin@sibd.local`
- Clave: `Admin123*`

## Migraciones SQL Server importantes
Ejecutar en Navicat sobre `GESTION_INF` cuando corresponda:

1. `st_migration_20260527_specialties_catalog_cleanup.sql`
2. `st_migration_20260208_reset_print_defaults.sql`
3. `st_migration_20260208_emergencia_a_procedimiento.sql`

## Crear DEV desde produccion
Solo usar si el admin crea `GESTION_INF_DEV` en el servidor privado y quieres un DEV compartido en ese servidor. Para DEV local usa los scripts `_dev`.

Ejecutar en Navicat sobre SQL Server privado:

```sql
database/sqlserver/crear_gestion_inf_dev_desde_prod.sql
```

## Nota sobre PHP y SQL Server
El servidor web debe tener habilitados `sqlsrv` y `pdo_sqlsrv` en PHP.
Si aparece el error `Encryption not supported on the client`, revisar el driver ODBC de SQL Server instalado en Windows o la politica de cifrado del servidor SQL.

## Copia historica desde MySQL, solo si hace falta
1. Herramientas → **Transferencia de datos** / **Data Transfer**
2. Origen: MySQL `sibd_firmas`
3. Destino: SQL Server `GESTION_INF`
4. Tablas (en orden):
   - core_roles
   - core_usuarios
   - core_especialidades
   - st_configuracion
   - st_impresoras
   - st_rangos
   - st_lotes
   - st_tickets
   - st_auditoria
5. Opciones: **Truncar tabla destino** antes de copiar
6. Ejecutar

## Automatico desde PC, solo para copia historica
```bash
php ti_neo/scripts/migrate_mysql_to_gestion_inf.php
```
