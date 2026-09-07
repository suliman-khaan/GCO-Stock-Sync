# GCO Stock Sync — Development Plan & Test Cases

This folder contains the complete development plan, test cases, and quality gates for every phase of the **GCO Supplier Stock Sync** plugin.

## Folder Structure

```
development-plan/
├── README.md                           ← You are here
├── PROGRESS.md                         ← Live status tracker — READ THIS FIRST when resuming or switching AIs
├── master-plan.md                      ← Full implementation plan overview
├── phase-1-feed-reconnaissance/
│   ├── plan.md                         ← Phase 1 tasks & deliverables
│   └── test-cases.md                   ← Phase 1 verification checklist
├── phase-2-plugin-skeleton/
│   ├── plan.md                         ← Phase 2 tasks & deliverables
│   └── test-cases.md                   ← Phase 2 automated test specs
├── phase-3-supplier-connector/
│   ├── plan.md                         ← Phase 3 tasks & deliverables
│   └── test-cases.md                   ← Phase 3 automated test specs
├── phase-4-sync-engine/
│   ├── plan.md                         ← Phase 4 tasks & deliverables
│   └── test-cases.md                   ← Phase 4 automated test specs (CRITICAL)
├── phase-5-admin-ui/
│   ├── plan.md                         ← Phase 5 tasks & deliverables
│   └── test-cases.md                   ← Phase 5 automated test specs
├── phase-6-hardening-release/
│   ├── plan.md                         ← Phase 6 tasks & deliverables
│   └── test-cases.md                   ← Phase 6 automated test specs
└── phase-7-deployment-handover/
    ├── plan.md                         ← Phase 7 tasks & deliverables
    └── test-cases.md                   ← Phase 7 manual verification checklist
```

## How to Use

0. **Check `PROGRESS.md` first** — it tracks live status across sessions/AIs:
   what's done, what's in progress, and the exact next step.
1. **Start with `master-plan.md`** for the big picture.
2. **Work one phase at a time.** Each phase folder has its own `plan.md` (what to build) and `test-cases.md` (how to verify).
3. **Do not start a new phase until the previous phase's gate is passed.**
4. **Each phase's test-cases.md includes:** test IDs, descriptions, preconditions, steps, expected results, and priority levels.

## Phase Summary

| Phase | Name | Type | Est. Effort |
|-------|------|------|-------------|
| 1 | Feed Reconnaissance | Research / Manual | 2–4 hours |
| 2 | Plugin Skeleton & Lifecycle | Code | 6–8 hours |
| 3 | Supplier Connector | Code | 6–8 hours |
| 4 | Sync Engine | Code (Critical) | 8–12 hours |
| 5 | Admin UI | Code | 8–10 hours |
| 6 | Hardening & Release | Code + QA | 6–8 hours |
| 7 | Deployment & Handover | Ops + Docs | 4–6 hours |
