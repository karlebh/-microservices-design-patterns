# Consistent Hashing

## The problem

To spread keys over N servers, the obvious formula is `hash(key) mod N`. It distributes well — until N changes. Add or remove one server and almost **every** key maps to a different server:

- a cache cluster loses nearly all its contents at once (and the database behind it takes the full load),
- a storage cluster has to move nearly all its data.

Servers come and go all the time (scaling, failures, deploys), so this is not acceptable.

## The idea

Hash both **keys and servers** onto the same circle (the ring, 0 … 2³²−1). A key belongs to the **first server found going clockwise** from the key's position.

```
            0
        ┌───●───┐   ●  = server
    C ●─┘       └─● A
      │   keys    │       a key is stored on the next server clockwise
      └─┐       ┌─┘
        └───●───┘
            B
```

Now adding a server only takes over the arc between it and its predecessor: on average **1/N of the keys move**, all of them *to* the new server. Removing a server only reassigns that server's keys to its successor. Everything else stays where it is.

### Virtual nodes

With one point per server the arcs are very uneven, and a removed server dumps its whole load on a single neighbour. So each physical server is placed on the ring **many times** (`server-A#1 … server-A#200`). The more virtual nodes, the more even the load, and a departing server's keys are shared among all the others.

### Replication

To keep N copies, continue clockwise and take the next N **distinct** physical servers — the *preference list* (this is how Dynamo and Cassandra place replicas).

## In this folder

| File | Role |
| --- | --- |
| `HashRing.php` | The ring: add/remove nodes with virtual nodes, look up the owner of a key, build preference lists |

## What to notice

- The tests compare, on the same keys, how many move under `mod N` (~80%) and under consistent hashing (~20%) when going from 4 to 5 servers.
- With 1 virtual node per server the busiest server holds several times the load of the quietest; with 200 they are within a few percent.
- Used by: Memcached/Redis client-side sharding, Cassandra, DynamoDB, CDNs, and load balancers that need session affinity.
- Alternatives with the same "minimal movement" property: rendezvous (highest-random-weight) hashing and jump hash.

## Try it

```bash
php artisan test --filter=ConsistentHashingTest
```
