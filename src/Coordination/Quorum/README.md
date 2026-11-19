# Quorum Systems

## The problem

Data is replicated to N nodes for durability and availability. Now every read and write faces a choice:

- Wait for **all** N → one slow or dead node blocks everything.
- Wait for **one** → fast and always available, but a read may hit a node that hasn't received the latest write.

## The idea

Require only a **quorum**: enough nodes that any two quorums must **overlap**.

- **N** — replicas of each value
- **W** — replicas that must acknowledge a write
- **R** — replicas that must answer a read

If **R + W > N**, the set of nodes a read asks and the set a write reached always share at least one node. That node has the newest version, and the reader picks it by version number. So reads see the latest acknowledged write, even though no single node is guaranteed to be up to date.

```
N=3, W=2, R=2      write reached:  [A, B]
                   read asks:         [B, C]     B is in both → the read sees the write
```

Tuning the three numbers is tuning the trade-off:

| N, W, R | Behaviour |
| --- | --- |
| 3, 2, 2 | The usual balance: tolerates 1 node down for reads and for writes |
| 3, 3, 1 | Fast reads, but one node down blocks all writes |
| 3, 1, 3 | Fast writes, but one node down blocks all reads |
| 3, 1, 1 | Fastest and most available; R + W ≤ N, so **stale reads are possible** |

## Supporting mechanisms

- **Read repair:** a read that notices a stale replica writes the newest value back to it.
- **Sloppy quorum + hinted handoff:** when a home replica is unreachable, another node accepts the write on its behalf with a *hint*, and delivers it when the replica returns. Writes stay available; the consistency guarantee is weakened until the handoff.

Quorums are also the heart of consensus: Raft and Paxos commit when a **majority** agrees, because two majorities always overlap.

## In this folder

| File | Role |
| --- | --- |
| `QuorumStore.php` | An N/W/R replicated key-value store with versions, read repair and optional hinted handoff |

## Try it

```bash
php artisan test --filter=QuorumTest
```
