# Despliegue del módulo de adjuntos — F&C Planner

Procedimiento para desplegar el módulo de adjuntos en producción (Plesk/Linux).

**Documentos relacionados**
- [ATTACHMENTS.md](ATTACHMENTS.md) — arquitectura y mantenimiento
- [BACKUP_RESTORE.md](BACKUP_RESTORE.md) — respaldo y restauración

> **Estado:** el módulo **todavía no está desplegado en producción**. Este
> documento es el plan a seguir, no el registro de algo ya hecho.
>
> Los **límites de subida ya están medidos** (§7) y el código ajustado a
> ellos. Lo que siga marcado **⚠️ sin verificar** debe comprobarse en el
> servidor antes de decidir.

---

## 1. Objetivo y alcance

Poner en producción el módulo de adjuntos: dos migraciones de base de datos,
código nuevo, una carpeta de almacenamiento con permisos correctos y dos
tareas programadas.

**Entra:** migraciones, código, `storage/attachments`, permisos, cron, pruebas
de humo, rollback.

**No entra:** cambios de infraestructura del servidor. **No hace falta subir
los límites de PHP ni de Nginx**: el módulo se ajustó a los que ya hay (§7).
Tampoco entra migrar datos existentes —no hay adjuntos previos que migrar.

---

## 2. Preflight

Todo lo de esta sección es **solo lectura**. No cambies nada mientras lo mides.

### 2.1 Código

```bash
git rev-parse HEAD && git status --porcelain
```

El árbol debe estar limpio y el commit debe ser el que se pretende desplegar.

### 2.2 Entorno

```bash
php -v && php -m | grep -Ei "fileinfo|gd|mysqli|zip|zlib"
```

| Requisito | Por qué | Mínimo |
|---|---|---|
| PHP | El código usa `match`, tipos y `str_contains` | **8.0+** |
| `fileinfo` | Detectar el MIME real. **Sin esto el módulo no arranca** | Obligatorio |
| `gd` | `getimagesize()` en imágenes | Obligatorio |
| `mysqli` | Acceso a la base | Obligatorio |
| `zip` / `zlib` | Script de respaldo | Recomendado |

### 2.3 Base de datos

```sql
SELECT VERSION(), @@character_set_database, @@collation_database, @@session.time_zone;
```

Se espera **MariaDB 10.6.23**, `utf8mb4` y `utf8mb4_unicode_ci` —confirmado
en producción—. Solo hay que verificar que sigue igual.

**Zona horaria:** ya está resuelta en producción — PHP en `America/Bogota` y
MariaDB en `-05:00`, con diferencia confirmada de 0 segundos. No hay que tocar
nada; solo confirmar que sigue así.

### 2.4 Límites de subida ✅ ya medidos — solo confirmar

```bash
php -i | grep -Ei "upload_max_filesize|post_max_size|max_file_uploads|memory_limit|max_execution_time"
```

Y el límite de Nginx (Plesk suele ponerlo delante de Apache):

```bash
grep -ri "client_max_body_size" /etc/nginx/ /var/www/vhosts/system/ 2>/dev/null
```

Se espera `upload_max_filesize=16M` y `post_max_size=16M` (§7). **No los
cambies**: el módulo está ajustado a ellos. Solo confirma que siguen así.

### 2.5 Disco y permisos

```bash
df -h . && du -sh . 2>/dev/null
```

Comprueba que hay espacio para los adjuntos previstos **más** los respaldos.

### 2.6 Cron

```bash
crontab -l
```

Ver qué hay ya programado, para no duplicar ni pisar horarios.

---

## 3. Migraciones, en este orden exacto

```
1) database/migrations/2026-07-29-create-task-attachments.sql
2) database/migrations/2026-07-29-add-external-links-to-task-attachments.sql
```

**El orden no es negociable.** La segunda modifica la tabla que crea la
primera: al revés falla.

- **(1)** crea `task_attachments` con las claves foráneas a `tasks` y `users`.
- **(2)** amplía el `enum` de `kind` con `link` y `embed`, permite `NULL` en
  `stored_path`, `original_name`, `mime` y `size_bytes`, y añade `external_url`,
  `provider` y el índice `idx_task_attachments_provider`.

---

## 4. Antes de migrar

1. **Respaldo completo** — ver [BACKUP_RESTORE.md](BACKUP_RESTORE.md). Sin esto
   no se sigue. Es el único camino de vuelta.
2. **Versión del motor** y charset (§2.3).
3. **¿Existe ya la tabla?**

```sql
SHOW TABLES LIKE 'task_attachments';
```

Si no existe: ejecuta (1) y (2). Si ya existe: **solo** (2), y comprueba antes
que no se aplicó ya:

```sql
SHOW COLUMNS FROM task_attachments LIKE 'external_url';
```

4. **✅ Ya verificadas en MariaDB 10.6.23.** Se probaron en producción con
   tablas aisladas `f7_*`, comprobando ALTER, JSON, múltiples NULL en el
   índice UNIQUE, `ON DELETE CASCADE`, `ON DELETE SET NULL`, InnoDB,
   `utf8mb4_unicode_ci` y `CHECK TABLE OK`. Las tablas de prueba se
   eliminaron (`TABLAS_F7_RESTANTES=0`).

   Si aun así quieres repetirlo antes de tocar la base real:

```bash
mysql -u <usuario> -p -e "CREATE DATABASE fyc_migra_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
mysql -u <usuario> -p fyc_migra_test < database/migrations/2026-07-29-create-task-attachments.sql
```

Las claves foráneas necesitan que existan `tasks` y `users`, así que carga
primero la estructura desde el dump del respaldo. Si las dos migraciones pasan
limpias, borra la base de pruebas y sigue. **Si falla, para y reporta.**

---

## 5. Procedimiento de despliegue

**Ventana recomendada:** fuera de horario. Ninguna migración es destructiva,
pero la (2) reescribe la tabla.

```
 1. Preparar el release en una carpeta temporal (no sobre el sitio vivo)
 2. Lint PHP de todo el release
 3. Verificar la sintaxis de los JS
 4. RESPALDO COMPLETO (base + storage) y verificar sus hashes
 5. Migración (1) y luego (2)
 6. Verificar la estructura resultante
 7. Crear storage/attachments (con .gitkeep y .htaccess)
 8. Aplicar permisos
 9. Desplegar los archivos
10. Verificar hashes de lo desplegado
11. Comprobar el versionado de assets
12. Pruebas de humo (§10)
13. Limpiar los datos QA de las pruebas
```

### Paso 2 — Lint

```bash
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors"
```

Sin salida = todo correcto.

### Paso 6 — Verificar la estructura

```sql
SHOW CREATE TABLE task_attachments;
```

Comprueba: el `enum` con los cinco valores, `stored_path` NULL y **UNIQUE**,
`external_url` de 2048, las dos claves foráneas con `ON DELETE CASCADE` y
`ON DELETE SET NULL`, y los cuatro índices.

### Paso 7 — Carpeta de almacenamiento

```bash
mkdir -p storage/attachments && touch storage/attachments/.gitkeep
```

El `.htaccess` viaja en el repositorio. **Debe estar**: es defensa en
profundidad por si algún día la carpeta acaba dentro del árbol público.

### Paso 11 — Versionado de assets

```bash
curl -s https://<dominio>/login.php | grep -o 'theme.css?v=[0-9]*'
```

Debe aparecer un número. Si sale sin `?v=`, la plantilla no está usando
`asset_url()`.

---

## 6. Permisos sugeridos

| Elemento | Permiso | Propietario |
|---|:--:|---|
| Directorios del proyecto | `750` | usuario del sitio : grupo del servidor web |
| Archivos PHP | `640` | usuario del sitio : grupo del servidor web |
| `storage/` | `750` | usuario del sitio : grupo del servidor web |
| `storage/attachments/` | `750` | **debe poder escribir el servidor web** |
| `.htaccess` | `640` | |

```bash
chown -R <usuario_sitio>:<grupo_web> storage && chmod -R 750 storage
```

> ⛔ **Nunca `chmod 777`.** Deja el directorio escribible por cualquier usuario
> del servidor. En un alojamiento compartido eso es una puerta abierta. Si algo
> no escribe, el problema es el **propietario**, no los permisos: revisa con qué
> usuario corre PHP (`ps aux | grep php-fpm`) y ajusta `chown`.

En Plesk, el usuario suele ser el del suscriptor y el grupo `psacln`.

---

## 7. Límites de subida ✅ medidos

### Lo que hay en producción

| Valor | Medido | Qué limita |
|---|:--:|---|
| `upload_max_filesize` | **16M** | Tamaño de **un** archivo |
| `post_max_size` | **16M** | Tamaño **total** del envío |
| `max_file_uploads` | **20** | Número de archivos por envío |
| `memory_limit` | **128M** | |
| PHP web | **8.3.33** | |

**No hay permisos de root ni sudo para modificarlos, y esta versión no los
necesita.**

### Lo que usa el módulo

| Regla | Valor |
|---|:--:|
| Máximo por archivo | **14 MB** |
| **Máximo por envío (suma)** | **14 MB** |
| Máximo de archivos | **5** |

**Por qué 14 y no 16.** `post_max_size` se aplica al **cuerpo completo** de la
petición, no a cada archivo. Ese cuerpo incluye las cabeceras multipart, los
campos `csrf` y `task_id` y un separador por archivo. Los 2 MB de diferencia
son ese margen, y crece con el número de archivos.

### Comprobado con envíos reales

| Envío | Resultado |
|---|---|
| 10 MB | ✅ funciona |
| 15 MB | ✅ funciona |
| **16 MB exactos** | ❌ PHP ejecuta pero **descarta el cuerpo** |
| 20 MB · 50 MB | ❌ cuerpo descartado |

De ahí el margen: a 16 MB exactos **ya falla**, porque el envoltorio empuja el
cuerpo por encima del límite.

### Qué NO hace falta

- ❌ **No** hay que pedir al hosting que amplíe `post_max_size`.
- ❌ **No** hacen falta subidas por fragmentos (*chunked uploads*).
- ❌ **No** hay que tocar Nginx, Apache ni Plesk.

**El módulo se adapta al servidor, no al revés.**

### Para lo que no cabe

| Contenido | Vía |
|---|---|
| Vídeos grandes | **YouTube o Vimeo** — se incrustan, no se suben |
| Cualquier otro archivo grande | **Enlace externo** |

Ninguna de las dos pasa por el límite de subida: son una fila en la base.

<details>
<summary><strong>Escenario futuro opcional — si algún día se amplía el hosting</strong></summary>

> ⚠️ **Nada de esto es requisito de la versión actual.** Se documenta solo por
> si en el futuro se contrata un plan con más margen y se quiere subir el
> límite del código.

Si se subieran `ATTACH_MAX_FILE_BYTES` y `ATTACH_MAX_REQUEST_BYTES`, el
servidor tendría que acompañar. La relación a respetar es:

```
client_max_body_size  ≥  post_max_size  ≥  ATTACH_MAX_REQUEST_BYTES + margen
```

Ejemplo: para permitir 5 archivos de 50 MB el envío pesaría 250 MB y haría
falta `post_max_size` de unos `275M`, más `client_max_body_size` igual o mayor
en Nginx.

**Cuidado con la asimetría:** poner `upload_max_filesize` alto y
`post_max_size` justo por encima permite **un** archivo grande pero falla al
subir dos, con un error que el usuario no entiende. Si no se puede llegar al
total necesario, lo honesto es **bajar el límite del código**, no dejar una
combinación que funciona a veces.

</details>

> **Si Nginx corta, PHP ni se entera.** Por eso el módulo detecta el cuerpo
> descartado y responde **413 con un mensaje de tamaño** —nunca un error de
> permisos— antes de validar el CSRF. Ver §12.

---

## 8. Tareas programadas

### `cron/purge_trash.php`

Elimina definitivamente los tableros con más de 30 días en la papelera, **y sus
adjuntos**.

```bash
0 3 * * *  php /var/www/vhosts/<dominio>/<carpeta>/cron/purge_trash.php >> /var/log/fyc_purge_trash.log 2>&1
```

Salidas: `0` correcto · `1` algún archivo no se pudo borrar (lo recogerá el cron
de huérfanos).

### `cron/purge_orphan_attachments.php`

Borra archivos que ya no tienen ficha. Es la red de seguridad, no el mecanismo
principal: el borrado normal ya limpia lo suyo.

**Estrénalo siempre en simulacro:**

```bash
php /var/www/vhosts/<dominio>/<carpeta>/cron/purge_orphan_attachments.php --dry-run --verbose
```

Déjalo así **una o dos semanas** y revisa el log. Si solo aparecen huérfanos
esperables, quita `--dry-run`:

```bash
0 4 * * 0  php /var/www/vhosts/<dominio>/<carpeta>/cron/purge_orphan_attachments.php >> /var/log/fyc_purge_orphans.log 2>&1
```

Opciones: `--dry-run` · `--grace-hours=N` (24 por defecto) · `--verbose`.

Salidas: `0` correcto · `1` errores al borrar · `2` argumento inválido ·
`3` **aborto de seguridad** (no pudo leer el inventario de la base; no tocó
nada).

> El código `3` es importante: significa que el script **prefirió no hacer nada**
> antes que arriesgarse. Si lo ves, revisa la conexión a la base **antes** de
> volver a lanzarlo.

---

## 9. Respaldo

Procedimiento completo en [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

Tres reglas para este despliegue:

1. **Genera el respaldo justo antes de migrar**, no la noche anterior.
2. **Verifica los hashes** antes de continuar (`sha256sum -c SHA256SUMS.txt`).
3. **Debe incluir `storage/`**. Un `mysqldump` solo ya no restaura el módulo.

En este despliegue concreto `storage/attachments` estará vacío —es la primera
vez que se instala—, pero el respaldo de la **base** es imprescindible: es lo
que permite deshacer las migraciones.

---

## 10. Pruebas de humo en producción

Con datos claramente marcados como QA, y limpiándolos al terminar.

| # | Prueba | Esperado |
|:--:|---|---|
| 1 | Entrar en el sistema | Sesión iniciada |
| 2 | Abrir un tablero | Kanban visible, sin errores en consola |
| 3 | Abrir una tarea | Cajón con la sección de adjuntos |
| 4 | Subir una imagen **pequeña** (~100 KB) | Aparece la miniatura |
| 5 | Abrir el visor | Imagen ampliada, se cierra con `Escape` |
| 6 | Descargar | El archivo llega íntegro |
| 7 | Subir un audio corto | Reproductor visible |
| 8 | Añadir una URL | Tarjeta con el dominio |
| 9 | Añadir un YouTube | Vídeo incrustado |
| 10 | Eliminar un adjunto | Desaparece; el archivo ya no está en disco |
| 11 | Entrar como **lector** | Ve los adjuntos, **sin** botones de subir ni eliminar |
| 12 | Revisar logs | Sin errores nuevos |
| 13 | Limpiar QA | Sin tableros, tareas ni archivos de prueba |

> ℹ️ **El techo es 14 MB por envío** (§7), ya medido. Comprueba también el caso
> que más confunde: **dos archivos de 8 MB**, que individualmente parecen
> pequeños pero juntos se pasan. Debe rechazarse el conjunto entero con un
> mensaje de tamaño, nunca de permisos.

Comprobación final de que no queda nada:

```bash
php cron/purge_orphan_attachments.php --dry-run --verbose
```

### Si algo falla durante las pruebas

La tabla de **[resolución de problemas de ATTACHMENTS.md §14](ATTACHMENTS.md)**
cubre los errores que se ven en un despliegue: **413** (el archivo supera el
límite del servidor, §7), **422** (el contenido no corresponde a la extensión),
**403** (sin permiso sobre el tablero o sesión caducada) y **416** (rango HTTP
imposible), además de audio o vídeo que no se reproducen, iframes vacíos y
estilos antiguos en caché.

---

## 11. Rollback

Prepáralo **antes** de desplegar. No lo ejecutes salvo fallo real.

```
1. Restaurar los archivos anteriores (release previo)
2. Restaurar la base desde el respaldo
3. Restaurar storage/ si se hubiera tocado
4. Retirar las entradas de cron nuevas
5. Borrar la carpeta del release temporal
6. Validar: entrar, abrir un tablero, abrir una tarea
```

### Deshacer solo las migraciones

Si el código ya volvió atrás y solo sobra la tabla:

```sql
DROP TABLE IF EXISTS task_attachments;
```

> ⚠️ Esto **borra las fichas de todos los adjuntos**. Solo tiene sentido si el
> despliegue se aborta el mismo día y no hay adjuntos que conservar. Si los hay,
> restaura la base desde el respaldo en lugar de esto.

### Retirar el cron

```bash
crontab -l | grep -v "purge_orphan_attachments" | crontab -
```

### Validación final del rollback

Entrar, abrir un tablero, abrir una tarea y comprobar que **el sistema funciona
como antes del despliegue**. Si la sección de adjuntos ya no aparece y todo lo
demás va bien, el rollback está completo.

---

## 12. Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| ~~Migración no probada en MariaDB 10.6~~ | — | ✅ **Resuelto**: verificada en MariaDB 10.6.23 con tablas aisladas `f7_*` (§4) |
| ~~Límites de subida desconocidos~~ | — | ✅ **Resuelto**: medidos (16M/16M) y el código ajustado a 14 MB (§7) |
| Caché del navegador | Estilos viejos tras desplegar | Resuelto por `asset_url()`; verificar en el paso 11 |
| Permisos incorrectos | Los adjuntos aparecen rotos aunque estén | `chown`/`chmod` del §6; **nunca 777** |
| Espacio en disco | Subidas fallan en silencio | Vigilar `df -h`; los adjuntos crecen sin límite superior |
| **Cron de huérfanos no probado aquí** | Borrar algo que no debía | Estrenarlo con `--dry-run` (§8) |
| Respaldo incompleto | No se puede volver atrás | Verificar hashes **antes** de migrar (§9) |
| Códecs de vídeo | El usuario sube un MP4 que no se reproduce | Ya hay aviso con descarga; documentado en [ATTACHMENTS.md §8](ATTACHMENTS.md) |

---

## 13. Paquete de despliegue

**No se despliega copiando el repositorio.** Hay archivos que son correctos
aquí y destructivos allí.

```bash
php scripts/build_release.php --commit=HEAD --label=produccion
```

El paquete sale en `_releases/` (ignorado por git), acompañado de su
`.sha256`.

### Lo que NO viaja, y por qué

| Excluido | Motivo |
|---|---|
| **`config/mail.php`** | Apunta al captador de correo local (puerto 1025). Desplegarlo **rompería el correo de producción en silencio** |
| `config/db.php` | Credenciales de base; cada entorno conserva el suyo |
| `.env` y variantes | Secretos |
| `.git/` | Historial completo |
| `tests/`, `tools/`, `db/` | No hacen falta en producción |
| Logs, respaldos, paquetes previos | Ruido y datos sensibles |
| `storage/attachments/AAAA/MM/` | **Adjuntos del entorno local** |

**Producción conserva sus propios `config/mail.php` y `config/db.php`.** El
paquete lleva `config/mail.example.php` y `config/db.example.php` como
plantillas, por si hay que crearlos desde cero.

### Lo que sí viaja

Código del módulo, las dos migraciones, la documentación, los cron, los
scripts de operación y **el esqueleto de `storage/attachments`** —solo
`.gitkeep` y `.htaccess`— para que la carpeta exista con su protección desde
el primer momento.

### Está probado, no prometido

`tests/attachments_release_smoke.php` **genera un paquete, lo abre y comprueba
su contenido real**: que `config/mail.php` no está, que no viajan secretos, y
que sí están las migraciones y los endpoints. El propio generador aborta si
falta un archivo obligatorio o si se cuela uno excluido.

---

## 14. Estrategia de `storage/attachments`

> Estos comandos se ejecutan **en el servidor, durante el despliegue**. No los
> ejecutes desde el entorno local.

**El orden importa:** primero el código, después la carpeta.

```bash
mkdir -p storage/attachments
```

```bash
chown -R admin_root:psacln storage && find storage -type d -exec chmod 0750 {} \; && find storage -type f -exec chmod 0640 {} \;
```

| Elemento | Valor |
|---|---|
| Propietario : grupo | `admin_root:psacln` |
| Directorios | `0750` |
| Archivos | `0640` |

### Reglas permanentes

- ❌ **Nunca copiar adjuntos del entorno local.** Son datos de desarrollo.
- ❌ **Nunca borrar los adjuntos existentes** en despliegues posteriores. La
  carpeta se conserva entre versiones; el código se reemplaza, los datos no.
- ✅ **`storage/attachments` va siempre en el respaldo** — ver
  [BACKUP_RESTORE.md](BACKUP_RESTORE.md).
- ℹ️ **Los enlaces y embeds no crean archivos.** Un tablero lleno de vídeos de
  YouTube puede ocupar cero bytes aquí y aun así necesitar la base de datos.

Si los adjuntos aparecen rotos tras desplegar, el sospechoso número uno es el
**propietario**, no los permisos: comprueba con qué usuario corre PHP
(`ps aux | grep php-fpm`) y ajusta el `chown`.

---

## 15. Guía exacta de migraciones

> **No ejecutes nada de esto todavía.** Es la guía para el día del despliegue.

### 1) Respaldo previo — obligatorio

```bash
php scripts/backup_project.php --output=/var/www/vhosts/<dominio>/backups --label=premigracion
```

Verifica los hashes antes de continuar. **Es el único camino de vuelta.**

### 2) Comprobar que la tabla no existe

```sql
SHOW TABLES LIKE 'task_attachments';
```

Sin resultados = aplicar las dos. Si ya existe, aplicar **solo** la segunda, y
antes comprobar que no se aplicó ya:

```sql
SHOW COLUMNS FROM task_attachments LIKE 'external_url';
```

### 3) Aplicar en orden

```bash
mysql -u <usuario> -p <base> < database/migrations/2026-07-29-create-task-attachments.sql
```

```bash
mysql -u <usuario> -p <base> < database/migrations/2026-07-29-add-external-links-to-task-attachments.sql
```

**El orden no es negociable:** la segunda modifica lo que crea la primera.

### 4) Verificar la estructura

```sql
SHOW CREATE TABLE task_attachments; SHOW INDEX FROM task_attachments; CHECK TABLE task_attachments;
```

Debe cumplirse todo esto:

| Comprobación | Esperado |
|---|---|
| `kind` | `enum('image','audio','video','link','embed')` |
| `stored_path` | NULL permitido y **UNIQUE** |
| `external_url` | `varchar(2048)` |
| Clave foránea a `tasks` | `ON DELETE CASCADE` |
| Clave foránea a `users` | `ON DELETE SET NULL` |
| Índices | PRIMARY, `stored_path`, `(task_id,id)`, `uploaded_by`, `provider` |
| Motor y cotejo | InnoDB · `utf8mb4_unicode_ci` |
| `CHECK TABLE` | `OK` |

*Verificado en MariaDB 10.6.23 con tablas aisladas `f7_*`, ya eliminadas.*

### 5) Reversión

```sql
DROP TABLE IF EXISTS task_attachments;
```

> ⚠️ **Esto borra las fichas de todos los adjuntos.** Solo tiene sentido si el
> despliegue se aborta el mismo día y no hay adjuntos que conservar. Si los
> hay, **restaura la base desde el respaldo** en lugar de esto.
>
> ⛔ **Revertir la base NO debe borrar los archivos físicos.** Al eliminar la
> tabla, `storage/attachments` queda con archivos sin ficha. **No los borres
> sin una decisión explícita**: si después se restaura la base, esos archivos
> vuelven a hacer falta. Y **desactiva el cron de huérfanos** mientras dure la
> reversión, o los borrará él pasadas 24 horas.

---

## 16. Lista de comprobación

**Preflight**
- [ ] Commit correcto y árbol limpio
- [ ] PHP 8.0+ con `fileinfo`, `gd`, `mysqli`
- [ ] MariaDB 10.6, `utf8mb4`, zona horaria confirmada
- [ ] Límites de PHP y Nginx **anotados**
- [ ] Espacio en disco suficiente
- [ ] Cron actual revisado

**Antes de migrar**
- [ ] Respaldo completo generado
- [ ] Hashes del respaldo verificados
- [ ] Migraciones probadas en base temporal (MariaDB)
- [ ] Comprobado si la tabla ya existe

**Despliegue**
- [ ] Lint PHP sin errores
- [ ] Sintaxis JS correcta
- [ ] Migración (1) ejecutada
- [ ] Migración (2) ejecutada
- [ ] Estructura verificada
- [ ] `storage/attachments` creada con `.gitkeep` y `.htaccess`
- [ ] Permisos aplicados (**sin 777**)
- [ ] Archivos desplegados y hashes verificados
- [ ] `?v=` presente en `theme.css`

**Pruebas de humo**
- [ ] Login · tablero · cajón
- [ ] Imagen pequeña · visor · descarga
- [ ] Audio · URL · YouTube
- [ ] Eliminación
- [ ] Vista de lector sin botones de escritura
- [ ] Logs sin errores nuevos
- [ ] Datos QA eliminados

**Cierre**
- [ ] `purge_trash` programado
- [ ] `purge_orphan_attachments` programado **en simulacro**
- [ ] Rollback documentado y accesible
- [ ] Respaldo guardado fuera del directorio del sitio

---

## 17. Resumen muggle 🧙‍♂️

**Qué se va a hacer.** Instalar en el servidor de verdad la función de adjuntar
archivos a las tareas. Son tres cosas: dos cambios en la base de datos, el
código nuevo, y una carpeta donde guardar los archivos con los permisos
correctos.

**Lo primero es la red de seguridad.** Antes de tocar nada se hace una copia
completa —base de datos y archivos— y se comprueba que la copia está bien. Si
algo sale mal, eso es lo único que permite volver atrás. Sin esa copia
verificada, no se empieza.

**Sobre el tamaño de los archivos, ya sabemos a qué atenernos.** El servidor
acepta **16 MB por envío** —está medido, no supuesto— y el programa se ha
ajustado a **14 MB**, dejando margen para el envoltorio que el navegador añade
alrededor de cada archivo. Se probó con envíos reales: 15 MB pasa, y **16
exactos ya fallan**, justo por ese envoltorio.

**No hay que pedirle nada al hosting.** Antes se planteaba solicitar una
ampliación; ya no hace falta. El programa se adaptó al servidor en lugar de
exigirle que cambiara.

**Lo que sí conviene explicar a quien lo use:** un archivo de 14 MB entra, pero
**dos de 8 no**, porque viajan juntos y lo que cuenta es la suma. Y para lo que
no cabe hay salida: los vídeos por YouTube o Vimeo, y el resto como enlace.

**Queda una cosa por confirmar:** los cambios en la base de datos se probaron
con un motor y el servidor usa otro. Son parientes cercanos y lo normal es que
funcione igual, pero «lo normal» no es «comprobado». Por eso se prueban primero
en una base de usar y tirar antes de tocar la de verdad.

**Sobre los permisos, un aviso.** Cuando los archivos no se pueden leer, la
tentación es dar permiso a todo el mundo. **No se hace.** Eso deja la carpeta
abierta a cualquiera que tenga acceso al servidor. El problema casi siempre es
otro: la carpeta pertenece al usuario equivocado. Se arregla cambiando el dueño,
no abriendo la puerta.

**Y sobre el proceso de limpieza automática.** Hay un programa que borra
archivos sueltos que ya no pertenecen a ninguna tarea. Va a estrenarse en
**modo ensayo**: durante una o dos semanas dirá lo que borraría sin borrarlo. Se
revisa que lo que dice tiene sentido, y solo entonces se le deja actuar. Un
programa que borra archivos se merece esa desconfianza inicial.

**Si algo sale mal**, hay un plan escrito para deshacerlo paso a paso, y termina
comprobando que el sistema quedó como estaba antes de empezar.
