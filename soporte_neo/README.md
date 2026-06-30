# Gestion de Soporte NEO

Sistema independiente para mesa de ayuda TI dentro del ecosistema NEO.

## Ruta local esperada

```text
http://localhost/soporte_neo/public
```

No requiere levantar un servidor aparte si la carpeta esta dentro del mismo `htdocs`
que ya sirve `gs_neo`, `ti_neo` y `neo`.

## Estilo

Usa el mismo patron visual de las apps NEO existentes:

- `app-shell`, `sidebar`, `side-nav`, `nav-item`
- `topbar`, `dashboard-hero`, `metrics-grid`
- `metric-card`, `panel-head`, `table-wrap`, `ghost-button`

## Integracion con Portal NEO

Ejecutar en SQL Server, base `GESTION_INF1`:

```text
soporte_neo/database/sqlserver/soporte_neo_schema.sql
soporte_neo/database/sqlserver/soporte_neo_seed.sql
```

Esos scripts:

- Crean el modelo operativo `sn_*` separado de `st_*`.
- Registra `soporte` en `neo_sistemas`.
- Crea grupos `soporte_admin`, `soporte_tecnico` y `soporte_consulta`.
- Crea politicas IAM para entrar, administrar, crear, asignar, resolver y ver reportes.
- Asocia las politicas a sus grupos.

El seed general `ti_neo/database/sqlserver/neo_seed.sql` tambien incluye el registro del sistema `soporte` para compatibilidad con el flujo actual del portal. El modelo global `ti_neo/database/sqlserver/core_iam_policy_model.sql` tambien incluye los grupos y politicas de soporte.
