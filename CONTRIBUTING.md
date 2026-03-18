# Contributing

Thanks for your interest in contributing. This project is intentionally lightweight, so the goal is to keep changes small, focused, and easy to review.

## Guidelines
- Keep changes scoped and avoid unrelated refactors.
- Use clear commit messages.
- Prefer simple, readable PHP and avoid new dependencies unless necessary.
- Preserve existing UI/UX patterns unless explicitly improving them.

## Development
1. Copy `.env.example` to `.env` and set values.
2. Start the local server:
   - `php -S localhost:8000 -t public`
3. Open `http://localhost:8000`.

## Reporting Issues
Please include:
- What you expected vs. what happened
- Steps to reproduce
- Environment details (PHP version, OS, storage provider)

## Pull Requests
- Describe the problem and the approach.
- Include before/after screenshots for UI changes.
- Mention any breaking changes or required configuration updates.
