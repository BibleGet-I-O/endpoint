## CodeRabbit CLI Workflow

1. Run `coderabbit --plain` and allow it to complete its analysis (can run in background).

2. Evaluate the reported issues:
   - Fix all major and critical issues
   - Fix minor nits only if they are clearly beneficial

3. After implementing changes, re-run CodeRabbit CLI to verify:
   - All critical issues are resolved
   - No new bugs were introduced

4. Iteration limit:
   - Maximum of four iterations
   - If the fourth run shows no critical issues, you may defer remaining minor nits
   - Do not exit with unresolved major issues unless explicitly documented with rationale and a follow-up issue owner
   - Provide a summary of completed changes, deferred items, and rationale
