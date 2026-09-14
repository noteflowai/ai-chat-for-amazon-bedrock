# AI Agents & Chat for Amazon Bedrock

Development notes for the WordPress plugin published at
<https://wordpress.org/plugins/ai-chat-for-amazon-bedrock/>.

User facing documentation lives in `readme.txt`, which is what the plugin directory
renders. This file is for people working on the code and never ships.

## Layout

| Path | Purpose |
| --- | --- |
| `ai-chat-for-amazon-bedrock.php` | Plugin header, activation and bootstrap |
| `includes/` | Bedrock client, MCP, retrieval, embeddings, OAuth, usage, CLI |
| `admin/` | Settings screens, dashboards and their assets |
| `public/` | Front-end chat, block and assets |
| `tests/` | Standalone test suites, run by PHP with no framework |
| `bin/` | Release tooling |
| `phpcs.xml.dist` | WordPress coding standards for this plugin |
| `.distignore` | The only list of what does not ship |

The WordPress.org SVN repository is a distribution channel. This tree is the source of
truth, including the tests and tooling that never reach SVN.

## Requirements

- PHP 7.4 or newer, matching the plugin header
- WordPress 6.4 or newer
- An AWS account with Amazon Bedrock model access for anything that calls a model
- `python3`, `curl` and `bash` for the release tooling
- For `phpcs`: a PHP with the tokenizer, xmlwriter and SimpleXML extensions

## Working on it

```bash
bin/setup-tools.sh                 # pinned phpcs, WPCS, PHPCSUtils, PHPCSExtra into .tools/
composer test                      # or run each tests/*.php directly
bin/prerelease.sh --php "$(which php)"
```

Useful options:

```bash
bin/prerelease.sh \
  --php /path/to/php \                    # tests and linting
  --phpcs-php /path/to/php-with-tokenizer \  # only if the first lacks the extensions
  --wp-cli "wp --path=/var/www/html"      # enables the official Plugin Check
```

`--skip-phpcs` and `--skip-plugin-check` exist for a quick loop, but a release should
run everything.

## The gate

`bin/prerelease.sh` is what must pass before publishing. It checks:

- every PHP file parses
- the plugin header, the runtime constant and the readme stable tag agree
- the changelog documents the version being released
- readme limits: description plus privacy policy under 2,500 words, short description
  under 150 characters, every upgrade notice under 300
- every suite in `tests/`
- WordPress coding standards through the pinned `phpcs`
- the official Plugin Check, when a WP-CLI command is supplied
- the file list that would ship, resolved from `.distignore`, including a scan for
  anything shaped like a credential

Two behaviours are deliberate:

**A check that cannot run reports `skip`, never `ok`.** An absent linter or a suite that
declined to run must not look like a pass. The same applies to a suite that prints
`SKIP:` as its first line.

**`.distignore` is the only exclusion list.** `bin/build-package.py` reads it and the
gate asks that script what would ship, so adding a development directory in one place is
enough. This mattered in practice: three hand maintained lists once disagreed, and a new
tools directory ended up inside the audit.

## Testing the gate

`tests/tooling.php` exercises the tooling. It copies the project to a scratch directory,
breaks one thing, and asserts that the responsible check reports a failure. Assertions
look for the message on a `FAIL` line rather than anywhere in the output, because a check
that still prints its message while no longer failing is precisely the regression worth
catching.

This suite exists because the gate shipped two false passes during development: `phpcs`
failing to start was reported as clean, and the credential scan never ran in the mode the
gate called. Each of the gate's checks was then disabled in turn to confirm the suite
notices.

The gate marks its environment with `AICFAB_GATE_SELFTEST=1` so this suite skips rather
than running the gate inside the gate. CI runs it as a separate step.

## Continuous integration

`.github/workflows/gate.yml` runs on every push and pull request:

- the gate on PHP 7.4 and 8.3
- `tests/tooling.php`
- a package build, verified against `.distignore`
- the official Plugin Check against a real WordPress install

## Releasing

1. Update the version in the plugin header, the `AI_CHAT_BEDROCK_VERSION` constant and
   the readme stable tag, and add a changelog entry and an upgrade notice.
2. Rebuild `languages/ai-chat-for-amazon-bedrock.pot`.
3. Run `bin/prerelease.sh` with everything enabled. Fix anything it reports.
4. `bin/build-package.py` and check the file count and checksum it prints.
5. Copy the package contents into the SVN working copy, commit trunk, then tag.
6. Confirm the directory shows the new version and that the package on
   downloads.wordpress.org contains what you built.

Screenshots in `assets/` must be real captures of the current interface. Retake them
when a screen changes rather than shipping a stale image.

## License

GPL-2.0-or-later.
