# Quiniela Mundial 2026 ⚽

Una aplicación web ligera y dinámica programada en PHP para gestionar una quiniela (polla / torneo de pronósticos) del Mundial de Fútbol 2026 entre amigos. El sistema se alimenta automáticamente de marcadores y estadísticas en tiempo real desde la API pública de ESPN y actualiza la tabla de posiciones (leaderboard) en vivo.

Diseñada con un estilo premium en modo oscuro, animaciones dinámicas, adaptabilidad móvil total y cero dependencias pesadas (Vanilla PHP, CSS y JS).

---

## 🚀 Características Principales

- **Registro Automático:** Los usuarios se registran al ingresar su nombre y contraseña por primera vez (no requiere flujos complejos de registro).
- **Acceso Inteligente (Case-Insensitive):** Inicio de sesión insensible a mayúsculas y minúsculas (por ejemplo, `Luis`, `luis` o `lUiS` acceden a la misma cuenta original).
- **Resultados en Vivo y Puntos Proyectados:** Visualización de partidos en vivo con destellos visuales al caer goles. El leaderboard calcula y muestra puntos provisionales "en vivo" y los consolida al finalizar el encuentro.
- **Detalle de Partido Interactivo (Modal):** Al hacer clic en un partido iniciado o finalizado, se abre un popup que muestra:
  - Estadísticas del partido (posesión, tiros, faltas, tiros de esquina, fueras de juego, etc.).
  - Alineaciones oficiales (titulares y suplentes con iconos de goles, tarjetas y sustituciones).
  - Cronología detallada de cambios.
- **Tabla de Grupos del Mundial:** Cálculo dinámico de la tabla de posiciones de los 12 grupos oficiales del Mundial de 2026 (Grupo A al L), aplicando tie-breakers oficiales de la FIFA.
- **Estadísticas y Disciplina:** Ranking de los 10 máximos goleadores, líderes de tarjetas amarillas (🟨) y rojas (🟥), promedios de goles, penales cobrados y récords de mejor delantera y defensa.
- **Panel de Administración:** Espacio privado para que el administrador cree partidos de forma manual, capture resultados o fuerce la actualización en vivo.

---

## 🛠️ Requisitos del Servidor

- **PHP:** Versión 5.6 o superior (totalmente compatible con PHP 7.x y PHP 8.x).
- **Base de Datos:** MySQL o MariaDB.
- **Extensiones PHP recomendadas:** `PDO` (con driver MySQL) y `cURL` (con soporte SSL para consulta externa de la API de ESPN).
- **Servidor Web:** Apache (con soporte de lectura para archivos `.htaccess` en hosting tipo cPanel).

---

## 📦 Instalación y Configuración

Sigue estos pasos para desplegar la quiniela en tu propio hosting (ej. cPanel):

### 1. Preparar la Base de Datos
1. Crea una base de datos MySQL en tu hosting.
2. Crea un usuario con todos los privilegios para esa base de datos.
3. Importa el archivo `schema.sql` (que incluye la estructura inicial de tablas y partidos de la Jornada 1).

### 2. Configurar Credenciales
1. Renombra el archivo `config.example.php` a `config.php`.
2. Edita `config.php` en un editor de texto e ingresa los datos de conexión de tu base de datos:
   ```php
   define('DB_HOST', 'tu_servidor_mysql'); // Ej: localhost o ip del servidor
   define('DB_PORT', '3306');
   define('DB_NAME', 'tu_nombre_de_bd');
   define('DB_USER', 'tu_usuario_de_bd');
   define('DB_PASS', 'tu_contrasena_de_bd');
   ```

### 3. Subir los Archivos
Sube todos los archivos del proyecto al directorio público de tu servidor web (usualmente `public_html`).

### 4. Inicializar y Migrar
1. Abre tu navegador y accede a: `http://tu-dominio.com/setup.php`
2. El script inicializará las tablas y validará que las columnas requeridas para ESPN estén creadas con éxito.
3. **SEGURIDAD IMPORTANTE:** Después de ejecutar el instalador, elimina los archivos `setup.php` y `schema.sql` de tu servidor mediante FTP o el Administrador de Archivos de cPanel para evitar que terceros reinicien tu base de datos.

### 5. Automatizar Resultados (Cron Job)
Para que los marcadores y estadísticas en vivo se sincronicen automáticamente sin intervención manual, configura un **Cron Job (Tarea Programada)** en tu panel de cPanel para que se ejecute cada 5 minutos:
```bash
*/5 * * * * php /home/tu_usuario_cpanel/public_html/api/sync.php
```
*(Nota: Asegúrate de ajustar la ruta física `/home/tu_usuario_cpanel/...` al directorio real donde instalaste tu quiniela).*

---

## 🎮 Sistema de Puntuación

Los puntos de los participantes se calculan de manera automática al finalizar cada partido:
* 🎯 **Marcador Exacto:** **6 puntos** (si aciertas los goles exactos de ambos equipos).
* ✓ **Resultado Correcto:** **3 puntos** (si aciertas quién gana o si hay empate, pero no los goles exactos).
* ✗ **Fallo:** **0 puntos**.

---

## 📄 Licencia

Este proyecto es de código abierto. Puedes usarlo, modificarlo y adaptarlo de manera libre para tus propios torneos y quinielas con amigos. ¡Que disfrutes del Mundial 2026! ⚽⚡
