# Remote WordPress Agent Architecture

Dit document beschrijft een veilige architectuur voor een WordPress plugin die een site laat koppelen aan een centrale applicatie, zichzelf kan aanmelden binnen een InstaWP-flow en daarna op afstand beheerd kan worden door een AI-agent of chatagent.

## Korte conclusie

Ja, dit is haalbaar, maar niet veilig als je het opzet als "de centrale server mag willekeurige WordPress-acties of PHP-code uitvoeren".

De veilige route is:

- de plugin fungeert als een **site agent**
- de centrale applicatie fungeert als **control plane**
- alle remote acties lopen via **gesigneerde, gelogde commands**
- de plugin voert alleen **expliciet toegestane capabilities** uit
- de AI praat idealiter met een **MCP-server aan centrale kant**, niet direct met WordPress

Met andere woorden: gebruik MCP als interface voor de AI, maar houd de WordPress plugin zelf op een strakke, beperkte command-API.

## Waarom geen "volledige remote admin"

Een plugin die extern "alles" mag doen is in de praktijk een RCE- en supply-chain-risico.

De grootste gevaren:

- willekeurige plugin/theme installatie vanaf onbetrouwbare bronnen
- SSRF via remote media, template imports of URL-fetches
- privilege-escalatie binnen WordPress
- kwaadaardige of foutieve AI-acties zonder menselijke rem
- tenant-overschrijding als centrale server meerdere sites beheert
- secrets-lekkage vanuit `wp-config.php`, plugins, uploads of private content

Daarom is het beter om "volledig beheer" functioneel te benaderen:

- content beheren
- media beheren
- menu's en navigatie beheren
- theme/template packs uit vertrouwde bron uitrollen
- settings binnen allowlists aanpassen
- plugins/modules uit vertrouwde catalogus installeren

Maar niet:

- arbitrary PHP uitvoeren
- willekeurige ZIP's installeren
- directe database queries vanaf extern
- raw filesystem writes buiten een gecontroleerde deploy-flow

## Aanbevolen hoofdarchitectuur

### 1. Componenten

1. **WordPress Site Agent plugin**
- draait in de WordPress-site
- bouwt alleen outbound verbindingen op
- registreert zich bij de centrale server
- haalt opdrachten op
- voert alleen allowlisted acties uit
- rapporteert status, logs en capability-info terug

2. **Central Control Plane**
- beheert tenants, sites, policies, audit logs en command queues
- bewaart geen onbeperkte site-secrets in plaintext
- valideert elk command tegen policy en site-capabilities
- vertaalt AI-intentie naar concrete site-acties

3. **AI Orchestrator**
- plant taken zoals "bouw landingspagina", "vervang hero", "installeer template"
- werkt niet direct tegen WordPress, maar tegen een formele tool-interface

4. **MCP Server**
- exposeert tools zoals `create_page`, `sync_media`, `install_template_pack`
- praat met de control plane
- laat AI veilig werken zonder dat de AI vrije toegang tot WordPress-internals krijgt

### 2. Verbindingsmodel

Gebruik bij voorkeur een **pull-model** vanuit de plugin:

- plugin doet outbound HTTPS naar centrale server
- plugin long-pollt of gebruikt korte poll-intervals
- optioneel later WebSocket/WebTransport voor snellere interactie

Voordelen:

- geen inbound poorten of publiek aanroepbare WP endpoints nodig
- beter te beheren in InstaWP, shared hosting en strengere firewalls
- eenvoudiger te beveiligen tegen externe scans en brute force

## MCP of niet?

### Advies

Gebruik **MCP aan de centrale kant**, niet als primair protocol tussen server en plugin.

Praktisch:

- **AI <-> MCP server**: ja
- **MCP server <-> control plane**: ja of intern serviceprotocol
- **control plane <-> WordPress plugin**: liever een compacte, eigen command-API

Waarom:

- de plugin heeft een kleine, robuuste runtime nodig
- command-validatie, retries, chunked uploads en WordPress-specifieke foutafhandeling zijn makkelijker in een doelgericht protocol
- je wilt versioning en capability-gating strak beheren

MCP is dus vooral het gereedschap voor de AI-laag, niet per se voor de plugin-transportlaag.

## Veilige onboarding voor InstaWP

De plugin moet na spin-up zichzelf kunnen aanmelden. Doe dat niet met alleen een "ik ben site X" registratie, maar met een **claim flow**.

Als InstaWP geen bruikbaar bootstrap token kan meegeven, is een **preinstalled plugin + outbound claim** een goed alternatief.

## Onboarding zonder bootstrap token

Dit is de variant die het best past als de plugin al in een InstaWP template of snapshot zit en na activatie zelfstandig moet opstarten.

### Doel

- geen handmatige WP-admin stappen voor de eindklant
- geen inbound publiek admin endpoint nodig
- veilige koppeling tussen nieuwe site en centrale control plane
- pas na claim volledige remote sturing inschakelen

### Flow

1. Nieuwe InstaWP-site wordt opgezet met de agent-plugin vooraf geinstalleerd.
2. Bij activatie genereert de plugin lokaal:
- een `agent_local_id` (UUID)
- een asymmetrisch sleutelpaar of gedeeld site secret
- een `site_fingerprint`
3. De plugin doet outbound `POST /v1/agents/discover` naar de centrale server.
4. De centrale server zet de site in status `discovered_unclaimed`.
5. De control plane toont de site in een wachtrij met metadata.
6. Jouw provisioninglaag of operator claimt de site en koppelt hem aan de juiste tenant/workspace.
7. Pas daarna geeft de centrale server een formele agent identity en policy terug.
8. De plugin schakelt dan over van `discovery mode` naar `managed mode`.

### Metadata voor discovery

De plugin mag bij discovery alleen beperkte, niet-gevoelige metadata sturen:

- `agent_local_id`
- `home_url`
- `site_url`
- WordPress versie
- PHP versie
- actieve theme slug
- lijst met beschikbare capabilities
- pluginversie
- optioneel InstaWP metadata die lokaal zichtbaar is

### Site fingerprint

De fingerprint hoeft niet geheim te zijn, maar moet wel helpen duplicaten en vergissingen te herkennen.

Bijvoorbeeld een hash van:

- `home_url`
- `ABSPATH`
- WordPress salts fingerprint
- `agent_local_id`

Niet gebruiken als enige authenticatiemethode, wel als herkennings- en deduplicatiesignaal.

### Belangrijke veiligheidsregel

Een ontdekte maar nog niet geclaimde site mag:

- wel discoveren
- wel heartbeats sturen
- wel read-only inventory rapporteren
- niet zelfstandig destructive of privileged commands uitvoeren

Dus voor claim:

- geen content writes
- geen media imports
- geen plugin/theme installaties
- geen settings mutaties

## Claim-mechanismen

Er zijn drie werkbare varianten. Ik raad variant A aan.

### Variant A: server-side claim op basis van provisioning context

Jouw centrale app weet welke site hij zojuist via InstaWP heeft laten maken.

De claim gebeurt door:

1. control plane creëert intern een `pending_site`
2. plugin discovert zichzelf
3. control plane matcht de discovery op domein, tijdvenster en provisioningrecord
4. site wordt automatisch geclaimd

Voordelen:

- geen handmatige klantactie
- geen token zichtbaar in browser of WordPress
- goed te automatiseren

Nadelen:

- je provisioninglaag moet netjes site-creatieevents registreren

### Variant B: handmatige claimcode

De plugin toont of rapporteert een korte claimcode, bijvoorbeeld `BLUE-RIVER-4821`.

Flow:

1. plugin discovert
2. centrale app toont `unclaimed agent`
3. operator voert claimcode in of bevestigt match
4. site wordt gekoppeld

Voordelen:

- simpel te bouwen
- handig als auto-match niet altijd betrouwbaar is

Nadelen:

- minder automatisch

### Variant C: signed callback via provisioning worker

Als jouw provisioning worker na site-creatie nog acties kan uitvoeren, kan die een claim-endpoint aanroepen met een server-side signature.

Voordelen:

- sterk en netjes geautomatiseerd

Nadelen:

- afhankelijk van extra provisioningstap buiten InstaWP

## Authenticatie en autorisatie

### Niet doen

- vaste bearer token zonder rotatie
- API key in querystring
- één globale sleutel voor alle sites

### Wel doen

- per site een unieke agent identity
- korte access tokens + refresh mechanisme
- request signing met timestamp en nonce
- replay protection
- capability-based authorization

Elke opdracht moet minimaal bevatten:

- `command_id`
- `site_id`
- `issued_at`
- `expires_at`
- `requested_by`
- `capability`
- `payload`
- `signature`

De plugin valideert:

- handtekening
- tenant/site match
- vervaltijd
- of capability lokaal is toegestaan
- of payload aan schema voldoet

## Commandmodel

Gebruik geen generieke "call method" endpoint. Maak een expliciet commandmodel.

### Voorbeelden van veilige capabilities

- `content.create_page`
- `content.update_page`
- `content.create_post`
- `content.delete_post`
- `media.upload_from_control_plane`
- `media.attach_featured_image`
- `menu.upsert`
- `theme.install_trusted_pack`
- `theme.activate_installed_theme`
- `template.apply_page_template`
- `plugin.install_trusted_module`
- `settings.update_allowed`
- `site.export_structure`
- `site.read_public_content_index`

### Voorbeelden van capabilities die standaard uit moeten blijven

- `code.execute_php`
- `db.query_raw`
- `filesystem.write_arbitrary`
- `plugin.install_from_url`
- `theme.install_from_url`
- `admin.impersonate_user`

## Trusted artifact model

Voor themes, templates, plugins en blocks is een **trusted catalog** veiliger dan vrije uploads.

### Aanbevolen aanpak

- centrale server beheert een catalogus van goedgekeurde artifact packs
- elk pack heeft:
  - `artifact_id`
  - versie
  - SHA-256 hash
  - signature
  - compatibiliteitsmetadata
- plugin downloadt alleen packs van vertrouwde origin(s)
- plugin verifieert hash en signature voor installatie

Zo kan de AI wel zeggen "installeer template X", maar niet "voer deze willekeurige ZIP uit".

## Media- en contentstroom

Voor media is server-side push vanuit control plane vaak prettiger dan externe URL-fetches vanaf WordPress.

### Veiliger patroon

1. Centrale server downloadt of genereert media.
2. Centrale server scant/valideert bestandstype en grootte.
3. Centrale server uploadt bestand via een gecontroleerd agent endpoint of via chunked transfer.
4. Plugin maakt attachment aan in WordPress.

Voordelen:

- minder SSRF-oppervlak in WordPress
- betere virus- of MIME-validatie centraal
- betere audit trail

Als de plugin toch externe media-URL's mag ophalen:

- alleen HTTPS
- host allowlist
- blokkade van private IP-ranges
- redirect-hercontrole
- strikte MIME- en size-limits

## Chatagent voor eindklant

De eindklant hoort niet direct op WordPress te zitten, maar ook niet op "ruwe AI-acties".

Zet er een beleidslaag tussen:

1. Klant vraagt in chat: "maak de homepage moderner"
2. AI zet dit om naar een voorstel of plan
3. Control plane vertaalt dat naar commands
4. High-risk acties vereisen extra bevestiging
5. Plugin voert uit en stuurt resultaat terug
6. Chatagent toont samenvatting, diff en eventueel preview

### Risicocategorieen

- **Low risk**: tekst aanpassen, afbeelding vervangen, conceptpagina maken
- **Medium risk**: menu wijzigen, template op pagina toepassen, plugin-module inschakelen
- **High risk**: theme switch, bulk delete, domeininstellingen, SEO-wide rewrites

Voor medium/high risk raad ik approvals of policy gates aan.

## Multi-tenant security

Als de centrale app veel websites beheert, is tenant-isolatie cruciaal.

### Minimale eisen

- elk site-record heeft eigen keys/secrets
- elke command queue is logisch tenant-gescheiden
- artifact permissions zijn tenant-aware
- audit logs bevatten actor, bron en diff
- supportmedewerkers krijgen scoped access

## Audit, observability en rollback

Een AI-beheerplatform zonder audit trail wordt snel onbruikbaar.

### Log per command

- wie of wat vroeg het aan
- welk model of welke actor initieerde het
- exacte payload
- WordPress object IDs voor en na
- succes/foutstatus
- links naar aangeraakte media of posts

### Rollback

Voor content en settings:

- bewaar pre-change snapshots
- gebruik revisions waar mogelijk
- houd een `last_known_good` vast voor templates/homepage configuratie

Voor theme/template deploys:

- gebruik versiebeheer van artifact packs
- ondersteun rollback naar vorige goedgekeurde versie

## Pluginopbouw

Een nette pluginstructuur zou ongeveer dit kunnen zijn:

```text
remote-site-agent/
  remote-site-agent.php
  includes/
    class-rsa-bootstrap.php
    class-rsa-auth.php
    class-rsa-command-bus.php
    class-rsa-capability-registry.php
    class-rsa-content-service.php
    class-rsa-media-service.php
    class-rsa-theme-service.php
    class-rsa-plugin-service.php
    class-rsa-settings-service.php
    class-rsa-audit-log.php
    class-rsa-policy.php
    class-rsa-http.php
```

### Kernregels in de plugin

- geen anonieme publieke endpoints
- alle remote input door schema-validatie
- capabilities standaard `deny`
- geen execution primitives
- alle downloads door één hardened HTTP-laag
- alle writes centraal loggen

## API-schets

### Plugin -> control plane

- `POST /v1/agents/discover`
- `POST /v1/agents/register`
- `POST /v1/agents/refresh`
- `POST /v1/agents/heartbeat`
- `POST /v1/agents/command-results`
- `GET /v1/agents/commands?limit=10`

### Discovery request voorbeeld

```json
{
  "agent_local_id": "f90d6fa8-c6d8-4e76-a373-28b1cc0b2c5f",
  "home_url": "https://example.instawp.xyz",
  "site_url": "https://example.instawp.xyz",
  "wp_version": "6.8.1",
  "php_version": "8.2.17",
  "plugin_version": "0.1.0",
  "theme_slug": "twentytwentyfive",
  "capabilities": [
    "site.read_public_content_index",
    "site.export_structure"
  ],
  "site_fingerprint": "sha256:1d4b0b..."
}
```

### Discovery response voorbeeld

```json
{
  "status": "discovered_unclaimed",
  "poll_after_seconds": 30,
  "discovery_id": "disc_01jxyz"
}
```

### Claim completion response voorbeeld

```json
{
  "status": "claimed",
  "site_id": "site_123",
  "agent_id": "agent_456",
  "access_token": "short-lived-token",
  "refresh_token": "rotation-secret",
  "policy": {
    "enabled_capabilities": [
      "content.create_page",
      "content.update_page",
      "media.upload_from_control_plane",
      "menu.upsert"
    ]
  }
}
```

## Aanbevolen MVP

Begin niet met "complete remote WordPress admin". Bouw in fases.

### Fase 1

- self-registration
- heartbeat / command polling
- content create/update voor pages/posts
- media upload
- menu update
- audit log
- tenant/site auth

### Fase 2

- trusted template packs
- page composition met blocks/patterns
- site structure export
- beperkte settings sync

### Fase 3

- trusted plugin modules
- theme lifecycle management
- preview environments
- approval workflows
- rollback UX in chat

## Mijn concrete advies

Als we dit project echt gaan bouwen, zou ik **niet** starten met een generieke MCP-plugin in WordPress.

Ik zou starten met:

1. een nieuwe plugin op basis van een kleine, hardened site-agent
2. een compacte control-plane API met self-registration en signed commands
3. een capability registry voor alleen content, media en menu's
4. een MCP-server bovenop de control plane zodat AI-agents ermee kunnen werken

Dat geeft je:

- betere veiligheid
- betere auditability
- betere AI-tooling
- minder WordPress-specifieke attack surface

Voor de gekozen onboardingvariant staat er nu ook een concreet MVP-spec in:

- `docs/VARIANT_A_ONBOARDING_MVP.md`

## Goede eerste technische scope

Voor een eerste werkende versie zou ik deze features kiezen:

- auto-register bij activatie met discovery mode
- poller voor remote commands
- create/update/delete van pages en posts
- media upload via gecontroleerde binary transfer
- menu sync
- read-only site inventory voor AI-context

Alles daarbovenop zou ik pas toevoegen nadat auth, signing, audit en rollback solide staan.
