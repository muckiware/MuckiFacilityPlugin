# MuckiFacilityPlugin
Muckiware Facility Plugin for Shopware 6 Web shops for to maintenance and backup of the database.
- The plugin provides a backup functionality for the database.
- Creates a backup of folders and files by an individual configuration into repository database. The plugin use the restic backup tool for to create the backups. https://restic.readthedocs.io/


## Installation
```shell
composer require muckiware/facility-plugin
bin/console plugin:install -a MuckiFacilityPlugin
```
## Command Line Interface
| Command                                                                      | Desc                                                          |
|:-----------------------------------------------------------------------------|:--------------------------------------------------------------|
| ```bin/console muckiware:backup:check <backupRepositoryId>```                | Checks a backup repository                                    |
| ```bin/console muckiware:backup:create <backupRepositoryId>```               | Creates a new backup by backup repository configuration       |
| ```bin/console muckiware:backup:forget <backupRepositoryId>```               | Removes snapshots of a backup repository by forget parameters |
| ```bin/console muckiware:backup:snapshots <backupRepositoryId>```            | Gets a list of snapshots in a backup repository id            |
| ```bin/console muckiware:backup:restore <backupRepositoryId> <snapshotId>``` | Restore data by backup repository id and snapshot id          |
| ```bin/console muckiware:db:dump <Type of backup>```                         | Creates just a database dump by global plugin setups          |
## Database dumps
Backups via command line interface are possible with the following commands:
```shell
bin/console muckiware:db:dump <backupType>
```
| Option backup type                   | Desc                                                                                                                                                                                        |
|:-------------------------------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| completeDatabaseSingleFile (default) | Backup the complete database as single file. The backup is stored in the directory `var/backup/db/` with the filename `YYYY-MM-DD_HH-MM-SS_db_backup.sql.gz`                                |
| completeDatabaseSeparateFiles        | Backup the complete database as single file for each database table. The backup is stored in the directory `var/backup/YYYY-MM-DD` with the filename `YYYY-MM-DD_HH-MM-SS_tablename.sql.gz` |

This command execute a backup of the database. The backup is stored in the directory `var/backup/db/` with the filename `db_backup_YYYY-MM-DD_HH-MM-SS.sql.gz`
## Restore
As long as the shopware instance is running, you can restore the backup data via cli like this:
```shell
bin/console muckiware:backup:restore 019403b49bed7109a1e238139fb759c5 8888ec40
```
Or you can choose the snapshot id from the list of snapshots in the administration panel.
- Settings -> Extensions -> Backup Repositories
- Select the backup repository
- Click on the Snapshots-tab
- And select in die list of snapshots the snapshot id for to restore the data.
  ![select_snapshot_for_restore.png](img%2Fselect_snapshot_for_restore.png)
This action will start the restore process of the backup data as a background process.
```shell
But if the shop instance is not running anymore, you can use the native restic commands for to restore the backup data.
```shell
restic -r /srv/restic-repo restore 79766175 --target /tmp/restore-workdir 
```
Checkout the description of the restic command for more information. https://restic.readthedocs.io/en/stable/050_restore.html#

# Testing
## phpstan
### Install
Install phpstan, if required
```shell
cd custom/plugin/MuckiFacilityPlugin
composer install
```
### Execute
```shell
cd custom/plugins/MuckiFacilityPlugin 
composer run-script phpstan
```
## Unit test
### Execute first time
```shell
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin" --testsuite "migration"
```

### Execute regular run
```shell
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin"
```