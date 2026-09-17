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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

use MuckiRestic\Library\Backup;
use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Exception\InvalidConfigurationException;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryCollection;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Exception\UnresolvableRepositoryPasswordException;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

class BackupRepository
{
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
            'backupPaths' => $this->createBackupPathsPayload($backupRepositoryInput),
            'forgetDaily' => $backupRepositoryInput->getForgetDaily(),
            'forgetWeekly' => $backupRepositoryInput->getForgetWeekly(),
            'forgetMonthly' => $backupRepositoryInput->getForgetMonthly(),
            'forgetYearly' => $backupRepositoryInput->getForgetYearly(),
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
