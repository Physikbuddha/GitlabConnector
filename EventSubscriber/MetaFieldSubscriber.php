<?php

namespace KimaiPlugin\GitlabConnectorBundle\EventSubscriber;

use App\Configuration\SystemConfiguration;
use App\Entity\MetaTableTypeInterface;
use App\Entity\ProjectMeta;
use App\Entity\TimesheetMeta;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\TimesheetMetaDefinitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class MetaFieldSubscriber implements EventSubscriberInterface
{
    /**
     * @var SystemConfiguration
     */
    private $configuration;
    /**
     * @var string
     */
    private $gitLabToken;
    public function __construct(SystemConfiguration $configuration)
    {
        $this->configuration = $configuration;
        $this->gitlabToken = $this->configuration->find('gitlab_private_token');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['loadTimesheetMeta', 200],
            ProjectMetaDefinitionEvent::class => ['loadProjectMeta', 200]
        ];
    }

    private function getProjectMetaField(): MetaTableTypeInterface
    {
        return (new ProjectMeta())
            ->setName('gitlab_project_id')
            ->setLabel('GitLab project ID')
            ->setType(IntegerType::class)
            ->setIsVisible(true);
    }

    public function loadProjectMeta(ProjectMetaDefinitionEvent $event)
    {
        if (!$this->gitlabToken) {
            return;
        }
        $event->getEntity()->setMetaField($this->getProjectMetaField());
    }

    private function getTimesheetMetaField(): MetaTableTypeInterface
    {
        return (new TimesheetMeta())
            ->setName('gitlab_issue_id')
            ->setLabel('GitLab issue ID')
            ->setType(IntegerType::class)
            ->setIsVisible(true);
    }

    public function loadTimesheetMeta(TimesheetMetaDefinitionEvent $event)
    {
        if (!$this->gitlabToken) {
            return;
        }
        $event->getEntity()->setMetaField($this->getTimesheetMetaField());
    }
}