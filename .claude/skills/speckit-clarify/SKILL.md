---
name: speckit-clarify
description: Identify underspecified areas in the current feature spec by asking up to 5 targeted clarification questions and encoding answers back into the spec.
user-invocable: true
disable-model-invocation: false
argument-hint: Optional areas to clarify in the spec
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Outline

Goal: Detect and reduce ambiguity in the active feature spec BEFORE planning. Each answer is immediately written back into the spec.

### Step 1 — Load Context

- Read `.specify/feature.json` → `FEATURE_DIR`, `FEATURE_SPEC`
- Read `/memory/constitution.md` for governance constraints
- Load the current spec file

### Step 2 — Ambiguity Scan

Scan the spec against this taxonomy. Mark each category: Clear / Partial / Missing:

**Functional Scope**: Core user goals, explicit out-of-scope, persona differentiation
**Domain & Data Model**: Entities, attributes, identity rules, lifecycle, data volume
**Interaction & UX**: Critical journeys, error/empty/loading states, a11y/localization
**Non-Functional**: Performance targets, scalability limits, reliability, observability, security, compliance
**Integration & Dependencies**: External services, failure modes, data formats, protocol assumptions
**Edge Cases & Failures**: Negative scenarios, rate limiting, conflict resolution
**Constraints & Tradeoffs**: Technical constraints, explicit tradeoffs, rejected alternatives
**Terminology**: Canonical glossary terms, avoided synonyms
**Completion Signals**: Acceptance criteria testability, measurable Definition of Done
**Placeholders**: TODO markers, vague adjectives ("robust", "intuitive") lacking quantification

### Step 3 — Prioritize Questions

- Maximum **5 questions** total
- Each answerable by: multiple-choice (2-5 options) OR short answer (≤5 words)
- Only ask if answer materially impacts: architecture, data model, tasks, tests, UX, operations, compliance
- Rank by (Impact × Uncertainty) — highest first

### Step 4 — Sequential Questioning (ONE at a time)

**For multiple-choice:**
```
**Recommended:** Option [X] — <1-2 sentence reasoning>

| Option | Description |
|--------|-------------|
| A | ... |
| B | ... |
| Short | Provide your own (≤5 words) |

Reply with letter, "yes"/"recommended", or your own answer.
```

**For short-answer:**
```
**Suggested:** <answer> — <brief reasoning>
Format: ≤5 words. Reply "yes" or provide your own.
```

### Step 5 — Integrate After EACH Answer

1. Add to `## Clarifications > ### Session YYYY-MM-DD`: `- Q: <question> → A: <answer>`
2. Immediately update the relevant spec section:
   - Functional ambiguity → update Functional Requirements
   - User interaction → update User Stories
   - Data shape → update Key Entities
   - Non-functional → update Success Criteria with measurable target
   - Edge case → add to Edge Cases
   - Terminology → normalize across spec
3. Replace (don't duplicate) invalidated statements
4. Save spec after each integration

### Step 6 — Stop Conditions

- All critical ambiguities resolved
- User signals "done", "good", "no more"
- 5 questions reached

### Step 7 — Report

- Questions asked & answered count
- Sections touched
- Coverage summary per taxonomy category: Resolved / Deferred / Clear / Outstanding
- Suggested next command (`/speckit-plan` or re-run `/speckit-clarify`)

## Key Rules

- NEVER exceed 5 questions
- End each answer by asking nothing more than "your choice?"
- If no meaningful ambiguities found: "No critical ambiguities detected. Proceed to `/speckit-plan`."
- Skip speculative tech stack questions — those belong in plan phase
- Respect early termination: "stop", "done", "proceed"

## Done When

- [ ] Ambiguities identified and answers integrated into spec
- [ ] Updated spec saved with `## Clarifications` section
- [ ] Coverage summary reported
- [ ] Next step suggested
