# Task Scope: Adjust Campaign Score Rule on Semester Merit Scoring

## Role and workflow

- Coordinator: `orchestrator`
- Reference workflow: `.agents/Workflows/orchestrator.md`
- Pipeline: `bug_fix` from `.agents/Workflows/pipeline.md`
- Primary artifact: corrected campaign scoring behavior for the page `Tinh Diem Thi Dua Hoc Ky`

## Problem statement

- Campaign points on the page `Tinh Diem Thi Dua Hoc Ky` are still being interpreted with the wrong granularity.
- Campaign score must be awarded at class level, not calculated from the number of participating students.
- If a class has at least one member participating in a selected campaign, that class must receive the score of that campaign.
- The scoring rule must not depend on how many students joined after the first valid participant is found.

## Scope

### 1. Change campaign scoring rule to class-level eligibility

- Update semester scoring logic so each selected campaign is evaluated with a binary rule for each class:
  - if the class has at least one valid participating member in that campaign, the class receives the campaign score
  - if the class has no participating member in that campaign, the class receives `0` for that campaign
- Remove any scoring logic that multiplies, scales, or derives campaign score from the number of participating students.
- Keep the rule focused on campaign participation existence, not student quantity.

### 2. Define valid participation for campaign eligibility

- A class is considered participating in a campaign when at least one member of that class has a valid participation record for the selected campaign.
- Reuse the existing participation source already used by the campaign registration or attendance flow, but only to determine whether participation exists.
- Once at least one valid participant is found, no additional student count should increase the campaign score.

### 3. Keep scoring outputs consistent across the semester scoring flow

- The preview on `Tinh Diem Thi Dua Hoc Ky` must apply the same class-level campaign rule.
- The class detail view must clearly reflect whether the class qualifies for the campaign score.
- Exported and saved semester scores must use the same campaign result as the preview.
- Any intermediate data such as `joined_quantity` may still be stored for reference, but it must not change the awarded campaign score beyond the binary eligible or not eligible rule.

## Files to inspect or update

- `public_html/controllers/scoring/calculate.php`
- `public_html/controllers/campaign_class_scores.php`
- `public_html/controllers/scoring/export.php`
- `public_html/controllers/scoring/save.php`
- `public_html/views/scoring.php`
- `public_html/assets/js/scoring.js` only if frontend labels or detail rendering need to reflect the updated rule

## Expected behavior

- If a class has at least one member participating in a selected campaign, the class receives that campaign's configured score.
- If a class has multiple participating members in the same campaign, the class still receives that campaign score only once.
- Campaign score is no longer proportional to student participation count.
- Preview, detail, export, and saved semester scores all show the same campaign result.

## Acceptance criteria

- Given a selected campaign with at least one valid participant from a class, the semester scoring preview awards the full campaign score to that class.
- Adding more participating students to the same class and campaign does not increase the awarded campaign score.
- A class with no valid participant for the selected campaign receives `0` for that campaign.
- Exported and saved semester scores match the preview after the rule change.
- Fee scoring and other non-campaign scoring rules remain unchanged.

## Suggested manual tests

1. Choose a school year and semester on `Tinh Diem Thi Dua Hoc Ky`.
2. Add at least one campaign item to the scoring configuration.
3. Prepare a class with exactly one valid participating member in that campaign.
4. Open the preview and confirm the class receives the full campaign score.
5. Add more participating members from the same class to the same campaign.
6. Refresh the preview and confirm the campaign score does not increase beyond the same awarded value.
7. Check class detail, export, and saved semester scores to confirm they match the preview.

## Out of scope

- Redesigning the scoring UI.
- Changing campaign configuration structure.
- Changing fee scoring rules.
- Changing unrelated semester scoring formulas.
