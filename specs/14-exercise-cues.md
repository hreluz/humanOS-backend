# SPEC 14 — Coaching cues for every exercise

> **Status:** Draft
> **Depends on:** SPEC 08, SPEC 09, SPEC 11
> **Date:** 2026-10-26
> **Objective:** Give each of the 759 imported exercises with no `cue` a one-line coaching cue in the style of the curated ones, written to rules in `docs/exercise-tagging.md`, and make `cue` required in the catalog test.

## Why this spec exists

The curated exercises (SPEC 08) and the 23 exercises added by hand to close coverage gaps (SPEC 09) each have a cue: one imperative line about form, such as "Elbows pinned to your sides, no swinging." The 759 exercises imported from free-exercise-db have `cue: null`, because free-exercise-db has none and writing them was too much for SPEC 09. Clients show the cue on every exercise card, so 82% of the catalog now shows nothing there. free-exercise-db's `instructions` are long, multi-step texts; a cue is the one thing to remember mid-set.

| File | Exercises | Without a cue |
|---|---|---|
| `curated.json` | 145 | 0 |
| `strength.json` | 536 | 513 |
| `olympic.json` | 56 | 56 |
| `conditioning.json` | 73 | 73 |
| `stretching.json` | 117 | 117 |
| **Total** | **927** | **759** |

## Scope

**In:**

- Cue rules added to `docs/exercise-tagging.md`.
- A cue for every exercise that has none, written in the data files.
- `ExerciseCatalogTest`: every exercise has a cue that follows the format rules.
- `docs/exercise-tagging.md` and SPEC 09's import rule 9 updated: new imports need a cue too.

**Out of scope:**

- Rewriting the existing 168 cues (curated and hand-added). They're the style reference.
- Longer coaching content (common mistakes, regressions, progressions), video, audio.
- Translations.
- Changing `instructions`.
- A schema change. The `cue` column stays nullable, so a future user-created exercise can omit it; only the seeded catalog requires one.

## Cue rules (`docs/exercise-tagging.md`)

1. **One line, imperative, about form.** It is the one thing to remember during the set: the position, the path or the most common mistake. ("Hinge with a flat back until you feel the hamstrings.")
2. **At most 90 characters**, one sentence or two short clauses joined by a comma, ending with a period. The longest curated cue is 89 characters.
3. **Specific to the exercise.** A cue that fits every exercise of its kind ("Keep good form.", "Control the weight.") is not allowed. Variants of one movement get cues that name what makes them different (the grip, the stance, the bench angle).
4. **No numbers or programming**: no reps, sets, tempo counts or seconds. Holds and stretches say what to feel or where to stop, not how long.
5. **No medical or safety claims** ("protects your knees"). Say what to do, not why it is safe.
6. **Plain words.** Muscle names only when they help the athlete feel the movement ("feel it in the hamstrings"). No anatomy jargon.
7. **Stretches and foam rolling:** where to feel it, or how to move into it ("Sink the hips forward until the front of the hip opens.", "Roll slowly, pause on tender spots.").
8. **Whole-body lifts:** the single most important point of the lift ("Bar close, drive the hips through before you pull.").
9. **Grounded in the instructions.** A cue must agree with free-exercise-db's `instructions` for that exercise. It can't contradict them or add a technique they don't describe.

## Process

- A cue for each exercise is drafted from its name, tags and `instructions`, then read and corrected by hand against the rules. Drafting can be done with an LLM, but nothing is committed without that review, the same standard SPEC 09 used for tags.
- One PR per batch, so each diff is reviewable:

| PR | File | Cues |
|---|---|---|
| 1 | Cue rules and the format test (cue still optional) + `olympic.json`, `conditioning.json` | 129 |
| 2 | `strength.json`, chest, shoulders and arms (by first primary) | ~250 |
| 3 | `strength.json`, core, back and legs | ~260 |
| 4 | `stretching.json`, and the test now requires a cue | 117 |

- The format test lands in PR 1 and checks only cues that are set. PR 4 turns on "every exercise has a cue".
- The PR description lists cues where the instructions were unclear or contradicted the exercise name, and what was chosen.

## Tests (`ExerciseCatalogTest`)

- **Format** (from PR 1): every non-null cue is at most 90 characters, has no line break, starts with a capital letter and ends with a period.
- **Not generic** (from PR 1): no cue appears on more than three exercises. Repeated cues usually mean a copy-paste across variants (rule 3).
- **Required** (from PR 4): every exercise in the catalog has a non-empty cue.

## Implementation plan

All commands run inside the `app` container. Branch from `master` after SPEC 09 is merged.

1. **Rules and format test.** Add the cue rules to `docs/exercise-tagging.md`, change SPEC 09 import rule 9 to "write a cue", and add the format and not-generic tests. Check: the suite passes with the existing cues.
2. **Batches.** For each batch, draft, review, write the cues into the file and run the suite. Check: the format tests pass, and the PR lists the judgement calls.
3. **Require cues.** In the last PR, add the required-cue test. Check: `composer test` passes, and no exercise in the seeded database has a null `cue`.
4. **Housekeeping.** `vendor/bin/pint --test`. `docs/openapi/v1.json` is unchanged (`cue` is already a nullable string in the exercise resource, SPEC 11; it stays nullable because the column is).

## Acceptance criteria

- [ ] `docker compose exec app composer test` passes and `vendor/bin/pint --test` reports no issues.
- [ ] Every exercise in `database/data/exercises/*.json` has a cue, and so does every exercise in the seeded database.
- [ ] Every cue is at most 90 characters, one line, starts with a capital letter and ends with a period.
- [ ] No cue is shared by more than three exercises.
- [ ] `docs/exercise-tagging.md` has the cue rules, and new imports must write a cue.
- [ ] The existing 168 cues are unchanged.

## Decisions

- **Yes:** cues written into the committed data files, reviewed in diffs, same as tags. The data file stays the source of truth (SPEC 08).
- **Yes:** drafts may come from an LLM, with every cue reviewed by hand. 759 cues written from scratch is days of work; unreviewed generated text is the risk SPEC 09 already refused to take for tags.
- **No:** deriving the cue from the first instruction step. The first step is almost always setup ("Lie on a flat bench…"), not the point to remember.
- **Yes:** a 90-character limit. It fits one line on a phone card, and the curated cues already fit.
- **Yes:** the column stays nullable; only the catalog test requires a cue. User-created exercises (a future spec) may not have one.
- **Yes:** four PRs. Around 250 cues is the most one review can read carefully.

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Cues that sound right but describe the wrong technique. | Rule 9 (agree with the instructions), hand review of every cue, and listed judgement calls in each PR. |
| Generic or repeated cues across near-identical variants. | Rule 3 and the "no cue on more than three exercises" test. |
| The style drifts from the curated cues. | The curated cues are the reference; the format test fixes length and punctuation. |
| Instructions in free-exercise-db are wrong for some exercises. | The PR lists those cases; the cue follows the movement the name describes, and the instructions stay as imported. |
