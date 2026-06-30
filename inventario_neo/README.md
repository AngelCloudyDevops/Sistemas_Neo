# Inventario NEO

Sistema PHP puro para gestionar datos institucionales de inventario sobre tablas `core_*`.

## Ruta local esperada

```text
http://localhost/inventario_neo/public
```

## Alcance inicial

- Personal: `core_personal`
- Equipos: `core_equipos`
- Impresoras: `core_impresoras`
- Consulta de asignacion actual de equipos contra `core_personal`

No crea tablas nuevas para personal, equipos o impresoras. El objetivo es administrar la data central migrada.

## Configuracion

Copiar:

```text
config/config.local.example.php
```

a:

```text
config/config.local.php
```

En desarrollo debe apuntar a `GESTION_INF1`. En produccion debe apuntar a `GESTION_INF`.

## Portal NEO

Ejecutar en SQL Server sobre `GESTION_INF1` primero:

```text
database/sqlserver/inventario_neo_seed.sql
```

El script registra el sistema `inventario`, grupos y politicas base.

## Estado

Primer corte funcional:

- login JWT
- dashboard
- listado/busqueda de personal, equipos e impresoras
- alta y edicion basica
- auditoria no bloqueante contra `neo_logs_acceso`
