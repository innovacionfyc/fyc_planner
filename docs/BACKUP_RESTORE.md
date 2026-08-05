# Respaldo y restauración — F&C Planner

Guía operativa del respaldo completo del módulo de adjuntos.

**Documentos relacionados**
- [ATTACHMENTS.md](ATTACHMENTS.md) — arquitectura, ciclo de vida y cron
- [DEPLOYMENT_ATTACHMENTS.md](DEPLOYMENT_ATTACHMENTS.md) — despliegue y rollback

> **Regla de oro:** desde la Fase A del módulo de adjuntos, `mysqldump` por sí
> solo **ya no restaura el sistema**. Sigue leyendo.

> **Estado:** el procedimiento está **probado en local** —respaldo real,
> verificación de hashes y restauración simulada en carpeta temporal—. **Todavía
> no se ha ejecutado en producción.** La primera vez conviene hacerlo con calma
> y verificar el resultado antes de confiar en él.

---

## 1. Por qué `mysqldump` solo ya no basta

Cada adjunto vive en **dos sitios a la vez**:

| Parte | Dónde vive | Qué pasa si falta |
|---|---|---|
| La ficha | Fila en `task_attachments` (base de datos) | El adjunto no aparece en la tarea |
| El archivo | `storage/attachments/AAAA/MM/<32 hex>.<ext>` | La ficha aparece pero **el adjunto está roto** |

`storage/` vive **fuera de `public/`** y **fuera de la base de datos**. Un dump
solo trae las fichas: al restaurarlo, cada imagen, audio y vídeo daría error de
carga. Y al revés, restaurar solo los archivos deja ficheros que nadie
referencia — que el cron de huérfanos acabaría borrando.

**Los dos se respaldan juntos, se restauran juntos y se verifica que casan.**

Los enlaces y los embeds (YouTube, Vimeo) son la excepción: no tienen archivo,
viven enteros en la base. Se restauran solo con el dump.

---

## 2. Cómo ejecutar el respaldo

### Local (Windows / Laragon)

```bash
php scripts/backup_project.php --dry-run
```

```bash
php scripts/backup_project.php
```

Por defecto escribe en `_backups/`, que está en `.gitignore`: **un respaldo
nunca debe acabar en el repositorio**.

### Producción (Plesk / Linux)

```bash
php /var/www/vhosts/<dominio>/<carpeta>/scripts/backup_project.php --output=/var/www/vhosts/<dominio>/backups --label=predeploy
```

Conviene que la carpeta de salida esté **fuera del directorio del sitio**, para
que el servidor web no pueda servirla nunca.

### Opciones

| Opción | Para qué |
|---|---|
| `--output=RUTA` | Carpeta de destino (por defecto `_backups`) |
| `--label=ETIQUETA` | Sufijo del nombre. Solo `A-Za-z0-9._-`, máx. 40 |
| `--dry-run` | Enseña lo que haría sin escribir nada |
| `--db-only` / `--storage-only` | Respaldo parcial |
| `--storage-format=zip\|targz` | Formato del archivo de storage |
| `--mysqldump=RUTA` | Ruta explícita al ejecutable |
| `--help` | Ayuda |

**Códigos de salida:** `0` correcto · `1` fallo · `2` argumento inválido ·
`3` falta un requisito del entorno.

### Automatizarlo

```bash
0 2 * * *  php /var/www/vhosts/<dominio>/<carpeta>/scripts/backup_project.php --output=/var/www/vhosts/<dominio>/backups >> /var/log/fyc_backup.log 2>&1
```

El script **nunca borra respaldos antiguos**. La rotación es responsabilidad
de quien administra el servidor: decidir cuántos días conservar y purgar el
resto es una decisión de negocio, no del script.

---

## 3. Estructura del respaldo

```
fyc_planner_backup_20260731_143012_predeploy/
├── database.sql.gz            dump completo comprimido
├── storage_attachments.zip    storage/attachments íntegro
├── manifest.json              qué hay dentro y si cuadra
└── SHA256SUMS.txt             sumas para verificar sin PHP
```

Dentro del zip la ruta se conserva completa (`storage/attachments/…`), para que
al abrirlo sea evidente dónde va cada cosa.

### Qué NO se incluye, a propósito

- `config/db.php` — contiene credenciales; se gestiona aparte
- Sesiones, logs, temporales
- Respaldos anteriores
- El código (lo aporta Git: el manifiesto anota el commit exacto)

**Los recursos estáticos tampoco necesitan respaldo especial.** `theme.css`,
`board-view.js` y compañía viajan en el repositorio, y su versión de caché
(`?v=…`) se calcula sola a partir de la fecha del archivo — ver
[ATTACHMENTS.md §10](ATTACHMENTS.md). Al restaurar el código desde Git, la
versión se regenera sin intervención.

### Los límites de subida no cambian la estrategia

El módulo acepta **14 MB por archivo y 14 MB por envío** (ver
[ATTACHMENTS.md §8](ATTACHMENTS.md)). Ese límite afecta a lo que se puede
**subir**, no a lo que hay que **respaldar**:

- **`storage/attachments` sigue incluido siempre.** Un adjunto de 2 MB necesita
  respaldo igual que uno de 14.
- **Los enlaces y embeds no generan archivo físico.** Viven enteros en la base
  de datos, así que el dump los restaura solos. Un tablero lleno de vídeos de
  YouTube puede ocupar cero bytes en `storage/` y aun así necesitar la base.
- Si bajara el límite de subida, el volumen a respaldar **no disminuye**: los
  adjuntos ya subidos siguen ahí.

---

## 4. Verificar la integridad

### Con `sha256sum` (Linux)

```bash
cd fyc_planner_backup_20260731_143012_predeploy && sha256sum -c SHA256SUMS.txt
```

### Con PowerShell (Windows)

```bash
Get-FileHash .\database.sql.gz -Algorithm SHA256 | Format-List
```

Compara el resultado con `database.sha256` del `manifest.json`.

### Qué mirar en `manifest.json`

| Campo | Para qué sirve |
|---|---|
| `project_commit` | **El código a desplegar debe ser este.** Restaurar una base nueva sobre código viejo rompe cosas |
| `project_dirty` | Si es `true`, había cambios sin confirmar: el commit no describe del todo lo que había |
| `database.table_count` | Debe coincidir con las tablas de la base |
| `module.physical_file_rows` | Fichas **con archivo**. Debe cuadrar con los archivos del zip menos `.gitkeep` y `.htaccess` |
| `module.orphan_count` | Archivos sin ficha. Si es alto, revisar antes de restaurar |
| `storage.file_count` | Archivos dentro del zip |
| `environment.db_session_timezone` | Debe ser `-05:00` |

El manifiesto **no guarda usuario, contraseña ni host**. Es seguro adjuntarlo
a un ticket o compartirlo con el equipo.

---

## 5. Orden de restauración

**El orden importa.** Hacerlo al revés deja el sitio roto a mitad.

### 1) Código compatible

```bash
git checkout <project_commit del manifiesto>
```

Primero el código: si la base restaurada tiene columnas que el código viejo no
conoce, el sitio falla.

### 2) Base de datos

```bash
gunzip -c database.sql.gz | mysql -u <usuario> -p <base_de_datos>
```

> El dump lleva `--add-drop-table`: **borra y recrea cada tabla**. Todo lo que
> haya en esa base se pierde. Restaura siempre sobre una base vacía o sobre una
> de la que ya tengas otro respaldo.

### 3) storage/attachments

```bash
unzip -o storage_attachments.zip -d /var/www/vhosts/<dominio>/<carpeta>/
```

El zip ya trae la ruta `storage/attachments/…`, así que se extrae sobre la raíz
del proyecto y cada archivo cae en su sitio.

Para `tar.gz`:

```bash
tar -xzf storage_attachments.tar.gz -C /var/www/vhosts/<dominio>/<carpeta>/
```

### 4) Permisos

```bash
chown -R <usuario_web>:<grupo_web> storage/attachments && chmod -R 750 storage/attachments
```

Es el paso que más se olvida. Sin él, PHP no puede leer los archivos y **todos
los adjuntos aparecen rotos aunque estén ahí**.

### 5) Verificar

```bash
php cron/purge_orphan_attachments.php --dry-run --verbose
```

En simulacro no borra nada y dice cuántos archivos sobran o faltan. Si aparecen
muchos huérfanos, la base y el storage no son de la misma fecha.

Códigos de salida: `0` correcto · `1` errores al borrar · `2` argumento
inválido · `3` **aborto de seguridad** (no pudo leer el inventario de la base y
prefirió no tocar nada). Tras una restauración, un `3` casi siempre significa
que la base aún no está lista: revísala antes de insistir.

> **No dejes el cron de huérfanos activo durante una restauración.** Si la base
> se restaura antes que los archivos, durante ese intervalo **todos** los
> adjuntos parecen huérfanos. El margen de gracia de 24 horas protege de esto,
> pero desactivarlo mientras dura la operación es más seguro. El ciclo de vida
> completo está en [ATTACHMENTS.md §5](ATTACHMENTS.md).

Después, en el navegador: abrir una tarea con adjuntos y comprobar que la
imagen se ve, el audio suena y el enlace abre.

---

## 6. Restaurar a una carpeta temporal (sin tocar nada)

Para inspeccionar un respaldo sin arriesgar:

```bash
mkdir /tmp/revision && cd /tmp/revision && unzip -q /ruta/al/storage_attachments.zip && gunzip -c /ruta/al/database.sql.gz > revision.sql
```

Y para cargar el dump en una base de usar y tirar:

```bash
mysql -u <usuario> -p -e "CREATE DATABASE fyc_revision CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
mysql -u <usuario> -p fyc_revision < revision.sql
```

Así se comprueba que el respaldo es restaurable **antes** de necesitarlo de
verdad. Es la única forma de saberlo.

---

## 7. Cuando solo falla una de las dos partes

### Solo falla `storage` (archivos perdidos, base intacta)

No restaures la base. Extrae únicamente los archivos:

```bash
unzip -o storage_attachments.zip -d /ruta/al/proyecto/
```

Después ajusta permisos y comprueba con el cron en simulacro. Si el respaldo es
más antiguo que la base, faltarán los adjuntos subidos desde entonces: sus
fichas quedarán apuntando a archivos inexistentes y se verán rotos. Se detectan
así:

```sql
SELECT id, task_id, original_name FROM task_attachments WHERE stored_path IS NOT NULL ORDER BY created_at DESC;
```

Las filas más recientes que la fecha del respaldo son las sospechosas.

### Solo falla la base (base corrupta, archivos intactos)

Restaura solo el dump y **no toques `storage/`**. Si el dump es más antiguo que
los archivos, sobrarán archivos sin ficha: son huérfanos y el cron los limpiará
pasadas 24 horas.

> **Antes de restaurar una base más antigua, haz un respaldo nuevo del storage
> actual.** Si no, el cron de huérfanos borrará archivos que en realidad eran
> válidos y ya no habrá vuelta atrás.

---

## 8. Advertencias

- **`--add-drop-table` destruye la base de destino.** Comprueba dos veces a qué
  base apuntas antes de restaurar.
- **El script nunca sobrescribe un respaldo previo.** Si el nombre existe, se
  detiene con código 1 en vez de pisarlo.
- **Un fallo a mitad se limpia solo.** No queda un respaldo incompleto
  aparentando estar bien; eso es peor que no tener ninguno.
- **Un respaldo sin probar no es un respaldo.** Restáuralo en una base temporal
  al menos una vez.
- **Enlaces simbólicos:** el script no los sigue ni los incluye. Si guardas
  adjuntos en un disco montado por enlace, **no entrarán en el respaldo**.
- El respaldo **no incluye `config/db.php`**: guarda las credenciales aparte,
  por otro medio.

---

## 9. Resumen muggle 🧙‍♂️

**El problema, en una frase:** los adjuntos están en dos sitios y hay que
copiar los dos.

Cuando alguien sube una foto a una tarea pasan dos cosas: se guarda **una ficha**
en la base de datos —quién la subió, cómo se llama, de qué tarea es— y se guarda
**la foto** en una carpeta del servidor. Son dos cosas separadas.

Antes bastaba con copiar la base de datos. **Ya no.** Si copias solo la base,
recuperas las fichas pero no las fotos: cada adjunto aparecería como una imagen
rota. Y si copias solo las fotos, tienes archivos que nadie sabe de quién son.

**Por eso hay un solo comando que copia las dos cosas a la vez** y además deja
una nota —el manifiesto— diciendo cuántas fichas y cuántos archivos había, para
que puedas comprobar que cuadran.

**Tres cosas que conviene saber:**

**Al restaurar, el orden importa.** Primero el programa, luego la base, luego
los archivos, y por último los permisos. El paso de los permisos es el que más
se olvida, y sin él los adjuntos siguen apareciendo rotos aunque estén todos
ahí. Es frustrante: parece que la copia falló cuando en realidad solo falta una
orden.

**Restaurar la base borra lo que hubiera.** No mezcla ni fusiona: deja la base
exactamente como estaba el día de la copia. Todo lo hecho después se pierde.
Por eso, antes de restaurar algo antiguo, conviene hacer una copia de lo actual.

**Una copia que nunca has probado a restaurar no es una copia.** Es una
suposición. Hay instrucciones arriba para restaurarla en una carpeta temporal
sin tocar nada real: hazlo una vez, con calma, antes de necesitarlo con prisa.

**Y una tranquilidad:** el programa nunca pisa una copia anterior, y si algo
falla a mitad borra lo que hubiera dejado a medias. Prefiere no dejarte nada
antes que dejarte algo roto que parezca bueno.
