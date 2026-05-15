<?php

class PluginMs365syncMsGraph extends CommonDBTM {

   private $access_tokens = [];

   /**
    * Bandera para evitar bucles de sincronización durante la importación
    */
   public static $is_importing = false;

   /**
    * Obtiene el token de acceso para un dominio específico
    */
   public function getAccessTokenByDomain($domain) {
      global $DB;
      if (isset($this->access_tokens[$domain])) {
         return $this->access_tokens[$domain];
      }

      // Buscar tenant de forma global para el sistema (Cron/API)
      $result = $DB->request([
         'FROM'  => 'glpi_plugin_ms365sync_tenants',
         'WHERE' => [
            'domain' => $domain,
            'active' => 1
         ]
      ])->current();

      if (!$result) {
         Toolbox::logInFile("ms365sync", "No hay un Tenant activo para el dominio: $domain\n");
         return false;
      }

      $tenant_id = $result['tenant_id'];
      $client_id = $result['client_id'];
      $key_manager = new GLPIKey();
      $client_secret = $key_manager->decrypt($result['client_secret']);
      
      $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";
      $post_fields = [
         'client_id'     => $client_id,
         'scope'         => 'https://graph.microsoft.com/.default',
         'client_secret' => $client_secret,
         'grant_type'    => 'client_credentials',
      ];

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $data = json_decode($response, true);
      curl_close($ch);

      if (isset($data['access_token'])) {
         $this->access_tokens[$domain] = $data['access_token'];
         Toolbox::logInFile("ms365sync", "Token de aplicación obtenido correctamente para el dominio: $domain\n", true);
         return $data['access_token'];
      } else {
         Toolbox::logInFile("ms365sync", "Error obteniendo Token para $domain (HTTP $http_code): " . $response . "\n");
      }
      return false;
   }

   /**
    * Genera la URL de autorización para el usuario (Permisos Delegados)
    */
   public function getAuthorizationUrl($users_id) {
      $user_email = $this->getUserEmail($users_id);
      $domain = explode('@', $user_email)[1] ?? '';
      
      global $DB, $CFG_GLPI;
      $tenant = $DB->request("glpi_plugin_ms365sync_tenants", ['domain' => $domain, 'active' => 1])->current();
      if (!$tenant) return "#";

      // Aseguramos que la URL sea absoluta y tenga las barras correctas
      $base_url = rtrim($CFG_GLPI['url_base'] ?? '', '/');
      if (empty($base_url)) {
         $base_url = rtrim(Toolbox::getWebBaseUrl(), '/');
      }
      $redirect_uri = $base_url . '/' . ltrim(Plugin::getWebDir('ms365sync', false), '/') . "/front/auth.php";
      $scopes = "offline_access Presence.ReadWrite User.Read Calendars.ReadWrite";
      
      return "https://login.microsoftonline.com/" . $tenant['tenant_id'] . "/oauth2/v2.0/authorize?" . http_build_query([
         'client_id'     => $tenant['client_id'],
         'response_type' => 'code',
         'redirect_uri'  => $redirect_uri,
         'response_mode' => 'query',
         'scope'         => $scopes,
         'state'         => $users_id
      ]);
   }

   /**
    * Obtiene un token delegado (del usuario) usando el refresh_token almacenado
    */
   private function getDelegatedAccessToken($users_id) {
      global $DB;
      $user_conf = $DB->request("glpi_plugin_ms365sync_users", ['users_id' => $users_id])->current();
      if (empty($user_conf['refresh_token'])) {
         Toolbox::logInFile("ms365sync", "Usuario $users_id no tiene refresh_token (requiere re-autorización)\n");
         return false;
      }

      $user_email = $this->getUserEmail($users_id);
      $domain = explode('@', $user_email)[1] ?? '';
      $tenant = $DB->request("glpi_plugin_ms365sync_tenants", ['domain' => $domain, 'active' => 1])->current();
      
      $key_manager = new GLPIKey();
      $refresh_token = $key_manager->decrypt($user_conf['refresh_token']);

      $url = "https://login.microsoftonline.com/" . $tenant['tenant_id'] . "/oauth2/v2.0/token";
      $post_fields = [
         'client_id'     => $tenant['client_id'],
         'client_secret' => $key_manager->decrypt($tenant['client_secret']),
         'grant_type'    => 'refresh_token',
         'refresh_token' => $refresh_token,
         'scope'         => 'offline_access Presence.ReadWrite User.Read Calendars.ReadWrite'
      ];

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $data = json_decode($response, true);
      curl_close($ch);

      if (isset($data['access_token'])) {
         return $data['access_token'];
      }

      Toolbox::logInFile("ms365sync", "Error renovando token delegado para usuario $users_id (HTTP $http_code): $response\n");
      return false;
   }

   /**
    * Wrapper para llamadas a la API de Microsoft Graph
    */
   private function callGraphAPI($method, $endpoint, $user_email, $data = null, $users_id = 0) {
      $token = ($users_id > 0) ? $this->getDelegatedAccessToken($users_id) : false;
      if (!$token) {
         $parts = explode('@', $user_email);
         $domain = end($parts);
         $token = $this->getAccessTokenByDomain($domain);
      }
      if (!$token) return false;

      // Si el endpoint ya es una URL completa (p.e. un nextLink), la usamos tal cual
      if (strpos($endpoint, 'http') === 0) {
         $url = $endpoint;
      } else {
         // Las APIs de Presencia requieren el endpoint /beta para funcionar con permisos de Aplicación.
         $version = (strpos($endpoint, '/presence') !== false) ? 'beta' : 'v1.0';
         $url = "https://graph.microsoft.com/$version" . $endpoint;
      }

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $headers = [
         "Authorization: Bearer $token",
         "Content-Type: application/json"
      ];

      if ($data !== null) {
         $payload = json_encode($data);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      } else if (in_array($method, ['POST', 'PATCH', 'PUT'])) {
         // Fix para Error 411 (Length Required): Graph exige un cuerpo (aunque sea vacío) en POST/PATCH/PUT.
         $payload = '{}';
         curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      }

      if (isset($payload)) {
         $headers[] = "Content-Length: " . strlen($payload);
      }

      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      
      $response = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curl_error = curl_error($ch);
      curl_close($ch);

      if ($http_code >= 200 && $http_code < 300) {
         return json_decode($response, true) ?: true;
      }

      $log_msg = "Error API Graph (HTTP $http_code) [$method] en $endpoint: $response";
      if ($http_code === 0) {
         $log_msg .= " | cURL Error: $curl_error";
      }
      Toolbox::logInFile("ms365sync", $log_msg . "\n");
      return false;
   }

   /**
    * Obtiene prefijos dinámicos
    */
   private function getDynamicPrefix($users_id, $is_event = false) {
      global $DB;
      $field = $is_event ? 'prefix_events' : 'prefix_tasks';
      $use_field = $is_event ? 'use_prefix_events' : 'use_prefix_tasks';
      $default = $is_event ? __("Event: ", "ms365sync") : __("Task: ", "ms365sync");

      $u_conf = $DB->request("glpi_plugin_ms365sync_users", ['users_id' => $users_id])->current();
      
      // Lógica de Usuario
      if ($u_conf && isset($u_conf[$use_field]) && $u_conf[$use_field] !== null) {
         if ($u_conf[$use_field] == 0) return "";
         return (empty($u_conf[$field])) ? $default : $u_conf[$field] . " ";
      }

      // Si no hay config de usuario, heredamos del Tenant
      $user_email = $this->getUserEmail($users_id);
      $domain = explode('@', $user_email)[1] ?? '';
      $t_conf = $DB->request("glpi_plugin_ms365sync_tenants", ['domain' => $domain])->current();
      
      if ($t_conf) {
         if ($t_conf[$use_field] == 0) return "";
         return (empty($t_conf[$field])) ? $default : $t_conf[$field] . " ";
      }

      return $default;
   }

   /**
    * Evita duplicados de prefijos (p.e. "Event: Event: ...")
    */
   private function cleanSubject($subject) {
      $prefixes = [
         __("Event: ", "ms365sync"),
         __("Task: ", "ms365sync"),
         "Event: ",
         "Task: "
      ];
      return trim(str_replace($prefixes, "", $subject));
   }

   /**
    * Sincroniza hacia Outlook
    */
   public function syncTaskToMS($item) {
      global $DB, $CFG_GLPI;

      $tech_id = $item->fields['users_id_tech'] ?? ($item->fields['users_id'] ?? 0);
      if ($tech_id <= 0) return;
      $user_email = $this->getUserEmail($tech_id);
      if (empty($user_email)) return;

      $itemType = $item->getType();
      $is_event = in_array($itemType, ['PlanningExternalEvent', 'PlanningEvent']);
      
      Toolbox::logInFile("ms365sync", "Iniciando sincronización hacia Outlook: $itemType ID " . $item->fields['id'] . "\n", true);

      // Construcción del Título (Subject)
      $rawName = $item->fields['name'] ?? "";
      if (empty($rawName) && in_array($itemType, ['TicketTask', 'ProblemTask', 'ChangeTask', 'ProjectTask'])) {
         $rawName = $itemType . " #" . $item->fields['id'];
      }
      
      $cleanTitle = $this->cleanSubject($rawName);
      $subject = trim($this->getDynamicPrefix($tech_id, $is_event) . $cleanTitle);

      // Seguridad: Si el asunto queda vacío, Graph fallará con HTTP 400
      if (empty($subject)) {
         $subject = ($is_event) ? __("External Event", "ms365sync") : __("GLPI Task", "ms365sync");
      }

      // URL Absoluta para Outlook
      $glpi_url = rtrim($CFG_GLPI['url_base'], '/') . $item->getLinkURL();
      
      // Contenido del cuerpo
      $content = $item->fields['content'] ?? ($item->fields['text'] ?? '');

      // Decodificamos entidades HTML (como &lt;p&gt;) para que Outlook pueda renderizar el HTML real
      $decoded_content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
      
      $bodyContent = "<div>" . $decoded_content . "</div>";
      $bodyContent .= "<br><hr><b>" . __("GLPI Link:", "ms365sync") . "</b> <a href='$glpi_url'>" . __("View Task", "ms365sync") . "</a>";

      // Fechas
      $begin_raw = $item->fields['begin'] ?? ($item->fields['date'] ?? null);
      $actiontime = intval($item->fields['actiontime'] ?? 3600);
      $is_event = in_array($itemType, ['PlanningExternalEvent', 'PlanningEvent']);
      $start = $this->formatDateTimeForGraph($begin_raw, $tech_id, !$is_event);
      $end_val = $item->fields['end'] ?? date(PLUGIN_MS365SYNC_DATE_FORMAT, strtotime($begin_raw) + $actiontime);
      $end = $this->formatDateTimeForGraph($end_val, $tech_id, !$is_event);

      // Seguridad: Si no hay fechas válidas, no podemos sincronizar (evita HTTP 400)
      if (empty($start) || empty($end)) {
         Toolbox::logInFile("ms365sync", "Sincronización abortada: Fechas inválidas para el item " . $item->fields['id'] . "\n");
         return;
      }

      $event_data = [
         'subject' => $subject,
         'body' => ['contentType' => 'HTML', 'content' => $bodyContent],
         'start' => $start, 'end' => $end,
         // Añadimos una extensión para identificar esta instancia de GLPI y evitar duplicados
         'extensions' => [
            [
               '@odata.type' => 'microsoft.graph.openTypeExtension',
               'extensionName' => 'org.glpi.ms365sync',
               'instance_uuid' => Config::getConfigurationValue('uuid', '')
            ]
         ]
      ];

      $table_map = "glpi_plugin_ms365sync_event_map";
      $mapped = $DB->request($table_map, ['glpi_itemtype' => $itemType, 'glpi_items_id' => $item->fields['id']])->current();

      if ($mapped) {
         $response = $this->callGraphAPI("PATCH", "/users/$user_email/events/" . $mapped['ms_event_id'], $user_email, $event_data);
         
         // Si el evento ya no existe en Outlook (404), borramos nuestro mapeo para permitir recreación
         if ($response === false) {
            // Nota: callGraphAPI loguea el error. Aquí solo detectamos la falla.
            // Podríamos verificar el código HTTP si el wrapper lo permitiera, 
            // pero por ahora si falla el PATCH, es sano limpiar el mapa.
            $DB->delete($table_map, ['id' => $mapped['id']]);
         }
      } else {
         $response = $this->callGraphAPI("POST", "/users/$user_email/events", $user_email, $event_data);
         if ($response && isset($response['id'])) {
            $DB->insert($table_map, [
               'glpi_itemtype' => $itemType, 'glpi_items_id' => $item->fields['id'],
               'ms_event_id' => $response['id'], 'ms_user_principal' => $user_email,
               'last_sync_date' => date(PLUGIN_MS365SYNC_DATE_FORMAT)
            ]);
            Toolbox::logInFile("ms365sync", "Nuevo evento creado exitosamente en Outlook para usuario $user_email\n", true);
         }
      }
   }

   /**
    * Presencia en Teams
    */
   public function setTeamsPresence($users_id, $is_starting = true, $item = null) {
      if (!Plugin::isPluginActive('actualtime')) {return;}
      $user_email = $this->getUserEmail($users_id);
      if (empty($user_email)) {return;}

      if ($is_starting && $item instanceof CommonDBTM) {
         global $CFG_GLPI;
         
         // Construcción robusta de la URL absoluta
         $base_url = rtrim($CFG_GLPI['url_base'] ?? '', '/');
         if (empty($base_url)) {
            $base_url = rtrim(Toolbox::getWebBaseUrl(), '/');
         }
         $glpi_url = $base_url . $item->getLinkURL();
         
         $status_msg = $this->getTeamsMessage($users_id) . " " . __("Working on:", "ms365sync") . " " . $glpi_url . "<pinnednote></pinnednote>";
      
         // 1. Petición para establecer el Mensaje de Estado (setStatusMessage)
         $status_data = [
            'statusMessage' => [
               'message' => [
                  'content' => $status_msg,
                  'contentType' => 'text'
               ],
               'published' => true, // "Show when people message me"
               'expiryDateTime' => [
                  'dateTime' => gmdate('Y-m-d\TH:i:s', strtotime('+8 hours')),
                  'timeZone' => 'UTC'
               ]
            ]
         ];
         if ($this->callGraphAPI("POST", "/me/presence/setStatusMessage", $user_email, $status_data, $users_id)) {
            Toolbox::logInFile("ms365sync", "Mensaje de estado de Teams actualizado para usuario $users_id\n", true);
         }
      
         // 2. Petición para establecer la Disponibilidad
         $presence_data = [
            'availability' => 'Busy',
            'activity' => 'Busy',
            'expirationDuration' => 'PT8H'
         ];
         $res = $this->callGraphAPI("POST", "/me/presence/setUserPreferredPresence", $user_email, $presence_data, $users_id);
         if ($res) {
            Toolbox::logInFile("ms365sync", "Presencia en Teams establecida como 'Ocupado' para usuario $users_id\n", true);
         }
         return $res;
      } else {
         // Limpiar el mensaje de estado (Enviamos objeto vacío con expiración inmediata para evitar HTTP 400)
         $clear_status = [
            'statusMessage' => [
               'message' => [
                  'content' => '',
                  'contentType' => 'text'
               ],
               'published' => false,
               'expiryDateTime' => [
                  'dateTime' => gmdate('Y-m-d\TH:i:s'),
                  'timeZone' => 'UTC'
               ]
            ]
         ];
         if ($this->callGraphAPI("POST", "/me/presence/setStatusMessage", $user_email, $clear_status, $users_id)) {
             Toolbox::logInFile("ms365sync", "Mensaje de estado de Teams limpiado para usuario $users_id\n", true);
         }
         $res = $this->callGraphAPI("POST", "/me/presence/clearUserPreferredPresence", $user_email, null, $users_id);
         if ($res) {
             Toolbox::logInFile("ms365sync", "Presencia en Teams restablecida a automático para usuario $users_id\n", true);
         }
         return $res;
      }
   }

   /**
    * Método estático requerido por el Cron de GLPI para la tarea 'syncEvents'
    */
   public static function cronSyncEvents(CronTask $crontask) {
      Toolbox::logInFile("ms365sync", "Cron syncEvents: Iniciando importación de eventos externos...\n", true);
      $instance = new self();
      $instance->importExternalEvents();
      
      $crontask->setVolume(1);
      return 1;
   }

   /**
    * Importación desde Outlook a GLPI
    */
   public function importExternalEvents() {
      global $DB;
      
      // Evitar timeout en sincronizaciones masivas
      set_time_limit(0);

      self::$is_importing = true;

      $instance_uuid = Config::getConfigurationValue('uuid', '');

      $tenants = $DB->request("glpi_plugin_ms365sync_tenants", ['active' => 1]);
      
      foreach ($tenants as $tenant_data) {
         $domain = $tenant_data['domain'];
         $iterator = $DB->request([
            'SELECT' => ['u.email', 'u.users_id'],
            'FROM'   => 'glpi_useremails AS u',
            'INNER JOIN' => ['glpi_plugin_ms365sync_users AS conf' => ['ON' => ['u' => 'users_id', 'conf' => 'users_id']]],
            'WHERE' => ['u.email' => ['LIKE', "%@$domain"], 'conf.is_sync_enabled' => 1]
         ]);

         foreach ($iterator as $user_data) {
            $added = 0; $updated = 0;
            $email = $user_data['email']; $user_id = $user_data['users_id'];
            Toolbox::logInFile("ms365sync", "Procesando usuario: $email\n", true);

            $periods = $this->getSyncPeriods($user_id, $tenant_data['id']);
            
            // Lógica: 0 = 7 días, cualquier otro número = meses
            $past_str = ($periods['past'] === 0) ? "7 days" : "{$periods['past']} months";
            $future_str = ($periods['future'] === 0) ? "7 days" : "{$periods['future']} months";

            $start_date = date('Y-m-d\T00:00:00\Z', strtotime("-".$past_str));
            $end_date   = date('Y-m-d\T23:59:59\Z', strtotime("+".$future_str));
            
            // Expandimos las extensiones para verificar el origen del evento
            $filter = rawurlencode("id eq 'org.glpi.ms365sync'");
            $next_link = "/users/$email/calendarView?startDateTime=$start_date&endDateTime=$end_date&\$top=100&\$expand=extensions(\$filter=$filter)";

            while ($next_link) {
               $response = $this->callGraphAPI("GET", $next_link, $email);
               if (!$response || !isset($response['value'])) {
                  break;
               }

               foreach ($response['value'] as $ms_event) {
                  $is_from_this_glpi = false;
                  if (isset($ms_event['extensions'])) {
                     foreach ($ms_event['extensions'] as $extension) {
                        if ($extension['id'] === 'org.glpi.ms365sync' && isset($extension['instance_uuid'])) {
                           if ($extension['instance_uuid'] !== $instance_uuid) {
                              // El evento pertenece a otra instancia de GLPI, ignorar.
                              continue 2;
                           }
                           $is_from_this_glpi = true;
                        }
                     }
                  }

                  // 2. Verificar si ya existe en el mapa
                  $check = $DB->request("glpi_plugin_ms365sync_event_map", ['ms_event_id' => $ms_event['id']])->current();

                  // Si el evento viene de esta instancia de GLPI y NO es un PlanningExternalEvent, 
                  // significa que es una Tarea (TicketTask, etc.). NO debemos importarla.
                  if ($is_from_this_glpi && $check && $check['glpi_itemtype'] !== 'PlanningExternalEvent') {
                     continue;
                  }

                  // Metadatos de sincronización se guardan en UTC
                  $ms_modified = $this->formatGraphDateToGLPI($ms_event['lastModifiedDateTime'], 'UTC', true);
                  $ms_body = $ms_event['body']['content'] ?? '';
                  
                  // Seguridad: Decodificar entidades HTML y limpiar tags para el texto plano
                  $clean_text = ($ms_body !== null) ? strip_tags(html_entity_decode($ms_body, ENT_QUOTES, 'UTF-8')) : "Sin descripción";
                  $clean_name = $this->cleanSubject($ms_event['subject'] ?? __("Untitled", "ms365sync"));

                  // Limpiar newlines y caracteres de control para evitar errores SQL en glpi_logs
                  $clean_text = preg_replace('/[[:cntrl:]]/', ' ', $clean_text); // Eliminar caracteres de control
                  $clean_text = preg_replace('/\s+/', ' ', $clean_text); // Reemplazar múltiples espacios/newlines con un solo espacio
                  $clean_name = preg_replace('/[[:cntrl:]]/', ' ', $clean_name); // Limpiar también el nombre
                  
                  // Limitar longitud para DB
                  if (mb_strlen($clean_name) > 250) {
                     $clean_name = mb_substr($clean_name, 0, 247) . '...';
                  }

                  // Si existe un mapeo, verificar que el objeto GLPI todavía existe realmente
                  if ($check) {
                     $item_check = new $check['glpi_itemtype']();
                     if (!$item_check->getFromDB($check['glpi_items_id'])) {
                        $DB->delete("glpi_plugin_ms365sync_event_map", ['id' => $check['id']]);
                        $check = false; // Forzamos a que se trate como un evento nuevo
                     }
                  }

                  $input = [
                     'name'         => $clean_name,
                     'text'         => $clean_text,
                     'users_id'     => $user_id, // Asignar al usuario GLPI
                     'plan'         => [ // GLPI espera 'plan' para eventos de planificación
                        // Guardamos la hora LOCAL tal cual viene de Graph para que GLPI la muestre correctamente
                        'begin' => $this->formatGraphDateToGLPI($ms_event['start']['dateTime'], $ms_event['start']['timeZone'], false),
                        'end'   => $this->formatGraphDateToGLPI($ms_event['end']['dateTime'], $ms_event['end']['timeZone'], false)
                     ],
                     'is_recursive' => 1, 
                     'state'        => 0, 
                     'entities_id'  => $this->getUserDefaultEntity($user_id),
                  ];

                  $external_event = new PlanningExternalEvent();

                  if (!$check) {
                     $new_id = $external_event->add($input);
                     if ($new_id) {
                        $DB->insert("glpi_plugin_ms365sync_event_map", [
                           'glpi_itemtype' => 'PlanningExternalEvent', 'glpi_items_id' => $new_id,
                           'ms_event_id' => $ms_event['id'], 'ms_user_principal' => $email,
                           'ms_last_modified' => $ms_modified, 'last_sync_date' => date(PLUGIN_MS365SYNC_DATE_FORMAT)
                        ]);
                        $added++;
                     }
                  } elseif ($check['ms_last_modified'] != $ms_modified) {
                     $input['id'] = $check['glpi_items_id'];
                     if ($external_event->update($input)) {
                        $DB->update("glpi_plugin_ms365sync_event_map", [
                           'ms_last_modified' => $ms_modified, 'last_sync_date' => date(PLUGIN_MS365SYNC_DATE_FORMAT)
                        ], ['id' => $check['id']]);
                        $updated++;
                     }
                  }
               }
               $next_link = $response['@odata.nextLink'] ?? null;
            }
            Toolbox::logInFile("ms365sync", "Usuario $email finalizado: $added nuevos, $updated actualizados.\n", true);
         }
      }

      self::$is_importing = false;
   }

   /**
    * Elimina un evento en Microsoft Graph
    */
   public function deleteOutlookEvent($user_email, $ms_event_id) {
      // Reutilizamos el wrapper callGraphAPI que ya maneja tokens y errores
      $response = $this->callGraphAPI("DELETE", "/users/$user_email/events/$ms_event_id", $user_email);
      
      // En un DELETE exitoso, Graph devuelve un HTTP 204 No Content (que nuestro wrapper traduce como true/false)
      return $response !== false;
   }

   // --- MÉTODOS DE APOYO ---

   private function getTeamsMessage($users_id) {
      global $DB;
      $default = __("Working on:", "ms365sync");
      $u_conf = $DB->request("glpi_plugin_ms365sync_users", ['users_id' => $users_id])->current();

      // Lógica Usuario
      if ($u_conf && isset($u_conf['use_teams_status_prefix']) && $u_conf['use_teams_status_prefix'] !== null) {
         if ($u_conf['use_teams_status_prefix'] == 0) return $default;
         return (!empty($u_conf['teams_status_msg'])) ? $u_conf['teams_status_msg'] : $default;
      }

      // Herencia Tenant
      $user_email = $this->getUserEmail($users_id);
      $domain = explode('@', $user_email)[1] ?? '';
      $t_conf = $DB->request("glpi_plugin_ms365sync_tenants", ['domain' => $domain])->current();

      if ($t_conf) {
         if ($t_conf['use_teams_status_prefix'] == 0) return $default;
         return (!empty($t_conf['teams_status_msg'])) ? $t_conf['teams_status_msg'] : $default;
      }

      return $default;
   }

   public function getUserEmail($users_id) {
      global $DB;
      $iterator = $DB->request('glpi_useremails', ['users_id' => $users_id]);
      $email = '';
      foreach ($iterator as $row) {
         if ($row['is_default']) {return $row['email'];}
         $email = $row['email'];
      }
      return $email;
   }

   public function formatDateTimeForGraph($date, $users_id, $is_utc_in_db = true) {
      if (empty($date) || $date == 'NULL') {
         return null;
      }
      $user_tz = $this->getUserTimezone($users_id);
      try {
         if ($is_utc_in_db) {
            // Para tareas (TicketTask, etc.) que se almacenan en UTC en la DB de GLPI
            $dt = new DateTime($date, new DateTimeZone('UTC'));
         } else {
            // Para PlanningExternalEvent/PlanningEvent que se almacenan en la zona horaria local en la DB de GLPI
            $dt = new DateTime($date, new DateTimeZone($user_tz));
         }
         $dt->setTimezone(new DateTimeZone($user_tz));
         return ['dateTime' => $dt->format('Y-m-d\TH:i:s'), 'timeZone' => $user_tz];
      } catch (Exception $e) { return null; }
   }

   private function getUserTimezone($users_id) {
      $user = new User();
      if ($user->getFromDB($users_id) && !empty($user->fields['timezone'])) {return $user->fields['timezone'];}
      return $_SESSION['glpitimezone'] ?? date_default_timezone_get();
   }

   /**
    * Convierte una fecha y hora de Graph API (con su zona horaria) a un formato compatible con GLPI (UTC).
    *
    * @param string $graphDateTime La cadena de fecha y hora de Graph (ej. "2024-05-15T10:00:00").
    * @param string $graphTimeZone La zona horaria de Graph. Por defecto 'UTC' (para lastModifiedDateTime).
    * @param bool $convertToUTC Si es true, convierte a UTC. Si false, mantiene la zona horaria original.
    * @return string|null La fecha y hora formateada para GLPI, o null si hay un error.
    */
   public function formatGraphDateToGLPI($graphDateTime, $graphTimeZone = 'UTC', $convertToUTC = true) {
      if (empty($graphDateTime)) {return null;}
      try {
         $dt = new \DateTime($graphDateTime, new \DateTimeZone($graphTimeZone));
         if ($convertToUTC) {
            $dt->setTimezone(new \DateTimeZone('UTC')); // Convertir a UTC para el almacenamiento interno de GLPI
         }
         return $dt->format(PLUGIN_MS365SYNC_DATE_FORMAT);
      } catch (\Exception $e) {
         Toolbox::logInFile("ms365sync", "Error al formatear fecha de Graph para GLPI: " . $e->getMessage() . " (DateTime: $graphDateTime, TimeZone: $graphTimeZone)\n");
         return null;
      }
   }

   private function getUserDefaultEntity($users_id) {
      $user = new User();
      return ($user->getFromDB($users_id)) ? ($user->fields['entities_id'] ?? 0) : 0;
   }

   public function getSyncPeriods($users_id, $tenant_id) {
      global $DB;
      $user_conf = $DB->request("glpi_plugin_ms365sync_users", ['users_id' => $users_id])->current();
      $tenant_conf = $DB->request("glpi_plugin_ms365sync_tenants", ['id' => $tenant_id])->current();
      
      $past = (isset($user_conf['sync_months_past']) && $user_conf['sync_months_past'] !== null && $user_conf['sync_months_past'] !== '')
              ? $user_conf['sync_months_past']
              : ($tenant_conf['sync_months_past'] ?? 1);

      $future = (isset($user_conf['sync_months_future']) && $user_conf['sync_months_future'] !== null && $user_conf['sync_months_future'] !== '')
              ? $user_conf['sync_months_future']
              : ($tenant_conf['sync_months_future'] ?? 1);

      return [
         'past'   => (int)$past,
         'future' => (int)$future
      ];
   }

   /**
    * Método para forzar la sincronización de una tarea específica
    */
   public function forceSyncItem($itemtype, $items_id) {
      global $DB;
      // Cargamos el objeto original
      $item = new $itemtype();
      if ($item->getFromDB($items_id)) {
         // Llamamos a tu método de sincronización existente
         $this->syncTaskToMS($item);
         return true;
      }
      return false;
   }

   /**
    * Resetea la fecha de última modificación de eventos mapeados para forzar una re-sincronización.
    *
    * @param int|null $users_id ID del usuario para resetear sus eventos, o null para todos los eventos.
    * @return bool True si la operación fue exitosa, false en caso contrario.
    */
   public function resetMsLastModified($users_id = null) {
      global $DB;
      $table_map = "glpi_plugin_ms365sync_event_map";
      $conditions = [];

      if ($users_id !== null) {
         $user_email = $this->getUserEmail($users_id);
         if (empty($user_email)) {
            Toolbox::logInFile("ms365sync", "Error: No se encontró email para el usuario ID $users_id al intentar resetear ms_last_modified.\n");
            return false;
         }
         $conditions['ms_user_principal'] = $user_email;
         $log_scope = "para el usuario $user_email (ID: $users_id)";
      } else {
         $conditions = [1 => 1]; // Permite actualizar todos los registros sin disparar el error de seguridad de GLPI
         $log_scope = "para TODOS los eventos de TODOS los usuarios";
      }

      // Establecer ms_last_modified a NULL para que el cron lo detecte como "modificado"
      if ($DB->update($table_map, ['ms_last_modified' => null], $conditions)) {
         Toolbox::logInFile("ms365sync", "Reinicio de ms_last_modified exitoso $log_scope. Ejecutado por " . ($_SESSION['glpiname'] ?? 'system') . " (ID: " . ($_SESSION['glpiID'] ?? 0) . ").\n");
         return true;
      }
      Toolbox::logInFile("ms365sync", "Fallo al reiniciar ms_last_modified $log_scope. Ejecutado por " . ($_SESSION['glpiname'] ?? 'system') . " (ID: " . ($_SESSION['glpiID'] ?? 0) . ").\n");
      return false;
   }
}
