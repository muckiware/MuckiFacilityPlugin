# MuckiFacilityPlugin
Muckiware Facility Plugin for Shopware 6 Web shops for to maintenance and backup of the database.
- The plugin provides a backup functionality for the database.
- Creates a backup of folders and files by an individual configuration into repository database.


## Installation
```shell
composer require muckiware/facility-plugin
bin/console plugin:install -a MuckiFacilityPlugin
```
## Command Line Interface
| Command                                                           | Desc                                                          |
|:------------------------------------------------------------------|:--------------------------------------------------------------|
| ```bin/console muckiware:backup:check <backupRepositoryId>```     | Checks a backup repository                                    |
| ```bin/console muckiware:backup:create <backupRepositoryId>```    | Creates a new backup by backup repository configuration       |
| ```bin/console muckiware:backup:forget <backupRepositoryId>```    | Removes snapshots of a backup repository by forget parameters |
| ```bin/console muckiware:backup:snapshots <backupRepositoryId>``` | Lets a list of snapshots in a backup repository id            |
| ```bin/console muckiware:db:dump <backupRepositoryId>```          | Creates just a database dump by global plugin setups          |
## Database dumps
Backups via command line interface are possible with the following commands:
```shell
bin/console bin/console muckiware:db:dump <backupType>
```
| Option backup type                   | Desc                                                                                                                                                                                        |
|:-------------------------------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| completeDatabaseSingleFile (default) | Backup the complete database as single file. The backup is stored in the directory `var/backup/db/` with the filename `YYYY-MM-DD_HH-MM-SS_db_backup.sql.gz`                                |
| completeDatabaseSeparateFiles        | Backup the complete database as single file for each database table. The backup is stored in the directory `var/backup/YYYY-MM-DD` with the filename `YYYY-MM-DD_HH-MM-SS_tablename.sql.gz` |

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