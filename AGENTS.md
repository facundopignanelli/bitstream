# AGENTS.md

Repository-wide instructions for BitStream work in this workspace.

## General
- Keep changes minimal and focused on the requested task.
- Do not modify unrelated files or revert user changes.
- Prefer existing project patterns and keep the current UI/behavior stable unless the user explicitly asks for a change.

## Versioning and release notes
- Do not bump the plugin version unless the user explicitly asks for a version bump.
- Keep `CHANGELOG.md` updated when making user-facing changes.
- If a changelog entry already exists for the current version, add new notes there instead of creating a new version without explicit instruction.

## Editing and validation
- Use `apply_patch` for file edits when possible.
- Validate touched PHP files with `php -l` after changes.
- Run the smallest useful check for the files you edited before wrapping up.

## Memory and preferences
- If a rule is important for this repository, keep it here as well so it is visible in the workspace.
