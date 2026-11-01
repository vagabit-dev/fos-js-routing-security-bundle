# Contributing to FOSJsRoutingSecurityBundle

Thank you for your interest in contributing to `FOSJsRoutingSecurityBundle`! Contributions from the community—whether bug reports, documentation improvements, feature proposals, or pull requests—are warmly welcomed.

This guide provides step-by-step instructions for setting up your development environment, understanding our project standards, and submitting your contributions.

---

## Table of Contents

1. [Development Environment Setup](#development-environment-setup)
   - [Prerequisites](#prerequisites)
   - [Step 1: Fork and Clone](#step-1-fork-and-clone)
   - [Step 2: Install Dependencies (Local or Docker)](#step-2-install-dependencies-local-or-docker)
2. [Contribution Workflow](#contribution-workflow)
   - [1. Branching Strategy](#1-branching-strategy)
   - [2. Making Changes & Writing Tests](#2-making-changes--writing-tests)
   - [3. Running Tests](#3-running-tests)
   - [4. Code Style & Quality Checks](#4-code-style--quality-checks)
   - [5. Submitting a Pull Request](#5-submitting-a-pull-request)
3. [Project Standards](#project-standards)
   - [Coding Standards](#coding-standards)
   - [Testing & Compatibility Standards](#testing--compatibility-standards)
   - [Git & Commit Conventions](#git--commit-conventions)
   - [Documentation Standards](#documentation-standards)
4. [Command Reference](#command-reference)

---

## Development Environment Setup

You can set up and work on this project either directly with a local PHP environment or using Docker / Docker Compose (no local PHP required).

### Prerequisites

- **Git** installed on your system.
- **Option A (Local development):**
  - PHP 8.1 or higher
  - Composer 2.x
  - Required PHP extensions (e.g., `mbstring`, `xml`, `yaml`)
- **Option B (Docker development):**
  - Docker and Docker Compose (or Docker Desktop)
- **Optional (for local CI matrix testing):**
  - [`act`](https://github.com/nektos/act) (to run GitHub Actions locally)

### Step 1: Fork and Clone

1. Fork the repository on GitHub.
2. Clone your fork locally:
   ```bash
   git clone https://github.com/<your-username>/fos-js-routing-security-bundle.git
   cd fos-js-routing-security-bundle
   ```
3. Add the upstream remote:
   ```bash
   git remote add upstream https://github.com/martino-piccinato/fos-js-routing-security-bundle.git
   git fetch upstream
   ```

### Step 2: Install Dependencies

#### Option A: Local PHP & Composer

```bash
composer update
```

#### Option B: Docker Compose

If you don't have PHP or Composer installed locally, you can use the provided Docker Compose service:

```bash
# Install dependencies inside the container
docker compose run --rm test composer udpate
```

---

## Contribution Workflow

### 1. Branching Strategy

Always create a new, descriptive branch based on the latest `main` branch:

```bash
git checkout main
git pull upstream main
git checkout -b feature/my-new-feature
# or: git checkout -b fix/issue-description
```

### 2. Making Changes & Writing Tests

- Keep changes focused and atomic.
- When fixing bugs or adding new features, add corresponding unit and/or functional tests under `tests/`.
- Ensure all public classes, methods, and configurations include clear PHPDoc annotations where appropriate.

### 3. Running Tests

Before submitting changes, make sure all tests pass across the test suite.

#### Local Environment

```bash
# Run all tests
vendor/bin/phpunit

# Or via Composer script
composer test

# Run a specific test class
vendor/bin/phpunit tests/Service/RoleFilteredJsRoutesExtractorTest.php
```

#### Docker Compose

```bash
# Run the test suite
docker compose run --rm test

# Or rebuild the test container and run
docker compose up --build
```

#### Standalone Docker

```bash
# Build the test image
docker build -t fos-js-routing-security-bundle-test .

# Run the test suite
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/phpunit
```

#### Testing the Matrix Locally with `act`

This bundle supports multiple PHP and Symfony versions. You can simulate the full GitHub Actions CI matrix locally using [`act`](https://github.com/nektos/act):

```bash
# Run the entire test matrix locally
act -j tests

# Run a specific matrix target (e.g. PHP 8.1 with lowest dependencies)
act -j tests --matrix php:8.1 --matrix symfony:6.4.* --matrix dependencies:lowest --container-architecture linux/amd64
```

> **Tip for Apple Silicon (M1/M2/M3/M4) users:**
> GitHub Actions runner images and action tools are compiled for `linux/amd64`. To avoid typing `--container-architecture linux/amd64` on every command, you can create a `.actrc` file in the repository root or in your home directory (`~/.actrc`):
> ```text
> --container-architecture linux/amd64
> ```

### 4. Code Style & Quality Checks

This project adheres to strict coding standards. Always run code style checks and fix formatting before committing.

#### Local Environment

```bash
# Check code style (dry run with diff)
composer cs-check

# Automatically fix code style
composer cs-fix
```

#### Docker Compose

```bash
# Check code style
docker compose run --rm --entrypoint composer test cs-check

# Automatically fix code style
docker compose run --rm --entrypoint composer test cs-fix
```

#### Standalone Docker

```bash
# Check code style
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/php-cs-fixer fix --dry-run --diff

# Automatically fix code style
docker run --rm -v "$(pwd):/app" -w /app fos-js-routing-security-bundle-test vendor/bin/php-cs-fixer fix
```

### 5. Submitting a Pull Request

1. Push your branch to your GitHub fork:
   ```bash
   git push origin feature/my-new-feature
   ```
2. Open a Pull Request targeting the `main` branch.
3. Fill out the PR description explaining:
   - What problem is solved or what feature is introduced.
   - Any relevant context or issue numbers (e.g., `Fixes #123`).
   - Summary of changes and tests added.
4. Ensure all CI checks pass. If there are failures, review the logs, fix them locally, and push additional commits.

---

## Project Standards

### Coding Standards

- **Standards:** The codebase strictly follows **PSR-12** and **Symfony Coding Standards**.
- **Configuration:** Governed by `.php-cs-fixer.dist.php` and `.editorconfig`.
- **Key Rules:**
  - 4 spaces indentation, Unix line endings (`LF`), UTF-8 encoding.
  - Short array syntax (`[]`).
  - Alphabetically ordered imports (`use` statements).
  - Unused imports are removed.
  - Trailing commas in multi-line arrays, arguments, and parameters.
  - Left-aligned PHPDoc blocks.
  - Strict type hints and return types where applicable.

### Testing & Compatibility Standards

- **Supported PHP Versions:** PHP 8.1, 8.2, 8.3, and 8.4.
- **Supported Symfony Versions:** Symfony 6.4 (LTS), 7.0, 7.1, 7.2, and 8.0.
- **Framework Compatibility:** New features and bug fixes must maintain compatibility across all supported PHP and Symfony combinations without introducing deprecation warnings.
- **Test Coverage:** All bug fixes must include regression tests. All new features must include unit tests and, when relevant, functional tests.

### Git & Commit Conventions

- Use clear and descriptive commit messages (e.g., `Fix route role extraction when access_control rule has no roles option`, `Add support for Symfony 8.0 runtime`).
- Keep commits atomic and logically separated. Avoid mixing unrelated refactoring with bug fixes or new features.

### Documentation Standards

- If your change modifies public APIs, CLI commands, configuration options, or setup requirements, update the corresponding documentation in `README.md`.
- Ensure Markdown files maintain clean formatting and valid code blocks.

---

## Command Reference

| Action | Local Command | Docker Compose Command |
|---|---|---|
| **Install dependencies** | `composer install` | `docker compose run --rm test composer install` |
| **Update dependencies** | `composer update` | `docker compose run --rm test composer update` |
| **Run tests** | `vendor/bin/phpunit` or `composer test` | `docker compose run --rm test` |
| **Check code style** | `composer cs-check` | `docker compose run --rm --entrypoint composer test cs-check` |
| **Fix code style** | `composer cs-fix` | `docker compose run --rm --entrypoint composer test cs-fix` |
| **Local CI matrix** | `act -j tests` | N/A |

---

Thank you for helping make `FOSJsRoutingSecurityBundle` better!
