# Leader Election Algorithms (Bully, Raft-based, ZooKeeper)

## The problem

Many designs are simple *if exactly one node is in charge*: one node assigns work, one node accepts writes, one node runs the scheduled job. So the cluster must agree on who that is, and replace it when it fails — without ever ending up with **two** leaders at once (split brain).

## Bully algorithm

Every node has a numeric id; the highest live id is the leader.

1. A node that notices the leader is gone sends **ELECTION** to every node with a higher id.
2. If nobody answers, it declares itself leader and sends **COORDINATOR** to the rest.
3. If a higher node answers, that node takes over and runs its own election.

Simple and deterministic. But it uses up to O(n²) messages, assumes failures can be detected reliably by timeouts, and **has no defence against a network partition**: each side elects its own highest node.

## Raft-style election (terms and majority votes)

Time is divided into numbered **terms**. A node that hears nothing from a leader becomes a candidate: it increments the term, votes for itself and asks for votes.

- A node grants **one vote per term**.
- A candidate needs a **majority** to win.

Two majorities always overlap, so there can be **at most one leader per term**. A partition's minority side cannot elect anyone. A stale leader that sees a higher term steps down. Split votes are resolved by randomised timeouts. This is safe under partitions, which Bully is not.

## ZooKeeper recipe (ephemeral sequential nodes)

Delegate to a coordination service. Each candidate creates an **ephemeral, sequential** node (`/election/n_0000000007`). The candidate with the **lowest** number is the leader. An ephemeral node disappears automatically when its creator's session dies.

Each candidate watches only the node **just before its own**. When that vanishes, it checks whether it is now the lowest. So a leader failure wakes up exactly one candidate instead of all of them (no *herd effect*). The hard part — agreeing on the order — is done by ZooKeeper's own consensus (Zab).

## In this folder

| File | Role |
| --- | --- |
| `BullyElection.php` | The Bully algorithm with message counting and a trace |
| `RaftElection.php` | Term-based voting with majorities, partitions and stepping down |
| `ZooKeeperElection.php` | The ephemeral-sequential-node recipe with predecessor watches |

Related: [Consensus](../Consensus) (the full Raft and Zab protocols), [Fencing Tokens](../FencingTokens) (what protects you when an old leader doesn't know it lost), [Leases](../HeartbeatsAndLeases).

## Try it

```bash
php artisan test --filter=LeaderElectionTest
```
