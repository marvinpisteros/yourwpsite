# Central App Phase 1 Agent Brief

Dit document is bedoeld als **bouwinstructie voor een code-agent** die de eerste fase van de centrale PHP-applicatie voor `yourWPsite` moet bouwen op `dev.yoursitehulp.nl/yourwpsite`.

De focus is bewust klein: we bouwen alleen de control plane die nodig is om een WordPress site-agent te laten discoveren, automatisch te claimen en daarna veilige basiscommando's te kunnen ontvangen.

## Doel

Bouw fase 1 van een centrale PHP control plane voor `yourWPsite`.

De applicatie moet:

- InstaWP-site provisioningrecords kunnen bewaren als `pending_sites`
- discovery calls van de WordPress plugin kunnen ontvangen
- een site automatisch kunnen claimen op basis van provisioning context
- agent credentials en policy kunnen uitgeven
- heartbeats kunnen ontvangen
- command polling ondersteunen
- command results kunnen opslaan

Nog niet bouwen:

- UI voor eindklanten
- AI-chatinterface
- media uploads
- theme/template installaties
- pluginmanagement
- multi-user admin UX

## Technische uitgangspunten

- Gebruik **PHP 8.2+**
- Gebruik **MySQL of MariaDB**
- Gebruik **PDO** met prepared statements
- Gebruik een kleine, duidelijke projectstructuur
- Introduceer **geen zwaar framework** tenzij er in de target-omgeving al een bestaand framework aanwezig is
- Bouw alles API-first in JSON
- Gebruik UTC timestamps in opslag en responses
- Gebruik alleen HTTPS-aannames

Als er nog geen bestaande codebase is, bouw dan een kleine custom PHP-app met een eenvoudige router en service classes.

## Op te leveren fase 1

De agent moet een werkende MVP neerzetten met:

1. basis projectstructuur
2. environment config
3. database schema + migratiescript
4. JSON API endpoints
5. auto-claim logic
6. token issuance
7. command queue basis
8. audit logging basis
9. eenvoudige healthcheck

## Gewenste projectstructuur

Gebruik ongeveer deze structuur:

```text
yourwpsite/
  public/
    index.php
  src/
    Http/
      Router.php
      Request.php
      Response.php
    Controllers/
      HealthController.php
      AgentDiscoveryController.php
      AgentHeartbeatController.php
      AgentCommandController.php
    Services/
      DiscoveryService.php
      ClaimService.php
      TokenService.php
      CommandService.php
      AuditLogService.php
    Repositories/
      PendingSiteRepository.php
      SiteRepository.php
      SiteAgentRepository.php
      CommandRepository.php
      AuditLogRepository.php
    Support/
      Db.php
      Env.php
      Json.php
      Uuid.php
      Clock.php
  config/
    app.php
  database/
    migrations/
      001_initial.sql
  storage/
    logs/
  .env.example
  README.md
```

## Database schema

Maak minimaal deze tabellen aan.

### `pending_sites`

Velden:

- `id`
- `tenant_id`
- `workspace_id`
- `instawp_site_id` nullable
- `expected_home_url`
- `expected_domain_host`
- `template_slug` nullable
- `status`
- `created_at`
- `claim_window_ends_at`
- `claimed_at` nullable

### `sites`

Velden:

- `id`
- `tenant_id`
- `workspace_id`
- `instawp_site_id` nullable
- `home_url`
- `host`
- `status`
- `current_policy_json`
- `claimed_at`
- `last_seen_at` nullable
- `created_at`
- `updated_at`

### `site_agents`

Velden:

- `id`
- `site_id`
- `agent_local_id`
- `agent_secret_hash`
- `plugin_version`
- `wp_version`
- `php_version`
- `theme_slug` nullable
- `site_fingerprint`
- `last_discovery_at` nullable
- `last_heartbeat_at` nullable
- `revoked_at` nullable
- `created_at`
- `updated_at`

### `commands`

Velden:

- `id`
- `site_id`
- `capability`
- `payload_json`
- `status`
- `issued_by`
- `issued_at`
- `expires_at`
- `started_at` nullable
- `finished_at` nullable
- `result_json` nullable
- `error_json` nullable

### `audit_logs`

Velden:

- `id`
- `site_id` nullable
- `agent_id` nullable
- `event_type`
- `event_payload_json`
- `created_at`

## Statuswaardes

Gebruik deze expliciete statussets.

### `pending_sites.status`

- `pending`
- `claimed`
- `expired`
- `needs_review`

### `sites.status`

- `claimed_active`
- `claimed_quarantined`
- `revoked`

### `commands.status`

- `queued`
- `delivered`
- `succeeded`
- `failed`
- `expired`

## API endpoints

De agent moet deze endpoints bouwen.

### `GET /health`

Doel:

- simpele healthcheck voor deploy en monitoring

Response:

```json
{
  "ok": true,
  "service": "yourwpsite-control-plane",
  "time": "2026-05-23T12:00:00Z"
}
```

### `POST /v1/agents/discover`

Doel:

- discovery ontvangen van de WordPress plugin
- proberen te matchen met een `pending_site`
- indien match: site claimen en credentials uitgeven

Request body:

```json
{
  "agent_local_id": "uuid",
  "home_url": "https://demo-abc.instawp.xyz",
  "site_url": "https://demo-abc.instawp.xyz",
  "wp_version": "6.8.1",
  "php_version": "8.2.17",
  "plugin_version": "0.1.0",
  "theme_slug": "twentytwentyfive",
  "capabilities": [
    "site.read_public_content_index"
  ],
  "site_fingerprint": "sha256:abc123"
}
```

Matchregels:

- host van `home_url` moet exact matchen met `pending_sites.expected_domain_host`
- pending site moet status `pending` hebben
- current time moet kleiner zijn dan `claim_window_ends_at`
- er mag nog geen `claimed_active` site op dezelfde host bestaan

Response bij nog niet claimbaar:

```json
{
  "status": "discovered_unclaimed",
  "poll_after_seconds": 30
}
```

Response bij succesvolle claim:

```json
{
  "status": "claimed",
  "site_id": "site_123",
  "agent_id": "agent_456",
  "access_token": "plain-issued-token",
  "refresh_token": "plain-refresh-token",
  "expires_in": 900,
  "policy": {
    "enabled_capabilities": [
      "site.read_public_content_index",
      "content.create_page",
      "content.update_page"
    ]
  }
}
```

### `POST /v1/agents/heartbeat`

Auth:

- bearer token vereist

Doel:

- laatst gezien moment bijwerken
- plugin health ontvangen

Request body:

```json
{
  "site_id": "site_123",
  "agent_id": "agent_456",
  "plugin_version": "0.1.0",
  "wp_version": "6.8.1",
  "php_version": "8.2.17",
  "health": {
    "mode": "managed",
    "last_command_at": "2026-05-23T10:10:00Z",
    "can_write": true
  }
}
```

Response:

```json
{
  "ok": true,
  "next_poll_after_seconds": 15
}
```

### `GET /v1/agents/commands`

Auth:

- bearer token vereist

Query:

- `site_id`
- `agent_id`
- `limit`

Doel:

- queued commands voor de site ophalen

Gedrag:

- lever alleen niet-verlopen `queued` commands
- update status naar `delivered` zodra ze worden teruggegeven
- maximaal `limit`, default 10, max 25

Response:

```json
{
  "commands": []
}
```

### `POST /v1/agents/command-results`

Auth:

- bearer token vereist

Doel:

- resultaten van uitgevoerde commands opslaan

Request body:

```json
{
  "site_id": "site_123",
  "agent_id": "agent_456",
  "command_id": "cmd_123",
  "status": "succeeded",
  "started_at": "2026-05-23T10:11:02Z",
  "finished_at": "2026-05-23T10:11:03Z",
  "result": {
    "post_id": 42
  }
}
```

Gedrag:

- update command status naar `succeeded` of `failed`
- sla `result_json` of `error_json` op
- log audit event

Voor failed responses moet de API ook dit formaat accepteren:

```json
{
  "site_id": "site_123",
  "agent_id": "agent_456",
  "command_id": "cmd_123",
  "status": "failed",
  "started_at": "2026-05-23T10:11:02Z",
  "finished_at": "2026-05-23T10:11:03Z",
  "error": {
    "message": "Capability is not enabled by policy."
  }
}
```

## Auth model voor fase 1

Hou dit simpel maar veilig.

### Discovery fase

- discovery endpoint is publiek bereikbaar
- rate limit op IP en host
- strikte JSON validatie
- alleen beperkte metadata accepteren

### Managed fase

- geef bij succesvolle claim een random `access_token` en `refresh_token` uit
- sla nooit plaintext tokens op; sla alleen hashes op
- `access_token` lifetime: 15 minuten
- `refresh_token` lifetime: 30 dagen

Voor fase 1 hoeft refresh nog niet volledig uitgewerkt te zijn zolang:

- access tokens uitgegeven en gevalideerd worden
- token hash veilig wordt opgeslagen
- de code voorbereid is op latere rotatie

## Auto-claim service

De agent moet een service bouwen die:

1. host uit `home_url` haalt
2. `pending_site` zoekt op exacte hostmatch
3. window checkt
4. duplicate active site checkt
5. bij succes:
   - record in `sites` aanmaakt
   - record in `site_agents` aanmaakt
   - `pending_site` op `claimed` zet
   - policy toekent
   - tokens uitgeeft

Gebruik transacties rond claim-logica zodat halve claims niet mogelijk zijn.

## Policy voor fase 1

Gebruik voor nu een eenvoudige JSON policy per site:

```json
{
  "enabled_capabilities": [
    "site.read_public_content_index",
    "content.create_page",
    "content.update_page"
  ]
}
```

Nog niet nodig:

- fijnmazige per-user policies
- policy inheritance
- scope per content type

## Audit logging

Log minimaal deze events:

- `agent_discovered`
- `agent_discovery_unclaimed`
- `site_claimed`
- `heartbeat_received`
- `commands_delivered`
- `command_result_received`
- `auth_failed`

Audit logs moeten machineleesbaar zijn in JSON.

## Beveiligingsregels

De agent moet deze regels expliciet volgen:

- geen SQL string concatenation; alleen prepared statements
- alle input schema-valideren
- geen stack traces of interne fouten in publieke responses
- tokens random genereren met `random_bytes`
- hashes opslaan met veilige hashing
- host validatie op echte URL parse, niet op losse strings
- geen wildcard hostmatching in fase 1
- rate limiting op discovery endpoint
- gebruik transacties bij claim en command status updates

## Seed/test hulpmiddelen

Laat de agent ook simpele ontwikkelhulpmiddelen maken:

- SQL of script om een `pending_site` testrecord aan te maken
- voorbeeld curl-requests voor discovery, heartbeat en command result
- klein README met lokale of server deploy-instructies

## Acceptatiecriteria

Fase 1 is klaar als dit werkt:

1. Er kan een `pending_site` record worden aangemaakt.
2. Een discovery request met matchende host claimt automatisch de site.
3. De response bevat `site_id`, `agent_id`, token en policy.
4. Heartbeat met bearer token werkt en update `last_seen_at`.
5. Commands kunnen voor een site in de database worden gezet.
6. `GET /v1/agents/commands` levert queued commands terug.
7. `POST /v1/agents/command-results` markeert commands als `succeeded` of `failed`.
8. Audit logs worden geschreven voor discovery, claim en command results.

## Wat de agent niet moet doen

- geen frontend of dashboard bouwen tenzij er tijd over is en het expliciet nodig is
- geen media pipeline bouwen
- geen theme/plugin installatie bouwen
- geen WebSocket laag introduceren
- geen generieke execution endpoint maken
- geen complexe auth server bouwen

## Opdrachttekst voor de agent

Gebruik onderstaande instructie letterlijk of bijna letterlijk:

```text
Bouw fase 1 van de centrale PHP control plane voor yourWPsite op dev.yoursitehulp.nl/yourwpsite.

Gebruik PHP 8.2+, MySQL/MariaDB en PDO. Bouw een kleine JSON API zonder zwaar framework, tenzij er al een bestaand framework aanwezig is in de omgeving. Gebruik een nette mappenstructuur met public/, src/, config/, database/migrations/ en storage/logs/.

Implementeer:
- GET /health
- POST /v1/agents/discover
- POST /v1/agents/heartbeat
- GET /v1/agents/commands
- POST /v1/agents/command-results

Maak database-tabellen voor:
- pending_sites
- sites
- site_agents
- commands
- audit_logs

Implementeer auto-claim logica op basis van:
- exacte hostmatch op pending_sites.expected_domain_host
- pending status
- claim window
- geen bestaande active site op dezelfde host

Bij succesvolle claim:
- maak site en site_agent records aan
- zet pending_site op claimed
- ken een eenvoudige JSON policy toe
- geef access_token en refresh_token uit

Gebruik prepared statements, transacties, JSON validatie, veilige token generatie en audit logging. Voeg een README, .env.example en test curl-voorbeelden toe. Bouw geen frontend, media pipeline of plugin/theme installaties in deze fase.
```

## Mijn advies aan de bouwagent

Werk in deze volgorde:

1. project skeleton
2. config + env loader
3. database migration
4. health endpoint
5. discovery endpoint
6. claim service
7. token service
8. heartbeat endpoint
9. commands endpoint
10. command results endpoint
11. README + curl examples

Dat houdt de eerste verticale slice klein en bruikbaar.
