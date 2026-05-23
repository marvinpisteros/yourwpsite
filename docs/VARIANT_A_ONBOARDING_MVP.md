# Variant A Onboarding MVP

Dit document werkt **Variant A: server-side claim op basis van provisioning context** uit tot een concreet MVP voor een centrale control plane en een WordPress site-agent plugin.

## Doel van dit MVP

Een nieuwe InstaWP-site:

- bevat de agent-plugin al in template of snapshot
- meldt zichzelf outbound aan bij de control plane
- wordt server-side gematcht aan het provisioningrecord
- krijgt daarna pas write-capabilities

De eerste functionele scope blijft bewust klein:

- discovery
- auto-claim
- heartbeat
- command polling
- `content.create_page`
- `content.update_page`
- `site.read_public_content_index`

## Hoofdflow

1. Jouw backend maakt via InstaWP een site aan.
2. De backend slaat direct een `pending_site` record op.
3. De WordPress plugin start op en doet een discovery call.
4. De control plane matcht die discovery met het juiste `pending_site`.
5. De control plane markeert de site als `claimed`.
6. De plugin ontvangt agent-credentials en policy.
7. De plugin gaat daarna periodiek heartbeats en command polling doen.

## Pending Site Record

Bij site-creatie moet jouw backend minimaal dit bewaren:

```json
{
  "pending_site_id": "pend_01jxyz",
  "tenant_id": "tenant_123",
  "workspace_id": "ws_123",
  "instawp_site_id": "iwp_456",
  "expected_home_url": "https://demo-abc.instawp.xyz",
  "expected_domain_host": "demo-abc.instawp.xyz",
  "template_slug": "sales-template-v1",
  "created_at": "2026-05-23T10:00:00Z",
  "claim_window_ends_at": "2026-05-23T10:20:00Z",
  "status": "pending"
}
```

## Matchregels voor auto-claim

De control plane mag een discovery alleen automatisch claimen als alle relevante checks slagen.

### Minimale checks

- `home_url` host matcht exact op `expected_domain_host`
- discovery valt binnen het claim-window
- er is nog geen andere geclaimde site met dezelfde host
- pluginversie is minimaal ondersteund
- tenant/workspace context bestaat nog

### Sterkere checks

- InstaWP site-id vergelijken als die ergens beschikbaar is
- eerste discovery IP of ASN vergelijken met verwachte hostingcontext
- extra vergelijking op template of theme fingerprint

### Bij twijfel

Niet claimen. Zet de site in `discovered_needs_review`.

## Statusmodel

Gebruik een simpel en expliciet statusmodel:

- `pending`
- `discovered_unclaimed`
- `discovered_needs_review`
- `claimed_active`
- `claimed_quarantined`
- `revoked`

## Plugin lifecycle

### 1. Activation bootstrap

Bij activatie doet de plugin:

- `agent_local_id` genereren als UUID
- lokaal secret genereren
- pluginopties initialiseren
- eerstvolgende discovery taak schedulen

### 2. Discovery mode

Zolang de site niet geclaimd is:

- `POST /v1/agents/discover`
- beperkt inventory rapporteren
- geen write commands uitvoeren
- backoff gebruiken bij fouten

### 3. Managed mode

Na claim:

- access token gebruiken
- refresh of rotation mechanisme gebruiken
- heartbeats sturen
- commands ophalen en uitvoeren
- resultaten terugrapporteren

## API contract

### `POST /v1/agents/discover`

Request:

```json
{
  "agent_local_id": "e8dff93d-0df5-42e3-9b1a-7f4f758f1d1b",
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

Response when still waiting:

```json
{
  "status": "discovered_unclaimed",
  "discovery_id": "disc_01jxyz",
  "poll_after_seconds": 30
}
```

Response when auto-claim succeeds:

```json
{
  "status": "claimed",
  "site_id": "site_123",
  "agent_id": "agent_456",
  "access_token": "short-lived-token",
  "refresh_token": "rotation-secret",
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

Request:

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

### `GET /v1/agents/commands?limit=10`

Response:

```json
{
  "commands": [
    {
      "command_id": "cmd_01jxyz",
      "capability": "content.update_page",
      "issued_at": "2026-05-23T10:11:00Z",
      "expires_at": "2026-05-23T10:16:00Z",
      "payload": {
        "page_ref": {
          "id": 42
        },
        "title": "Nieuwe homepage",
        "content_html": "<h1>Welkom</h1><p>Nieuwe intro</p>",
        "status": "draft"
      },
      "signature": "base64-signature"
    }
  ]
}
```

### `POST /v1/agents/command-results`

Request:

```json
{
  "site_id": "site_123",
  "agent_id": "agent_456",
  "command_id": "cmd_01jxyz",
  "status": "succeeded",
  "started_at": "2026-05-23T10:11:02Z",
  "finished_at": "2026-05-23T10:11:03Z",
  "result": {
    "post_id": 42,
    "revision_id": 1001,
    "preview_url": "https://demo-abc.instawp.xyz/?p=42&preview=true"
  }
}
```

## Eerste capabilities

### `site.read_public_content_index`

Read-only capability voor AI-context:

- lijst publieke pages/posts
- titels
- slugs
- post IDs
- modified timestamps

Nog niet:

- private meta
- pluginlijsten
- gebruikers
- secrets

### `content.create_page`

MVP payload:

- `title`
- `slug`
- `content_html`
- `status`

### `content.update_page`

MVP payload:

- `page_ref.id`
- optioneel `title`
- optioneel `slug`
- optioneel `content_html`
- optioneel `status`

### Guardrails

- alleen post type `page`
- alleen statuses uit allowlist
- content door `wp_kses_post`
- revisions laten vastleggen
- optioneel alleen paginas met plugin-managed meta marker aanpassen

## Aanbevolen data model control plane

### `sites`

```text
id
tenant_id
workspace_id
instawp_site_id
home_url
host
status
current_policy_id
claimed_at
last_seen_at
```

### `site_agents`

```text
id
site_id
agent_local_id
public_key_or_secret_ref
plugin_version
last_discovery_at
last_heartbeat_at
revoked_at
```

### `pending_sites`

```text
id
tenant_id
workspace_id
instawp_site_id
expected_home_url
expected_domain_host
claim_window_ends_at
status
```

### `commands`

```text
id
site_id
capability
payload_json
status
issued_by
issued_at
expires_at
result_json
```

## Beveiligingsregels voor MVP

Zelfs in MVP zou ik deze niet overslaan:

- alleen HTTPS naar control plane
- korte token lifetime, bijvoorbeeld 15 minuten
- signed commands met nonce/timestamp
- command expiry verplicht
- deny-by-default capability registry
- audit log voor elke write
- idempotency op `command_id`
- rate limiting op discovery
- quarantine status bij verdachte mismatch

## Pluginstructuur voor MVP

```text
remote-site-agent/
  remote-site-agent.php
  includes/
    class-rsa-settings.php
    class-rsa-bootstrap.php
    class-rsa-discovery.php
    class-rsa-auth.php
    class-rsa-heartbeat.php
    class-rsa-command-poller.php
    class-rsa-capability-registry.php
    class-rsa-content-page-handler.php
    class-rsa-audit-log.php
```

## Aanbevolen implementatievolgorde

1. Control plane endpoint `discover`
2. Plugin discovery mode
3. `pending_sites` en auto-claim matching
4. Managed credentials issuance
5. Heartbeat + command polling
6. `site.read_public_content_index`
7. `content.create_page`
8. `content.update_page`
9. Audit log + retries + quarantine

## Wat ik als eerste zou bouwen

Als we morgen starten, zou ik dit eerst opleveren:

- plugin activeert en doet discovery
- backend claimt automatisch op host + tijdvenster
- backend geeft policy terug
- plugin toont lokaal status `unclaimed` of `managed`
- AI kan via control plane een testpagina laten aanmaken

Dat is klein genoeg om snel te valideren, maar groot genoeg om de hele keten van provisioning tot AI-actie te bewijzen.
