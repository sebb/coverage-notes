# coverage-notes

Track and compare PHPUnit code coverage evolution in CI using native **Git notes** (`refs/notes/coverage`) — with no third-party SaaS (no Codecov/Coveralls), no duplicate test runs, and no commit-history pollution.

## How It Works

1. On pushes to your default branch (e.g. `main`), `coverage-notes record` parses PHPUnit's Clover XML (`coverage.xml`) into a compact JSON summary containing:
   - Project-wide statement totals and percentage
   - Per-class (and procedural file fallback) breakdown
2. It attaches that summary directly to the commit SHA using `git notes` under `refs/notes/coverage` and pushes the notes ref to `origin`.
3. On pull requests or subsequent commits, `coverage-notes compare` fetches `refs/notes/coverage`, resolves the baseline commit (using `git merge-base` by default, with automatic ancestor walk-back if a commit skipped CI), and reports the exact coverage evolution.

Because Git stores notes outside `refs/heads/*` and `refs/tags/*`, normal `git clone`, `git pull`, and `git log` stay fast and clean, and branch protection rules on `main` do not block pushing notes.

## Requirements

- **PHP 8.4+** (`ext-json`, `ext-simplexml`)
- **Git**

## GitHub Actions Usage

Use the composite action directly in your workflow after running PHPUnit with `--coverage-clover=coverage.xml`:

```yaml
permissions:
  contents: write # Required to push refs/notes/coverage on main

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0 # Required so merge-base and baseline history are available

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: pcov

      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/phpunit --coverage-clover=coverage.xml

      - uses: sebb/coverage-notes@v1
        with:
          clover-file: coverage.xml
          base-branch: main
          max-drop: 0
```

In the default `auto` mode, the action:
- Runs `compare` on every push and pull request (posting a Markdown table to the GitHub Actions Job Summary and printing the console report).
- Runs `record --push` when pushing to `base-branch`.

### Action Inputs

| Input | Default | Description |
| :--- | :--- | :--- |
| `mode` | `auto` | `auto`, `compare`, or `record` |
| `clover-file` | `coverage.xml` | Path to the Clover XML coverage report |
| `base-branch` | `main` | Base branch name to compare against and record on in `auto` mode |
| `strategy` | `merge-base` | Baseline lookup strategy: `merge-base` or `direct` |
| `fail-under` | `''` | Minimum required overall coverage percentage |
| `max-drop` | `''` | Maximum allowed coverage drop compared to baseline |
| `ref` | `coverage` | Git notes ref namespace (`refs/notes/<ref>`) |
| `remote` | `origin` | Git remote name |
| `push` | `true` | Push recorded notes back to the remote (`true` or `false`) |

## CLI Usage

### `record`

Parse a Clover XML file and attach the JSON summary to a commit:

```bash
bin/coverage-notes record coverage.xml --push
```

Options:
- `-c, --commit=COMMIT` — Target commit ref to annotate (default: `HEAD`)
- `-w, --workspace=WORKSPACE` — Root path to strip from relative file paths (default: current working directory)
- `--ref=REF` — Git notes ref namespace (default: `coverage`)
- `--remote=REMOTE` — Git remote name (default: `origin`)
- `--fetch` / `--no-fetch` — Fetch remote notes before recording (default: `--fetch`)
- `-p, --push` — Merge with remote notes and push `refs/notes/<ref>`
- `--dry-run` — Output the generated JSON note without writing to Git

### `compare`

Compare a Clover XML file against the baseline note:

```bash
bin/coverage-notes compare coverage.xml --base=origin/main --max-drop=0
```

Options:
- `-b, --base=BASE` — Base branch or commit reference (default: `origin/main`)
- `-s, --strategy=STRATEGY` — `merge-base` (default) or `direct`
- `-w, --workspace=WORKSPACE` — Workspace root path (default: current working directory)
- `--ref=REF` — Git notes ref namespace (default: `coverage`)
- `--remote=REMOTE` — Git remote name (default: `origin`)
- `--fetch` / `--no-fetch` — Fetch remote notes before comparing (default: `--fetch`)
- `-f, --format=FORMAT` — Output format: `console` (default), `markdown`, or `json`
- `--fail-under=PERCENT` — Exit with code `1` if overall coverage is below this percentage
- `--max-drop=PERCENT` — Exit with code `1` if coverage drops by more than this percentage vs baseline

Example Markdown output:

```markdown
### Coverage Evolution

**Overall:** 80.00% ➔ **85.00%** (`+5.00%` vs `a1b2c3d` | `85/100` statements)

| Status | Class / File | Baseline | Current | Delta | Statements |
| :--- | :--- | ---: | ---: | ---: | ---: |
| 🗑️ Removed | `App\Legacy\OldHelper` | 100.00% | N/A | Removed | was `20/20` |
| ✅ Improved | `App\Service\PaymentService` | 80.00% | 90.00% | +10.00% | `45/50` |
| 🆕 New | `App\Validator\CouponValidator` | N/A | 100.00% | New | `20/20` |
```

### `history`

Inspect recent commits along with their coverage evolution:

```bash
bin/coverage-notes history --limit=10
```

Options:
- `-l, --limit=LIMIT` — Number of recent commits to display (default: `10`)
- `-b, --branch=BRANCH` — Branch or commit ref to inspect (default: `HEAD`)
- `--ref=REF` — Git notes ref namespace (default: `coverage`)
- `--remote=REMOTE` — Git remote name (default: `origin`)
- `--fetch` / `--no-fetch` — Fetch remote notes before reading history (default: `--fetch`)

You can also view coverage notes directly with native Git commands:

```bash
git fetch origin refs/notes/coverage:refs/notes/coverage
git log --show-notes=coverage -n 5
```

## GitLab CI Example

```yaml
test:
  stage: test
  variables:
    GIT_DEPTH: 0
  script:
    - vendor/bin/phpunit --coverage-clover=coverage.xml
    - coverage-notes compare coverage.xml --base=origin/main --max-drop=0
    - |
      if [ "$CI_COMMIT_BRANCH" = "$CI_DEFAULT_BRANCH" ]; then
        git config user.name "gitlab-ci"
        git config user.email "ci@example.com"
        coverage-notes record coverage.xml --push
      fi
```

## License

MIT — see [LICENSE](LICENSE).
