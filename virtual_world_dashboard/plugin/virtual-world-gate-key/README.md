# Virtual World Gate Key

Plugin WordPress pensato per girare dentro Home Assistant come middleware:

- collega WordPress a Home Assistant
- salva endpoint e long-lived token Home Assistant
- salva e sincronizza `user_code` e `token_api` per **WP GPT Automation Pro**
- espone endpoint REST semplici per GPT Actions e client esterni

## Rotte REST

### Stato plugin
`GET /wp-json/vwgk/v1/status`

Header richiesto:
`x-api-key: <vwgk_api_key>`

### Lettura stato entitÃ  HA
`GET /wp-json/vwgk/v1/ha/state?entity_id=light.salone`

### Chiamata servizio Home Assistant
`POST /wp-json/vwgk/v1/ha/service`

```json
{
  "domain": "light",
  "service": "turn_on",
  "payload": {
    "entity_id": "light.salone"
  }
}
```

### Proxy verso WP GPT Automation Pro
`POST /wp-json/vwgk/v1/wp-gpt/proxy`

```json
{
  "namespace": "v1",
  "route": "/manage-articles-with-user-code",
  "method": "POST",
  "payload": {
    "action": "search-post",
    "search": "home assistant"
  }
}
```

Il plugin aggiunge automaticamente `user_code` e Bearer token di WP GPT Automation Pro.

