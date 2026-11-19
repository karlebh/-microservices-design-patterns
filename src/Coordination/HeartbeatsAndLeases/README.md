# Heartbeating & Lease Mechanisms

## Heartbeats — is that node alive?

In a distributed system you cannot observe that a node has crashed. All you can observe is **silence**, and silence has many causes: the node died, the network dropped packets, the node is in a long GC pause. So nodes periodically send "I'm alive" messages, and others draw conclusions from their absence.

**Timeout detector.** No heartbeat for more than T → suspect the node. Simple, but T is a blunt compromise: too short and a hiccup triggers a false alarm (and an unnecessary failover); too long and a real failure goes unnoticed.

**Phi-accrual detector.** Instead of a yes/no answer it outputs a **suspicion level** φ that grows continuously the longer the silence lasts, *relative to how regularly that node normally reports*. A node that beats every second is suspicious after five seconds of silence; one that beats every minute is not. Each consumer picks its own threshold. (Used by Cassandra and Akka.)

A failure detector can only ever *suspect*. It is impossible to distinguish a dead node from a slow one, which is why its output must be combined with something that tolerates being wrong: quorums, [fencing tokens](../FencingTokens), leases.

## Leases — ownership with an expiry date

A lease is a lock that **expires by itself**: "you are the leader / you own this resource *until time T*". The holder must renew it before T. If the holder crashes, nobody needs to detect it — the lease just runs out and someone else may take it.

This turns "is the holder alive?" (unanswerable) into "has the time passed?" (answerable). The price is a dependency on clocks: the holder and the grantor must roughly agree on how fast time passes. So the holder stops acting a little **before** its lease officially ends (a safety margin for clock drift), and pauses remain dangerous — a holder frozen past its expiry doesn't know. Hence leases are paired with fencing.

## In this folder

| File | Role |
| --- | --- |
| `HeartbeatFailureDetector.php` | Fixed-timeout detection |
| `PhiAccrualFailureDetector.php` | Suspicion level that adapts to each node's heartbeat rhythm |
| `LeaseManager.php` | Time-bound exclusive ownership: grant, renew, expire, with a holder-side safety margin |

## Try it

```bash
php artisan test --filter=HeartbeatsAndLeasesTest
```
