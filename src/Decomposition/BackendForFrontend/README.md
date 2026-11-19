# Backend-for-Frontend (BFF)

## The problem

A product page needs data from several services: catalog, reviews, recommendations. Different clients need it differently:

- The **mobile app** is on a slow, metered network and a small screen: it wants one small response.
- The **web app** has a big screen and a fast connection: it wants everything.

If clients call the services directly, the phone makes three round trips and downloads fields it never shows. If you build **one** general-purpose API for all clients, it becomes a compromise that suits none, and every team has to coordinate changes to it.

## The idea

Give each kind of frontend its **own** backend: a thin server-side layer, owned by the frontend team, that calls the downstream services and returns exactly the shape that UI needs.

```
mobile app ──► Mobile BFF ──┐
                            ├──► catalog / reviews / recommendations
web app ─────► Web BFF ─────┘
```

## In this folder

| File | Role |
| --- | --- |
| `Services/CatalogService.php`, `ReviewService.php`, `RecommendationService.php` | The downstream microservices (in memory, counting calls) |
| `MobileBff.php` | One compact response: essentials only, a rating *summary*, no recommendations |
| `WebBff.php` | One rich response: full description, every review, recommendations |

## What to notice

- **One round trip** from the device instead of three; the fan-out happens server-side where the network is fast.
- **Different shape, different cost.** The mobile BFF doesn't even *call* the recommendation service.
- **Each BFF decides how to degrade.** With reviews down, mobile simply omits the rating; web shows a notice. The policy lives next to the UI it serves.
- **No business logic in a BFF.** It aggregates, trims and formats. Rules belong in the downstream services, or two BFFs will implement them differently.
- The price is duplication between BFFs and one more deployable per frontend. With one client type, a plain [API gateway](../../Infrastructure/ApiGateway) is enough.

## Try it

```bash
php artisan test --filter=BackendForFrontendTest
```
