# Git Tracking Information

## What's Tracked in Git ✅

The following files in `local-tests/` are tracked:

```
local-tests/
├── .gitignore                      # Ignore rules for test artifacts
├── 01-test-local-health.sh         # Test script
├── 02-test-dev-docker.sh           # Test script
├── 03-test-prod-docker.sh          # Test script
├── 04-test-docker-compose-db.sh    # Test script
├── 05-test-todo-app.sh             # Test script
├── run-all-tests.sh                # Master runner
├── README.md                       # Full documentation
├── QUICK-START.md                  # Quick reference
└── GIT-INFO.md                     # This file
```

## What's Ignored ❌

Test artifacts are automatically excluded from git:

```
local-tests/
├── test-workspace/       # Any workspace directories
├── *.log                 # Log files generated during tests
├── docker-data/          # Docker volume data
└── postgres-data/        # PostgreSQL data directories
```

Also excluded from parent `.gitignore`:
```
/tmp/test-stonescriptphp-*   # All temp test directories
```

## Verification

To verify what git will ignore:
```bash
# Check if patterns work
git check-ignore -v local-tests/test-workspace/
git check-ignore -v local-tests/*.log
git check-ignore -v local-tests/docker-data/
```

## Why This Matters

- ✅ Test scripts are version controlled
- ✅ Documentation is tracked
- ❌ Generated artifacts are excluded (keeps repo clean)
- ❌ Temporary files are ignored
- ❌ Docker data doesn't bloat the repo

## Safe to Commit

You can safely run:
```bash
git add local-tests/
git commit -m "Add comprehensive local testing suite"
```

All test artifacts will be automatically excluded!
