# CLAUDE.md – Freizeitliste

Plugin-specific context for this repository. General MyBB conventions live in the global
config and the `mybb-plugin-dev` skill.

## Purpose

A leisure/activity list for MyBB 1.8 RPG forums. Members of configured groups submit
activities (location, time, description, contacts); every submission and every contact
change passes through a team approval queue in the ModCP before it becomes public.
Approved activities are shown by category on `freizeit.php`, where members can join and
set an optional role.

## Architecture

Class/core split (procedural main file + one core class):

- `freizeit.php` – front-end entry point (MyBB root), routes POST actions and renders
  the `freizeit_overview` template.
- `inc/plugins/freizeitliste.php` – lifecycle (info/install/uninstall/activate/
  deactivate), settings, templates, all hooks, and the ACP config page (rendered in the
  `admin_load` hook).
- `inc/plugins/freizeitliste/core.php` – `FreizeitlisteCore` class with all business
  logic (permissions, submission, participation, approval queue, ModCP rendering).

## Codename / prefixes

- Plugin codename: **freizeitliste** (main file name). Front-end script is `freizeit.php`.
- Function prefix: `freizeitliste_*` (hooks/lifecycle) and `FreizeitlisteCore::*`.
- Settings: `freizeitliste_*`; setting group `freizeitliste`.
- Templates: `freizeit_*` and `modcp_freizeit_*` / `acp_freizeit_*`.
- Language keys: `freizeit_*` / `freizeitliste_*`.

## Database tables (no `mybb_` hardcoded – always `TABLE_PREFIX`)

- `freizeit_categories` – categories (name, description, displayorder).
- `freizeit_entries` – activities (category_id, title, ort, zeit, beschreibung,
  created_by, status enum pending/approved, created_at).
- `freizeit_contacts` – contacts per entry (entry_id, user_id).
- `freizeit_participants` – participants per entry (entry_id, user_id, rolle).
- `freizeit_pending_changes` – approval queue (entry_id, type enum new_entry/
  contact_change, payload JSON, created_by, created_at).

## Settings

- `freizeitliste_submit_groups` – groups allowed to submit (groupselect).
- `freizeitliste_modcp_groups` – groups with ModCP approval rights (groupselect).
- `freizeitliste_view_participants_groups` – groups allowed to view participants.

Group checks go through `FreizeitlisteCore::has_group_permission()` (supports `-1` =
all groups; checks primary + additional groups).

## Hooks

- `global_start` – load language on `freizeit.php`.
- `index_start` / `index_end` – red pending-approval alert for team members
  (`{$freizeit_red_alert}` injected into the `index` template on activate; `index_end`
  fallback prepends it if the placeholder is missing).
- `modcp_nav` – inject queue/contacts links into the ModCP navigation (appends to the
  `modcp_nav_misc` section variable, which MyBB merges into `$modcp_nav` before
  `modcp_start`).
- `modcp_start` – router for `freizeit_queue` and `freizeit_contacts` actions.
- `admin_config_menu` / `admin_config_action_handler` / `admin_config_permissions` /
  `admin_load` – ACP category management under `config-freizeitliste`.

## What NOT to change

- The `modcp_nav` injection targets `modcp_nav_misc`/section vars **before** the final
  `$modcp_nav` eval; do not move it to `modcp_start` (too late – `$modcp_nav` is already
  built there). Custom ModCP pages inherit the links via the already-built `$modcp_nav`.
- Contact changes must stay routed through `freizeit_pending_changes`; do not apply them
  directly.
- Templates and settings are owned by install()/uninstall(); the index template edit is
  owned by activate()/deactivate(). Keep those mirror pairs intact.

## Known follow-ups (non-blocking)

- ACP page is rendered from the `admin_load` hook rather than a dedicated
  `admin/modules/config/freizeitliste.php` module (works, but non-standard).
- Templates are stored as inline strings in `freizeitliste_insert_templates()` rather
  than `.tpl` files.
