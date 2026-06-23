# COMMIT GUIDELINES - Based on Conventional Commits v1.0.0-beta.4

Official specification: https://www.conventionalcommits.org/pt-br/v1.0.0-beta.4/

## 🚨 CORE PRINCIPLE: SMALL, FOCUSED COMMITS

**MUST follow this rule - This is NOT optional:**

⚠️ **NEVER create large commits with multiple unrelated changes**

Each commit MUST contain:
- **ONE logical change only**
- **ONE type** (feat, fix, refactor, test, etc.)
- **ONE scope** (if applicable)
- **ONE reason to revert** (small commits can be easily reverted)

**Separation Rules:**
- Different types → Different commits (e.g., a feat and a fix → 2 commits)
- Different scopes → Different commits (e.g., auth and database → 2 commits)
- Different concerns → Different commits (e.g., API change and documentation → 2 commits)

**Why this matters:**
- ✅ Easy to review: Reviewers understand the change in seconds
- ✅ Easy to revert: Problematic commits can be removed without affecting other changes
- ✅ Easy to understand: Git history tells the story of development
- ✅ Automated tools: Can generate CHANGELOG and determine version bumps automatically

## Commit Message Format

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

### Type (REQUIRED)
Must be one of:
- **feat**: New feature (e.g., `feat(auth): add JWT validation`)
  - Correlates to MINOR version in SemVer
- **fix**: Bug fix (e.g., `fix(login): handle null user email`)
  - Correlates to PATCH version in SemVer
- **refactor**: Code refactoring without behavior change (e.g., `refactor(jwt): extract token validation`)
  - Internal improvements only
- **test**: Test changes only (e.g., `test(auth): add fingerprint validation tests`)
  - Add, remove, or modify tests
- **docs**: Documentation changes (e.g., `docs: update README with setup instructions`)
  - Changes to markdown, comments, or documentation only
- **style**: Code style changes (e.g., `style: format code with prettier`)
  - No logic changes (whitespace, semicolons, commas, etc.)
- **chore**: Build, dependencies, etc. (e.g., `chore(deps): update NUnit to v4.0`)
  - Changes to build scripts, dependencies, tooling
- **perf**: Performance improvements (e.g., `perf(db): add index to user queries`)
  - Code that improves performance without feature changes
- **ci**: CI/CD changes (e.g., `ci: add GitHub Actions workflow`)
  - Changes to GitHub Actions, build pipelines, etc.

### Scope (OPTIONAL but RECOMMENDED)
The area affected by the change (put in parentheses):
- Examples: `auth`, `jwt`, `fingerprint`, `migration`, `api`, `database`, `middleware`, `dto`, `service`, `repository`, `controller`, etc.
- Keep it concise (1-2 words)

### Description (REQUIRED)
- Use **imperative mood** ("add", not "added" or "adds")
- **Do NOT capitalize** the first letter
- **No period** at the end
- **60-72 characters max** for English commits
- Be specific and descriptive

✅ Good: `feat(jwt): add token expiration validation`
❌ Bad: `feat(jwt): added token expiration validation`
❌ Bad: `feat(jwt): Add token expiration validation.`

### Body (OPTIONAL but RECOMMENDED for non-trivial changes)
- Explain **what** and **why**, not **how**
- Wrap at 100 characters per line
- Separate from subject with **one blank line**
- Use bullet points for multiple reasons
- Example:
  ```
  Implement device fingerprint generation using IP, User-Agent, and email.
  Validate device fingerprints on each request to detect unauthorized access.
  
  This improves security by preventing token theft and session hijacking.
  ```

### Footer (OPTIONAL)
- Reference issue numbers: `Closes #123`, `Fixes #456`, `Related to #789`
- Document breaking changes:
  ```
  BREAKING CHANGE: Users now need to pass device fingerprint in auth header
  ```
- One reference per line

## Breaking Changes (IMPORTANT!)

If your commit introduces a breaking change, you MUST:

1. Add `BREAKING CHANGE: description` in the footer (or body start)
2. Optionally add `!` before the colon in the type: `feat(auth)!: change API`
3. Correlates to MAJOR version in SemVer

Example:
```
feat(auth)!: require device fingerprint for authentication

BREAKING CHANGE: all authentication requests must now include device fingerprint header
Users without fingerprint support will need to update their clients
```

## GOOD EXAMPLES (Small, Focused, Single Concern)

### Feature Addition
```
feat(fingerprint): add device fingerprint validation

Implement device fingerprint generation using IP, User-Agent, and email.
Validate device fingerprints on each request to detect unauthorized access.
Store fingerprint in JWT claims for future validation.

Closes #45
```

### Code Refactoring
```
refactor(jwt): extract JTI validation into separate method

Extracted repeated JTI validation logic into validateJti() method to reduce
code duplication. Improves testability and maintainability.

Related to #78
```

### Bug Fix
```
fix(login): handle missing user-agent header gracefully

Use device ID as fallback when User-Agent header is empty.
Prevents login failures for clients with stripped headers.
Improves security by always generating unique fingerprints.

Fixes #123
```

### Tests Only
```
test(auth): add refresh token rotation tests

Add integration tests to verify:
- Refresh token rotation on each login
- Old tokens are invalidated
- Token rotation happens before expiry

Closes #89
```

### Documentation
```
docs: add device fingerprint implementation guide

Document device fingerprint generation and validation process.
Include examples for different client types (web, mobile, desktop).
```

### Dependencies/Chore
```
chore(deps): update EntityFramework Core to 9.0.0

Update from 8.0.1 to 9.0.0 to get latest features and security patches.
```

### Style/Formatting
```
style(auth): enforce consistent code formatting

Apply prettier and ESLint rules to authentication services.
No logic changes.
```

### Performance
```
perf(db): add index to user_id column in tokens table

Add database index to frequently queried user_id column.
Reduces query time from 500ms to 10ms on average in performance tests.
```

### CI/CD
```
ci: add automated security scanning workflow

Add GitHub Actions workflow to scan for vulnerabilities with Snyk.
Run on every pull request and schedule daily.
```

## BAD EXAMPLES (Too Large, Unfocused, Multiple Concerns)

❌ **DO NOT DO THIS:**

```
❌ feat(api): add device fingerprint, update JWT validation, fix role assignment bug

This commit mixes:
- New feature (fingerprint)  
- Refactor (JWT)
- Bug fix (roles)
→ SPLIT INTO 3 COMMITS
```

```
❌ refactor: restructure authentication services and reduce cognitive complexity and update JWT and add fingerprints

This commit tries to do everything at once and mentions multiple concerns
→ Focus on ONE goal per commit
```

```
❌ feat(auth): make several improvements

Vague description that doesn't explain what changed
→ Be specific and descriptive
```

```
❌ Updates

No type, no scope, no description - completely uninformative
→ Follow the format strictly
```

## Common Mistakes to Avoid

| ❌ Wrong | ✅ Correct | Reason |
|---------|----------|--------|
| `feat(auth): added JWT validation` | `feat(auth): add JWT validation` | Use imperative mood |
| `feat(auth): Add JWT validation.` | `feat(auth): add JWT validation` | Don't capitalize or add period |
| `feat(auth): Add very long description that exceeds the recommended 60-72 character limit` | `feat(auth): add JWT validation` | Keep it short, use body for details |
| `feat(auth), refactor(jwt): multiple changes` | Two separate commits | One type per commit |
| `feat(auth,jwt,api): add feature` | `feat(auth): add JWT validation` | One scope per commit (if needed) |
| `git commit -m "work in progress"` | `feat(auth): add JWT validation` | Always use proper format |
| Large commit with 20 file changes | Break into smaller commits | One concern per commit |

## Frequently Asked Questions (FAQ)

### I made a large commit with multiple changes - can I split it?

**Yes!** Use `git reset` and re-commit in separate commits:

```bash
# Unstage everything
git reset HEAD~1

# Now stage and commit each change separately
git add <file1> <file2>
git commit -m "feat(auth): add JWT validation"

git add <file3>
git commit -m "fix(roles): handle missing role correctly"
```

### What if my change touches files in multiple scopes?

**Create multiple commits**, one per scope:

```bash
# First commit
git add src/auth/*
git commit -m "feat(auth): add JWT validation"

# Second commit  
git add src/database/*
git commit -m "refactor(database): improve query performance"
```

### Can I commit experimental/temporary code?

**No.** Each commit should be production-ready. If you need to save work:
- Create a feature branch: `git checkout -b experiment/my-idea`
- Squash before merging to main

### I accidentally made a large commit - how do I fix it?

**Option 1: Amend before pushing (if not pushed yet)**
```bash
git reset HEAD~1      # Undo last commit
git add <file1>       # Stage first change
git commit -m "feat(auth): add validation"  # Commit
git add <file2>       # Stage second change
git commit -m "fix(api): handle error"      # Commit
```

**Option 2: Rebase after pushing (use with caution)**
```bash
git rebase -i HEAD~3  # Rewrite last 3 commits
# Edit each commit separately
```

### What's the difference between `feat` and `refactor`?

- **feat**: Adds new behavior/functionality (visible to users)
- **refactor**: Improves code without changing behavior (internal only)

Example:
- `feat(auth): add two-factor authentication` ← User sees a new feature
- `refactor(auth): extract validation logic` ← Same behavior, better code

### What if my commit message is wrong?

**Before pushing:**
```bash
git commit --amend -m "feat(auth): correct message"
```

**After pushing:**
```bash
git commit --amend -m "feat(auth): correct message"
git push -f origin main  # Use with caution!
```

### How do I know if something should be a separate commit?

Ask yourself:
- Could this commit be reverted independently? → YES = different commit
- Does this commit have multiple types (feat + fix)? → YES = different commits  
- Does this commit affect multiple unrelated scopes? → YES = different commits
- Does this commit have a clear, single purpose? → YES = it's good

### Can I squash commits before merging?

**Yes!** Some teams prefer:
- Many small commits during development
- Squash into one clean commit before PR merge

Use git squash when merging:
```bash
git merge --squash feature-branch
git commit -m "feat(auth): add fingerprint validation"
```

❌ feat(auth): add JWT authentication with refresh token support including device fingerprinting and role management
```

## COMMIT CHECKLIST

Before committing, ask yourself:
- [ ] Does this commit do ONE logical thing?
- [ ] Can I describe it in one short sentence?
- [ ] Is the scope clear and specific?
- [ ] Are all changes related to the type specified?
- [ ] Have I used imperative mood ("add", not "added")?

## WORKFLOW EXAMPLE

```bash
# View changes by type
git status

# Stage only related files for one logical change
git add file1.ts file2.ts

# Commit with focused message
git commit -m "feat(auth): add device fingerprint validation"

# If you have more unrelated changes, commit them separately
git add otherFile.ts
git commit -m "refactor(jwt): extract validation logic"
```

## Common Scopes in This Project

- `auth` - Authentication services
- `jwt` - JWT token generation and validation
- `fingerprint` - Device fingerprint features
- `migration` - Database migrations
- `db` - Database context and repositories
- `dto` - Data transfer objects
- `test` - Test utilities and helpers
- `role` - Role and permission management
- `api` - API endpoints and controllers
