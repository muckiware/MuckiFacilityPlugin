<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Exception\InvalidConfigurationException;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryCollection;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\BackupCommandTypes;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Exception\UnresolvableRepositoryPasswordException;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use Shopware\Core\Framework\Uuid\Uuid;

class BackupRepository
{
    /** Laenge der DAL-Spalten pre/post_backup_command (varchar(4096)). */
    public const MAX_BACKUP_COMMAND_LENGTH = 4096;

    /** Laenge der DAL-Spalten pre/post_backup_command_arguments (LONGTEXT). */
    public const MAX_BACKUP_COMMAND_ARGUMENTS_LENGTH = 65535;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupRepository,
        protected RepositoryPasswordResolver $passwordResolver,
    )
    {}

    /**
     * @throws InvalidConfigurationException
     */
    public function initRepository(BackupRepositorySettings $backupRepositoryInput): ResultEntity
    {
        $backupClient = Backup::create();
        $ownResticPath = $this->pluginSettings->getOwnResticBinaryPath();
        if($ownResticPath) {
            $backupClient->setBinaryPath($ownResticPath);
        }
        $backupClient->setRepositoryPassword($backupRepositoryInput->getRepositoryPassword());
        $backupClient->setRepositoryPath($backupRepositoryInput->getRepositoryPath());
        return $backupClient->createRepository();
    }

    /**
     * Laedt ein Repository und ersetzt den gespeicherten Passwortwert durch den Klartext.
     *
     * Alle Aufrufer bekommen damit wie bisher ein direkt verwendbares Passwort. Laesst sich der
     * Wert nicht aufloesen, bricht der Aufruf ab, statt Chiffretext oder einen Dateipfad an
     * restic weiterzureichen — das waere sonst als "wrong password" kaum zu diagnostizieren.
     *
     * @throws UnresolvableRepositoryPasswordException
     */
    public function getBackupRepositoryById(string $backupRepositoryId): ?BackupRepositoryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $backupRepositoryId));
        $criteria->addAssociation('backupRepositoryChecks');
        $criteria->setLimit(1);

        $backupRepositoryResults = $this->backupRepository->search($criteria, Context::createDefaultContext());
        if ($backupRepositoryResults->count() === 1) {

            /** @var BackupRepositoryEntity $backupRepository */
            $backupRepository = $backupRepositoryResults->first();
            $backupRepository->setRepositoryPassword(
                $this->passwordResolver->resolve(
                    $backupRepository->getRepositoryPassword(),
                    $backupRepository->getPasswordSourceType()
                )
            );

            return $backupRepository;
        }

        return null;
    }

    /**
     * Sucht ein Repository ueber den Pfad, auch wenn er mit Schraegstrich am Ende gespeichert wurde.
     * Loest das Passwort bewusst nicht auf.
     */
    public function getBackupRepositoryByPath(string $repositoryPath): ?BackupRepositoryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('repositoryPath', [$repositoryPath, $repositoryPath . '/']));
        $criteria->setLimit(1);

        /** @var BackupRepositoryEntity|null $backupRepository */
        $backupRepository = $this->backupRepository->search($criteria, Context::createDefaultContext())->first();

        return $backupRepository;
    }

    /**
     * Repositories einer Passwortquelle als id => name.
     *
     * Loest das Passwort bewusst nicht auf: die Liste wird unter anderem gebraucht, um
     * Altbestand zu finden, und soll auch dann funktionieren, wenn einzelne Werte defekt sind.
     *
     * @return array<string, string>
     */
    public function getRepositoryNamesByPasswordSource(PasswordSource $passwordSource): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('passwordSource', $passwordSource->value));

        $repositoryNames = [];
        /** @var BackupRepositoryEntity $backupRepository */
        foreach ($this->backupRepository->search($criteria, Context::createDefaultContext()) as $backupRepository) {
            $repositoryNames[$backupRepository->getId()] = $backupRepository->getName();
        }

        return $repositoryNames;
    }

    /**
     * Bringt einen eingegebenen Wert in die Form, die gespeichert wird.
     *
     * Bewusst getrennt vom Schreiben: Aufrufer pruefen damit, ob das Passwort ueberhaupt
     * ablegbar ist, bevor sie teure oder nebenwirkungsbehaftete Schritte ausfuehren. Fehlt
     * etwa MUWA_FACILITY_SECRET, soll das auffallen, bevor `restic init` ein Repository auf
     * der Platte anlegt, das anschliessend niemand mehr zuordnen kann.
     *
     * @throws UnresolvableRepositoryPasswordException
     */
    public function prepareStoredPassword(string $inputValue, PasswordSource $passwordSource): string
    {
        return $this->passwordResolver->prepareForStorage($inputValue, $passwordSource);
    }

    /**
     * Legt ein Repository an — der einzige Schreibpfad fuer Passwort und Passwortquelle.
     *
     * `repository_password` und `password_source` sind WriteProtected auf den System-Scope,
     * die generische DAL-Route kann sie also nicht setzen.
     *
     * @param string $storedPassword Ergebnis von prepareStoredPassword()
     */
    public function createBackupRepository(
        BackupRepositorySettings $backupRepositoryInput,
        string $storedPassword,
        PasswordSource $passwordSource
    ): void
    {
        $payload = $this->createPayload($backupRepositoryInput, $storedPassword, $passwordSource);

        Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($payload): void {
                $this->backupRepository->create([$payload], $systemScopedContext);
            }
        );
    }

    /**
     * Entfernt einen Datensatz (System-Scope, wie beim Anlegen). Das restic-Repository bleibt unberuehrt.
     */
    public function removeBackupRepository(string $backupRepositoryId): void
    {
        Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($backupRepositoryId): void {
                $this->backupRepository->delete([['id' => $backupRepositoryId]], $systemScopedContext);
            }
        );
    }

    /**
     * Ersetzt die Pre-/Post-Backup-Kommandokonfiguration eines Repositories.
     *
     * Die zugehoerigen Felder sind WriteProtected (System-Scope): die generische DAL-Route
     * kann sie nicht schreiben, diese Methode ist — neben dem Anlegen — der einzige Schreibpfad
     * und wird ausschliesslich vom BackupRepositoryHooksController mit dem eigenen
     * Hochrisiko-Recht muwa_backup_repository:execute_commands aufgerufen (Security-Finding S-02).
     *
     * @param array<string, mixed> $payload Ergebnis von assertValidHookConfiguration()
     */
    public function updateBackupRepositoryHooks(string $backupRepositoryId, array $payload): bool
    {
        $criteria = new Criteria([$backupRepositoryId]);
        if ($this->backupRepository->search($criteria, Context::createDefaultContext())->first() === null) {
            return false;
        }

        $payload['id'] = $backupRepositoryId;
        Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($payload): void {
                $this->backupRepository->update([$payload], $systemScopedContext);
            }
        );

        return true;
    }

    /**
     * True, wenn fuer dieses Repository ein ausfuehrbares Pre-/Post-Backup-Kommando
     * konfiguriert ist. Bestimmt (zusaetzlich zu :backup) das erforderliche Recht
     * fuer den Backup-Start in BackupController (Security-Finding S-02).
     */
    public function hasBackupCommands(string $backupRepositoryId): bool
    {
        if (!Uuid::isValid($backupRepositoryId)) {
            return false;
        }

        $repository = $this->backupRepository
            ->search(new Criteria([$backupRepositoryId]), Context::createDefaultContext())
            ->first();

        if (!$repository instanceof BackupRepositoryEntity) {
            return false;
        }

        return self::isCommandConfigured($repository->getPreBackupCommandType(), $repository->getPreBackupCommand())
            || self::isCommandConfigured($repository->getPostBackupCommandType(), $repository->getPostBackupCommand());
    }

    /**
     * Liest die Hook-Konfiguration aus einem Admin-Request und validiert sie.
     *
     * @return array<string, mixed>|null normalisiertes Payload mit allen Hook-Feldern,
     *                                   oder null, wenn kein Kommando konfiguriert ist
     * @throws \InvalidArgumentException bei ungueltiger Konfiguration
     */
    public static function extractHookConfiguration(RequestDataBag $requestDataBag): ?array
    {
        $payload = self::assertValidHookConfiguration($requestDataBag->all());

        $hasCommands = self::isCommandConfigured($payload['preBackupCommandType'], $payload['preBackupCommand'])
            || self::isCommandConfigured($payload['postBackupCommandType'], $payload['postBackupCommand']);

        return $hasCommands ? $payload : null;
    }

    /**
     * Validiert eine Pre-/Post-Backup-Kommandokonfiguration und normalisiert sie.
     *
     * Erlaubt sind ausschliesslich die Typen aus BackupCommandTypes; beim Typ `batch` ein
     * Pfad (max. 4096 Zeichen, wie die DAL-Spalte), beim Typ `php` ein Shopware-Console-
     * Kommandoname nach demselben Muster, das BackupCommandExecutor zur Laufzeit durchsetzt.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> Payload mit allen acht Hook-Feldern
     * @throws \InvalidArgumentException
     */
    public static function assertValidHookConfiguration(array $input): array
    {
        $payload = [];
        foreach (['pre', 'post'] as $position) {
            $type = strtolower(trim((string) ($input[$position . 'BackupCommandType'] ?? BackupCommandTypes::NONE->value)));
            if (BackupCommandTypes::tryFrom($type) === null) {
                throw new \InvalidArgumentException(sprintf('Invalid %s backup command type', $position));
            }

            $command = trim((string) ($input[$position . 'BackupCommand'] ?? ''));
            $arguments = trim((string) ($input[$position . 'BackupCommandArguments'] ?? ''));
            $failOnError = (bool) ($input[$position . 'BackupFailOnError'] ?? true);

            if ($type === BackupCommandTypes::NONE->value) {
                $command = '';
                $arguments = '';
            }

            if ($type === BackupCommandTypes::BATCH->value) {
                if ($command === '') {
                    throw new \InvalidArgumentException(sprintf('%s backup command path must not be empty', $position));
                }
            }

            if ($type === BackupCommandTypes::PHP->value) {
                if ($command === '' || preg_match('/^[a-zA-Z0-9:_-]+$/', $command) !== 1) {
                    throw new \InvalidArgumentException(sprintf(
                        'Invalid Shopware console command name for the %s backup command',
                        $position
                    ));
                }
                // Console commands are started without additional arguments.
                $arguments = '';
            }

            if (strlen($command) > self::MAX_BACKUP_COMMAND_LENGTH) {
                throw new \InvalidArgumentException(sprintf('%s backup command is too long', $position));
            }
            if (strlen($arguments) > self::MAX_BACKUP_COMMAND_ARGUMENTS_LENGTH) {
                throw new \InvalidArgumentException(sprintf('%s backup command arguments are too long', $position));
            }

            $payload[$position . 'BackupCommandType'] = $type;
            $payload[$position . 'BackupCommand'] = $command;
            $payload[$position . 'BackupCommandArguments'] = $arguments;
            $payload[$position . 'BackupFailOnError'] = $failOnError;
        }

        return $payload;
    }

    /**
     * Genau die Bedingung, unter der BackupCommandSubscriber ein Kommando ausfuehrt.
     */
    public static function isCommandConfigured(string $type, string $command): bool
    {
        $type = trim($type);

        return $type !== '' && $type !== BackupCommandTypes::NONE->value && trim($command) !== '';
    }

    /**
     * Ersetzt Passwort und Quelle eines bestehenden Repositories.
     *
     * @param string $storedPassword Ergebnis von prepareStoredPassword()
     */
    public function updateRepositoryPassword(
        string $backupRepositoryId,
        string $storedPassword,
        PasswordSource $passwordSource
    ): void
    {
        $payload = [
            'id' => $backupRepositoryId,
            'repositoryPassword' => $storedPassword,
            'passwordSource' => $passwordSource->value,
        ];

        Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($payload): void {
                $this->backupRepository->update([$payload], $systemScopedContext);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function createPayload(
        BackupRepositorySettings $backupRepositoryInput,
        string $storedPassword,
        PasswordSource $passwordSource
    ): array
    {
        return [
            'id' => $backupRepositoryInput->getBackupRepositoryId(),
            'active' => $backupRepositoryInput->isActive(),
            'name' => $backupRepositoryInput->getName(),
            'type' => $backupRepositoryInput->getBackupType(),
            'hostname' => $backupRepositoryInput->getHostName(),
            'repositoryPath' => $backupRepositoryInput->getRepositoryPath(),
            'repositoryPassword' => $storedPassword,
            'passwordSource' => $passwordSource->value,
            'restorePath' => $backupRepositoryInput->getRestorePath(),
            'dbDumpPath' => $backupRepositoryInput->getDbDumpPath(),
            'preBackupCommandType' => $backupRepositoryInput->getPreBackupCommandType(),
            'preBackupCommand' => $backupRepositoryInput->getPreBackupCommand(),
            'preBackupCommandArguments' => $backupRepositoryInput->getPreBackupCommandArguments(),
            'preBackupFailOnError' => $backupRepositoryInput->isPreBackupFailOnError(),
            'postBackupCommandType' => $backupRepositoryInput->getPostBackupCommandType(),
            'postBackupCommand' => $backupRepositoryInput->getPostBackupCommand(),
            'postBackupCommandArguments' => $backupRepositoryInput->getPostBackupCommandArguments(),
            'postBackupFailOnError' => $backupRepositoryInput->isPostBackupFailOnError(),
            'backupPaths' => $this->createBackupPathsPayload($backupRepositoryInput),
            'forgetDaily' => $backupRepositoryInput->getForgetDaily(),
            'forgetWeekly' => $backupRepositoryInput->getForgetWeekly(),
            'forgetMonthly' => $backupRepositoryInput->getForgetMonthly(),
            'forgetYearly' => $backupRepositoryInput->getForgetYearly(),
            'databaseConnectionId' => $backupRepositoryInput->getDatabaseConnectionId(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function createBackupPathsPayload(BackupRepositorySettings $backupRepositoryInput): array
    {
        $backupPathsPayload = [];

        /** @var BackupPathEntity $backupPath */
        foreach ($backupRepositoryInput->getBackupPaths() as $backupPath) {
            $backupPathsPayload[] = [
                'id' => $backupPath->getId(),
                'backupPath' => $backupPath->getBackupPath(),
                'compress' => $backupPath->isCompress(),
                'position' => $backupPath->getPosition(),
                'isDefault' => $backupPath->isDefault(),
            ];
        }

        return $backupPathsPayload;
    }
}
