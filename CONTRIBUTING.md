# Contributing to Uploads Proxy

Thanks for taking the time to contribute! This document explains how to submit
changes and the one legal step we ask of every contributor.

## Contributor License Agreement (required)

Uploads Proxy is released under the GPL-2.0-or-later license, and the Project
Owner (Divine Apparitions) retains copyright over the combined work so the
project can be licensed flexibly (including a possible commercial edition
alongside the GPL release).

Before we can merge your contribution, you must agree to the
**[Contributor License Agreement](CLA.md)**. You keep the copyright to your own
work; the CLA grants Divine Apparitions a broad license to use and relicense it.

### How to accept the CLA

You accept the CLA by adding a `Signed-off-by` line to **every commit** in your
pull request. This line certifies that you have read and agree to the
[CLA](CLA.md) and that you have the right to submit the work under it.

Add it automatically with the `-s` flag:

```bash
git commit -s -m "Your commit message"
```

This appends a line in the form:

```
Signed-off-by: Your Name <your.email@example.com>
```

Use your real name and a reachable email address. The first time you contribute,
please also confirm in your pull request description:

> I have read and agree to the Contributor License Agreement in CLA.md.

Pull requests without a sign-off on all commits cannot be merged.

## Development workflow

1. Fork the repository and create a topic branch from `master`.
2. Make your change with a focused, well-described commit (signed off as above).
3. Run the checks before opening a pull request:
   ```bash
   composer check    # runs lint + analyze + test
   ```
   Or individually: `composer lint` (phpcs), `composer analyze` (phpstan),
   `composer test` (phpunit). Use `composer lint:fix` to auto-fix style issues.
4. Open a pull request describing the change and the motivation.

## Coding standards

This project follows the WordPress coding standards enforced by `phpcs.xml.dist`
and the static-analysis rules in `phpstan.neon.dist`. Please make sure both pass
before submitting.

## Reporting security issues

Please do **not** open a public issue for security vulnerabilities. Instead,
report them privately to the maintainer so a fix can be prepared before
disclosure.
