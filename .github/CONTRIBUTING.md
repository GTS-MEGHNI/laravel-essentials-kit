# Contribution Guide

Thank you for considering contributing to Laravel Essentials Kit! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

`composer install` also points git at the hooks in `.githooks`. If you cloned before they existed, run `composer hooks` once. `pre-commit` checks the formatting of the staged PHP files and runs PHPStan. `pre-push` runs `composer audit` and then `composer test`. Skip either once with `--no-verify`.

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```
