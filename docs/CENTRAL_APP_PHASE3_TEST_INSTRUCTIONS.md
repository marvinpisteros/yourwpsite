# Central App Phase 3 Test Instructions

Dit document is bedoeld voor de andere agent die de `yourWPsite` applicatiekant bouwt en test.

Doel van deze testronde:

- bevestigen dat fase 3 end-to-end werkt
- bewijzen dat de control plane en de WordPress plugin exact hetzelfde contract spreken
- regressies uit fase 2 vermijden

## Uitgangspunten

- Test tegen een **nieuwe site** uit template `yourwpsite`, zodat je zeker weet dat pluginversie `0.2.0` actief is.
- De Germany-basissite `https://ywd-sgid4.start.hosting.nl` is al bijgewerkt naar plugin `0.2.0`.
- Nieuwe testsites moeten dus uit een **gesyncte snapshot/template** komen.
- De plugin ondersteunt nu:
  - `site.read_public_content_index`
  - `site.export_structure`
  - `content.create_page`
  - `content.update_page`
  - `content.create_post`
  - `content.update_post`
  - `content.trash_page`
  - `content.trash_post`
  - `media.upload_from_control_plane`
  - `menu.upsert`
  - `media.attach_featured_image`

## Wat jij eerst moet bevestigen

Voordat je de inhoudelijke tests doet, bevestig eerst deze punten:

1. De testsite is nieuw aangemaakt vanuit template `yourwpsite`
2. De site heeft een **eigen** `agent_local_id`
3. De site is succesvol geclaimd en staat in `managed` mode
4. De policy die de plugin ontvangt bevat de fase-3 capabilities die jij wilt testen

Rapporteer minimaal terug:

- site URL
- `site_id`
- `agent_id`
- `agent_local_id`
- pluginversie zoals zichtbaar in WordPress

## Contract dat de plugin nu verwacht

### 1. Command poll

```http
GET /v1/agents/commands
Authorization: Bearer <access_token>
```

Response:

```json
{
  "commands": [
    {
      "id": "cmd_123",
      "capability": "site.export_structure",
      "payload": {},
      "issued_at": "2026-05-23T14:00:00Z",
      "expires_at": "2026-05-23T14:10:00Z"
    }
  ]
}
```

Belangrijk:

- de plugin accepteert `id` en ook `command_id`
- Bearer token bepaalt de identiteit

### 2. Command result

```http
POST /v1/agents/command-results
Authorization: Bearer <access_token>
```

Succesvoorbeeld:

```json
{
  "command_id": "cmd_123",
  "status": "succeeded",
  "started_at": "2026-05-23T14:01:00Z",
  "finished_at": "2026-05-23T14:01:01Z",
  "result": {
    "count": 2
  }
}
```

Foutvoorbeeld:

```json
{
  "command_id": "cmd_123",
  "status": "failed",
  "started_at": "2026-05-23T14:01:00Z",
  "finished_at": "2026-05-23T14:01:01Z",
  "error": {
    "message": "Target post not found."
  }
}
```

### 3. Media download route

Voor `media.upload_from_control_plane` verwacht de plugin:

```http
GET /v1/agents/media-download?upload_token=...
Authorization: Bearer <access_token>
```

Verwachting:

- response body is de ruwe binary
- alleen short-lived upload tokens accepteren
- alleen gecontroleerde image types serveren
- `200` op succes
- `4xx` of `5xx` op fout

## Testvolgorde

Voer de tests in deze volgorde uit.

### Test 1. Regressiecheck fase 2

Doe eerst een snelle sanity-test dat de oude command-loop nog werkt:

1. `site.read_public_content_index`
2. `content.create_page`
3. `content.update_page`

Controleer:

- command gaat van `queued` naar `delivered`
- result wordt teruggepost
- status eindigt op `succeeded`

### Test 2. `site.export_structure`

Maak een command aan:

```json
{
  "capability": "site.export_structure",
  "payload": {}
}
```

Verwacht resultaat:

```json
{
  "pages": [],
  "posts": [],
  "menus": [],
  "counts": {
    "pages": 0,
    "posts": 0,
    "menus": 0
  }
}
```

Controleer:

- `pages`, `posts` en `menus` zijn arrays
- `counts` klopt met de lengte van de arrays
- items bevatten bruikbare IDs, titels, statussen en URL’s

### Test 3. `content.create_post`

Payload:

```json
{
  "title": "Fase 3 testpost",
  "slug": "fase-3-testpost",
  "content_html": "<h1>Fase 3 testpost</h1><p>Aangemaakt via yourWPsite.</p>",
  "status": "draft"
}
```

Controleer:

- WordPress maakt echt een post aan
- result bevat `post_id`
- result bevat `post_type: post`

### Test 4. `content.update_post`

Gebruik het `post_id` uit test 3.

Payload:

```json
{
  "post_ref": {
    "id": 123
  },
  "title": "Fase 3 testpost bijgewerkt",
  "content_html": "<p>Bijgewerkt via yourWPsite.</p>",
  "status": "draft"
}
```

Controleer:

- post is zichtbaar bijgewerkt in WordPress
- result bevat `post_id`
- result bevat `revision_id`

### Test 5. `content.trash_post`

Gebruik opnieuw hetzelfde `post_id`.

Payload:

```json
{
  "post_ref": {
    "id": 123
  }
}
```

Controleer:

- post gaat naar prullenbak
- result bevat:
  - `post_id`
  - `post_type`
  - `previous_status`
  - `current_status: "trash"`

### Test 6. `content.trash_page`

Maak eerst een testpagina aan als dat nodig is, en stuur daarna:

```json
{
  "page_ref": {
    "id": 42
  }
}
```

Controleer:

- pagina gaat naar trash
- result shape is gelijk aan `content.trash_post`

### Test 7. `menu.upsert`

Voor deze test heb je minimaal 2 bestaande pagina’s nodig.

Payload:

```json
{
  "menu_location": "primary",
  "items": [
    {
      "type": "page",
      "object_id": 42,
      "title": "Home",
      "position": 1
    },
    {
      "type": "page",
      "object_id": 43,
      "title": "Contact",
      "position": 2
    }
  ]
}
```

Controleer:

- menu wordt aangemaakt of hergebruikt op de opgegeven locatie
- oude items worden vervangen door de nieuwe set
- result bevat:
  - `menu_id`
  - `menu_location`
  - `items[]` met `menu_item_id`, `object_id`, `title`

Let op:

- fase 3 ondersteunt alleen `type: "page"`
- stuur expres ook 1 fouttest met een niet-bestaande pagina

Verwachte fout:

```json
{
  "error": {
    "message": "Menu item page not found: ..."
  }
}
```

### Test 8. `media.upload_from_control_plane`

Test eerst de uploadroute zelf.

Aanpak:

1. upload een kleine testafbeelding naar de control plane
2. laat de control plane een short-lived `upload_token` genereren
3. verstuur daarna het command

Payload:

```json
{
  "filename": "fase3-hero.jpg",
  "mime_type": "image/jpeg",
  "upload_token": "short-lived-token",
  "purpose": "content_image"
}
```

Controleer:

- de plugin haalt de binary op via `/v1/agents/media-download`
- WordPress maakt een attachment aan
- result bevat:
  - `attachment_id`
  - `filename`
  - `mime_type`
  - `purpose`
  - `url`

Stuur daarna ook minstens 2 fouttests:

1. verlopen of ongeldige `upload_token`
2. unsupported `mime_type`

Verwachte uitkomst:

- command eindigt op `failed`
- `error.message` is opgeslagen

### Test 9. `media.attach_featured_image`

Gebruik:

- een bestaand `post_id` of `page_id`
- het `attachment_id` uit test 8

Payload:

```json
{
  "post_ref": {
    "id": 42
  },
  "attachment_id": 555
}
```

Controleer:

- featured image staat echt op het object in WordPress
- result bevat:
  - `post_id`
  - `attachment_id`
  - `featured_image_url`

## Verplichte fouttests

Doe minimaal deze foutscenario’s:

1. `content.update_post` met niet-bestaand `post_ref.id`
2. `content.trash_page` met niet-bestaand `page_ref.id`
3. `menu.upsert` met niet-bestaand `object_id`
4. `media.upload_from_control_plane` met verlopen `upload_token`
5. `media.attach_featured_image` met niet-bestaand `attachment_id`

Controleer steeds:

- result wordt als `failed` teruggestuurd
- `error.message` is bruikbaar en concreet
- command blijft niet hangen in `delivered`

## Wat jij moet terugrapporteren

Lever na je testronde minimaal dit terug:

1. Welke testsite je gebruikte
2. Welke pluginversie op die site stond
3. Welke capabilities geslaagd zijn
4. Welke capabilities nog falen
5. De exacte request/response-vorm van de foutgevallen
6. Of `/v1/agents/media-download` al correct werkt
7. Of er contractafwijkingen zijn tussen applicatie en plugin

## Klaar = pas als dit bewezen is

Fase 3 is pas “klaar voor vervolg” als:

- alle regressietests van fase 2 nog slagen
- `site.export_structure` werkt
- post create/update/trash werkt
- page trash werkt
- menu upsert werkt voor simpele page-links
- media upload werkt met upload token
- featured image koppelen werkt
- foutafhandeling betrouwbaar blijft
