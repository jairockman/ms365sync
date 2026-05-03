# Changelog

Todos los cambios notables en este proyecto serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
y este proyecto se adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-05-04

### Fixed
- **Database Schema**: Corregidos los tipos de datos de las claves primarias y foráneas a `UNSIGNED` para cumplir con los estándares de GLPI 10 y evitar advertencias de PHP.
- **Teams Status**: Añadida la etiqueta interna `<pinnednote>` para habilitar la opción "Show when people message me" en el mensaje de estado de Microsoft Teams.
- **Outlook Calendar HTML**: Corregido el renderizado de etiquetas HTML en el cuerpo de las tareas mediante la decodificación de entidades.
- **Optimización de Logs**: Eliminados logs redundantes en hooks y crons para reducir el ruido y mejorar el rendimiento de importación.
- **SQL Log Safety**: Limpiados caracteres de control y nuevas líneas en el texto de los eventos para evitar errores de sintaxis en `glpi_logs`.
- **Sync Integrity**: Mejora en la importación para detectar y limpiar mapeos huérfanos si los eventos de GLPI fueron eliminados manualmente o mediante limpieza de base de datos.
- **Prefix Flexibility**: Ahora se permite dejar los prefijos de tareas y eventos vacíos tanto a nivel de Tenant como de usuario.
- **Sync Range Logic**: Implementada lógica especial donde el valor `0` en el rango de meses (pasado o futuro) equivale a una sincronización de 7 días.
- **Database Compatibility**: Refactorización del proceso de instalación y esquemas para eliminar advertencias de tipos de datos `SIGNED` y corregir errores de sintaxis en GLPI 10.

## [1.0.0] - 2026-05-03

### Added
- Versión inicial estable.
- Sincronización bidireccional de Calendario (Outlook <-> GLPI).
- Integración de presencia y mensajes de estado con Microsoft Teams.
- Soporte para múltiples Tenants y deduplicación por UUID de instancia.
- Sistema de logs detallado para depuración.

[1.0.0]: https://github.com/jairockman/ms365sync/releases/tag/v1.0.0