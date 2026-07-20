# PLAN: Picnic (online supermarket) MCP tools in Prism

## Doel

Voeg een Prism-integratie toe voor [Picnic](https://picnic.app/nl/) zodat agents via MCP:

1. **Producten zoeken** (en details + image URLs ophalen)
2. **Het “lijstje” beheren** — in Picnic is dat de **winkelwagen / cart**
3. **Recepten browsen/bekijken** (incl. plaatjes + ingrediënten)
4. Optioneel: recept-ingrediënten in één keer op het lijstje zetten

Credentials blijven in Prism YAML (multi-tenant servers/profiles), niet in Cursor/xa-atlas.

---

## Verdict (kort)

| Vraag | Antwoord |
|---|---|
| Officiële public API? | **Nee.** Picnic biedt geen developer-/partner-API. |
| Stabiel genoeg voor een integratie? | **Ja, met voorbehoud** — reverse-engineered storefront API (`api/15`), actief onderhouden community-wrappers. |
| Haalbaar in Prism? | **Ja.** Aanbevolen: **native PHP HTTP-client** die dezelfde endpoints/headers gebruikt als `picnic-api` v4. |
| “Lijstje” als aparte Picnic-feature? | **Nee.** Er is geen stabiele named shopping-list API; het cart-object *is* het boodschappenlijstje. |
| Recepten + plaatjes? | **Ja.** Recipe-pages + `image_id` → publieke static image URLs. |

**Aanbeveling:** implementeer native in Prism (PHP). Gebruik `picnic-api` / `mcp-picnic` als **referentie**, niet als runtime-dependency in de Symfony-app.

---

## Wat er online bestaat

### Officieel

- Geen public API, geen OAuth apps, geen partner docs.
- Alles wat werkt, praat met de **mobile/storefront API**:
  - Base: `https://storefront-prod.{nl|de|fr}.picnicinternational.com/api/15`
  - Auth header: `x-picnic-auth`
  - Device headers: `x-picnic-agent`, `x-picnic-did`
  - Login: `POST /user/login` met `key` (email) + `secret` = **MD5(password)** + `client_id: 30100`
  - Optionele SMS-2FA: `/user/2fa/generate` + `/user/2fa/verify` (nieuwe auth key in response header)

### Unofficiële libraries (ecosysteem)

| Project | Taal | Status (jul 2026) | Bruikbaar voor |
|---|---|---|---|
| [`MRVDH/picnic-api`](https://github.com/MRVDH/picnic-api) (`picnic-api` npm, **v4.6.0**) | TypeScript/Node | Actief (~100★, gepusht jul 2026), MIT, domain services (catalog/cart/recipe/…) | **Beste referentie** voor endpoints + types |
| [`ivo-toby/mcp-picnic`](https://github.com/ivo-toby/mcp-picnic) (`mcp-picnic` npm, **v1.15**, depends `picnic-api ^4.5`) | Node MCP server | Klaar-voor-gebruik MCP met 30+ tools | Snelle lokale spike / tool-shape inspiratie |
| [`codesalatdev/python-picnic-api`](https://github.com/codesalatdev/python-picnic-api) (`python-picnic-api2`) | Python | Cart/search/login; minder recipe/Fusion-coverage dan Node v4 | Home Assistant e.d.; **niet** eerste keus voor Prism |
| [`simonmartyr/picnic-api`](https://github.com/simonmartyr/picnic-api) | Go | Kleiner, ouder | Image-URL helper bevestigt static path |
| PHP Packagist “picnic” | — | **Geen** relevante Picnic-supermarkt client | — |

### Wat `picnic-api` v4 concreet kan (bron: library services)

**Catalog**

- `search(query)` → `GET /pages/search-page-results?search_term=…` (Fusion page → `sellingUnit[]`)
- `getSuggestions(query)` → `GET /suggest?search_term=…`
- `getProductDetails(productId)` → Fusion PDP geparsed naar structured details (**experimental**, layout-gevoelig)
- Images: `https://storefront-prod.{cc}.picnicinternational.com/static/images/{imageId}/{size}.png`  
  sizes: `tiny` \| `small` \| `medium` \| `large` \| `extra-large`

**Cart (= lijstje)**

- `getCart()`, `addProductToCart`, `addProductsToCart`, `removeProductFromCart`, `clearCart`
- Ook: delivery slots, order status, `confirmOrder` — **bewust buiten fase 1** (geld/order-risico)

**Recipe**

- `getRecipesPage()` / `getCookbookPage()` / `getRecipeDetailsPage(recipeId)`
- `saveRecipe` / `unsaveRecipe`
- Recept → cart via selling-group: `assignSellingGroupToBasket`, `updateSellingGroupPortions`, `removeSellingGroupFromBasket`

### Bestaande MCP-server toolset (`mcp-picnic`) — ter referentie

Al aanwezig bij derden (niet in Prism): o.a. `picnic_search`, `picnic_get_product_details`, `picnic_get_image`, `picnic_get_cart`, `picnic_add_to_cart`, `picnic_remove_from_cart`, `picnic_clear_cart`, `picnic_browse_recipes`, `picnic_get_recipe`, `picnic_get_recipe_ingredients`, `picnic_add_recipe_to_cart`, `picnic_save_recipe`, plus delivery/payment/2FA helpers.

Nuttig om shapes van af te kijken; **niet** als productie-afhankelijkheid van deze Symfony-app.

---

## Mogelijkheden vs onmogelijkheden

### Mogelijk (goed onderbouwd)

| Capability | Hoe |
|---|---|
| Inloggen + sessie (`x-picnic-auth`) | Login + optionele 2FA; auth key cachen |
| Productzoeken | Search page / suggest |
| Productdetails + prijs/allergieën | PDP Fusion parse (of raw page als fallback) |
| Product-/receptplaatjes | `image_id` → static PNG URL (geen auth nodig voor images) |
| Lijstje bekijken | `getCart()` — items, quantities, totals |
| Item toevoegen/verwijderen/wissen | cart mutations |
| Recepten browsen + detail + ingrediënten | recipe/cookbook Fusion pages |
| Recept (deels) op lijstje | selling-group assign/remove |
| Multi-profile / multi-server | Prism `type: picnic` profiles |

### Mogelijk, maar fragiel / experimenteel

| Capability | Risico |
|---|---|
| Structured product/recipe parsing | Picnic UI is **Fusion/PML**; parsers breken bij layout-wijzigingen |
| Category tree browsing | `getCategories()` verwijderd in picnic-api v4; niet in scope tenzij opnieuw via Fusion page |
| “Build shopping list from N recipes” | Client-side aggregatie (zoals mcp-picnic); geen Picnic-native list entity |
| Delivery tracking / slots / cancel | Werkt in libraries, maar order-side effects — aparte fase + expliciete safety |

### Onmogelijk of bewust niet doen

| Item | Waarom |
|---|---|
| Officiële, supported API | Bestaat niet |
| Aparte named “boodschappenlijstjes” zoals Apple Reminders | Niet (stabiel) in de storefront API; cart = lijst |
| Gegarandeerde ToS-compliance | Reverse engineering; Picnic kan endpoints wijzigen of profiles beperken |
| Betrouwbare checkout/order-plaatsing door agents | Te riskant als default; `confirmOrder` / slot booking alleen opt-in later |
| PHP Composer-package van Picnic zelf | Bestaat niet — we schrijven zelf een dunne client |
| Node/Python runtime in de Prism PHP-container als kernpad | Past niet bij Prism-architectuur / coding defaults; hoogstens tijdelijke lokale spike buiten de app |

### Juridisch / operationeel risico (expliciet)

- Unofficiële toegang tot een private consumer API.
- Credentials (email + wachtwoord) moeten in `prism.config.yaml` / `prism.kiko.yaml` — **gitignored**, scrubbed examples only.
- API of agent-headers (`x-picnic-agent`) kunnen plotseling falen → tools moeten duidelijke errors teruggeven.
- Schrijfacties raken een **echt** huishoud-profile: tools moeten idempotent genoeg en duidelijk “destructive” zijn (`clear_cart`).

---

## Architectuurkeuze voor Prism

### Optie A — Native PHP client (aanbevolen)

Volg het bestaande integratiepatroon (`Telegram` / `Matomo` / `Tmdb`):

```
src/Picnic/
  PicnicProfileConfig.php
  PicnicConfigLoader.php
  PicnicClient.php          # HTTP + headers + login/2FA + auth-key cache
  PicnicService.php         # search/cart/recipe normalisatie
  PicnicFusion.php          # (optioneel) JSONPath-achtige helpers voor sellingUnits / recipe fields
src/Mcp/Tool/Picnic*Tool.php
```

- Symfony `HttpClientInterface` voor alle calls.
- Endpoints/headers 1:1 afkijken van `picnic-api` v4 (open source, MIT).
- Geen Node in de runtime.

**Voordelen:** past in Prism, multi-tenant, admin Try It, geen sidecar.  
**Nadelen:** Fusion-parsers zelf onderhouden; bij Picnic-wijzigingen moet PHP meekomen (net als de Node lib).

### Optie B — Proxy naar `mcp-picnic` (Pattern C, snelle spike)

Profile config met upstream MCP endpoint + credentials; Prism proxied een allowlist van tools.

**Voordelen:** snel.  
**Nadelen:** Node-proces, dubbele auth-model, slechtere multi-tenant fit, moeilijker admin UX, niet “Prism-native”. Alleen zinvol als tijdelijke spike op de workstation, niet als eindarchitectuur.

### Optie C — Shell-out naar `picnic-api` vanuit PHP

Afgewezen: fragile process boundary, slecht voor Symfony DI/request scope.

**Keuze voor dit plan: Optie A.**

---

## Semantiek: “lijstje”

In gebruikerspraak is “op mijn Picnic-lijstje zetten” ≈ **in de winkelwagen**.

| Gebruiker zegt | Prism-tool |
|---|---|
| wat staat er op mijn lijstje? | `picnic_get_cart` |
| zet X erop | `picnic_search` → kies product_id → `picnic_add_to_cart` |
| haal X eraf / maak leeg | `picnic_remove_from_cart` / `picnic_clear_cart` |
| boodschappenlijst uit recepten | `picnic_get_recipe` (+ optioneel `picnic_add_recipe_to_cart`) — eventueel later een helper die meerdere recepten merget |

Tool descriptions moeten dit expliciet maken (“Picnic cart = shopping list”).

---

## Profile type & YAML

Profile type string: `picnic`.

Scrubbed example voor `prism.config.yaml.example`:

```yaml
household-picnic:
  type: picnic
  label: "Picnic (household)"
  username: "user@example.com"
  password: "replace-me"
  country_code: "NL"   # NL | DE | FR
  # optional after first successful login / 2FA:
  # auth_key: "replace-me-if-you-persist-manually"
  # api_version: "15"
```

Live credentials alleen in gitignored config (`prism.config.yaml` / `prism.kiko.yaml`).

### Auth-key cache (belangrijk — Prism heeft geen DB)

Aanbevolen gedrag in `PicnicClient`:

1. Als `auth_key` in profile config staat → gebruik die.
2. Anders: login met username/password; schrijf auth key naar  
   `var/cache/picnic/{profileKey}.auth` (of Symfony cache pool), file mode 0600.
3. Bij HTTP 401 → één keer re-login, cache verversen, retry.
4. Als login `second_factor_authentication_required` teruggeeft → tools:
   - `picnic_generate_2fa_code`
   - `picnic_verify_2fa_code`  
   Daarna auth key cachen. Zonder 2FA-completion falen write/read tools met duidelijke error.

---

## Tool shapes (fase 1 — MVP)

Alle tools: `getProfileType() → 'picnic'`.  
Return: MCP text content met JSON (`JSON_THROW_ON_ERROR`).  
Common optional arg: `profile` (string, profile key; default = enige/default profile).

Prijzen in Picnic komen vaak als **centen integer** (`display_price: 599` = €5,99) — normaliseer naar:

```json
{ "price_cents": 599, "price_eur": 5.99, "currency": "EUR" }
```

### Profiles

| Tool | Input | Output |
|---|---|---|
| `picnic_list_profiles` | — | `[{key, label, country_code}]` (geen secrets) |

### Auth (alleen nodig bij 2FA / sessieproblemen)

| Tool | Input | Output |
|---|---|---|
| `picnic_generate_2fa_code` | optional `profile`, optional `channel` (default `SMS`) | `{ok: true}` of error |
| `picnic_verify_2fa_code` | `code` (required), optional `profile` | `{ok: true}` — caches new auth key |

### Catalog / search

| Tool | Input | Output |
|---|---|---|
| `picnic_search` | `query` (required), optional `limit` (default 20), `profile` | `{query, products: [NormalizedProductLight…]}` |
| `picnic_get_product` | `product_id` (required, bv. `s11295810`), optional `profile` | `NormalizedProduct` (details + `image_urls`) |
| `picnic_get_image_url` | `image_id` (required), optional `size` (default `medium`), `profile` | `{image_id, size, url}` |

`NormalizedProductLight`:

```json
{
  "id": "s11295810",
  "name": "…",
  "unit_quantity": "500 gram",
  "price_cents": 599,
  "price_eur": 5.99,
  "image_id": "abc…",
  "image_url": "https://storefront-prod.nl.picnicinternational.com/static/images/abc…/medium.png",
  "max_count": 50
}
```

`NormalizedProduct` = light + `description`, `brand`, `allergens`, `highlights`, `promotion`, `image_urls[]` (meerdere sizes of gallery ids).

**Images in MCP:** net als TMDb — **URLs teruggeven**, geen base64 in tool output (te groot). Agents/atlas kunnen downloaden.

### Cart (= lijstje)

| Tool | Input | Output |
|---|---|---|
| `picnic_get_cart` | optional `profile` | `{items: […], item_count, total_price_cents, total_price_eur, raw_summary?}` |
| `picnic_add_to_cart` | `product_id` (required), optional `quantity` (default 1), `profile` | updated cart summary |
| `picnic_remove_from_cart` | `product_id` (required), optional `quantity` (default 1), `profile` | updated cart summary |
| `picnic_clear_cart` | optional `confirm` (required `true` om te wissen), `profile` | emptied cart / error if confirm missing |

### Recipes (fase 1b — direct na cart, zelfde PR of snelle follow-up)

| Tool | Input | Output |
|---|---|---|
| `picnic_browse_recipes` | optional `category` / `page` hint, `profile` | `{recipes: [RecipeLight…], categories?: […]}` |
| `picnic_get_recipe` | `recipe_id` (required), `profile` | `NormalizedRecipe` (title, time, portions, steps?, ingredients[], `image_url` / `image_urls`, selling_group_id?) |
| `picnic_add_recipe_to_cart` | `recipe_id` of `selling_group_id` (één required), optional `portions`, `profile` | updated cart summary |
| `picnic_remove_recipe_from_cart` | `selling_group_id` (required), `profile` | updated cart summary |
| `picnic_save_recipe` / `picnic_unsave_recipe` | `recipe_id`, `profile` | `{ok: true}` |

`RecipeLight`:

```json
{
  "id": "…",
  "title": "Pasta carbonara",
  "image_id": "…",
  "image_url": "https://…/static/images/…/medium.png",
  "duration_minutes": 20,
  "selling_group_id": "…"
}
```

Als Fusion-parsing van steps/ingredients onbetrouwbaar is: tool returnt best-effort structured fields + `raw_excerpt` / warning flag, nooit stille lege lijsten zonder uitleg.

---

## Bestanden om te maken

```
src/Picnic/PicnicProfileConfig.php
src/Picnic/PicnicConfigLoader.php
src/Picnic/PicnicClient.php
src/Picnic/PicnicService.php
src/Picnic/PicnicImage.php              # URL builder voor static images
src/Mcp/Tool/PicnicListProfilesTool.php
src/Mcp/Tool/PicnicGenerate2faCodeTool.php
src/Mcp/Tool/PicnicVerify2faCodeTool.php
src/Mcp/Tool/PicnicSearchTool.php
src/Mcp/Tool/PicnicGetProductTool.php
src/Mcp/Tool/PicnicGetImageUrlTool.php
src/Mcp/Tool/PicnicGetCartTool.php
src/Mcp/Tool/PicnicAddToCartTool.php
src/Mcp/Tool/PicnicRemoveFromCartTool.php
src/Mcp/Tool/PicnicClearCartTool.php
src/Mcp/Tool/PicnicBrowseRecipesTool.php
src/Mcp/Tool/PicnicGetRecipeTool.php
src/Mcp/Tool/PicnicAddRecipeToCartTool.php
src/Mcp/Tool/PicnicRemoveRecipeFromCartTool.php
src/Mcp/Tool/PicnicSaveRecipeTool.php
src/Mcp/Tool/PicnicUnsaveRecipeTool.php
```

Docs/example updates (scrubbed):

- `prism.config.yaml.example` — sample `type: picnic` profile
- `AGENTS.md` — `picnic` toevoegen aan supported types + tool list

Geen Doctrine, geen migrations.

---

## Implementatieplan (concreet)

### Fase 0 — Spike (½–1 dag, lokaal, niet in prod-pad)

1. Met echte NL-credentials (lokaal) via Node one-liner of tijdelijk `npx mcp-picnic` verifiëren:
   - login (+ 2FA indien aan)
   - search “melk”
   - getCart / add / remove
   - één recipe page + image URL in browser/curl
2. Noteer exacte response shapes (sellingUnit fields, cart item shape, recipe id fields).
3. Beslis: welke Fusion-velden we **normaliseren** vs raw doorgeven.

Acceptatiekader spike: search + cart round-trip werkt; image URL laadt; recipe detail heeft title + ≥1 image + ingredient-achtige data.

### Fase 1 — Core PHP integratie (search + cart)

1. `PicnicProfileConfig` + `PicnicConfigLoader` (scoped via `ServerContext`).
2. `PicnicClient`:
   - base URL per `country_code`
   - headers: `Content-Type`, `x-picnic-auth`, optioneel picnic-agent/did voor page routes
   - `login()` met MD5 secret
   - auth-key file cache + 401 retry
3. `PicnicService` methods: `search`, `getCart`, `addToCart`, `removeFromCart`, `clearCart`.
4. Tools uit tabel “Catalog” + “Cart” + `picnic_list_profiles`.
5. Scrubbed YAML example + kiko local profile (gitignored).
6. Handmatig via admin Try It of MCP `tools/call` valideren.

**Done when:** agent kan zoeken → product kiezen → op cart zetten → cart lezen → item verwijderen.

### Fase 2 — Product details + images

1. `getProduct` via PDP endpoint; start met **light parse** (name/price/image_ids uit `sellingUnit` / bekende node ids), niet de volledige Node helper in één keer overzetten.
2. `PicnicImage::url(country, imageId, size)`.
3. Tools: `picnic_get_product`, `picnic_get_image_url`.
4. Search-results altijd vullen met `image_url` (medium).

**Done when:** search hits en product detail hebben werkende image URLs.

### Fase 3 — Recipes (+ images)

1. Client methods voor cookbook/recipes page + recipe details page.
2. Normaliseer: id, title, image_id(s), duration, portions, ingredients (id/name/quantity waar mogelijk), selling_group_id.
3. Tools: browse / get / add-recipe-to-cart / remove-recipe-from-cart / save / unsave.
4. Tool descriptions: “images are HTTPS URLs; download outside Prism if needed”.

**Done when:** agent kan “toon een 20-minuten recept met plaatje en zet de ingrediënten op mijn lijstje” end-to-end doen.

### Fase 4 — Hardening (aanbevolen vóór breed gebruik)

- Rate-limit / friendly errors bij 429/5xx.
- Never log password of full auth key.
- `clear_cart` verplicht `confirm: true`.
- Optioneel: profile flag `allow_writes: true` (default true) / later `allow_orders: false`.
- AGENTS.md + korte `docs/picnic.md` (auth, 2FA, cart=list semantieк, risico’s).

### Fase 5 — Later / out of scope voor v1

- Delivery slots, tracking, cancel, rate delivery
- Wallet / payment profile
- `confirmOrder` / checkout
- Promotions page, meal combinations, multi-recipe shopping-list builder
- Category browser
- Proxy naar upstream `mcp-picnic`

---

## Mapping: library method → Prism service method

| picnic-api | PicnicService / Client |
|---|---|
| `auth.login` | `PicnicClient::ensureAuthenticated()` |
| `auth.generate2FACode` / `verify2FACode` | `generate2fa` / `verify2fa` |
| `catalog.search` | `searchProducts` |
| `catalog.getProductDetails` | `getProduct` (best-effort parse) |
| `catalog.getImage` URL form | `PicnicImage::url` (geen binary download in tools) |
| `cart.getCart` | `getCart` |
| `cart.addProductToCart` | `addToCart` |
| `cart.removeProductFromCart` | `removeFromCart` |
| `cart.clearCart` | `clearCart` |
| `recipe.getRecipesPage` / `getCookbookPage` | `browseRecipes` |
| `recipe.getRecipeDetailsPage` | `getRecipe` |
| `recipe.assignSellingGroupToBasket` | `addRecipeToCart` |
| `recipe.removeSellingGroupFromBasket` | `removeRecipeFromCart` |
| `recipe.saveRecipe` / `unsaveRecipe` | `saveRecipe` / `unsaveRecipe` |

---

## Testplan (handmatig; geen suite in repo)

1. `picnic_list_profiles` toont scrubbed labels.
2. Zonder/met 2FA: auth flow werkt; daarna search.
3. Search “yoghurt” → ≥1 product met `id`, prijs, `image_url`.
4. `picnic_add_to_cart` → `picnic_get_cart` bevat item; quantity klopt.
5. `picnic_remove_from_cart` / `picnic_clear_cart` (met confirm).
6. `picnic_browse_recipes` → `picnic_get_recipe` heeft image_url + ingredients.
7. `picnic_add_recipe_to_cart` vergroot cart; remove recipe group ruimt op.
8. Verkeerde password / expired auth → duidelijke MCP `isError` message, geen stack trace leak.

---

## Open vragen (vóór/ tijdens fase 0)

1. Heeft het huishoud-profile **SMS-2FA** aan? (bepaalt of 2FA-tools day-1 moeten)
2. Willen we **write tools** meteen op de kiko-server, of eerst een dedicated `picnic-dev` server met eigen bearer token?
3. Is checkout/slots ooit gewenst, of blijft Prism bewust “list + recipes only”?
4. Mag auth key op disk in `var/cache`, of liever verplicht `auth_key` in YAML na handmatige login?

Defaults als geen antwoord: (1) bouw 2FA-tools mee, (2) eigen profile op bestaande personal/kiko server, (3) geen checkout in v1, (4) file cache + optional YAML override.

---

## Samenvatting

Picnic-integratie is **haalbaar zonder officiële API**, door de stabielste community-stack (`picnic-api` v4) als blueprint te gebruiken voor een **native PHP Prism-integratie**. Het “lijstje” is de **cart**; recepten inclusief plaatjes (via static image URLs) horen in fase 2–3. Checkout en delivery-side effects blijven bewust buiten v1.
