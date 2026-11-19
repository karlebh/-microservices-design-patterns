# Micro-frontend Architecture

## The problem

The backend was split into services owned by independent teams, but the UI is still one big frontend application. Every team's feature ships through the same codebase, the same build and the same release. The monolith just moved to the browser.

## The idea

Apply the microservice idea to the UI: slice it **vertically by business capability**. Each team owns its feature end to end — database, service *and* the piece of UI for it — and deploys that piece on its own.

```
┌──────────────────────── app shell (layout, routing, auth) ────────────────────────┐
│  ┌─ product details ─┐   ┌─ reviews ─────────┐   ┌─ cart ────────────┐            │
│  │  team: catalog    │   │  team: reviews    │   │  team: checkout   │            │
│  └───────────────────┘   └───────────────────┘   └───────────────────┘            │
└───────────────────────────────────────────────────────────────────────────────────┘
```

A thin **app shell** owns only what is truly shared (page layout, navigation, the logged-in user) and composes the page from **fragments** it knows nothing about.

Composition can happen in different places:

| Where | How | Notes |
| --- | --- | --- |
| **Server** (shown here) | The shell requests each fragment's HTML and stitches the page (SSI/ESI, Podium, Tailor) | Fast first paint, SEO-friendly |
| **Client** | The shell loads each team's JS bundle at runtime (Module Federation, single-spa, Web Components) | Rich interactivity, heavier in the browser |
| **Build time** | Fragments are npm packages bundled together | Simple, but everyone must release together again — defeats the purpose |

## In this folder

| File | Role |
| --- | --- |
| `MicroFrontend.php` | The contract every fragment implements: owner, `render(context)`, required assets |
| `FragmentRegistry.php` | Which fragment currently serves which slot. "Deploying" = replacing one entry |
| `AppShell.php` | Composes the page; isolates failures; de-duplicates assets |
| `Teams/*.php` | Three fragments owned by three teams |

## What to notice

- **Independent deployment:** a team replaces its fragment in the registry. The shell and the other teams don't rebuild or redeploy.
- **Failure isolation:** if the reviews fragment throws, its slot shows a fallback and the customer can still buy.
- **Shared dependencies** (the design-system script) are loaded once, not once per team.
- **Fragments don't know each other.** They get shared, read-only context from the shell; in the browser they talk through events (`cart:item-added`), never by calling each other's code.
- **Costs:** payload duplication, keeping the look consistent (a shared design system is mandatory), and more operational surface. Worth it for several teams on one product; overkill for one team.

## Try it

```bash
php artisan test --filter=MicroFrontendsTest
```
