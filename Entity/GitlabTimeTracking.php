<?php

namespace KimaiPlugin\GitlabConnector\Entity;

use App\Entity\Timesheet;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'plugin_gitlab_connector_times')]
class GitlabTimeTracking
{
    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Timesheet $timesheet = null;

    #[ORM\Column(name: 'name', type: 'integer')]
    private ?int $lastDuration = null;
}
