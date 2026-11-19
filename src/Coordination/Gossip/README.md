# Gossip Protocol & Cluster Membership

## The problem

A cluster of hundreds of nodes needs every node to know things: who the members are, who is down, what the latest configuration is. The obvious designs don't scale:

- **A central registry** is a single point of failure and a bottleneck.
- **Everyone tells everyone** costs N² messages, and one lost message leaves someone uninformed.

## The idea

Spread information the way rumours (or epidemics) spread. Periodically, every node picks **a few random peers** and exchanges what it knows. Each node that learns something new starts passing it on.

```
round 1:  1 node knows        ●○○○○○○○
round 2:  2 nodes know        ●●○○○○○○
round 3:  4 nodes know        ●●●●○○○○
round 4:  8 nodes know        ●●●●●●●●      doubling each round → O(log N) rounds
```

- **Scalable:** each node does a constant amount of work per round, whatever the cluster size.
- **Robust:** no node is special, and a lost message just means that pair talks again later. It keeps working with nodes failing and messages dropped.
- **Eventually consistent:** nodes don't learn things at the same moment, and nobody knows when everyone knows.

## Membership and failure detection

Gossip the membership list itself. Each node keeps a **heartbeat counter** that it increments every round, and gossips a table `node → heartbeat`. On receiving a table, keep the higher counter per node.

A node whose counter **stops increasing** has stopped gossiping. After enough rounds without progress, the other nodes mark it as failed — each reaching that conclusion on its own, with no coordinator. A new node joins by contacting any one existing member (a *seed*); gossip does the rest.

Used by Cassandra, Consul/Serf (SWIM), Redis Cluster, DynamoDB.

## In this folder

| File | Role |
| --- | --- |
| `GossipNode.php` | A node's local view: versioned data and the heartbeat table |
| `GossipCluster.php` | Runs gossip rounds between nodes, with crashes, joins and message loss |

## Try it

```bash
php artisan test --filter=GossipTest
```
