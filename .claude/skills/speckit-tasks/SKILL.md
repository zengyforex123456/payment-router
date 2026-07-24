---
name: speckit-tasks
description: Break the implementation plan into actionable, dependency-ordered tasks organized by user story.
user-invocable: true
disable-model-invocation: false
argument-hint: Optional task generation constraints
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Outline

1. **Discover feature**: Read `.specify/feature.json` or scan `specs/` for latest.

2. **Load context**: spec.md (user stories), plan.md (tech stack), data-model.md, contracts/.

3. **Generate tasks.md** in phases:

   **Phase 1: Setup** — Init, deps, tooling config
   **Phase 2: Foundational** — Core infra all stories depend on (BLOCKS all)
   **Phase 3+: User Stories** — One phase per story in priority order (P1→P2→P3)
     - Tests first (write, ensure FAIL), then implementation
     - Each story independently testable
   **Phase N: Polish** — Cross-cutting: a11y, perf, edge cases, docs

4. **Task format**: `[ID] [P?] [Story] Description with exact file paths`
   - `[P]` = parallelizable (different files, no deps)
   - `[Story]` = US1/US2/US3 for traceability

5. **Report**: task count, phase breakdown, parallel opportunities, MVP scope

## Key Rules

- Every task MUST reference specific file paths
- Tests BEFORE implementation in each story phase
- Foundational phase BLOCKS all user stories
- Mark parallel tasks with [P]

## Done When

- [ ] tasks.md with all phases, file paths, story labels
- [ ] MVP scope identified (Phase 3 = US1 only)
- [ ] Reported ready for `/speckit-implement`
