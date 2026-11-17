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

## Installation

### Standalone PHAR (GitHub Releases)

Download `coverage-notes.phar` (and optionally verify its checksum with `coverage-notes.phar.sha256`) from the [Releases](https://github.com/sebb/coverage-notes/releases) page:

```bash
curl -fsSLO https://github.com/sebb/coverage-notes/releases/latest/download/coverage-notes.phar
curl -fsSLO https://github.com/sebb/coverage-notes/releases/latest/download/coverage-notes.phar.sha256
sha256sum -c coverage-notes.phar.sha256
chmod +x coverage-notes.phar
mv coverage-notes.phar /usr/local/bin/coverage-notes
```

### Composer

```bash
composer require --dev sebb/coverage-notes
```

### Build PHAR from Source

With [`humbug/box`](https://github.com/box-project/box) installed:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer build:phar
```

This outputs `build/coverage-notes.phar`.

## GitHub Actions Usage

Use the composite action directly in your workflow after running PHPUnit with `--coverage-clover=coverage.xml`:

```yaml
permissions:
  contents: write # Required to push refs/notes/coverage on main
  pull-requests: write # Required when pr-comment is enabled

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
        with:
          fetch-depth: 0 # Required so merge-base and baseline history are available

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: pcov

      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/phpunit --coverage-clover=coverage.xml

      - uses: sebb/coverage-notes@v0.2.0
        with:
          clover-file: coverage.xml
          base-branch: main
          max-drop: 0
          pr-comment: true
```

In the default `auto` mode, the action:
- Runs `compare` on every push and pull request (posting a Markdown table to the GitHub Actions Job Summary, optionally posting or updating a sticky PR comment when `pr-comment: true`, and printing the console report).
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
| `pr-comment` | `false` | Post or update a sticky coverage report comment on pull requests (`true` or `false`) |
| `github-token` | `${{ github.token }}` | GitHub token used to post or update pull request comments |

## GitLab CI Usage

### Repository & Token Setup

1. Ensure Git history is available in CI jobs by setting `GIT_DEPTH: 0` (required for `git merge-base` and baseline history lookup).
2. Grant CI permission to push `refs/notes/coverage` on your default branch using either option:
   - **CI/CD Job Token (GitLab 17.2+):** Enable **Settings ➔ CI/CD ➔ Job token permissions ➔ Allow Git push requests to the repository** (no extra token variable needed for pushing notes).
   - **Project Access Token:** Create a Project Access Token with the **Maintainer** role and `write_repository` scope (or `api` scope if you also want sticky Merge Request comments), and store it as a masked CI/CD variable named `GITLAB_TOKEN`.

### `.gitlab-ci.yml` Configuration

Download the standalone PHAR (or run `vendor/bin/coverage-notes` if installed via Composer), compare coverage on every pipeline, and record coverage notes on pushes to the default branch:

```yaml
stages:
  - test

test:
  stage: test
  image: php:8.4-cli
  variables:
    GIT_DEPTH: 0
  before_script:
    - curl -fsSL -o /usr/local/bin/coverage-notes https://github.com/sebb/coverage-notes/releases/download/v0.2.0/coverage-notes.phar
    - chmod +x /usr/local/bin/coverage-notes
  script:
    - vendor/bin/phpunit --coverage-clover=coverage.xml
    - BASE_BRANCH="${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-$CI_DEFAULT_BRANCH}"
    - git fetch origin "$BASE_BRANCH:refs/remotes/origin/$BASE_BRANCH"
    - coverage-notes compare coverage.xml --base="origin/$BASE_BRANCH" --max-drop=0
    - |
      if [ "$CI_COMMIT_BRANCH" = "$CI_DEFAULT_BRANCH" ]; then
        git config user.name "gitlab-ci"
        git config user.email "ci@example.com"
        git remote set-url origin "https://gitlab-ci-token:${GITLAB_TOKEN:-$CI_JOB_TOKEN}@${CI_SERVER_HOST}/${CI_PROJECT_PATH}.git"
        coverage-notes record coverage.xml --push
      fi
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
    - if: $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH
```

### Optional: Merge Request Coverage Comment

To post or update a sticky coverage summary comment on Merge Requests, add a masked `GITLAB_TOKEN` variable with `api` scope (`curl` and `jq` required), generate Markdown output before enforcing thresholds, and publish via the GitLab Notes API:

```yaml
  script:
    - vendor/bin/phpunit --coverage-clover=coverage.xml
    - BASE_BRANCH="${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-$CI_DEFAULT_BRANCH}"
    - git fetch origin "$BASE_BRANCH:refs/remotes/origin/$BASE_BRANCH"
    - coverage-notes compare coverage.xml --base="origin/$BASE_BRANCH" --format=markdown > coverage-report.md
    - |
      if [ -n "${CI_MERGE_REQUEST_IID:-}" ] && [ -n "${GITLAB_TOKEN:-}" ]; then
        NOTES_URL="${CI_API_V4_URL}/projects/${CI_PROJECT_ID}/merge_requests/${CI_MERGE_REQUEST_IID}/notes"
        NOTE_ID="$(
          curl -fsSL -H "PRIVATE-TOKEN: ${GITLAB_TOKEN}" "${NOTES_URL}?per_page=100" \
            | jq -r '.[] | select((.body // "") | startswith("<!-- coverage-notes-report -->\n")) | .id' \
            | head -n 1
        )"
        PAYLOAD="$(jq -Rs --arg marker '<!-- coverage-notes-report -->' '{body: ($marker + "\n" + .)}' coverage-report.md)"
        if [ -n "$NOTE_ID" ]; then
          curl -fsSL -X PUT -H "PRIVATE-TOKEN: ${GITLAB_TOKEN}" -H "Content-Type: application/json" -d "$PAYLOAD" "${NOTES_URL}/${NOTE_ID}" > /dev/null
        else
          curl -fsSL -X POST -H "PRIVATE-TOKEN: ${GITLAB_TOKEN}" -H "Content-Type: application/json" -d "$PAYLOAD" "$NOTES_URL" > /dev/null
        fi
      fi
    - coverage-notes compare coverage.xml --base="origin/$BASE_BRANCH" --no-fetch --max-drop=0
    - |
      if [ "$CI_COMMIT_BRANCH" = "$CI_DEFAULT_BRANCH" ]; then
        git config user.name "gitlab-ci"
        git config user.email "ci@example.com"
        git remote set-url origin "https://gitlab-ci-token:${GITLAB_TOKEN:-$CI_JOB_TOKEN}@${CI_SERVER_HOST}/${CI_PROJECT_PATH}.git"
        coverage-notes record coverage.xml --push
      fi
```

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

### `backfill`

Populate missing coverage notes on recent commits by checking out each commit in a temporary `git worktree` (without touching your working directory):

```bash
bin/coverage-notes backfill --limit=10 --push
```

Options:
- `-l, --limit=LIMIT` — Maximum number of recent commits to inspect (default: `10`)
- `-b, --branch=BRANCH` — Branch or commit reference to inspect (default: `HEAD`)
- `-c, --command=COMMAND` — Shell command to generate the Clover XML file in the worktree (default: `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover=coverage.xml`)
- `--clover-file=CLOVER-FILE` — Relative path to the Clover XML file generated inside the worktree (default: `coverage.xml`)
- `-f, --force` — Recompute and overwrite commits that already have a coverage note
- `--ref=REF` — Git notes ref namespace (default: `coverage`)
- `--remote=REMOTE` — Git remote name (default: `origin`)
- `--fetch` / `--no-fetch` — Fetch coverage notes before backfilling (default: `--fetch`)
- `-p, --push` — Push updated notes back to the remote after backfilling

## License

MIT — see [LICENSE](LICENSE).
