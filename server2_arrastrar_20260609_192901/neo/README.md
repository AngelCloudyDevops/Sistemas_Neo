# Portal NEO en PHP puro

Portal centralizado del ecosistema NEO sin Laravel ni Composer.

## Objetivo

- Login contra `core_usuarios`.
- Listado de sistemas desde `neo_sistemas`.
- Permisos evaluados como IAM desde `core_politicas`.
- Auditoria basica en `neo_logs_acceso`.

## Modelo de permisos

El portal no decide acceso solo por `rol_id`.

La evaluacion correcta es:

1. Usuario inicia sesion.
2. Se recolectan politicas directas excepcionales del usuario.
3. Se recolectan politicas de sus grupos permanentes.
4. Se recolectan politicas de roles temporales activos en `core_usuario_roles`.
5. Si hay un `Deny` explicito para la accion/recurso, se deniega.
6. Si hay un `Allow` explicito, se permite.
7. Si no hay `Allow`, se deniega por defecto.

Regla operativa:

```text
GRUPOS = acceso permanente normal
ROLES  = acceso temporal, suplencia o elevacion
```

`core_usuarios.rol_id` queda como dato base/compatibilidad. El portal no debe
dar acceso permanente solo porque el rol se llame "Super Admin"; ese usuario
debe pertenecer al grupo permanente `super_admins`.

Formato conceptual:

```text
efecto:  Allow | Deny
accion:  sistema.entrar | sistema.admin | tickets.emitir | usuarios.crear | *
recurso: sistema:gs | sistema:tickets | sistema:* | *
```

Para que un sistema aparezca en el portal:

```text
Allow + sistema.entrar + sistema:<codigo>
```

Para acceso administrativo:

```text
Allow + sistema.admin + sistema:<codigo>
```

Un `Deny` sobre el mismo recurso gana siempre.

## Estructura

- `public/index.php`: pantalla principal.
- `api/v1/login.php`: autenticacion.
- `api/v1/portal.php`: dashboard de sistemas.
- `app/Core`: conexion, request, response, auth.
- `app/Models`: consultas de usuario y portal.
- `app/Services`: JWT y auditoria.

## Configuracion

Copiar:

```text
config/config.local.example.php
```

a:

```text
config/config.local.php
```

y completar los datos reales de SQL Server.

En desarrollo puede heredar la configuracion de `ti_neo/config/config.local.php`.

## URL local esperada

```text
http://localhost/neo/public
```

## Produccion

Subir la carpeta `neo` a `htdocs` del servidor web y ajustar `config/config.local.php` con:

- `base_url`
- host SQL Server
- base `GESTION_INF`
- usuario/clave

No subir credenciales reales al repositorio.

## Scripts relacionados

El modelo IAM se define en:

```text
ti_neo/database/sqlserver/core_iam_policy_model.sql
```

`neo_permisos_sistema` queda solo como compatibilidad temporal mientras se migra a politicas.
