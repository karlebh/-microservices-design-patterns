# Strangler Fig Pattern

## The problem

You have a legacy monolith to replace. The "big bang" rewrite — build the new system for two years, then switch over in a weekend — is the riskiest option there is: no value until the end, a moving target, and one all-or-nothing cut-over.

## The idea

A strangler fig grows around a host tree and gradually replaces it. Do the same to the monolith:

1. Put a **facade** (proxy / gateway) in front of the legacy system. At first it forwards everything. Nothing changes for users.
2. Build **one** feature in the new system and tell the facade to send that feature's traffic there.
3. Repeat, route by route, until nothing reaches the legacy system. Then delete it.

```
                 ┌──────────── /invoices ──► new service
client ──► FACADE┤
                 └──────────── everything else ──► legacy monolith
```

At every step the system works, value ships continuously, and a bad migration is undone by changing a routing rule.

## In this folder

| File | Role |
| --- | --- |
| `StranglerFacade.php` | Routes each request to legacy or to the new system, per path prefix, with gradual rollout, shadowing and fallback |

## What to notice

- **Gradual rollout**: a route can go to the new system for 10% of users first. The split is by a hash of the user id, so one user always gets the same side (no flip-flopping between two implementations).
- **Shadow mode** (dark launch): send the request to *both*, return the legacy answer, and record any difference. You validate the new code with real traffic before a single user depends on it. Only safe for read-only requests.
- **Fallback**: if the new implementation throws, serve from legacy. Remove this once you trust it, or it hides bugs forever.
- **Rollback is a config change**, not a deployment.
- The hard part is rarely routing — it is the **data**. While both systems are live, they must agree (shared database for a while, or sync via [CDC](../../DataArchitecture/ChangeDataCapture) / events).
- Finish the job. A half-strangled monolith with a permanent facade is two systems to maintain.

## Try it

```bash
php artisan test --filter=StranglerFigTest
```
