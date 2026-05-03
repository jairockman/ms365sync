# MS365 Sync - GLPI Plugin

[![GLPI 10.0+](https://img.shields.io/badge/GLPI-10.0+-blue.svg)](https://github.com/glpi-project/glpi)
[![License: GPL-3.0](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

---

## 🇪🇸 Descripción (Español)

Este plugin permite una integración profunda y bidireccional entre **GLPI** y el ecosistema de **Microsoft 365**. Está diseñado para sincronizar calendarios y reflejar la actividad técnica en Microsoft Teams en tiempo real.

### Características Principales
1. **Sincronización Bidireccional de Calendario**: 
   - Las tareas de Tickets, Problemas, Cambios y Proyectos creadas en GLPI se sincronizan como eventos en el calendario de Outlook.
   - Los eventos creados manualmente en Outlook se importan automáticamente a GLPI como "Eventos Externos".
   - **Deduplicación Inteligente**: Utiliza metadatos (Open Extensions) para evitar duplicados si varias instancias de GLPI (ej. Local vs Producción) apuntan a la misma cuenta de Microsoft 365.
2. **Integración con Microsoft Teams**:
   - Cambia automáticamente el estado del técnico a **"Ocupado" (Busy)** cuando inicia un temporizador de la tarea (vía plugin *Actualtime*).
   - Establece un mensaje de estado en Teams indicando en qué ticket se está trabajando, incluyendo un enlace directo.
   - Restablece el estado a **"Disponible"** y limpia el mensaje al detener el temporizador.

### Prerrequisitos
- **GLPI**: Versión 10.0 o superior.
- **PHP**: 8.1 o superior con extensión `curl`.
- **Plugin Actualtime**: Requerido para la sincronización de presencia en Teams.

### Configuración en Microsoft Entra ID (Azure AD)
Es necesario realizar un **App Registration** (Registro de aplicación) en el portal de Azure con la siguiente configuración:

#### 1. Permisos de API (Microsoft Graph)
Se deben conceder y otorgar consentimiento de administrador a los siguientes permisos:

| API / Nombre Permiso | Tipo | Descripción |
| :--- | :--- | :--- |
| `Calendars.ReadWrite` | Application | Leer y escribir calendarios en todos los buzones. |
| `Calendars.ReadWrite` | Delegated | Acceso total a los calendarios del usuario. |
| `offline_access` | Delegated | Mantener el acceso a los datos (Refresh Token). |
| `Presence.Read.All` | Application | Leer información de presencia de todos los usuarios. |
| `Presence.ReadWrite` | Delegated | Leer y escribir la presencia del usuario. |
| `Presence.ReadWrite.All` | Application | Leer y escribir la presencia de todos los usuarios. |
| `User.Read` | Delegated | Iniciar sesión y leer el perfil del usuario. |
| `User.Read.All` | Application | Leer perfiles completos de todos los usuarios. |

#### 2. Autenticación (Redirect URI)
Configurar una URL de redireccionamiento de tipo **Web**:
`http://localhost/glpi/marketplace/ms365sync/front/auth.php`
*(Cambie `localhost` por su dominio real en producción)*.

### Configuración en GLPI
1. **Tenants**: En el menú de configuración del plugin, registre su Tenant ID, Client ID y Client Secret.
2. **Autorización de Usuario**: Cada técnico debe ir a su perfil -> pestaña **Microsoft 365 Sync**, habilitar la sincronización y hacer clic en **"Authorize Microsoft Teams"** para vincular su cuenta mediante OAuth2.

### Tareas Programadas (Cron)
El plugin registra dos tareas automáticas:
1. **`syncEvents`**: Sincroniza los eventos de calendario entre Outlook y GLPI (Recomendado: cada 5-15 min).
2. **`monitorTimers`**: Detecta cambios en los cronómetros de *Actualtime* para actualizar el estado de Teams (Recomendado: cada 1 min).

---

## 🇺🇸 Description (English)

This plugin enables a deep, bidirectional integration between **GLPI** and the **Microsoft 365** ecosystem. It is designed to sync calendars and reflect technical activity in Microsoft Teams in real-time.

### Main Features
1. **Bidirectional Calendar Sync**:
   - Tasks from Tickets, Problems, Changes, and Projects created in GLPI are synced as events in the Outlook calendar.
   - Events created manually in Outlook are automatically imported into GLPI as "External Events".
   - **Smart De-duplication**: Uses metadata (Open Extensions) to prevent duplicates if multiple GLPI instances (e.g., Local vs. Production) point to the same Microsoft 365 account.
2. **Microsoft Teams Integration**:
   - Automatically changes the technician's status to **"Busy"** when a task timer is started (via *Actualtime* plugin).
   - Sets a status message in Teams indicating which ticket is being worked on, including a direct link.
   - Resets status to **"Available"** and clears the message when the timer is stopped.

### Prerequisites
- **GLPI**: Version 10.0 or higher.
- **PHP**: 8.1 or higher with `curl` extension.
- **Actualtime Plugin**: Required for Teams presence synchronization.

### Microsoft Entra ID (Azure AD) Configuration
You must perform an **App Registration** in the Azure portal with the following settings:

#### 1. API Permissions (Microsoft Graph)
The following permissions must be granted and admin consent must be provided:

| API / Permission Name | Type | Description |
| :--- | :--- | :--- |
| `Calendars.ReadWrite` | Application | Read and write calendars in all mailboxes. |
| `Calendars.ReadWrite` | Delegated | Have full access to user calendars. |
| `offline_access` | Delegated | Maintain access to data (Refresh Token). |
| `Presence.Read.All` | Application | Read presence information for all users. |
| `Presence.ReadWrite` | Delegated | Read and write a user's presence information. |
| `Presence.ReadWrite.All` | Application | Read and write presence information for all users. |
| `User.Read` | Delegated | Sign in and read user profile. |
| `User.Read.All` | Application | Read all users' full profiles. |

#### 2. Authentication (Redirect URI)
Configure a **Web** redirect URL:
`http://localhost/glpi/marketplace/ms365sync/front/auth.php`
*(Change `localhost` to your actual production domain)*.

### GLPI Configuration
1. **Tenants**: In the plugin configuration menu, register your Tenant ID, Client ID, and Client Secret.
2. **User Authorization**: Each technician must go to their profile -> **Microsoft 365 Sync** tab, enable synchronization, and click **"Authorize Microsoft Teams"** to link their account via OAuth2.

### Scheduled Tasks (Cron)
The plugin registers two automatic tasks:
1. **`syncEvents`**: Syncs calendar events between Outlook and GLPI (Recommended: every 5-15 min).
2. **`monitorTimers`**: Detects changes in *Actualtime* timers to update Teams status (Recommended: every 1 min).

---

### Author
**Jairo Cervantes**

---

### License
This project is licensed under the GPLv3 License.