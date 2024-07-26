<?php

namespace KimaiPlugin\GitlabConnectorBundle\EventSubscriber;

use App\Configuration\SystemConfiguration;
use App\Entity\ProjectMeta;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\TimesheetMetaDefinitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class MetaFieldSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['loadTimesheetMeta', 200],
            ProjectMetaDefinitionEvent::class => ['loadProjectMeta', 200]
        ];
    }

    public function loadProjectMeta(ProjectMetaDefinitionEvent $event)
    {
        if (!$this->getGitlabBaseUrl()) {
            // Only add the project ID field if a Gitlab URL has been configured.
            return;
        }

        $newField = (new ProjectMeta())
            ->setName('gitlab_project_id')
            ->setType(TextType::class)
            ->setIsVisible(true);
        $event->getEntity()->setMetaField($newField);
    }

    public function loadTimesheetMeta(TimesheetMetaDefinitionEvent $event)
    {
        if (
            !$this->getGitlabBaseUrl() ||
            $event->getEntity()->getUser() !== null && !$this->getGitlabAccessToken($event->getEntity()->getUser()) ||
            $event->getEntity()->getProject() !== null && !$event->getEntity()->getProject()->getMetaField('gitlab_project_id')
        ) {
            // Only add the issue ID field if a Gitlab URL and an access token have been configured.
            // If the timesheet has a project already set, hide the field if the Kimai project hasn't been configured with a Gitlab project ID.
            // Ignore the project restriction if a new timesheet is being created, and project is still set to null.
            return;
        }

        $newField = (new TimesheetMeta())
            ->setName('gitlab_issue_id')
            ->setType(IntegerType::class)
            ->setIsVisible(true);
        $event->getEntity()->setMetaField($newField);
    }

    private function getGitlabBaseUrl(): ?string
    {
        return $this->configuration->find('gitlab_instance_base_url');
    }

    private function getGitlabAccessToken(User $user): ?string
    {
        /** @var ?UserPreference $gitlabTokenPreference */
        $gitlabTokenPreference = $user->getPreferences()->findFirst(
            fn(int $key, UserPreference $pref) => $pref->getName() === 'gitlab_private_token'
        );
        return $gitlabTokenPreference?->getValue();
    }
}