---
name: speckit-plan
description: Execute the implementation planning workflow — generate design artifacts from the feature spec.
user-invocable: true
disable-model-invocation: false
argument-hint: Optional guidance for the planning phase
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Outline

1. **Discover feature**: Read `.specify/feature.json` to find current feature directory, or scan `specs/` for the latest feature. The spec is at `specs/NNN-short-name/spec.md`.

2. **Load context**: Read spec.md and `.specify/memory/constitution.md`. Copy plan template from `.specify/templates/plan-template.md` to `specs/NNN-short-name/plan.md`.

3. **Fill Technical Context**:
   - Language/Version, Primary Dependencies, Storage, Testing, Target Platform, Project Type
   - Performance Goals, Constraints, Scale/Scope
   - Mark unknowns as "NEEDS CLARIFICATION"

4. **Constitution Check**: Verify plan against each principle in constitution.md. GATE: must pass before Phase 0.

5. **Phase 0: Research**:
   - Create `research.md` — resolve all NEEDS CLARIFICATION
   - For each unknown: research best practices, alternatives considered, final decision with rationale

6. **Phase 1: Design**:
   - `data-model.md` — entities, fields, relationships, validation rules
   - `contracts/` — API endpoints, CLI interfaces, or UI contracts (if applicable)
   - `quickstart.md` — runnable validation scenarios with expected outcomes

7. **Re-evaluate Constitution Check** after design. Report branch, plan path, generated artifacts.

## Key Rules

- Use absolute paths for file operations
- ERROR on gate failures or unresolved clarifications
- Do NOT create tasks.md — that's `/speckit-tasks` job
- Keep design artifacts concise — details go in tasks.md

## Done When

- [ ] research.md complete (all NEEDS CLARIFICATION resolved)
- [ ] data-model.md + contracts/ + quickstart.md generated
- [ ] Constitution Check passed (before and after design)
- [ ] Reported ready for `/speckit-tasks`
