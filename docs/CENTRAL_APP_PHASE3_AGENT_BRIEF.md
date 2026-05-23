# Central App Phase 3 Agent Brief

Dit document is bedoeld als **bouwinstructie voor een code-agent** die fase 3 van de centrale PHP-applicatie voor `yourWPsite` moet bouwen op `dev.yoursitehulp.nl/yourwpsite`.

Fase 2 heeft bewezen dat de command-loop end-to-end werkt:

- site provisioning via InstaWP template
- self-registration van de WordPress plugin
- command polling
- command execution
- result reporting

Fase 3 gaat niet over "meer van hetzelfde", maar over de eerste set **bruikbare remote beheerfuncties** voor echte websites.

## Doel van fase 3

Breid de control plane uit zodat `yourWPsite` echte websitebeheer-acties veilig kan uitsturen en volgen.

De focus ligt op:

- contentbeheer
- mediabeheer
- navigatie/menu's
- beter site-inzicht
- operator-UX voor commanduitgifte en opvolging

Nog niet bouwen:

- vrije plugininstallatie vanaf URL
- theme switching zonder trusted catalog
- arbitrary code execution
- chatinterface voor eindgebruikers
- volledig automatisch AI-beheer zonder operatorcontrole

## Functionele scope

Bouw fase 3 in deze volgorde.

### 1. Content capabilities uitbreiden

Voeg command support toe voor:

- `content.create_post`
- `content.update_post`
- `content.trash_page`
- `content.trash_post`
- optioneel `content.publish_page`
- optioneel `content.publish_post`

Belangrijk:

- gebruik dezelfde command queue infrastructuur als fase 2
- houd payloads klein en expliciet
- geen bulkacties in fase 3

### 2. Media pipeline toevoegen

Voeg veilige support toe voor:

- `media.upload_from_control_plane`
- `media.attach_featured_image`

Doel:

- de control plane kan een afbeelding uploaden naar een site
- de plugin maakt daar een WordPress attachment van
- de control plane kan die attachment daarna koppelen als featured image

Belangrijk:

- begin met **server-to-plugin upload**, niet met vrije externe image fetches
- de control plane blijft verantwoordelijk voor validatie van type, grootte en herkomst
- de plugin mag alleen gecontroleerde uploads verwerken

### 3. Menu / navigatie support

Voeg support toe voor:

- `menu.upsert`

Doel:

- de control plane kan menu-items maken of bijwerken
- eerste versie hoeft alleen simpele pagina-links te ondersteunen

Nog niet:

- mega menus
- custom walker logica
- theme-specifieke menu magicals

### 4. Site-inzicht uitbreiden

Voeg read-capabilities toe voor:

- `site.export_structure`

Doel:

- beter inzicht voor operators en latere AI-aansturing
- pages, posts, menu's en basisstructuur terughalen

Nog niet:

- private meta dumps
- gebruikersbeheer
- plugininventaris als schrijvende capability

### 5. Operator test- en beheerflow

Breid de interne `yourWPsite` test/adminlaag uit zodat operators:

- een site kiezen
- een capability kiezen
- een payload invullen
- het command uitsturen
- live de status en resultaten zien

Dit hoeft geen eindgebruikers-UI te zijn. Een eenvoudige interne operatorpagina is voldoende.

## Technische uitgangspunten

- Blijf in **PHP 8.2+**
- Gebruik de bestaande command queue en token-based agent-auth
- Gebruik **PDO** en prepared statements
- Houd alles JSON-first
- Gebruik UTC timestamps
- Maak changes backward-compatible waar mogelijk

## Nieuwe capabilities en payloadcontracten

Begin met heldere payloads. Geen generieke "do anything" payloads.

### `content.create_post`

Payload:

```json
{
  "title": "Nieuwe blogpost",
  "slug": "nieuwe-blogpost",
  "content_html": "<h1>Nieuwe blogpost</h1><p>Inhoud</p>",
  "status": "draft"
}
```

### `content.update_post`

Payload:

```json
{
  "post_ref": {
    "id": 123
  },
  "title": "Bijgewerkte blogpost",
  "content_html": "<p>Nieuwe inhoud</p>",
  "status": "draft"
}
```

### `content.trash_page`

Payload:

```json
{
  "page_ref": {
    "id": 42
  }
}
```

### `content.trash_post`

Payload:

```json
{
  "post_ref": {
    "id": 123
  }
}
```

### `media.upload_from_control_plane`

Payload:

```json
{
  "filename": "hero-image.jpg",
  "mime_type": "image/jpeg",
  "upload_token": "short-lived-upload-token",
  "purpose": "content_image"
}
```

Opmerking:

- de binary upload zelf mag via een aparte uploadroute of pre-signed/proxied flow lopen
- houd command en file transfer logisch gescheiden

### `media.attach_featured_image`

Payload:

```json
{
  "post_ref": {
    "id": 42
  },
  "attachment_id": 555
}
```

### `menu.upsert`

Payload eerste versie:

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

### `site.export_structure`

Result shape ongeveer:

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

## Applicatie-aanpassingen

### 1. Command builder uitbreiden

De control plane moet interne builders of validators krijgen per capability.

Minimaal:

- `ContentCommandBuilder`
- `MediaCommandBuilder`
- `MenuCommandBuilder`
- `SiteReadCommandBuilder`

Elke builder moet:

- payload schema controleren
- capability-specifieke validatie doen
- nette foutmeldingen geven aan operators

### 2. Site capability matrix

Voeg in `yourWPsite` een eenvoudige capability matrix per site toe.

Doel:

- operator ziet wat een site/pluginversie aankan
- control plane kan ongeldige commands vroeg blokkeren

Voor fase 3 mag dit simpel zijn:

- capabilities uit discovery/policy tonen
- geen complex role systeem nodig

### 3. Command test UI

Bouw een interne operatorpagina met minimaal:

- site selector
- capability selector
- JSON payload textarea of capability-specifiek formulier
- knop `Queue command`
- tabel met recente commands
- status + result + error

Nog niet nodig:

- design polish
- klantrollen
- chat UX

### 4. Media upload route

Voeg een veilige media-uploadflow toe in de applicatie.

Aanpak:

1. operator uploadt bestand naar control plane
2. control plane valideert bestand
3. control plane slaat tijdelijk op
4. control plane maakt `upload_token`
5. plugin haalt of ontvangt het bestand via gecontroleerde route

Belangrijk:

- korte token-lifetime
- file size limieten
- allowlist mime types
- audit trail voor uploads

Verwacht plugin-contract voor deze fase:

```text
GET /v1/agents/media-download?upload_token=...
Authorization: Bearer <access_token>
```

Response:

- succes: ruwe binary body met `200`
- fout: nette `4xx` of `5xx`

## Nieuwe database/opslagbehoeften

Je hoeft niet alles in 1 migratie te doen, maar hou rekening met:

### `commands`

Mogelijke uitbreidingen:

- `capability_group`
- `operator_note`
- `target_object_id`

### `command_attachments` of `media_uploads`

Voor mediaflow:

- `id`
- `site_id`
- `filename`
- `mime_type`
- `size_bytes`
- `storage_path`
- `upload_token_hash`
- `expires_at`
- `created_at`

### `site_capabilities_cache`

Optioneel:

- `site_id`
- `capabilities_json`
- `updated_at`

## Beveiligingsregels voor fase 3

Deze regels zijn verplicht.

- geen arbitrary file upload naar plugin zonder tijdelijke token
- geen remote plugin/theme installatie in deze fase
- geen vrije URL-fetch voor media als primary flow
- elke write capability moet audit logging krijgen
- expiratie op upload tokens en commands
- payloadvalidatie per capability
- operator-only test UI
- command result errors niet wegslikken

## Acceptatiecriteria

Fase 3 is geslaagd als dit werkt:

1. `content.create_post` werkt end-to-end
2. `content.update_post` werkt end-to-end
3. `content.trash_page` of `content.trash_post` werkt end-to-end
4. `site.export_structure` geeft bruikbare structuurdata terug
5. `menu.upsert` werkt voor een simpel primary menu
6. `media.upload_from_control_plane` kan een afbeelding veilig op een site krijgen
7. `media.attach_featured_image` kan die afbeelding aan een post/page koppelen
8. operators kunnen dit via een interne testpagina zelf uitsturen en opvolgen

## Wat de agent niet moet bouwen in fase 3

- geen AI-chatlaag
- geen klant-facing interface
- geen theme marketplace
- geen plugin store
- geen arbitrary code execution
- geen bulk destructive workflows

## Opdrachttekst voor de agent

Gebruik dit als werkopdracht:

```text
Bouw fase 3 van de yourWPsite control plane op basis van de bestaande fase-2 command-loop.

Breid de applicatie uit met capability-specifieke command support voor:
- content.create_post
- content.update_post
- content.trash_page
- content.trash_post
- media.upload_from_control_plane
- media.attach_featured_image
- menu.upsert
- site.export_structure

Gebruik de bestaande token-based agent-auth en command queue. Bouw capability validators/builders per commandtype. Voeg een eenvoudige interne operator testpagina toe waarmee een site kan worden gekozen, een capability kan worden geselecteerd, payload kan worden ingevoerd, en commandresultaten kunnen worden bekeken.

Voor media: bouw een veilige control-plane uploadflow met tijdelijke upload tokens, centrale validatie en audit logging. Gebruik geen arbitrary remote file fetches als primaire route.

Bouw nog geen AI-chatinterface, plugin/theme marketplace, arbitrary code execution of bulk destructive tools.
```

## Mijn advies voor implementatievolgorde

1. `content.create_post`
2. `content.update_post`
3. `site.export_structure`
4. interne operator test UI
5. `content.trash_page` / `content.trash_post`
6. media upload basis
7. featured image koppeling
8. `menu.upsert`

Dat geeft snel zichtbare productwaarde zonder de risico's van zwaardere site-ingrepen.
