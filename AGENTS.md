<!-- Generated from workspace-wiki/meta/routers.yml by scripts/router.py. Do not edit by hand. -->

# Agent Router: ProjectMakers Health Endpoint

Router for the ProjectMakers Health Endpoint WordPress plugin. The
profile lives in the vault.

## Always Read In This Order

1. `../workspace-wiki/agents/README.md`: the shared reading chain of the ProjectMakers vault
2. `../workspace-wiki/projects/wp-health-endpoint/README.md`: this project's page in the vault
3. [README.md](README.md): features, endpoints and monitoring
4. [readme.txt](readme.txt): the WordPress.org readme

If `../workspace-wiki` is missing, clone the vault there first. Without it the shared
half of the rules is missing. The vault is private, its clone URL is in the
router of every private ProjectMakers repository.

## Secrets

Never put a secret value into a page or a commit. Rules for every project:
`../workspace-wiki/agents/environment.md`, section 6. Cross-project keys:
`../workspace-wiki/infrastructure/secrets.md`.

## Rules

- The release ZIP and the plugin check exclude these router files. Keep the `rsync` excludes in `.github/workflows/` in step when adding files that must not ship.
