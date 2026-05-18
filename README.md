# Laravel Developer — Take-Home Test

## What this test is about

This is not a syntax test. We are not measuring how fast you type or how much of the Laravel docs you have memorised.

We are measuring how you **think**, how you **use AI tools**, and how you make **engineering decisions under real-world constraints**.

All four scenarios contain intentionally naive or broken implementations. Your job is to identify the problems, reason about the tradeoffs, and improve the code — using AI however you like.

**The way you use AI is part of the evaluation.** We want to see your prompt history. Strong candidates ask targeted, specific questions and push back on AI output. Weak candidates ask AI to "fix everything" and accept whatever comes out.

---

## Requirements

- Docker and Docker Compose (no local PHP or MySQL needed)

---

## Getting started

```bash
cd laravel
./setup.sh
```

This builds the Docker image, starts MySQL, installs dependencies, runs migrations, seeds baseline data, and starts the app.

The app is available at **http://localhost:35734**

To run the queue worker (needed for Scenarios 1 and 3):

```bash
docker compose exec app php artisan queue:work
```

To run the test suite:

```bash
docker compose exec app php artisan test
```

To reset the database back to baseline at any time:

```bash
./reset.sh
```

To stop everything:

```bash
docker compose down
```

---

## Submission

For each scenario you complete, submit:

1. **Your final code** — as a PR form a fork in github
2. **Your AI conversation/prompt history** — exported from Cursor, Copilot, ChatGPT, or whichever tool you used
3. **A short written explanation** (≤500 words per scenario) covering:
   - What the problem was
   - What you changed and why
   - What tradeoffs you considered

**Time guidance:** 1 hour total. You do not need to complete all four scenarios. Pick two that you think will best demonstrate your workflow and thought process.

---

---

# Scenario 1 — Webhook Reliability

An external provider sends webhook events to your application. The current implementation stores events and dispatches a background job — but it is naive.

**Relevant files:**
- `app/Http/Controllers/WebhookController.php`
- `app/Jobs/ProcessWebhookJob.php`
- `app/Models/WebhookEvent.php`
- `database/migrations/2024_01_01_100000_create_webhook_events_table.php`

**Endpoints:**
```
POST /api/webhooks/{provider}
GET  /api/webhooks/{id}
```

**Your task:** Improve the implementation so that:

- Duplicate webhook events (same `event_id` from the same provider) do not get processed more than once
- Concurrent requests arriving simultaneously are handled safely
- Asynchronous processing still works correctly
- The solution remains simple and maintainable

You may refactor however you see fit. Do not overengineer it.

---

---

# Scenario 2 — API Performance

The orders endpoint works functionally but performs poorly as data grows. The database has been seeded with realistic volume (50 customers, 1,000 orders, 5,000 order items).

**Relevant files:**
- `app/Http/Controllers/OrderController.php`
- `app/Models/Order.php`
- `app/Models/Customer.php`
- `app/Models/OrderItem.php`

**Endpoint:**
```
GET /api/orders
```

**Your task:**

- Identify the performance issue
- Explain the root cause
- Improve the implementation
- Keep the solution maintainable

Do not add caching as a first resort. Understand the query problem before reaching for a fix.

---

---

# Scenario 3 — Resilient CSV Import

The CSV import system accepts a file, queues a background job, and imports users. But it behaves poorly when the data contains invalid rows.

**Relevant files:**
- `app/Http/Controllers/ImportController.php`
- `app/Jobs/ProcessImportJob.php`
- `app/Models/ImportJob.php`
- `database/migrations/2024_01_01_300000_create_import_jobs_table.php`

**Endpoints:**
```
POST /api/imports        (multipart/form-data, field: file)
GET  /api/imports/{id}
```

**Your task:** Improve the importer so that:

- A single invalid row does not abort the entire import
- Progress can be tracked (how many rows have been processed)
- Row-level failures are visible in the response
- The implementation remains maintainable

Avoid unnecessary enterprise abstractions. Keep it simple and operational.

---

---

# Scenario 4 — Feature Flags

The application has a basic feature flag system. Features can be globally enabled/disabled and individually assigned to users. The current implementation has inconsistencies that make it hard to maintain.

**Relevant files:**
- `app/Http/Controllers/FeatureController.php`
- `app/Http/Middleware/FeatureEnabled.php`
- `app/Models/Feature.php`

**Endpoints:**
```
GET  /api/features
POST /api/features/{feature}/assign/{user}
GET  /api/beta                                (protected by FeatureEnabled middleware)
```

**Seeded data:**
- Feature `beta` (enabled globally) — assigned to `beta@example.com`
- Feature `dark-mode` (disabled globally) — assigned to no one
- Users: `beta@example.com`, `regular@example.com` (password: `password`)

Test access via:
```
GET /api/beta?user_id=1    # should be allowed
GET /api/beta?user_id=2    # should be denied
```

**Your task:** Improve the implementation so that:

- Feature access logic is consistent and centralized
- The global `enabled` flag is respected everywhere
- The design is maintainable and easy to extend

Do not turn this into a distributed feature flag platform. Keep it pragmatic.
