# MuckiFacilityPlugin
Muckiware Facility Plugin for Shopware 6 Webshops


## Installation
```shell
composer require muckiware/facility-plugin
bin/console plugin:install -a MuckiFacilityPlugin
```


## CLIs
```shell
bin/console muckiware:create:backup:db
```
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