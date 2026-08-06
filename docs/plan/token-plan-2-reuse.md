# Plan 2 — Reuse

**Status:** proposal. Nothing here is implemented.
**Scope:** opt-in. Every item requires the consuming application to decide something first.
**Companion:** [Plan 1 — Wire format](token-plan-1-wire-format.md). Source analysis: [../token-cost.md](../token-cost.md).

## The boundary

An item is here when the package **cannot ship it without the consumer answering a question the
package has no business answering** — where to persist, how long to keep it, whether bound values
may be stored, whether a stale answer is acceptable.

This is the same line the package already draws for auditing: `QueryPlanExecuted` is dispatched,
and no migration ships, because retention and redaction are decisions only the application can
make. Everything below inherits that stance.

Plan 1 items apply to everyone on upgrade. Plan 2 items apply to nobody until they are configured.

## Why this plan is the durable one

The two plans attack different bills:

| | what it reduces | billed |
|---|---|---|
| **Plan 1** | the standing tool payload | every step of every turn, including turns that never query |
| **Plan 2** | plan *generation* | turns that actually query |

If `cache_control` lands upstream in `laravel/ai` (Plan 1, item 7), the standing payload starts
being billed at roughly a tenth of input price and most of Plan 1's value evaporates with it.
Nothing in Plan 2 changes. A plan the model never generates is free at any price per token, and
stays free.

So Plan 1 is the urgent one and Plan 2 is the lasting one. That ordering is deliberate and should
survive an upstream fix.

## Two invariants

Everything below obeys both. They are not per-item caveats; they are the plan's correctness
argument.

**1. Cache plans. Never cache rows.**

A validated plan is user-independent — it names columns and operators, not data. Replaying one
re-runs it through the compiler, which re-applies `alwaysScope` under the *current* user. That is
precisely what makes reuse safe: the tenant boundary is re-derived on every execution rather than
carried in the cache.

Caching rows would hand one tenant another tenant's data on a cache hit. No configuration option
should make that reachable.

**2. Key on the contract fingerprint, and always re-validate.**

`SchemaContract::fingerprint()` (Plan 1, item 7) is the cache key component that makes staleness
impossible to ignore: a schema edit, a removed column, or a `visibleWhen` that resolves
differently for this user all change the fingerprint and therefore miss.

Re-validation on replay is not optional and is not expensive — `PlanValidator` costs zero tokens.
There is no scenario where skipping it buys anything worth having.

## The decisions this plan asks of a consumer

| item | the question the application must answer |
|---|---|
| 1. Saved queries | Which questions are worth naming? Config file or database? |
| 2. Exact replay | Is a stale answer ever acceptable, and for how long? |
| 3. Negative cache | May refusals be stored, and for how long? |
| 4. Retrieval few-shot | Is an embeddings dependency and a vector store worth it? |
| 5. Storage | Nothing. The package ships no table; attribution is a userland event subscriber. |

The package ships the mechanism and a default that requires no infrastructure where one is
possible. It ships no migration and no retention policy.

## Configuration

Items 2 and 3 need somewhere to put things. That is a cache, and Laravel already has one.

```php
'cache' => [

    // Null uses the application's default store. Name one explicitly if the
    // default is per-server (file) and the application runs on more than one.
    'store' => null,

    'prefix' => 'ai-query',

],

'reuse' => [

    'replay' => ['enabled' => false, 'ttl' => 300],

    'refusals' => ['enabled' => false, 'ttl' => 3600],

],
```

**Default to the application's default store: yes.** It is the idiomatic Laravel default, it needs
no configuration to work, and an application on `array` (the usual test default) gets a silent
no-op rather than a failure. The one thing worth documenting is that `file` is per-server, so a
multi-node application gets a per-node cache — correct, just less effective than it looks.

**Default the reuse features to on: no.** Not because caching is risky in general, but because of
what these two cache. Turning replay on by default means an application that upgrades and changes
nothing can start returning an answer generated from a different prompt at a different time,
without anyone having chosen that. This package flags truncated results rather than passing them
off as complete, and rejects unknown plan keys rather than dropping them, precisely because a
plausible wrong answer is worse than a visible failure. A stale answer is the same category, and
it should not arrive by upgrade.

So: one cache block that says *where*, and per-feature switches that say *whether*, defaulting
off. A consumer who wants replay writes one line. A consumer who does not gets no behaviour
change.

**The key is the fingerprint, and it does more work than it looks like.** A replay entry is keyed
on `(normalized prompt, resource, contract fingerprint)` — with no user identifier, deliberately.
Invariant 1 says plans are user-independent, and the fingerprint already encodes what *this* user
can see, so two users with identical visibility share an entry and that sharing is exactly the
win. What they are not sharing is any tenant value: `alwaysScope` is applied by the compiler at
execution, never stored in the plan, so a shared plan is re-scoped per user on every hit. A user
who can see fewer columns fingerprints differently and misses.

Package version belongs in the prefix. A change to how plans compile must not hit entries written
by an older version.

## 1. Named saved queries

**Build this first.** Best return, and the only item here with no correctness hazard the validator
does not already handle.

Promote a validated plan to a named query with typed holes, and expose the set as a second, small
tool:

```php
// config/ai-query-builder.php
'saved' => [
    'weekly_delivery_report' => [
        'plan' => [ /* a plan that already validated */ ],
        'params' => ['since' => 'date'],
    ],
],
```

The tool's schema is an enum of names plus a handful of typed parameters — a few hundred chars
against the full plan schema's several thousand. Recurring questions ("the weekly report", "open
invoices by customer") stop paying for plan generation entirely.

Parameters are substituted into the stored plan's literal positions and the result goes through
`PlanValidator` unchanged. A saved query is not a privileged path: it is a plan like any other,
and it is validated like any other. A saved plan that references a column the current user cannot
see is rejected as *unknown*, exactly as if the model had guessed it.

**The consumer decision:** where the registry lives. A config array is the zero-infrastructure
default and covers the dashboard case. A table is what an application wants if users are to save
their own queries — and that is a Plan 2 decision inside a Plan 2 item, which is why the registry
should be a contract with a config-array driver shipped, not a hardcoded config read.

**Verify:** a saved query with a parameter that violates the schema is rejected with the same
error shape as a model-generated plan; a saved query naming a hidden column is rejected as
unknown for a user who cannot see it and runs for a user who can.

**Size:** M.

## 2. Exact replay

Hash the normalized prompt, look up the plan, re-validate, re-run. Zero model tokens on a hit.

The hazard is that the same words do not mean the same query over time. "This week", "recent",
"the last 30 days" all resolve to a literal at generation time and bake it into the stored plan.
A replay of `between ['2026-08-01', '2026-08-07']` will confidently answer last week's question
next month — and it will look right, which is the worst failure mode this package has. It is the
same class of error the truncation flag and the fan-out guard exist to prevent: a plausible,
inflated, unlabelled wrong answer.

Three mitigations, in order of preference:

1. **Only cache plans with no temporal literal.** Detectable: the validator already knows each
   column's declared type, so a plan filtering a date column with a literal is exactly the case to
   refuse to cache. This is the honest default.
2. **Template the literal into a parameter** and promote the plan to item 1, where the caller
   supplies the date.
3. **A TTL short enough that the drift cannot matter.** Weakest of the three, because the right
   TTL depends on the question and the cache does not know the question.

Ship (1) as the default and make (3) opt-in rather than the other way round.

**The consumer decision:** whether a stale answer is ever acceptable, and the store and TTL.

**Verify:** a plan containing a date literal is not cached under the default policy; a cache entry
built under one contract fingerprint misses after a schema edit.

**Size:** M, and most of it is the temporal-literal detection.

## 3. Negative cache

Some questions are structurally refusable. The fan-out guard rejects "total invoice value by
product type" every single time it is asked, at full token cost, every time.

Record refusals by `(normalized prompt, contract fingerprint, error code)` and let the tool answer
immediately with the explanation and the suggested rephrasing. Cheaper, and a better experience
than the model rediscovering the same wall on every conversation.

Scope it to **structural** rejections — `fan_out_aggregate`, `unsupported_relation` — and not to
`unknown_column`, which is usually a typo the model corrects on the next step and would poison the
cache with a transient mistake. `QueryPlanRejected` already carries the codes needed to make that
distinction.

**The consumer decision:** whether refusals may be stored, and for how long. Lighter than the
others — no rows, no bound values, just prompts and codes — but a prompt is user text and that is
not nothing.

**Verify:** a refusal that is retried returns the cached explanation without a model call; an
`unknown_column` rejection is not cached.

**Size:** S. It uses the cache, so it needs no storage of its own.

## 4. Retrieval few-shot

Store `(prompt, plan)` pairs for successful queries, retrieve the top few by similarity, inject
them as examples.

On its own this is a poor trade: it *adds* tokens to every turn to save tokens on some of them.

It becomes interesting paired with Plan 1's `Generic` detail level. Three concrete worked examples
plausibly steer a model as well as an exhaustive column enum, and unlike the enum they are
**bounded** — three examples cost the same whether the resource has 8 columns or 800. That is the
same asymptotic argument that makes the generic schema worth having, applied to the steering that
the generic schema gives up.

Measure it against rejection rate before committing to either. If `Generic` alone holds the
rejection rate steady, this is unnecessary; if it does not, this is the cheapest way to recover
the accuracy without reintroducing an unbounded payload.

**The consumer decision:** an embeddings dependency and a vector store, which is the heaviest ask
in either plan.

**Size:** L. Do it last, or not at all.

## 5. Storage — the cache, and nothing else

**Decided 2026-08-06: the package ships no table.** The shared cache is the only storage this plan
builds. Per-user and per-resource tracking stays in userland, on the events that already exist.

"History" had been doing double duty, which is what made scoping it feel underspecified. Reuse
wants plans shared as widely as safety allows, values intact, and does not care who asked. Audit
wants every execution attributed, retained for a fixed period, possibly redacted, and shared with
nobody. One table serves both badly: attribution narrows what reuse can match on, and retained
bound values are exactly what an audit policy may need to strip.

Separating them showed that reuse never needed a table at all. A cache entry keyed on the contract
fingerprint is shared by construction — two users who see the same columns share it, which is the
whole win — and there is no owner column to get in the way. Everything the log was for is
attribution, and the events already carry it.

### What userland does instead

`QueryPlanExecuted` carries `plan`, `sql`, `bindings`, `rowCount`, `durationMs`, `truncated`,
`user` and `prompt`. `QueryPlanRejected` carries `resource`, the rejected `input`, `errorCodes()`
and the same `user` and `prompt`. `$event->plan->resource` and `$event->plan->toArray()` give the
resource and the plan structure.

That is everything a per-user or per-resource history needs, so an application that wants one
writes a subscriber against its own table, with its own columns, retention and redaction:

```php
Event::listen(QueryPlanExecuted::class, function (QueryPlanExecuted $event) {
    QueryLog::create([
        'user_id' => $event->user?->getAuthIdentifier(),
        'resource' => $event->plan->resource,
        'plan' => $event->plan->toArray(),
        'rows' => $event->rowCount,
        'duration_ms' => $event->durationMs,
    ]);
});
```

No polymorphic owner to design, no resolver to register, no prune window to configure, no migration
to publish. An application wanting team-level attribution writes `team_id`; one wanting none writes
nothing. This is the stance the package already takes on auditing, and the README already documents
the shape — so this is one story for both, not a second mechanism.

### What this defers, and what would bring it back

Two designs are parked rather than discarded:

- **A plan catalog** — plan shapes keyed by fingerprint, literals lifted to parameters, no prompt
  and no user. It is the natural first table if one is ever needed, because it inherits the cache's
  sharing model and raises no attribution or redaction question. The trigger is item 1 showing that
  promoting plans to saved queries by hand is the bottleneck.
- **A polymorphic owner log.** The trigger is an application needing accountability the events
  cannot give it — which, since the events carry the user, means wanting the *package* to own
  retention rather than the application. That is a weak trigger, and this staying parked
  indefinitely is a fine outcome.

**Verify:** the package ships no migration; with both reuse features disabled, nothing is written
anywhere.

**Size:** none. This is a decision not to build.

## Sequence

**Plan 1 comes first in full.** Its savings apply to everyone on upgrade; nothing here applies
until it is configured.

1. **Item 1 — saved queries**, config-array registry. No hazard, immediate return, no storage.
2. **The cache block and item 3 — negative cache.** Smallest useful thing that needs configuration,
   and it exercises the fingerprint key before anything depends on it being right.
3. **Item 2 — exact replay**, with temporal-literal detection as the default policy.
4. **Item 4 — retrieval few-shot**, only if Plan 1 item 4a's measured rejection rate says it is
   needed, and only against storage the application provides.

No step needs a migration, and that is the point: the cache serves reuse, the events serve
attribution, and the database layer gets reached for only when a table is wanted for its own sake.

## Open questions

- **Is prompt normalization worth attempting at all?** Items 2 and 3 both key on a normalized
  prompt, and normalization is a heuristic that will collide two questions that differ in a way
  that matters. Keying on the *model's chosen plan* instead of the prompt is exact but only
  available after generation, which is the cost being avoided. There may be no good answer here,
  and if there is not, item 1's explicit naming is the honest alternative to both.
- **Should saved queries be visible to the model at all?** Exposing them as a tool lets the model
  choose one; exposing them only to application code makes them a developer feature. The first is
  the token win; the second is the safer default.
- **Can literals be lifted into parameters reliably?** Item 2's temporal policy assumes a plan can
  be templated — that a filter value can be identified as a parameter rather than part of the
  query's identity. `status = 'paid'` is identity; `issued_at between [...]` is a parameter;
  `total > 500` could be either. If that distinction cannot be drawn automatically, item 2 reduces
  to item 1 with a human naming the query, which may simply be the right answer.
- **Does replay belong in this package?** Every mechanism here is application-shaped. The package's
  contribution might be `fingerprint()`, a documented replay recipe, and nothing else — which
  would be consistent with how auditing is handled and is the smallest thing that could work.
