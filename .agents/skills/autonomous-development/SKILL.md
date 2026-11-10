---
name: autonomous-development
description: Autonomous end-to-end software development, persistent project continuation, Lightpanda browser testing, error auditing, self-healing, regression testing, and context-reset recovery. Use whenever implementing, maintaining, debugging, auditing, or continuing project development.
---

# Autonomous Development Skill

## PHASE 1 — RECOVERY AND DISCOVERY

At the beginning of every task:

1. Read all files in `.agents/state/`.
2. Read the project instructions.
3. Inspect the installed skills.
4. Read the Lightpanda skill completely.
5. Verify Lightpanda executable and MCP availability.
6. Verify Context7 availability.
7. Inspect Git status and existing modifications.
8. Inspect the current application state.

If resuming after a context reset, continue from the existing state. Do not discard previous progress.

## PHASE 2 — PROJECT INVENTORY

Build a complete inventory of:

- Application routes and pages.
- Features and user workflows.
- Backend endpoints.
- Database dependencies.
- Authentication and authorization.
- Forms and interactive components.
- External integrations.
- Existing automated tests.
- Known bugs and technical debt.

Create or update TASK_BACKLOG.md and TESTING_CHECKLIST.md.

Every feature must have a traceable implementation and validation status.

## PHASE 3 — IMPLEMENTATION

For every pending requirement:

1. Understand the existing architecture.
2. Inspect the relevant source code.
3. Use Context7 to verify current documentation.
4. Implement the smallest correct solution.
5. Follow existing conventions.
6. Add or update automated tests.
7. Execute the relevant test suite.
8. Update persistent state.

Keep business logic in appropriate model or service layers rather than placing database queries and business logic directly in controllers.

## PHASE 4 — APPLICATION STARTUP

Determine the actual commands required by the project.

Examples:
- Backend development server.
- Frontend development server.
- Database services.
- Required queue workers.
- Required background processes.

Start the necessary processes and verify that they remain accessible.

Do not invent startup commands. Inspect package scripts, framework configuration, and project documentation first.

Use a dedicated test environment and test database.

## PHASE 5 — LIGHTPANDA BROWSER TESTING

Use the Lightpanda skill and its available MCP tools.

Mandatory browser testing sequence:

1. goto — Open the application.
2. waitForSelector or waitForState — Wait for application readiness.
3. tree or markdown — Inspect the rendered page.
4. interactiveElements — Discover available interactions.
5. detectForms — Inspect form structures.
6. click / fill / selectOption / setChecked — Execute interactions.
7. extract — Inspect resulting data.
8. consoleLogs — Inspect browser console output.
9. getUrl — Verify navigation.
10. Repeat interactions where necessary.

Do not assume that a feature works merely because it renders.

Test realistic workflows, including successful submissions, invalid inputs, empty data, failed requests, and permission restrictions.

Use isolated test data. Clean up only test records created by the current testing process.

### Browser testing matrix

For every accessible feature, test applicable scenarios:

- Page loading.
- Navigation.
- Authentication.
- Authorization.
- Data retrieval.
- Data creation.
- Data modification.
- Data deletion.
- Form validation.
- Search.
- Filters.
- Sorting.
- Pagination.
- Modal interactions.
- Dropdown interactions.
- API communication.
- Loading state.
- Empty state.
- Error state.
- Business rules.
- Related feature regression.

Record PASS, FAIL, BLOCKED, or NOT APPLICABLE.

A page loading successfully is not sufficient evidence that its functionality works.

## PHASE 6 — ERROR AUDIT

Inspect:

- Browser console errors and warnings.
- Failed network requests.
- HTTP response codes.
- JavaScript exceptions.
- API response payloads.
- Authentication failures.
- Backend application logs.
- Database exceptions.
- Broken assets.
- Unexpected redirects.
- Invalid business logic.
- Missing permissions.
- Unexpected UI behavior.

Classify findings as:

P0 — Critical security, data loss, or application outage.
P1 — Major functionality failure.
P2 — Normal functional defect.
P3 — Minor defect or usability issue.
P4 — Improvement or non-blocking warning.

## PHASE 7 — SELF-HEALING

For each actionable failure:

1. Create a reproducible description.
2. Identify the affected feature.
3. Determine the root cause.
4. Identify the correct source of the defect.
5. Implement a permanent correction.
6. Execute relevant automated tests.
7. Reload the application.
8. Re-run the failed Lightpanda interaction.
9. Inspect console and network output again.
10. Perform regression testing.
11. Update ERROR_LOG.md and TESTING_CHECKLIST.md.

Do not merely patch symptoms.

Do not mark a finding as resolved until the original failure has been reproduced successfully after the fix.

## PHASE 8 — REGRESSION TESTING

After fixing an error:

- Re-test the original scenario.
- Re-test related workflows.
- Re-test affected API endpoints.
- Re-test dependent features.
- Run relevant automated tests.
- Check for newly introduced console and network errors.

Return to the feature inventory and continue testing remaining features.

## PHASE 9 — PERSISTENT CHECKPOINT

After every meaningful implementation or testing milestone, update:

PROJECT_STATUS.md:
- Current project status.
- Completed requirements.
- Remaining requirements.
- Overall readiness.

TASK_BACKLOG.md:
- Pending tasks.
- In-progress tasks.
- Completed tasks.
- Blocked tasks.
- Priorities.

TESTING_CHECKLIST.md:
- Feature.
- Scenario.
- Test environment.
- Expected result.
- Actual result.
- Status.
- Evidence or notes.

ERROR_LOG.md:
- Error identifier.
- Severity.
- Reproduction steps.
- Root cause.
- Files changed.
- Fix applied.
- Verification result.

SESSION_HANDOFF.md:
- Last completed task.
- Current task.
- Current application state.
- Files changed.
- Commands executed.
- Latest test results.
- Outstanding errors.
- Exact next actions.

Never leave the handoff file describing an outdated project state.

## PHASE 10 — CONTINUATION

After each completed task:

1. Check TASK_BACKLOG.md.
2. Select the next highest-priority pending task.
3. Continue implementation or testing.
4. Repeat the full validation cycle.

If execution is interrupted:

- Save a checkpoint.
- Preserve unfinished tasks.
- Record the exact next action.
- Never report unfinished work as complete.

When a new agent session starts, recover the project from persistent files and continue automatically from the last incomplete task.

## PHASE 11 — COMPLETION

Completion requires:

- All requested requirements implemented.
- All accessible features inventoried.
- Relevant browser testing performed.
- Critical and functional defects resolved.
- Regression testing passed.
- Remaining blockers explicitly documented.
- Persistent state updated.

Provide a factual final report.

Never fabricate browser interactions, API results, test execution, or successful fixes.

# EXECUTION PRINCIPLE

ANALYZE → IMPLEMENT → RUN → TEST → AUDIT → FIX → VERIFY → CHECKPOINT → CONTINUE.

The objective is a working, tested, and verified application.