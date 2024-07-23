<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GitLabBundle\Entity;

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
