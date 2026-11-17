<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class RepositoryMirrorAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::MODULE . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    private function detailScript(): string
    {
        return $this->read('page/muwa-backup-repository-detail/index.js');
    }

    private function detailTemplate(): string
    {
        return $this->read('page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig');
    }

    public function testDetailMirrorSectionUsesSelectAndLocalFormData(): void
    {
        $template = $this->detailTemplate();

        self::assertStringContainsString('muwa-backup-repository.mirror.cardTitle', $template);
        self::assertStringContainsString('<muwa-s3-connection-select', $template);
        self::assertStringContainsString('v-model:value="mirrorForm.s3ConnectionId"', $template);
        self::assertStringContainsString('v-model:value="mirrorForm.bucket"', $template);
        self::assertStringContainsString('v-model:value="mirrorForm.prefix"', $template);
        self::assertStringContainsString('@click="onMirrorSave"', $template);
        // the WriteProtected entity fields must never be bound to a form control
        self::assertDoesNotMatchRegularExpression('/v-model(:value)?="backupRepository\.mirrorS3/', $template);
    }

    public function testDetailMirrorSaveCallsPutRouteAndShowsErrorDetail(): void
    {
        $script = $this->detailScript();

        self::assertStringContainsString("requestMirror: '/_action/muwa/backup'", $script);
        self::assertStringContainsString('${this.requestMirror}/${this.backupRepository.id}/mirror', $script);
        self::assertStringContainsString('this.httpClient.put(', $script);
        self::assertStringContainsString('s3ConnectionId: this.mirrorForm.s3ConnectionId', $script);
        self::assertStringContainsString('errors?.[0]?.detail', $script);
        self::assertStringContainsString('muwa-backup-repository.mirror.saveError', $script);
    }

    public function testGenericSaveNeverSendsMirrorFields(): void
    {
        $script = $this->detailScript();

        // the generic save only talks to backupRepository; the mirror form is separate local data
        self::assertStringContainsString('mirrorForm: {', $script);
        self::assertDoesNotMatchRegularExpression('/this\.backupRepository\.mirrorS3\w+\s*=[^=]/', $script);
        self::assertStringContainsString('this.backupRepository.mirrorS3ConnectionId', $script);
        self::assertStringContainsString('syncMirrorForm', $script);
    }

    public function testMirrorSaveReloadsRepositoryAndTargetFieldsAreResynced(): void
    {
        $script = $this->detailScript();
        $start = strpos($script, 'onMirrorSave()');
        self::assertNotFalse($start);
        $method = substr($script, $start, 1800);

        self::assertStringContainsString('this.getBackupRepository()', $method);
        self::assertStringContainsString('this.fetchUploads()', $method);
    }

    public function testUploadHistoryInStatusTab(): void
    {
        $script = $this->detailScript();
        $template = $this->detailTemplate();

        self::assertStringContainsString("this.repositoryFactory.create('muwa_backup_repository_uploads')", $script);
        self::assertStringContainsString("Criteria.sort('createdAt', 'DESC')", $script);
        self::assertStringContainsString('criteria.setLimit(10)', $script);
        self::assertStringContainsString("Criteria.equals('status', 'succeeded')", $script);
        self::assertStringContainsString('lastSucceededUpload', $script);
        foreach (['status', 'filesUploaded', 'filesDeleted', 'bytesUploaded', 'message'] as $column) {
            self::assertStringContainsString("property: '" . $column . "'", $script);
        }
        self::assertStringContainsString("tab === 'backupRepositoryStats'", $template);
        self::assertStringContainsString('muwa-backup-repository.mirror.historyTitle', $template);
        self::assertStringContainsString('muwa-backup-repository.mirror.lastSucceeded', $template);
        self::assertStringContainsString('muwa-backup-repository.mirror.firstRunNotice', $template);
        self::assertStringContainsString('showFirstRunNotice', $template);
        self::assertStringContainsString('formatBytes(item.bytesUploaded)', $template);
    }

    public function testNoElseBranchFollowsASlotTemplate(): void
    {
        foreach ([
            'page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig',
            'page/muwa-backup-repository-create/muwa-backup-repository-create.html.twig',
            'component/muwa-backup-status-dashboard-widget/muwa-backup-status-dashboard-widget.html.twig',
        ] as $file) {
            // a <template #slot v-if> is no v-if node: a v-else(-if) right after it fails at runtime
            self::assertDoesNotMatchRegularExpression(
                '/<template\\b[^>]*\\s#[\\w-]+[^>]*>(?:(?!<template\\b).)*?<\\/template>\\s*<[^>]*\\bv-else(?:-if)?\\b/s',
                $this->read($file),
                $file
            );
        }
    }

    public function testCreatePageSetsMirrorAfterInitWithoutUndoingRepository(): void
    {
        $script = $this->read('page/muwa-backup-repository-create/index.js');
        $template = $this->read('page/muwa-backup-repository-create/muwa-backup-repository-create.html.twig');

        self::assertStringContainsString('<muwa-s3-connection-select', $template);
        self::assertStringContainsString('v-model:value="mirrorForm.s3ConnectionId"', $template);
        self::assertStringContainsString('${this.requestMirror}/${this.backupRepository.id}/mirror', $script);

        self::assertStringContainsString('saveMirrorTarget(', $script);
        self::assertStringContainsString('this.mirrorForm.s3ConnectionId', $script);
        self::assertStringContainsString('muwa-backup-repository.mirror.saveError', $script);
        // a mirror failure must still navigate to the created repository
        self::assertStringContainsString("this.\$router.push({ name: 'muwa.backup.repository.detail'", $script);
    }

    public function testDashboardWidgetShowsMirrorStatusColumnConditionally(): void
    {
        $script = $this->read('component/muwa-backup-status-dashboard-widget/index.js');
        $template = $this->read('component/muwa-backup-status-dashboard-widget/muwa-backup-status-dashboard-widget.html.twig');

        self::assertStringContainsString("criteria.getAssociation('uploads')", $script);
        self::assertStringContainsString('.setLimit(1)', $script);
        self::assertStringContainsString('mirrorS3ConnectionId', $script);
        self::assertStringContainsString("property: 'mirrorStatus'", $script);
        self::assertStringContainsString('hasMirror', $script);
        self::assertStringContainsString('#column-mirrorStatus', $template);
        self::assertStringContainsString('mirrorStatusVariant(item)', $template);
        self::assertStringContainsString("succeeded: 'success'", $script);
        self::assertStringContainsString("failed: 'danger'", $script);

        $columns = strpos($script, 'statusColumns() {');
        self::assertNotFalse($columns);
        self::assertStringContainsString('showMirrorColumn', substr($script, $columns, 1500));
    }

    public function testViewerRoleCanReadUploadHistoryAndS3Connections(): void
    {
        $acl = $this->read('acl/index.js');
        $start = strpos($acl, '        viewer: {');
        self::assertNotFalse($start);
        $end = strpos($acl, 'dependencies:', $start);
        self::assertNotFalse($end);
        $viewer = substr($acl, $start, $end - $start);

        foreach ([
            'muwa_backup_repository_checks:read',
            'muwa_backup_repository_stats:read',
            'muwa_backup_repository_snapshots:read',
            'muwa_backup_repository_uploads:read',
            // the dashboard/detail page read mirrorS3ConnectionId and load S3 connections for the select
            'muwa_s3_connection:read',
        ] as $privilege) {
            self::assertStringContainsString("'" . $privilege . "'", $viewer, $privilege);
        }
        self::assertSame(1, substr_count($acl, "'muwa_backup_repository_uploads:read'"));
    }

    public function testDashboardMirrorCellShowsDateAndLastSuccessTooltip(): void
    {
        $script = $this->read('component/muwa-backup-status-dashboard-widget/index.js');

        self::assertStringContainsString('fetchLastSucceededUploads', $script);
        self::assertStringContainsString("this.repositoryFactory.create('muwa_backup_repository_uploads')", $script);
        self::assertStringContainsString("Criteria.equals('status', 'succeeded')", $script);
        self::assertStringContainsString("criteria.getAssociation('backupRepositoryChecks')", $script);
        self::assertStringContainsString('this.formatDateTime(item.mirrorAt)', $script);
        self::assertStringContainsString('muwa-backup-repository.dashboard.mirrorLastSucceeded', $script);
        self::assertStringContainsString('isMirrorOutdated(item)', $script);
        self::assertStringContainsString("return 'warning';", $script);
    }

    public function testDashboardHealthyRuleMatchesPhpCheckStatus(): void
    {
        $script = $this->read('component/muwa-backup-status-dashboard-widget/index.js');

        // PHP CheckStatus::isHealthy(): case-insensitive "contains" - no strict equality in JS
        self::assertStringContainsString('checkStatus.toLowerCase().includes(CHECK_STATUS_SUCCESS)', $script);
        self::assertStringNotContainsString('=== CHECK_STATUS_SUCCESS', $script);
    }

    public function testMirrorTargetHelpWarnsAboutDedicatedTargetAndHttpEndpoints(): void
    {
        $de = json_decode($this->read('snippet/de-DE.json'), true, 512, JSON_THROW_ON_ERROR)['muwa-backup-repository'];
        $en = json_decode($this->read('snippet/en-GB.json'), true, 512, JSON_THROW_ON_ERROR)['muwa-backup-repository'];

        self::assertStringContainsString('erste Lauf', $de['mirror']['prefixHelpText']);
        self::assertStringContainsString('first run', $en['mirror']['prefixHelpText']);
        self::assertStringContainsString('http://', $de['s3Connection']['endpointHint']);
        self::assertStringContainsString('http://', $en['s3Connection']['endpointHint']);
    }

    public function testSnippetsExistInBothLanguagesWithIdenticalKeys(): void
    {
        $de = json_decode($this->read('snippet/de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode($this->read('snippet/en-GB.json'), true, 512, JSON_THROW_ON_ERROR);
        $deMirror = $de['muwa-backup-repository']['mirror'] ?? [];
        $enMirror = $en['muwa-backup-repository']['mirror'] ?? [];

        self::assertNotSame([], $deMirror);
        self::assertSame($this->flattenKeys($deMirror), $this->flattenKeys($enMirror));
        foreach ([$deMirror, $enMirror] as $block) {
            array_walk_recursive($block, static fn (mixed $value) => self::assertNotSame('', $value));
        }
        self::assertSame(
            $this->flattenKeys($de['muwa-backup-repository']['dashboard']),
            $this->flattenKeys($en['muwa-backup-repository']['dashboard'])
        );
        self::assertArrayHasKey('mirrorStatusLabel', $de['muwa-backup-repository']['dashboard']);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $path . '.'));
                continue;
            }
            $keys[] = $path;
        }
        sort($keys);

        return $keys;
    }
}
