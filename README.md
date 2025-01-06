# MuckiFacilityPlugin
Muckiware Facility Plugin for Shopware 6 Web shops for to maintenance and backup of the database.
- The plugin provides a backup functionality for the database.
- Creates a backup of folders and files by an individual configuration into repository database. The plugin use the restic backup tool for to create the backups. https://restic.readthedocs.io/
- The backups will be encrypted by a password.

## Requirements
- Shopware 6.6.x
- PHP 8.2.x
- restic 0.14.x or greater

## Installation
```shell
composer require muckiware/facility-plugin
bin/console plugin:install -a MuckiFacilityPlugin
```
## General Configuration
Plugin configuration under:<br>Settings -> Extensions -> My extensions -> MuckiFacilityPlugin -> Configure

| Option                                                  | Desc                                                                                                            |
|:--------------------------------------------------------|:----------------------------------------------------------------------------------------------------------------|
| Global settings - Active                                | Turn plugin on/off global                                                                                       |
| Global backup settings -> Active database backup        | Turn backup database on/off global, for cli command ```bin/console muckiware:db:dump <Type of backup>```        |
| Global backup settings -> Compress database backup file | Turn on/off compressen for db dump, for cli command ```bin/console muckiware:db:dump <Type of backup>```        |
| Global backup settings -> Use own restic path           | Turn on/off own restic                                                                                          |
| Global backup settings -> Own path to binary of restic                              | Enter the absolut path to binary file of restic. Example input: ```/var/www/html/bin/restic_0.17.3_linux_386``` |

## Respoitory Configuration
First you will need a repository configuration for the backup. The repository configuration is the base for the backup process. The repository configuration contains the backup source and the backup target. The backup source is the path to the folder or file which should be backuped. The backup target is the path to the repository where the backup data should be restored.<br>
<br>Settings -> Extensions -> Backup Repositories -> Add Repository<br>for to create a new repository configuration.
### General Configuration Setup
| Option             | Desc                                                                                                                                                                                                                                                                                     |
|:-------------------|:-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Active             | Turn repository on/off                                                                                                                                                                                                                                                                   |
| Technical Name     | Free choose as a technical name, is just for better to organize the backup configurations                                                                                                                                                                                                |
| Database dump type | Choose the typ of database dump backup. As separate dump files for each table, only one dump file for the complete database, or none database dump.                                                                                                                                      |
| Repository Path      | Absolute path to the backup repository folder. **Note that this path cannot be changed after creation!**                                                                                                                                                                                 |
| Repository Password       | The backup repository will be encryped by a password. Remembering this password is very important! If you lose it, you won’t be able to get access to the data stored in the repository outside of the shop functionality. **Note that this password cannot be changed after creation!** |
| Restore Path      | Enter here the absolute path for the target of the restored data.                                                                                                                                                                                                                        |

### Backup Paths
Use the button __Add backup path__ for to define the files and folders which should be backup. 
### Delete of snapshots
You can remove old snapshots of a repository with the command 
```shell
bin/console muckiware:backup:snapshots <backupRepositoryId>
```
This setup defines the daily, weekly, month or yearly values for this remove process. More details about the keep-parameters you can find in the restic documentation https://restic.readthedocs.io/en/latest/060_forget.html#removing-snapshots-according-to-a-policy

Finally, click on the __save__-button, for to create the repository configuration.

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