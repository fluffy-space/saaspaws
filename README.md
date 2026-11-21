# SaasPaws

Operations modules for apps built on [Fluffy Paws](https://github.com/fluffy-space/paws). Each module
is a Fluffy start-up with its own routes, migrations, settings, capabilities and admin pages (a
Viewi package), so an app turns on only what it needs.

| Module | Start-up | What it does |
|---|---|---|
| Deploy | `SaasPaws\Deploy\DeployStartUp` | Deploys the app from its own admin over SSH: servers, sources, applications, blue/green rotation with a check before traffic moves, the key vault push. Admin routes exist in devMode only, so it runs from a local or dev copy, never from production. |
| Ops | `SaasPaws\Ops\OpsStartUp` | Log viewer, system monitor, cron admin, resource alerts and a weekly resource report by email, the live-colour gate for cron jobs (`LiveInstanceService`). |
| Backup | `SaasPaws\Backup\BackupStartUp` | PostgreSQL and uploaded-files backups, encrypted with `age`, sent to an SFTP box (built for a Hetzner Storage Box), with history, verify runs and a failure email. |

## Install

```bash
composer require fluffy-space/saaspaws
```

In the app's `StartUp`, after Paws:

```php
$this->useStartUp(PawsStartUp::class);
$this->useStartUp(DeployStartUp::class);
$this->useStartUp(OpsStartUp::class);
$this->useStartUp(BackupStartUp::class);
```

Then `php fluffy migrate`.

## Needs on the machine

- Deploy: PHP `ssh2` extension.
- Backup: `pg_dump`, `age`, `sftp` and `ssh-keyscan`.

## Capabilities

Bits in the application region of the Paws permission mask: `ManageDeployments` 54,
`ViewSystemInfo` 45, `ViewCron` 46, `ManageCron` 47, `ViewBackups` 55, `ManageBackups` 56. An app
using these modules must not give those bits another meaning.

## Several apps on one box

- Resource alerts and the weekly report describe the whole host: keep them on in one app only
  (settings `monitoring.alertsEnabled`, `monitoring.weeklyReportEnabled`).
- Give each app its own backup time and its own remote folder.

## License

MIT
