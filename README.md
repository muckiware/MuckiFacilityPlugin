# MuckiFacilityPlugin
Muckiware Facility Plugin for Shopware 6 Webshops.
- The plugin provides a backup functionality for the database.


## Installation
```shell
composer require muckiware/facility-plugin
bin/console plugin:install -a MuckiFacilityPlugin
```
## Command Line Interface
## Backup
Backups via command line interface are possible with the following commands:
```shell
bin/console muckiware:facility:backup <backupType>
```
| Option backup type            | Desc                                                                                                                                                                                        |
|:------------------------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| completeDatabaseSingleFile    | Backup the complete database as single file. The backup is stored in the directory `var/backup/db/` with the filename `YYYY-MM-DD_HH-MM-SS_db_backup.sql.gz`                                |
| completeDatabaseSeparateFiles | Backup the complete database as single file for each database table. The backup is stored in the directory `var/backup/YYYY-MM-DD` with the filename `YYYY-MM-DD_HH-MM-SS_tablename.sql.gz` |

This command execute a backup of the database. The backup is stored in the directory `var/backup/db/` with the filename `db_backup_YYYY-MM-DD_HH-MM-SS.sql.gz`
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