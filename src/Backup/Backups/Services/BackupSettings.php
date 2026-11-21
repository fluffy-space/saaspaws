<?php

namespace SaasPaws\Backup\Backups\Services;

use Fluffy\Services\Settings\SettingDefinition;
use Fluffy\Services\Settings\SettingsRegistry;

/**
 * Declares the backup settings (Fluffy settings store, group `backup`) so the whole job is
 * controlled from the admin settings page — schedule, destination, retention — with no redeploy
 * and no shell access. Copied from Urlicer (design in its docs/backup-plan.md §v2). Secrets (host,
 * user, keys) are entered at /admin/settings; nothing is in the repo.
 *
 * Called once per worker at boot from BackupStartUp::configureServices (SettingsRegistry is static and
 * in-memory; a row only appears in the DB once someone overrides a default).
 *
 * WHY THE SCHEDULE IS AN INTERVAL, NOT A CRON EXPRESSION
 * The crontab entry ticks every 15 minutes and BackupTask decides whether a run is due from
 * `backup.schedule` and the last successful run. Cron schedules are set at boot and only
 * change on a full restart, whereas the whole point here is that the operator can move from
 * daily to hourly from a settings page. It is also the mechanism the planned intra-day
 * granularity work will use.
 */
class BackupSettings
{
    public static function register(): void
    {
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.enabled',
            type: 'boolean',
            group: 'backup',
            label: 'Backups enabled',
            description: 'Master switch. When off, the cron tick returns immediately and nothing '
                . 'is dumped or uploaded. "Run now" in the admin page still works, so a disabled '
                . 'schedule can be tested by hand.',
            initial: false,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.alertsEnabled',
            type: 'boolean',
            group: 'backup',
            label: 'Email the admins when a backup fails',
            description: 'One email when a scheduled run fails, a reminder after the monitoring '
                . 'cooldown while it keeps failing, nothing once a run succeeds. Separate from the '
                . 'host resource alerts (monitoring), which stay off while the box is shared.',
            initial: true,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.schedule',
            type: 'string',
            group: 'backup',
            label: 'Schedule (cron expression)',
            description: 'Six fields: sec min hour day month weekday — the same syntax as the '
                . 'app\'s other cron jobs. "0 40 3 * * *" is 03:40 daily; give each app on a shared box '
                . 'its own time, so two dumps never run at once. "0 0 * * * *" is hourly. '
                . 'Evaluated on a 15-minute tick, so 15 minutes is the finest granularity and the '
                . 'run happens on the first tick at or after the scheduled moment. A missed slot '
                . '(box was off) runs once when it comes back rather than being skipped.',
            initial: '0 40 3 * * *',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.retainCount',
            type: 'number',
            group: 'backup',
            label: 'Backups to keep',
            description: 'Oldest artifacts beyond this count are deleted from the destination '
                . 'after a successful run. Keeping only one means tonight overwrites the last '
                . 'good copy, so corruption noticed a day late is unrecoverable — a handful of '
                . 'copies costs a few MB and removes that risk.',
            initial: 7,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.excludeTables',
            type: 'string',
            group: 'backup',
            label: 'Tables to exclude (data only)',
            description: 'Comma-separated table names whose ROWS are skipped; the schema is still '
                . 'dumped, so a restore recreates them empty. For append-only logs this is most of '
                . 'the size for none of the value. Leave blank to dump everything.',
            initial: 'EmailLog,DeploymentHistory',
        ));

        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.verifyDatabase',
            type: 'string',
            group: 'backup',
            label: 'Scratch database for verify',
            description: 'A throwaway database that "Verify latest" restores into. Create it ONCE '
                . 'as a superuser, owned by the app role — `Install/provision-postgres.sh` does '
                . 'this, so a provisioned box already has it. Each run resets its schema rather '
                . 'than creating a database, so '
                . 'the app never needs CREATEDB — a privilege that would let a compromised app '
                . 'spawn databases until the disk filled, granted permanently to serve one admin '
                . 'feature. It is never the live database, and verify refuses to touch that. Blank = '
                . '"<database>_verify" (the database name from config).',
            initial: '',
        ));

        // ---------------------------------------------------------------- destination ---
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.storageHost',
            type: 'string',
            group: 'backup',
            label: 'Storage Box host',
            description: 'e.g. u123456.your-storagebox.de',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.storageUser',
            type: 'string',
            group: 'backup',
            label: 'Storage Box user',
            description: 'Use a SUB-account scoped to its own directory, never the main account. '
                . 'A sub-account can write backups but cannot delete the Storage Box snapshots, '
                . 'which is what survives if this server is compromised.',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.storagePort',
            type: 'number',
            group: 'backup',
            label: 'Storage Box port',
            description: 'Port 23 carries the rsync/BorgBackup/SFTP service and must be enabled on '
                . 'the box ("SSH Support"). Port 22 is always open but expects RFC4716-format keys.',
            initial: 23,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.remoteBase',
            type: 'string',
            group: 'backup',
            label: 'Remote directory (this instance WRITES here)',
            description: 'Relative to the sub-account home; artifacts land in <base>/pg-dump/ and '
                . '<base>/files/. Blank = the database name from config. GIVE EACH ENVIRONMENT ITS '
                . 'OWN, e.g. "myapp/prod" and "myapp/dev", and never share one with '
                . 'another app on the same Storage Box. Sharing one base mixes their artifacts in a '
                . 'single directory, and because retention keeps N by COUNT, a chatty dev box '
                . 'silently rotates production backups out.',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.restoreBase',
            type: 'string',
            group: 'backup',
            label: 'Remote directory to RESTORE from',
            description: 'Where verify and restore READ, when that is not where this instance '
                . 'writes. Blank = the same base. This is what lets the deployer verify and '
                . 'restore PRODUCTION\'s backups without touching production: set it to the prod '
                . 'base (e.g. "myapp/prod") while backups of this machine keep going to its own. '
                . 'The age private key lives here and nowhere else, so this machine is the only '
                . 'place a production artifact can be opened.',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.sshPrivateKey',
            type: 'string',
            group: 'backup',
            label: 'Storage Box SSH private key',
            description: 'OpenSSH private key (PEM text) for the sub-account, WITHOUT a passphrase '
                . '— an unattended job cannot type one. Written to a 0600 temp file for the '
                . 'duration of each run and deleted afterwards. Note the circularity: this key '
                . 'lives in the database, so it is inside the very backup it is needed to fetch. '
                . 'Keep a copy in your password manager.',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.knownHosts',
            type: 'string',
            group: 'backup',
            label: 'Pinned host key',
            description: 'known_hosts line(s) for the Storage Box. Left blank, the first run pins '
                . 'whatever answers and stores it here. Pinning matters: without it anyone able to '
                . 'answer for that hostname receives every backup, and from this side it looks '
                . 'exactly like a successful upload.',
            initial: '',
        ));

        // ---------------------------------------------------------------- encryption ---
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.ageRecipient',
            type: 'string',
            group: 'backup',
            label: 'age public key (encryption)',
            description: 'Artifacts are encrypted to this PUBLIC key, so this server can write '
                . 'backups it cannot itself read — a full compromise of this box leaks no backup '
                . 'content. The private half must never be here; it belongs in your password '
                . 'manager and is used only when restoring. Blank disables encryption, which '
                . 'means the storage provider holds your users\' data in the clear.',
            initial: '',
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'backup.ageIdentity',
            type: 'string',
            group: 'backup',
            label: 'age PRIVATE key (restore only)',
            description: 'The identity that DECRYPTS backups (AGE-SECRET-KEY-...). Needed only to '
                . 'verify or restore from the admin UI; leave blank and this instance can write '
                . 'backups but never read one back. ⚠️ Setting this on a production, '
                . 'internet-facing box gives up the property the whole design exists for — that '
                . 'whoever takes the box gets ciphertext. It belongs on the deployer/control '
                . 'plane, which can already deploy code to prod and so gains nothing new. Note it '
                . 'is stored in the database, and therefore inside every backup it can open: keep '
                . 'the authoritative copy in your password manager.',
            initial: '',
        ));
    }
}
