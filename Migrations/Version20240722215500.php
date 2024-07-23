<?php

namespace KimaiPlugin\GitLabBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * @version 2.18
 */
final class Version20240722215500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Gitlab related tables';
    }

    public function up(Schema $schema): void
    {
        $gitlabTable = $schema->createTable('plugin_gitlab_connector_times');

        $gitlabTable->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $gitlabTable->addColumn('timesheet_id', 'integer', ['notnull' => true]);
        $gitlabTable->addColumn('last_duration', 'integer', ['notnull' => true, 'default' => 0]);

        $gitlabTable->setPrimaryKey(['id']);

        $gitlabTable->addUniqueIndex(['timesheet_id'], 'timesheet_idx');

        $gitlabTable->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_gitlab_timesheet');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('plugin_gitlab_connector_times');
        $table->removeForeignKey('fk_gitlab_timesheet');

        $schema->dropTable('plugin_gitlab_connector_times');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
