# GitHub Publication and Privacy Checklist

The repository `rhuinkz/noniuswtax` is public. The uploaded operational ZIP contains **`config.local.php`**, **`storage/backups/*.sql` and `*.zip`**, login photographs, and the SQL dump has identifiable user details, password hashes, supplier TINs, transactions and audit trails. The user's statement that no real data was included cannot be independently substantiated by the uploaded record content.

**Never upload:** live configuration, passwords/API keys, SQL dumps, archival backups, production transaction snapshots, account recovery emails, live supplier TINs, `storage/` or uploaded photo assets. A `.gitignore` is insufficient once a sensitive file has already entered Git history.

## Safe source procedure
1. Start with the curated ZIP rather than the original installed-site ZIP.
2. Inspect its file inventory and run a secret scanner (e.g., Gitleaks).
3. Use a branch and pull request so code and documentation can be reviewed.
4. Review installer exposure, dependencies, application updater and security risks before pushing runtime source to public default branch.
5. Keep synthetic fixtures separate; avoid BIR/financial forms prefilled with actual identifiers.
6. If any secret was already publicly committed, remove it from history and rotate it. Determine reporting obligations for a confirmed sensitive-data incident.

## Proposed repository structure
```text
README.md
docs/
  PROJECT_BRIEF.md
  ROLE_MATRIX.md
  SYSTEM_REQUIREMENTS.md
  WORKFLOWS_AND_DATA.md
  TECHNICAL_REVIEW.md
  TEST_PLAN.md
  SAFE_PUBLICATION.md
  roles/
    PROJECT_OWNER.md
    DIVISION_ADMINISTRATOR.md
    SCHOOL_USER.md
```

The documentation branch records the review. The complete sanitized source and full-length manuals were also generated as local downloadable archives for repository owner validation.
