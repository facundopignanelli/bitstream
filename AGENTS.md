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
- Never add fixes or changes that affect the current version being developed (that is, if we fix a bug of a new feature introduced on the current unreleased version, do not add it to the changelog because nobody has encountered it yet since it is unreleased).

## Editing and validation
- Use `apply_patch` for file edits when possible.
- Validate touched PHP files with `php -l` after changes.
- Run the smallest useful check for the files you edited before wrapping up.

## Memory and preferences
- If a rule is important for this repository, keep it here as well so it is visible in the workspace.

## Modal design system
All modals in BitStream use a single unified design. **Always use `bitstream-composer-modal-*` classes** for new modals — do not invent new modal class names.

### Canonical HTML structure
```html
<div class="bitstream-composer-modal bitstream-composer-modal-{name}" hidden>
  <div class="bitstream-composer-modal-backdrop" data-composer-modal-close="{name}"></div>
  <div class="bitstream-composer-modal-dialog" role="dialog" aria-modal="true" aria-label="…">
    <header class="bitstream-composer-modal-header">
      <h3>Title</h3>
      <button type="button" class="bitstream-composer-modal-close" data-composer-modal-close="{name}" aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </header>
    <div class="bitstream-composer-modal-body">
      <!-- scrollable content -->
    </div>
    <footer class="bitstream-composer-modal-footer">
      <button type="button" class="bitstream-composer-modal-cancel" data-composer-modal-close="{name}">Cancel</button>
      <button type="button" class="bitstream-composer-modal-confirm">Confirm</button>
    </footer>
  </div>
</div>
```

Use `bitstream-composer-modal-dialog-wide` on the dialog div for wider modals (e.g. Drafts).

### Design tokens (do not deviate)
| Token | Value |
|---|---|
| Dialog radius | `20px` |
| Backdrop | `rgba(0,0,0,0.45)` + `backdrop-filter: blur(4px)` |
| Shadow | `0 24px 60px rgba(0,0,0,0.18)` |
| Header padding | `1rem 1.4rem` |
| Body padding | `1rem 1.4rem 1.2rem` |
| Footer padding | `0.75rem 1.4rem` |
| Header/footer divider | `1px solid #eef0f2` |
| Header title color | `var(--wp--preset--color--accent-1, #2c6e49)` |
| Input border | `1.5px solid #e2e8f0`, `border-radius: 12px` |
| Input background | `#f8fafc` (resting), `#fff` (focus) |
| Focus ring | `0 0 0 3px rgba(44,110,73,0.13)` |
| Confirm button | accent bg, `border-radius: 10px`, `box-shadow: 0 4px 14px rgba(44,110,73,0.22)`, hover lift `translateY(-1px)` |
| Cancel button | `border: 1.5px solid #e2e8f0`, `border-radius: 10px` |
| Animation | `0.25s cubic-bezier(0.16, 1, 0.3, 1)` spring, from `scale(0.96) translateY(12px)` |

The timeline edit modal (`.bs-edit-modal-*`) follows the same visual tokens but uses its own prefixed classes. Its forms contain an inner `.bs-edit-modal-body` (scrollable) and `.bs-edit-modal-footer` (sticky) matching the pattern above.
