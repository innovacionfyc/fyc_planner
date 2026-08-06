# Módulo de adjuntos — F&C Planner

Documentación técnica del módulo de adjuntos multimedia de las tareas.

**Documentos relacionados**
- [DEPLOYMENT_ATTACHMENTS.md](DEPLOYMENT_ATTACHMENTS.md) — cómo desplegarlo
- [BACKUP_RESTORE.md](BACKUP_RESTORE.md) — cómo respaldarlo y restaurarlo

> **Estado:** desarrollado y probado **en local**. **Todavía no desplegado en
> producción.** A lo largo del documento se distingue explícitamente entre
> *probado localmente*, *pendiente de verificar en producción* y *recomendado*.

---

## 1. Propósito

Permitir que cada tarea lleve adjuntos: archivos (imágenes, audio, vídeo),
enlaces externos y vídeos incrustados de YouTube y Vimeo. El objetivo es que
una tarea se explique sola —una captura, una nota de voz, el enlace al
documento— sin salir del tablero.

---

## 2. Capacidades

| Capacidad | Cómo se usa | Estado |
|---|---|---|
| Subida por selector | Botón «Adjuntar» en el cajón de la tarea | Probado local |
| Pegar con `Ctrl+V` | Con el cajón abierto, fuera de un campo de texto | Probado local |
| Arrastrar y soltar | Sobre la sección de adjuntos | Probado local |
| Imágenes | Miniatura en cuadrícula + visor ampliado | Probado local |
| Audio | Reproductor con controles | Probado local (ver §12) |
| Vídeo | Reproductor con controles | Probado local (ver §12) |
| Enlaces (URL) | Tarjeta con dominio y enlace seguro | Probado local |
| YouTube / Vimeo | Vídeo incrustado responsivo | Probado local |
| Galería | Cuadrícula adaptable con distintivo de tipo | Probado local |
| Visor ampliado (*lightbox*) | Clic en la miniatura | Probado local |
| Descarga | Enlace «Descargar» por adjunto | Probado local |
| Eliminación | Solo con permiso de escritura | Probado local |

Ctrl+V **no** se intercepta cuando el foco está en un `input`, `textarea`,
`select` o elemento editable: ahí pegar significa pegar texto.

---

## 3. Arquitectura

```
config/bootstrap.php            zona horaria, app_url(), asset_url()
public/_attachments.php         política central: validación, rutas, límites
public/tasks/
  ├── attachment_upload.php     POST  subir archivos
  ├── attachment_link.php       POST  añadir URL / embed
  ├── attachment_delete.php     POST  eliminar un adjunto
  ├── attachment.php            GET   servir el archivo (con permisos)
  └── drawer.php                HTML  fragmento del cajón de la tarea
public/assets/
  ├── board-view.js             subida, pegado, arrastre, visor, borrado
  └── theme.css                 galería, visor, estados, modo oscuro
storage/attachments/AAAA/MM/    archivos reales, FUERA de public/
cron/
  ├── purge_trash.php           purga tableros con +30 días en papelera
  └── purge_orphan_attachments.php  borra archivos sin ficha
database/migrations/            SQL versionado
```

**Regla de oro de la arquitectura:** los archivos viven **fuera de `public/`**.
No hay ninguna URL que apunte directamente a ellos. El único camino es
`attachment.php`, que comprueba sesión y permisos antes de entregar un byte.

El cajón (`drawer.php`) devuelve HTML que `board-view.js` inyecta con
`innerHTML`. **Los `<script>` dentro del cajón no se ejecutan** (los navegadores
no ejecutan scripts insertados así). Por eso toda la lógica del cajón vive en
`board-view.js` mediante delegación de eventos sobre `document`.

---

## 4. Modelo de datos

Tabla `task_attachments`:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `bigint unsigned` PK | |
| `task_id` | `bigint unsigned` | FK → `tasks.id` **ON DELETE CASCADE** |
| `uploaded_by` | `bigint unsigned` NULL | FK → `users.id` **ON DELETE SET NULL** |
| `kind` | `enum` | `image`, `audio`, `video`, `link`, `embed` |
| `original_name` | `varchar(255)` NULL | Nombre visible, nunca usado para rutas |
| `stored_path` | `varchar(255)` NULL | `AAAA/MM/<32 hex>.<ext>` · **UNIQUE** |
| `mime` | `varchar(120)` NULL | MIME real detectado, no el declarado |
| `size_bytes` | `bigint unsigned` NULL | |
| `external_url` | `varchar(2048)` NULL | Solo enlaces y embeds |
| `provider` | `varchar(40)` NULL | `youtube`, `vimeo` o NULL |
| `meta_json` | `json` NULL | `{host, video_id}` |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` |

Índices: `idx_task_attachments_task (task_id, id)`, `..._uploaded_by`,
`..._provider`, y `UNIQUE (stored_path)`.

### Archivo, enlace y embed

| | `stored_path` | `external_url` | `provider` |
|---|:--:|:--:|:--:|
| Archivo (`image`/`audio`/`video`) | ✔ | NULL | NULL |
| Enlace (`link`) | NULL | ✔ | NULL |
| Embed (`embed`) | NULL | ✔ | `youtube`/`vimeo` |

**Contrato:** una fila es archivo **o** enlace, nunca ambos ni ninguno. Se
comprueba en PHP con `attach_validate_source()` y **no** con una restricción
`CHECK`, porque producción usa **MariaDB 10.6** y no queremos depender de un
comportamiento que difiere entre motores.

### Por qué no hay `board_id`

Sería un dato **derivado**: el tablero se obtiene con
`task_attachments → tasks → boards`. Duplicarlo abre la puerta a que se
desincronice si una tarea se mueve de tablero, y ese desajuste sería
silencioso pero afectaría a los **permisos**. Se resuelve por `JOIN` en
`attach_find_with_board()` y `attach_stored_paths_of_board()`.

---

## 5. Ciclo de vida

| Acción | Fila | Archivo |
|---|:--:|:--:|
| Subida | INSERT | Se escribe en `storage/attachments/AAAA/MM/` |
| Lectura / descarga | — | Se sirve por `attachment.php` |
| Eliminar un adjunto | DELETE | Se borra |
| **Eliminar una tarea** | Cascada | **Se borra** |
| **Purgar un tablero** | Cascada | **Se borra** |
| Mover una tarea | — | **Se conserva** |
| Editar una tarea | — | **Se conserva** |
| Archivar un tablero | — | **Se conserva** |
| Enviar a la papelera | — | **Se conserva** |
| Restaurar de la papelera | — | **Se conserva** |
| Cron de papelera (+30 días) | DELETE | **Se borra** |
| Cron de huérfanos | — | Borra archivos **sin** fila |

**El detalle que lo explica todo:** `task_attachments.task_id` tiene
`ON DELETE CASCADE`, y `tasks.board_id` también. Al borrar una tarea o purgar
un tablero, **las filas se van solas pero los archivos se quedarían en disco
para siempre**. Por eso los tres flujos de borrado recogen las rutas **antes**
del `DELETE`: después ya no habría forma de saber cuáles eran.

Orden deliberado: **primero la fila (en transacción), después el archivo**. Si
el borrado físico falla, queda un archivo suelto que el cron de huérfanos
recogerá. El caso inverso —archivo borrado con la fila viva— sería peor:
dejaría un adjunto roto y visible.

---

## 6. Seguridad

| Defensa | Cómo |
|---|---|
| MIME real | `finfo` sobre el contenido; se ignora el MIME declarado |
| Extensión | Lista blanca cerrada; la extensión **debe** casar con el MIME |
| Imágenes | `getimagesize()` además del MIME |
| Extensiones prohibidas | `svg`, `html`, `php`, `exe`, `js`… rechazadas siempre |
| Nombre en disco | `bin2hex(random_bytes(16))` — no se usa el nombre del usuario |
| Rutas | Patrón `AAAA/MM/<32 hex>.<ext>` + `realpath` contenido en la raíz |
| Storage protegido | Fuera de `public/` + `.htaccess` como defensa en profundidad |
| CSRF | `$_SESSION['csrf']` + `hash_equals()` en todo endpoint mutante |
| Permisos | Lectura `has_board_access()`, escritura/borrado `can_write_board()` |
| XSS | `h()` en PHP; en JS `textContent`, **nunca** `innerHTML` para nombres |
| URLs externas | Solo `http`/`https`, sin credenciales, sin caracteres de control |
| Hosts | Lista **exacta** de hosts, comparada con `in_array` (no `strpos`) |
| Embeds | `src` construido desde **plantilla propia** con el ID validado |
| Rango HTTP | `Accept-Ranges`, `206`, `Content-Range`, `416` |
| `stored_path` | **Nunca** sale al HTML ni a las respuestas JSON |
| Sin Base64/BLOB | Los archivos no entran en la base de datos |

**Sobre los embeds, que es el punto más delicado:** `external_url` **jamás** se
usa como `src` de un `<iframe>`. Se extrae el identificador del vídeo, se
valida (`^[A-Za-z0-9_-]{11}$` en YouTube, `^\d{6,15}$` en Vimeo) y se construye
la URL desde cero:

```
https://www.youtube-nocookie.com/embed/{id}
https://player.vimeo.com/video/{id}
```

Así, aunque alguien guarde una URL retorcida, el iframe solo puede apuntar a
esos dos dominios. **El sistema no hace ninguna petición HTTP hacia la URL que
escribe el usuario** — ni para validar, ni para leer metadatos.

---

## 7. Permisos

| Rol | Ver y descargar | Subir | Eliminar |
|---|:--:|:--:|:--:|
| Propietario del tablero | ✔ | ✔ | ✔ |
| Editor | ✔ | ✔ | ✔ |
| Lector | ✔ | ✖ | ✖ |
| Ajeno al tablero | ✖ | ✖ | ✖ |

Al lector **no se le pintan** los botones de eliminar ni el formulario de
subida. Y el backend vuelve a comprobarlo: ocultar un botón no es una defensa.

---

## 8. Formatos y límites actuales

| Tipo | Extensiones | Límite por archivo |
|---|---|:--:|
| Imagen | `jpg`, `jpeg`, `png`, `webp`, `gif` | **14 MB** |
| Audio | `mp3`, `m4a`, `ogg`, `wav` | **14 MB** |
| Vídeo | `mp4`, `webm`, `mov` | **14 MB** |

**Los tres tipos comparten el mismo techo**: lo que manda es el límite del
servidor, no la naturaleza del archivo.

| Regla | Valor |
|---|:--:|
| Máximo por archivo | **14 MB** |
| **Máximo por envío (suma de todos)** | **14 MB** |
| Máximo de archivos por envío | **5** |
| URL máxima | 2048 caracteres |

### Los dos límites no son lo mismo

Parece redundante que ambos valgan 14 MB, pero son reglas distintas:

- un archivo de **14 MB entra**;
- **dos de 8 MB no**, aunque cada uno esté por debajo del máximo individual.

**Los archivos de una selección múltiple viajan juntos en una sola petición**,
así que lo que cuenta es la suma. Si se pasa, **se rechaza el conjunto
completo**: no se guardan unos sí y otros no. Aceptar a medias dejaría al
usuario sin saber qué llegó.

### De dónde sale el 14

Producción tiene `upload_max_filesize = 16M` y `post_max_size = 16M`
—medidos, no supuestos—. Ese límite lo aplica PHP al **cuerpo completo** de la
petición. Los 2 MB de diferencia son el margen para las cabeceras multipart,
los campos `csrf` y `task_id` y los separadores que el navegador añade por cada
archivo.

**Esta versión no necesita que el hosting amplíe nada.** Ver
[DEPLOYMENT_ATTACHMENTS.md §7](DEPLOYMENT_ATTACHMENTS.md).

### ¿Y lo que no cabe?

| Contenido | Vía |
|---|---|
| Vídeos grandes | **YouTube o Vimeo** (se incrustan, no se suben) |
| Cualquier otro archivo grande | **Enlace externo** a donde esté alojado |

Los enlaces y embeds **no ocupan disco ni pasan por el límite de subida**: son
una fila en la base de datos.

**Limitaciones de códecs:** un `.mov` o un `.mp4` se aceptan por su MIME, pero
que el navegador **sepa reproducirlos** depende del códec interno (H.264 sí,
HEVC/ProRes normalmente no). El módulo **no transcodifica**. Cuando el
navegador no puede, aparece un aviso con enlace de descarga.

---

## 9. Experiencia de uso

Tres formas de adjuntar —selector, `Ctrl+V` y arrastrar— que comparten el mismo
flujo interno (`uploadTaskAttachments()`), así que se comportan igual y fallan
igual.

Estados visibles: subiendo, zona de arrastre activa, «eliminando» (tarjeta
atenuada y sin clics), error por archivo rechazado.

**Modo oscuro:** toda la interfaz usa variables CSS (`--bg-surface`,
`--text-ghost`…) y se adapta sola.

**Accesibilidad:** `aria-label` donde el texto no basta, foco visible, visor con
`role="dialog"` + `aria-modal="true"`, cierre con `Escape`, clic fuera o botón,
y **el foco vuelve al elemento que abrió el visor**.

**Cuando algo falla no se queda en silencio:** imagen rota → aviso en su lugar;
audio o vídeo no reproducible → mensaje y enlace de descarga; embed bloqueado →
enlace para verlo en YouTube o Vimeo.

---

## 10. Versionado de recursos estáticos

```php
asset_url('assets/theme.css')   // → /assets/theme.css?v=1785504475
```

El número es el `filemtime()` del archivo: cambia solo cuando el archivo
cambia. Antes las versiones se escribían a mano y quedaban desfasadas — de
hecho `board-view.js` llegó a tener `?v=2` en una plantilla y `?v=1` en otra
**para el mismo archivo**.

Versionados hoy: `theme.css` (6 plantillas), `board-view.js` (2),
`boards-actions.js` (1).

**`app.css` queda fuera por ahora**: lo genera Tailwind y su gestión es
independiente. Sigue enlazado como estaba. Cuando se regenere, conviene
incorporarlo — está anotado como pendiente (§12).

El helper rechaza `../`, `..\`, rutas absolutas, `http://`, `https://`, `//`,
byte nulo y cualquier segmento hecho solo de puntos. Solo se le llama con
rutas literales escritas por nosotros.

---

## 11. Pruebas

```bash
php tests/attachments_backend_smoke.php
```

| Suite | Archivo | Cubre |
|---|---|---|
| A | `tests/attachments_backend_smoke.php` | Validación, permisos, endpoints, Range, límite total |
| B | `tests/attachments_ui_smoke.php` | Interfaz del cajón, galería, escapado, textos del contrato |
| C | `tests/attachments_paste_drop_smoke.php` | `Ctrl+V`, arrastrar y soltar |
| D | `tests/attachments_links_smoke.php` | URLs, YouTube, Vimeo, embeds |
| E | `tests/attachments_gallery_smoke.php` | Visor, accesibilidad, fallbacks |
| F1 | `tests/attachments_lifecycle_smoke.php` | Borrado en cascada y cron de huérfanos |
| F2 | `tests/attachments_backup_smoke.php` | Respaldo, manifiesto, restauración |
| F3 | `tests/assets_versioning_smoke.php` | `asset_url()` y su aplicación |
| F4 | `tests/attachments_docs_smoke.php` | Coherencia de la documentación |
| F5 | `tests/attachments_final_integration_smoke.php` | Integración de punta a punta |
| F7 | `tests/attachments_production_preflight_smoke.php` | Preflight del release y del entorno |
| G3 | `tests/attachments_client_limits_smoke.php` | Límites en el cliente, sin llegar a la red |
| G7 | `tests/attachments_release_smoke.php` | Qué viaja y qué no en el paquete |

**Más de 450 verificaciones automatizadas**, todas en verde en el cierre de la
fase. El recuento exacto de cada entrega se registra en su informe, no aquí:
un número fijo en la documentación queda obsoleto a la primera prueba nueva.

Para conocer el total del momento basta ejecutarlas: cada suite imprime su
propio `RESULTADO`.

Todas son de línea de comandos, se limpian solas y **necesitan Apache y MySQL
en marcha** (crean datos QA y llaman a los endpoints por HTTP). Si aparecen
fallos masivos, comprueba primero que ambos servicios están arriba.

Ninguna deja residuos: al terminar, `task_attachments` y `storage/attachments`
quedan exactamente como estaban.

---

## 12. Pendientes conocidos

| # | Pendiente | Estado |
|:--:|---|---|
| 1 | ~~Límites de subida en Plesk~~ | ✅ **RESUELTO.** Medidos en producción: `upload_max_filesize=16M`, `post_max_size=16M`. El módulo usa 14 MB como margen seguro (§8) |
| 2 | **Migración en MariaDB 10.6** | **Sin probar.** Solo se ha ejecutado sobre MySQL 8.0.30 |
| 3 | **Reproducción de un MP4 real** | **Sin verificar.** No hay `ffmpeg` en el entorno para generar uno |
| 4 | **Audio en otro navegador** | Aislado como decodificación de este Chrome concreto, no del código |
| 5 | ~~Desbordamiento en móvil del cajón~~ | ✅ **NO EXISTÍA.** Refutado en la fase F8: las medidas antiguas se tomaron con el cajón cerrado o a media transición. Con el cajón realmente abierto no hay desplazamiento horizontal en 512, 768, 1280 ni 1920 px |
| 6 | ~~Scripts inline muertos en el cajón~~ | ✅ **RESUELTO** en F8.2.1. `loadDrawer()` inserta el HTML con `innerHTML`, que no ejecuta los `<script>`; ahora llama a `runEmbedScripts()` después de insertar. `Ctrl+Enter` funciona |
| 7 | **Retención de respaldos** | El script nunca borra respaldos antiguos; falta decidir política |
| 8 | **`app.css` sin versionar** | Decisión actual (§10) |
| 9 | **Etiquetas sin tablas ni migración** | **Decisión pendiente.** Ver §12.1 |

### 12.1 Etiquetas: código sin esquema

Detectado durante la fase F8. No es un fallo introducido por ella.

**Lo que hay:**

- interfaz de etiquetas en `public/tasks/drawer.php` y `public/boards/view.php`;
- endpoint completo en `public/tags/tag_action.php` (crear, borrar, asignar, quitar);
- el código espera dos tablas: `task_tags` y `task_tag_pivot`.

**Lo que no hay:**

- ninguna de las dos tablas existe en la base de datos;
- **ninguna migración las crea** — `database/migrations/` solo contiene las dos
  de adjuntos;
- nunca estuvieron en el volcado de esquema del repositorio.

**Por qué no se rompe nada hoy:** tanto el cajón como el tablero comprueban
`SHOW TABLES LIKE 'task_tags'` antes de pintar los controles, así que la
interfaz simplemente no aparece. Y desde F8.2.1 el endpoint también comprueba
las tablas: en lugar de morir con un `500` de cuerpo vacío, responde
`{"ok":false,"error":"Las etiquetas no están disponibles en esta instalación"}`.

**Decisión pendiente, en un bloque propio:** o se añade la migración que crea
las tablas y se prueba la funcionalidad de punta a punta, o se retira el código
de interfaz y el endpoint. Mantener código de una función que no puede
ejecutarse invita a que alguien la dé por disponible.

---

## 13. Guía de mantenimiento

### Añadir un MIME o una extensión

En `attach_whitelist()` de `public/_attachments.php`:

```php
'flac' => ['audio', ['audio/flac', 'audio/x-flac'], ATTACH_MAX_AUDIO],
```

Comprueba antes qué devuelve `finfo` **de verdad** para ese formato: no siempre
coincide con lo que dice la documentación.

```bash
php -r '$f=new finfo(FILEINFO_MIME_TYPE); echo $f->file("ruta/al/archivo");'
```

Actualiza también la suite A y, si procede, `attach_kind_label()`.

### Añadir un proveedor de vídeo

Cuatro sitios en `public/_attachments.php`:

1. `attach_provider_hosts()` — hosts **exactos**, sin comodines.
2. Una función `attach_parse_<proveedor>_id()` con su patrón de ID.
3. `attach_classify_external_url()` — reconocer el proveedor.
4. `attach_build_embed_url()` y `attach_build_watch_url()` — **plantillas
   propias**, nunca la URL del usuario.

Nunca uses `strpos($host, 'proveedor.com')`: `proveedor.com.evil.net` pasaría.

### Cambiar los límites

Las constantes `ATTACH_MAX_IMAGE`, `ATTACH_MAX_AUDIO`, `ATTACH_MAX_VIDEO` y
`ATTACH_MAX_FILES` al inicio de `public/_attachments.php`. **Subirlas no basta:**
el servidor manda. Ver [DEPLOYMENT_ATTACHMENTS.md §7](DEPLOYMENT_ATTACHMENTS.md).

### Añadir un campo a la tabla

Crea una **migración nueva** en `database/migrations/` con la fecha del día.
Sigue el patrón de resiliencia del proyecto (`SHOW COLUMNS` en tiempo de
ejecución) si el código debe funcionar con y sin la columna.

### Cambiar la ubicación del almacén

Solo `attach_storage_root()`. Todo lo demás se deriva de ahí. Después mueve los
archivos conservando la estructura `AAAA/MM/` y **respalda antes**.

### ⛔ No edites las migraciones ya publicadas

`2026-07-29-create-task-attachments.sql` y
`2026-07-29-add-external-links-to-task-attachments.sql` **son historia**. Si ya
se ejecutaron en algún entorno, cambiarlas hace que los entornos dejen de ser
comparables. Siempre una migración nueva.

---

## 14. Resolución de problemas

| Síntoma | Causa probable | Qué mirar |
|---|---|---|
| **413** al subir | El archivo supera `post_max_size` o `client_max_body_size` | Configuración de PHP y Nginx. §7 de despliegue |
| **422** con «no corresponde a su extensión» | El MIME real no casa con la extensión | Correcto: el archivo no es lo que dice ser. Verifícalo con `finfo` |
| **403** al ver un adjunto | Sin permiso sobre el tablero, o sesión caducada | Membresía en `board_members`; volver a entrar |
| **416** en audio o vídeo | El navegador pidió un rango imposible | Normal si el archivo cambió; recargar |
| Audio o vídeo no reproduce | Códec no soportado (§8) | Aparece el aviso de descarga. No es un fallo del módulo |
| Iframe vacío | El proveedor bloquea la incrustación | Usar el enlace «verlo en…» que aparece debajo |
| **Estilos viejos tras desplegar** | Caché del navegador | Resuelto por `asset_url()` (§10). Si persiste, comprobar que la plantilla usa el helper |
| Adjunto roto: fila sí, archivo no | Restauración parcial o borrado físico fallido | `php cron/purge_orphan_attachments.php --dry-run --verbose` |
| Archivos huérfanos | Subida interrumpida o borrado fallido | El cron los limpia pasadas 24 h |
| Todas las pruebas fallan de golpe | Apache o MySQL parados | Arrancarlos y repetir (§11) |

---

## 15. Resumen muggle 🧙‍♂️

**Qué hace esto.** Deja pegar archivos y enlaces dentro de una tarea: una foto,
una nota de voz, un vídeo, la dirección de un documento o un vídeo de YouTube.
La idea es que la tarea se explique sola sin tener que buscar nada por fuera.

**Cómo está montado, en corto.** Cada adjunto vive en dos sitios: una **ficha**
en la base de datos (quién lo subió, cómo se llama, de qué tarea es) y el
**archivo** en una carpeta del servidor. Es importante saberlo porque significa
que copiar solo la base de datos **no** salva los adjuntos.

**La decisión de seguridad más importante:** los archivos están guardados en un
sitio **al que el navegador no puede llegar directamente**. No existe ninguna
dirección pública que lleve a ellos. Cada vez que alguien quiere ver un adjunto,
el programa comprueba primero si esa persona tiene acceso al tablero. Sin
permiso, no hay archivo.

**Y la segunda:** el programa **no se fía del nombre** de los archivos. Alguien
podría renombrar un programa peligroso como si fuera una foto. Aquí se mira el
contenido real, no la etiqueta, y si no coinciden se rechaza.

**Con los vídeos de YouTube pasa algo parecido.** El programa no usa la
dirección que pegas: extrae solo el código del vídeo, comprueba que tenga la
forma correcta, y **construye la dirección desde cero**. Así, aunque alguien
pegue algo raro, el reproductor solo puede apuntar a YouTube o Vimeo.

**Sobre borrar.** Mover una tarea, editarla o mandarla a la papelera **no borra
nada**: todo eso se puede deshacer. Los archivos solo desaparecen cuando el
borrado es definitivo. Y hay un proceso automático que, una vez a la semana,
recoge archivos sueltos que ya no pertenecen a ninguna tarea.

**Cuánto cabe, y qué hacer si no cabe.** El tope son **14 MB por archivo y
14 MB entre todos** los que selecciones a la vez, con un máximo de cinco. Esa
cifra no es caprichosa: el servidor real acepta 16 MB por envío, y los 2 MB
restantes son el envoltorio que el navegador añade alrededor de cada archivo.

Ojo a un detalle que confunde: **un archivo de 14 MB entra, pero dos de 8 no**.
Lo que cuenta es la suma, porque todos viajan juntos. Y si la suma se pasa,
**no se sube ninguno** — es más honesto que subir la mitad y dejarte con la
duda de cuál faltó.

**Para lo que no cabe hay salida:** los vídeos se comparten con YouTube o
Vimeo, y cualquier otro archivo grande como enlace externo. Ninguna de las dos
ocupa espacio ni pasa por el límite de subida.

**Qué está probado y qué no.** Las comprobaciones automáticas pasan enteras, y
los límites del servidor ya están medidos —no supuestos—. Lo que sigue
pendiente de confirmar en el servidor de verdad son otras cosas, listadas sin
adornos en la sección de pendientes.
