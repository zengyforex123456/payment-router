---
name: speckit-specify
description: Create or update the feature specification from a natural language feature description.
user-invocable: true
disable-model-invocation: false
argument-hint: Describe the feature you want to specify
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Outline

The text after `/speckit-specify` **is** the feature description.

1. **Generate short name** (2-4 words, action-noun) from the description
2. **Create feature directory**: `specs/NNN-short-name/spec.md`
   - Read `.specify/init-options.json` for numbering
   - Copy from `.specify/templates/spec-template.md`
3. **Load constitution**: `.specify/memory/constitution.md` if exists
4. **Fill spec**:
   - **User Scenarios** — 2-4 user stories (P1/P2/P3), each with GWT acceptance scenarios
   - **Edge Cases** — boundary conditions, error handling
   - **Requirements** — FR-XXX, testable, no implementation details
   - **Key Entities** — if data involved
   - **Success Criteria** — SC-XXX, measurable, technology-agnostic
   - **Assumptions** — defaults and scope boundaries
5. **Validate**: Max 3 [NEEDS CLARIFICATION], all mandatory sections filled
6. **Report**: feature dir, spec path, readiness for `/speckit-plan`

### Key Rules

- WHAT and WHY, never HOW (no tech stack, APIs, frameworks)
- Each user story INDEPENDENTLY TESTABLE
- GWT format for all acceptance scenarios
- Make informed guesses for unspecified details — don't ask unnecessary questions

## Done When

- [ ] Spec written with all mandatory sections complete
- [ ] Feature directory created under `specs/`
- [ ] Reported ready for `/speckit-plan`
