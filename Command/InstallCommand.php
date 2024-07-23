<?php

namespace KimaiPlugin\GitlabConnector\Command;

use App\Command\AbstractBundleInstallerCommand;

class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'gitlab';
    }

    protected function getMigrationConfigFilename(): ?string
    {
        return __DIR__ . '/../Migrations/gitlab.yaml';
    }
}