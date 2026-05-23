# yourWPsite WordPress Plugin

Deze map bevat de eerste MVP-plugin voor de `yourWPsite` site-agent.

## Inhoud

- `yourwpsite-agent/`
  - Installeer deze map als WordPress plugin

## Fase 1 scope

- lokale agent identity genereren
- discovery naar de centrale control plane
- managed status en tokens opslaan
- heartbeat sturen
- commands pollen
- read-only site index exporteren
- pages aanmaken en bijwerken

## Fase 2 readiness

- adminstatus toont nu ook de laatste response body excerpt
- recente commanduitvoeringen worden lokaal bijgehouden
- discovery, heartbeat, polling en command-result flows accepteren geldige `2xx` responses
- plugin volgt nu het vereenvoudigde token-based control plane contract zonder verplichte `site_id`/`agent_id` requestvelden
- snapshot clones regenereren automatisch hun agent identity zodra de site-URL afwijkt van de snapshot-basis
- nieuwe sites proberen discovery nu ook actief bij de eerste echte request, zodat self-registration niet alleen van WP-Cron afhangt
- legacy snapshots zonder bootstrap-URL metadata regenereren nu ook hun agent identity, zodat oude template-builds niet dezelfde `agent_local_id` blijven erven

## Fase 3 capabilities

- `site.export_structure`
- `content.create_post`
- `content.update_post`
- `content.trash_page`
- `content.trash_post`
- `media.upload_from_control_plane`
- `menu.upsert`
- `media.attach_featured_image`

## Verwachte media-download route

Voor `media.upload_from_control_plane` verwacht de plugin dat de control plane de binary aanbiedt op:

```text
GET /v1/agents/media-download?upload_token=...
Authorization: Bearer <access_token>
```

Verwacht gedrag:

- alleen short-lived upload tokens accepteren
- alleen gecontroleerde image mime types toestaan
- response body is de ruwe binary
- `200` op succes, anders een nette foutstatus

## Verwachte control plane basis-URL

Standaard:

```text
https://dev.yoursitehulp.nl/yourwpsite
```

Pas dit aan in `Instellingen -> yourWPsite Agent` als nodig.
