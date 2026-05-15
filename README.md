## This Repository is a playground to try out a lot of methodologies employed in backend development, microservice design (patterns), and distributed systems.

I shall do my best to cover the below and more:

---

### Data Consistency, Events, & Distributed Transactions

- Transactional Outbox Pattern
- Idempotent Consumer / Idempotency Keys
- Saga Pattern (Choreography & Orchestration)
- Event Sourcing
- CQRS (Command Query Responsibility Segregation)
- Two-Phase Commit (2PC) / Three-Phase Commit (3PC)
- Compensating Transactions
- Distributed Locks (Redlock, Chubby)
- At-Least-Once, At-Most-Once, and Exactly-Once Delivery Semantics
- Publish-Subscribe Patterns & Event Notifications vs Event-Carried State Transfer
- Event Streaming vs Message Queuing
- Outbox + CDC Integration

### Messaging & Event-Driven Architecture

- Message Brokers Deep Dive (Kafka, Pulsar, RabbitMQ, NATS, ActiveMQ)
- Competing Consumers & Partitioned Processing
- Request-Reply over Async Messaging
- Message Ordering Guarantees (per-key, global)
- Event-Driven Architecture Best Practices

### Microservices & Decomposition Patterns

- Domain-Driven Design (DDD): Bounded Contexts, Aggregates, Ubiquitous Language
- Strangler Fig Pattern
- Backend-for-Frontend (BFF)
- API Composition vs. Database per Service
- Micro-frontend Architecture
- Service Granularity Trade-offs & Decomposition Strategies
- Independent Deployability & Evolutionary Architecture

### Fault Tolerance & Resilience Architecture

- Circuit Breaker Pattern
- Bulkhead Isolation Pattern
- Dead Letter Queues (DLQ) & Poison Pill Handling
- Exponential Backoff with Jitter Retries
- Rate Limiting (Token Bucket, Leaking Bucket, Sliding Window Log, Fixed Window)
- Load Shedding & Request Throttling
- Graceful Degradation / Fallbacks
- Chaos Engineering (Fault Injection)
- Failover & Active-Active / Active-Passive Clustering

### Data Architecture, Scaling, & Storage

- Database Sharding & Horizontal Partitioning
- Consistent Hashing
- Change Data Capture (CDC)
- Write-Ahead Logging (WAL)
- Log-Structured Merge-trees (LSM Trees) vs B-Trees
- Read Replicas & CQRS-driven Data Synchronization
- Cache Aside, Write-Through, Write-Back, and Refresh-Ahead Caching
- Cache Stampede / Thundering Herd Mitigation
- Vector Databases & Similarity Search (HNSW, IVF-Flat)
- Data Lakehouse (Iceberg, Hudi, Delta Lake)
- Materialized Views & Incremental Computation

### Big Data, Streaming & Analytics

- Kappa vs. Lambda Architecture
- Stream Processing (Flink, Spark Streaming, ksqlDB, Beam)
- OLAP vs. OLTP vs. HTAP
- Feature Stores

### Distributed Coordination & Network Protocols

- Consensus Algorithms (Raft, Paxos, Zab)
- Gossip Protocol & Cluster Membership
- Heartbeating & Lease Mechanisms
- Vector Clocks & Lamport Timestamps (Logical Time)
- CAP Theorem & PACELC Theorem Trade-offs
- Leader Election Algorithms (Bully, Raft-based, ZooKeeper)
- Fencing Tokens & Epoch Numbers
- Quorum Systems
- CRDTs (Conflict-free Replicated Data Types)
- Tombstones & Version Vectors
- gRPC, Protocol Buffers, and FlatBuffers
- WebSockets, Server-Sent Events (SSE), and HTTP/3 (QUIC)
- TCP Flow Control, Congestion Control, and Window Scaling

### Networking, Service Discovery & Traffic Management

- Service Discovery (Consul, Eureka, DNS-based)
- Client-Side Load Balancing vs Server-Side
- Traffic Shifting, Mirroring, Shadowing
- mTLS + SPIFFE/SPIRE

### Security, Identity, & Governance

- OAuth 2.1, OIDC (OpenID Connect), and SAML
- Zero Trust Architecture & Microsegmentation
- Mutual TLS (mTLS) Authentication
- JWT (JSON Web Token) Revocation Strategies (Blacklisting, Refresh Token Rotation)
- Role-Based Access Control (RBAC) & Attribute-Based Access Control (ABAC)
- Data Anonymization, Pseudonymization, and Tokenization
- Encryption at Rest, in Transit, and in Use (Confidential Computing)
- Secrets Management
- Data Residency & Sovereignty
- Supply Chain Security (SBOM, SLSA)
- Audit Logging & Immutable Logs

### Infrastructure, Deployment, & Observability

- Blue-Green Deployments & Canary Releases
- Service Mesh Architecture (Sidecar Pattern)
- Distributed Tracing (OpenTelemetry, W3C Trace Context)
- Structured Logging & Log Aggregation
- Metric Cardinality Optimization
- API Gateways (Dynamic Routing, SSL Termination, Request Transformation)
- Edge Computing & Global Server Load Balancing (GSLB)
- Observability Pipelines (Metrics, Logs, Traces correlation)
- Synthetic Monitoring & Real User Monitoring (RUM)

### Reliability, SRE & Operations

- Error Budgets & SLOs/SLIs/SLAs
- Alert Fatigue Reduction & On-call Best Practices
- Postmortem Culture & Blameless Analysis
- Probe-based Health Checks

### Cost, Sustainability & Modern Topics

- FinOps & Cloud Cost Optimization
- Resource Quotas & Multi-tenancy
- Carbon-aware Computing
- Capacity Planning & Predictive Scaling
- eBPF-based Observability & Networking
- WebAssembly (Wasm) in Edge/Serverless
- Formal Verification & TLA+
- GitOps & Progressive Delivery
