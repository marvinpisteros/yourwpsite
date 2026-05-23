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

## Verwachte control plane basis-URL

Standaard:

```text
https://dev.yoursitehulp.nl/yourwpsite
```

Pas dit aan in `Instellingen -> yourWPsite Agent` als nodig.
