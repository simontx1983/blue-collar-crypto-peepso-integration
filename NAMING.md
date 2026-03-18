# BCC Trust Engine — Naming Conventions

Canonical domain language for the BCC Trust Engine ecosystem.
All new code must follow these conventions. Existing code should be
migrated incrementally (see "Migration Status" at the bottom).

---

## Canonical Terms

| Term              | Definition                                                      | Use In                        |
|-------------------|-----------------------------------------------------------------|-------------------------------|
| **Page**          | A PeepSo Page entity — the primary object users create          | DB columns, PHP, REST, UI     |
| **Page ID**       | The WordPress post ID of a PeepSo Page (`page_id`)             | DB FK columns, meta keys      |
| **Category**      | PeepSo page classification (Validator, Builder, DAO, NFT)       | `category_id`, `bcc_get_category_map()` |
| **Segment**       | A UI tab / route within the PeepSo page dashboard               | PeepSo hooks only             |
| **Domain**        | The CPT-backed business model (Validator, Builder, DAO, NFT)    | PHP class layer               |
| **Trust Score**   | The computed aggregate trust metric for a page                   | UI labels, API responses      |
| **Vote**          | A user's up/down trust signal on a page                         | DB, PHP, REST, UI             |
| **Endorsement**   | A weighted, contextual trust signal from a verified user         | DB, PHP, REST, UI             |
| **Vote Weight**   | Fraud-adjusted multiplier applied to a vote                      | `weight` column in votes table |
| **Endorsement Boost** | Fixed-value trust bonus from an endorsement                 | `weight` column in endorsements table |
| **Owner**         | The WordPress author of a page / CPT (`post_author`)             | PHP permissions layer         |

---

## Deprecated Terms (Do Not Use)

| Deprecated         | Replacement        | Reason                                        |
|--------------------|--------------------|-----------------------------------------------|
| `project`          | `page`             | PeepSo calls them Pages; one canonical name   |
| `credibility score`| `Trust Score`      | Standardized user-facing label                |
| `page score`       | `Trust Score`      | Standardized user-facing label                |
| `trust rating`     | `Trust Score`      | Standardized user-facing label                |
| `type` (for domain)| `domain` or `category` | `type` is ambiguous with `post_type`     |

---

## Layer-Specific Conventions

### Database

- Table prefix: `bcc_trust_` for trust tables, `bcc_` for plugin tables
- FK to PeepSo pages: always `page_id` (never `project_id`)
- FK to users: `{role}_user_id` (e.g., `voter_user_id`, `endorser_user_id`)
- Timestamps: `created_at`, `updated_at`
- No ENUMs — use VARCHAR with PHP validation or lookup tables

### PHP

- Repositories: `{Entity}Repository` (e.g., `ScoreRepository`)
- Value Objects: `{Entity}` (e.g., `PageScore`)
- Domain models: `BCC_Domain_{Name}` (e.g., `BCC_Domain_Validator`)
- Controllers: `{Feature}Controller` or `BCC_Ajax_{Feature}`
- Variables referencing a PeepSo page ID: `$pageId` or `$page_id`

### REST API

- Namespace: `bcc-trust/v1` (trust operations), `bcc/v1` (discovery)
- Page-scoped: `/page/{id}/...`
- Collections: `/pages/...`
- User-scoped: `/user/{id}/...`
- Actions: `POST /vote`, `POST /endorse` (verb-based)

### Meta Keys

- Plugin-owned: `_bcc_{key}` (e.g., `_bcc_visibility`)
- PeepSo linkage: `_peepso_page_id`
- Cross-CPT linkage: `_linked_{cpt_slug}_id`
- Category reference: `_peepso_cat_id`

### User-Facing UI

- Always **"Trust Score"** (capitalized, two words)
- Always **"Vote"** for up/down signals
- Always **"Endorsement"** for contextual trust boosts
- Always **"Page"** when referring to the entity users create in PeepSo

---

## Known Legacy Inconsistencies (Pending Migration)

These exist in the codebase and will be addressed in a future schema migration:

| Issue                                | Location                        | Status   |
|--------------------------------------|---------------------------------|----------|
| `project_id` column (= `page_id`)   | `bcc_project_scores`, `bcc_project_identities`, `bcc_project_metrics_history` | Deferred |
| `bcc_project_*` table prefix         | Project analytics tables         | Deferred |
| `validators` (plural) vs `builder` (singular) CPT slugs | CPT registration | Deferred |
| Dual REST namespace (`bcc-trust/v1` + `bcc/v1`) | Controllers       | Deferred |

---

## Migration Status

- **Phase 1 (Complete):** UI labels, comments, documentation standardized
- **Phase 2 (Pending):** PHP alias methods, internal variable renames
- **Phase 3 (Pending):** Database column/table renames with compatibility layer
- **Phase 4 (Pending):** CPT slug normalization
