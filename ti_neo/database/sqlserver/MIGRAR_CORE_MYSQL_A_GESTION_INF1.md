# Migrar tablas core_* desde MySQL a SQL Server

Origen:

```text
MySQL / XAMPP
Base: sibd_firmas
```

Destino de pruebas:

```text
SQL Server
Base: GESTION_INF1
```

No migrar primero a `GESTION_INF`. Validar todo en `GESTION_INF1`.

## Por que no se ejecuta desde PHP local

PHP/ODBC local esta fallando contra SQL Server con:

```text
Encryption not supported on the client
```

Navicat si conecta correctamente. Por eso, por ahora, la migracion de datos debe hacerse con **Navicat Data Transfer** o ejecutando scripts SQL en Navicat.

## Script generado desde el dump completo

El 2026-06-05 se exporto un dump completo desde Navicat:

```text
SIBD/backups/core_mysql_sibd_firmas_20260605.sql
```

Desde ese dump se genero un script SQL Server separado:

```text
ti_neo/database/sqlserver/core_mysql_to_sqlserver_gestion_inf1.sql
```

Ese script:

- usa `USE GESTION_INF1`;
- crea solo tablas `core_*` faltantes;
- excluye `core_roles`, `core_usuarios` y `core_especialidades`, porque ya existen en SQL Server;
- crea `core_logs_auditoria`, pero no migra sus registros historicos;
- no contiene `DROP TABLE`;
- no debe ejecutarse en `GESTION_INF` produccion.

Si Navicat Data Transfer no esta disponible, ejecutar este script en Navicat sobre `GESTION_INF1`.

Estado 2026-06-05: script ejecutado correctamente en `GESTION_INF1` desde Navicat.
La auditoria institucional quedo como tabla disponible para nuevos eventos, sin copiar
los logs historicos del MySQL.

## Tablas core_* encontradas en MySQL

Conteo revisado el 2026-06-05:

| Tabla | Filas |
|-------|------:|
| core_antivirus | 4 |
| core_areas | 413 |
| core_cargos | 102 |
| core_equipo_hardware | 393 |
| core_equipo_perifericos | 393 |
| core_equipo_software | 397 |
| core_equipos | 397 |
| core_especialidades | 30 |
| core_estados_activo | 16 |
| core_historial_equipos | 79 |
| core_impresora_personal | 1 |
| core_impresoras | 163 |
| core_logs_auditoria | 412 en MySQL / 0 migradas a SQL Server |
| core_marcas | 87 |
| core_modalidades | 6 |
| core_modelos | 342 |
| core_oficinas | 275 |
| core_personal | 1011 |
| core_personal_asignaciones | 1013 |
| core_roles | 3 |
| core_sistemas_operativos | 8 |
| core_tipos_equipo | 5 |
| core_tipos_impresora | 8 |
| core_unidades | 58 |
| core_usuarios | 10 |

## Orden recomendado de transferencia

Usar este orden para reducir errores por dependencias:

```text
core_roles
core_unidades
core_areas
core_oficinas
core_cargos
core_modalidades
core_personal
core_personal_asignaciones
core_usuarios
core_especialidades
core_estados_activo
core_tipos_equipo
core_marcas
core_modelos
core_sistemas_operativos
core_antivirus
core_equipos
core_equipo_hardware
core_equipo_software
core_equipo_perifericos
core_tipos_impresora
core_impresoras
core_impresora_personal
core_historial_equipos
core_logs_auditoria
```

## Forma recomendada en Navicat

1. Abrir **Data Transfer**.
2. Origen: MySQL `sibd_firmas`.
3. Destino: SQL Server `GESTION_INF1`.
4. Seleccionar las tablas `core_*`.
5. Si la tabla no existe en destino, dejar que Navicat cree estructura y datos.
6. Si la tabla ya existe en destino, revisar si conviene:
   - `Truncate destination table before loading`, o
   - solo insertar/actualizar si Navicat lo permite.
7. Ejecutar primero en `GESTION_INF1`.
8. Revisar conteos finales.

## Validacion despues de migrar

En Navicat, ejecutar sobre `GESTION_INF1`:

```sql
SELECT 'core_personal' AS tabla, COUNT(*) AS filas FROM dbo.core_personal
UNION ALL SELECT 'core_areas', COUNT(*) FROM dbo.core_areas
UNION ALL SELECT 'core_usuarios', COUNT(*) FROM dbo.core_usuarios
UNION ALL SELECT 'core_roles', COUNT(*) FROM dbo.core_roles
UNION ALL SELECT 'core_especialidades', COUNT(*) FROM dbo.core_especialidades;
```

Los conteos deben coincidir con MySQL o estar justificados si se filtraron registros.
