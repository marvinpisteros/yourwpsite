# Central App Phase 4 Agent Brief

Dit document is bedoeld als **bouwinstructie voor een code-agent** die fase 4 van de centrale PHP-applicatie voor `yourWPsite` bouwt op `dev.yoursitehulp.nl/yourwpsite`.

Fase 3 heeft nu bewezen dat de WordPress site-agent betrouwbaar kan:

- content aanmaken en bijwerken
- posts/pages naar trash verplaatsen
- media uploaden
- featured images koppelen
- menu’s bouwen
- site-structuur teruggeven
- homepage instellen

De volgende stap is dus niet “meer losse commands”, maar:

**van scrape/build-input naar een uitvoerbaar siteplan en automatische site-opbouw.**

## Doel van fase 4

Bouw in de centrale applicatie een eerste **site composer pipeline** die:

1. een bron zoals `nova_build_input` inleest
2. daar een gestandaardiseerde `build_spec` van maakt
3. een uitvoerbaar `build_run` plan opstelt
4. dat plan stap voor stap naar de WordPress site-agent uitvoert
5. de voortgang, fouten en resultaten inzichtelijk maakt

Dit is de eerste fase waarin `yourWPsite` niet alleen beheeracties doet, maar ook echt een **volledige website kan opzetten**.

## Wat fase 4 expliciet wel is

- build spec normalisatie
- composer/orchestrator logica
- execution plan per site
- statusbewaking per bouwstap
- eerste review-/previewflow voor operators
- focus op concrete builds zoals de `blander.nl` testsite

## Wat fase 4 expliciet nog niet is

- eindgebruikerschat
- vrije AI-autonomie zonder operatorcontrole
- generieke theme/plugin marketplace
- willekeurige codegeneratie direct in WordPress
- volautomatische redesign van elk willekeurig site-type zonder patroonlaag

## Kernidee

De centrale applicatie krijgt nu 3 lagen:

1. **Source Input**
   - `nova_prompt`
   - `nova_build_input`
   - `nova_page_blueprint`
   - `nova_images_classified`

2. **Build Spec**
   - een gestandaardiseerd JSON-plan voor de te bouwen site

3. **Execution Plan**
   - de concrete command-reeks die naar de plugin gaat

De AI werkt dus niet direct op WordPress, maar op de **Build Spec**.

## Te bouwen onderdelen

### 1. Build Spec model

Definieer een machineleesbaar JSON-formaat, bijvoorbeeld `build_spec`.

Minimale velden:

```json
{
  "site": {
    "title": "",
    "language": "nl",
    "tagline": "",
    "business_type": "",
    "contact": {},
    "branding": {}
  },
  "pages": [],
  "menu": [],
  "media": [],
  "homepage": {},
  "settings": {}
}
```

### 2. Page model

Elke pagina in `build_spec.pages[]` moet minimaal hebben:

```json
{
  "slug": "contact",
  "title": "Contact",
  "page_type": "contact",
  "status": "draft",
  "sections": [],
  "featured_image_ref": null,
  "source_ref": {}
}
```

### 3. Section model

Gebruik een eenvoudige maar expliciete sectievorm.

Voorbeeld:

```json
{
  "type": "rich_text",
  "heading": "Contact",
  "body_html": "<p>...</p>"
}
```

Ondersteun in deze fase minimaal:

- `hero`
- `rich_text`
- `bullet_list`
- `cta`
- `image_grid`
- `service_cards`
- `contact_details`

Nog niet:

- complexe nested builders
- willekeurige Gutenberg block-JSON

### 4. Media model

Voorbeeld:

```json
{
  "source_url": "https://...",
  "filename": "hero.jpg",
  "mime_type": "image/jpeg",
  "role": "hero",
  "label": "Hero afbeelding",
  "target_usage": [
    "page:home:featured_image"
  ]
}
```

### 5. Menu model

Voorbeeld:

```json
{
  "location": "primary",
  "items": [
    { "page_slug": "home", "title": "Home", "position": 1 },
    { "page_slug": "contact", "title": "Contact", "position": 2 }
  ]
}
```

### 6. Homepage model

Voorbeeld:

```json
{
  "page_slug": "home"
}
```

## Composer-verantwoordelijkheid

De composerlaag moet:

1. input normaliseren
2. media selecteren
3. pagina’s bepalen
4. secties genereren
5. menuvolgorde bepalen
6. homepage bepalen
7. een build-run aanmaken

Belangrijk:

- de composer hoeft nog niet perfect generiek te zijn
- hij mag in fase 4 beginnen met 2 of 3 sitepatronen

## Eerste sitepatronen

Ondersteun in fase 4 minimaal:

1. `service_business`
   - zoals `blander.nl`

2. `association_nonprofit`
   - zoals de historische vereniging testdata

3. optioneel `local_business_simple`

## Pattern mapping

Gebruik patternregels in de centrale app, niet in de plugin.

Voorbeeld:

- `service_business.home`
- `service_business.service_page`
- `service_business.contact_page`
- `association_nonprofit.home`
- `association_nonprofit.activity_page`
- `association_nonprofit.contact_page`

De output van een pattern is een set secties die daarna naar HTML of eenvoudige page content worden vertaald.

## Execution Plan

Bouw een `build_run` systeem.

Voor elke build moet een uitvoerplan bestaan met stappen zoals:

1. site koppelen
2. media importeren
3. pagina’s aanmaken
4. content invullen
5. featured images koppelen
6. menu bouwen
7. homepage instellen
8. build afronden

Voorbeeld:

```json
{
  "build_run_id": "build_123",
  "site_id": "site_123",
  "status": "running",
  "steps": [
    { "type": "media.upload", "status": "queued" },
    { "type": "page.create", "status": "queued" },
    { "type": "page.update", "status": "queued" },
    { "type": "menu.upsert", "status": "queued" },
    { "type": "site.set_homepage", "status": "queued" }
  ]
}
```

## Statusmodel

Gebruik heldere statussen:

- `draft`
- `ready`
- `running`
- `blocked`
- `failed`
- `completed`

Op stapniveau:

- `queued`
- `sent`
- `succeeded`
- `failed`
- `skipped`

## Datamodel dat je nu moet toevoegen

### `build_specs`

Minimaal:

- `id`
- `source_card_id` of andere bronreferentie
- `site_type`
- `site_title`
- `spec_json`
- `status`
- `created_at`
- `updated_at`

### `build_runs`

Minimaal:

- `id`
- `build_spec_id`
- `site_id`
- `agent_id`
- `status`
- `started_at`
- `finished_at`
- `created_at`

### `build_run_steps`

Minimaal:

- `id`
- `build_run_id`
- `step_order`
- `step_type`
- `payload_json`
- `status`
- `result_json`
- `error_json`
- `created_at`
- `updated_at`

## Applicatiefunctionaliteit

### 1. Build Spec generator

Bouw een interne service die van `nova_build_input` naar `build_spec` gaat.

Doel:

- AI-output opslaan is niet genoeg
- we willen een stabiele interne representatie

### 2. Build Run starter

Bouw een actie als:

- `Start build for site`

Die:

- een target site kiest
- een build spec koppelt
- build_run records maakt
- stappen in de juiste volgorde plant

### 3. Command dispatcher

Gebruik de bestaande command infrastructuur om build steps uit te voeren.

Zorg dat de dispatcher:

- afhankelijkheden respecteert
- stap voor stap werkt
- resultaten terugkoppelt naar build_run_steps

### 4. Asset mapping

Na media upload moet je een mapping bewaren:

- source asset → attachment_id

Na page create moet je een mapping bewaren:

- `page_slug` → `page_id`

Die mappings zijn essentieel voor:

- `media.attach_featured_image`
- `menu.upsert`
- `site.set_homepage`

### 5. Operator review screen

Bouw een eenvoudige interne build-view waarin zichtbaar is:

- welke build spec gekozen is
- welke pagina’s gebouwd gaan worden
- welke media gebruikt worden
- welke stappen al klaar zijn
- waar het fout ging als iets faalt

Nog geen fancy UI nodig, wel duidelijkheid.

## Eerste concrete workflow om te ondersteunen

Ondersteun expliciet de Blander-flow:

1. Blander brondata selecteren
2. build spec genereren
3. nieuwe site provisionen
4. build run starten
5. media uploaden
6. pagina’s aanmaken en vullen
7. featured images koppelen
8. menu instellen
9. homepage instellen
10. testsite URL tonen

## Verwachte plugin capabilities voor fase 4

Ga uit van deze bestaande capabilities:

- `content.create_page`
- `content.update_page`
- `content.create_post`
- `content.update_post`
- `content.trash_page`
- `content.trash_post`
- `site.export_structure`
- `media.upload_from_control_plane`
- `media.attach_featured_image`
- `menu.upsert`
- `site.set_homepage`
- `site.set_posts_page`

Als één van deze nog ontbreekt of niet live staat, documenteer dat scherp.

## Belangrijke regels

- geen wp-admin handwerk als primaire route
- de build moet via de control plane lopen
- gebruik eenvoudige HTML-content waar nodig, maar houd het voorspelbaar
- hou audit trail op build niveau
- gebruik UTC timestamps
- failures moeten hervatbaar zijn

## Niet doen in deze fase

- geen perfecte WYSIWYG builder bouwen
- geen generieke Gutenberg block engine ontwerpen
- geen eindgebruikerschat bouwen
- geen volledig autonomous redesign voor alle site-types

## Acceptatiecriteria

Fase 4 is geslaagd als:

1. de applicatie een `build_spec` kan maken uit bestaande scrape/build-data
2. de applicatie een `build_run` kan starten voor een nieuwe of bestaande site
3. media/page/menu/homepage stappen automatisch worden uitgevoerd
4. mappings van assets en page slugs correct worden bijgehouden
5. een operator de build-status en fouten kan volgen
6. minimaal één concrete showcase-site via deze flow gebouwd wordt

## Prioriteit

Bouw dit in deze volgorde:

1. `build_spec` model
2. `build_run` + step model
3. asset/page mapping
4. dispatcher
5. review/status screen
6. Blander end-to-end build via de composerflow
