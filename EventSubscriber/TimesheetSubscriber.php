<?php

namespace KimaiPlugin\GitlabConnectorBundle\EventSubscriber;

use App\Configuration\SystemConfiguration;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Event\AbstractTimesheetEvent;
use App\Event\TimesheetCreatePostEvent;
use App\Event\TimesheetDeleteMultiplePreEvent;
use App\Event\TimesheetDeletePreEvent;
use App\Event\TimesheetStopPostEvent;
use App\Event\TimesheetUpdateMultiplePostEvent;
use App\Event\TimesheetUpdatePostEvent;
use DateTime;
use KimaiPlugin\GitlabConnectorBundle\Gitlab\GitlabApiConnection;
use KimaiPlugin\GitlabConnectorBundle\Gitlab\GitlabApiConnectionFactoryInterface;
use KimaiPlugin\GitlabConnectorBundle\Utility\TimelogUtility;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @phpstan-import-type Timelog from GitlabApiConnection
 */
class TimesheetSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfiguration $configuration,
        private readonly GitlabApiConnectionFactoryInterface $apiConnectionFactory
    ) {
    }

    public static function getSubscribedEvents()
    {
        // TimesheetDuplicatePostEvent and TimesheetRestartPostEvent are not neccessary,
        // since TimesheetCreatePostEvent is triggered along as well
        return [
            TimesheetCreatePostEvent::class => ['onCreateUpdate', 100],
            TimesheetUpdatePostEvent::class => ['onCreateUpdate', 100],
            TimesheetStopPostEvent::class => ['onCreateUpdate', 100],
            TimesheetDeletePreEvent::class => ['onDelete', 100],
            TimesheetUpdateMultiplePostEvent::class => ['onUpdateMultiple', 100],
            TimesheetDeleteMultiplePreEvent::class => ['onDeleteMultiple', 100],
        ];
    }

    public function onCreateUpdate(AbstractTimesheetEvent $event): void
    {
        $this->processTimesheet($event->getTimesheet(), false);
    }

    public function onDelete(TimesheetDeletePreEvent $event): void
    {
        $this->processTimesheet($event->getTimesheet(), true);
    }

    public function onUpdateMultiple(TimesheetUpdateMultiplePostEvent $event): void
    {
        foreach ($event->getTimesheets() as $timesheet) {
            $this->processTimesheet($timesheet, false);
        }
    }

    public function onDeleteMultiple(TimesheetDeleteMultiplePreEvent $event): void
    {
        foreach ($event->getTimesheets() as $timesheet) {
            $this->processTimesheet($timesheet, true);
        }
    }

    private function processTimesheet(Timesheet $timesheet, bool $timesheetIsGettingDeleted): void
    {
        $gitlabBaseUrl = $this->getGitlabBaseUrl();
        $gitlabToken = $this->getGitlabAccessToken($timesheet->getUser());

        if (!$gitlabBaseUrl || !$gitlabToken) {
            return;
        }

        // Trim any trailing slashes from the base URL
        $gitlabBaseUrl = trim($gitlabBaseUrl, '/');

        $project = $timesheet->getProject();
        $projectId = $project->getMetaField('gitlab_project_id')?->getValue();
        $issueId = $timesheet->getMetaField('gitlab_issue_id')?->getValue();

        if (!$issueId) {
            // No Gitlab issue ID has been set in the timesheet meta fields.
            // Check if issue information has been provided in the timesheet description.
            $issueIdentifiers = $this->extractGitlabIssueIdentifiers($gitlabBaseUrl, $timesheet->getDescription() ?? '');

            $projectId = $issueIdentifiers['projectPath'] ?? $projectId;
            $issueId = $issueIdentifiers['issueId'] ?? $issueId;
        }

        if (!$projectId || !$issueId) {
            // Still no luck, don't send anything to Gitlab.
            return;
        }

        // A Gitlab project full path should not start or end with a slash
        $projectId = trim($projectId, '/');

        if (is_numeric($projectId)) {
            // Project id was provided as integer and not as full path.
            // Convert it to an integer to let the API connection class convert it to a full path automatically.
            $projectId = (int)$projectId;
        }

        $connection = $this->apiConnectionFactory->createConnection($gitlabBaseUrl, $gitlabToken);

        // Check if we have any existing timelog containing the Kimai timesheet ID
        $timelogsForIssue = $connection->getTimelogsForIssue($projectId, $issueId);
        $existingTimelogs = $this->filterTimelogsByTimesheet($timelogsForIssue['timelogs'], $timesheet->getId());

        if (count($existingTimelogs) > 1) {
            // If there's more than one Gitlab timelog for this timesheet, delete all of them.
            // A fresh one will be created after.
            foreach ($existingTimelogs as $existingTimelog) {
                $connection->deleteTimelog($existingTimelog['id']);
            }
        } elseif (count($existingTimelogs) === 1) {
            // There is already a timelog for this timesheet, check if it needs updating.
            $existingTimelog = reset($existingTimelogs);
            if (!$timesheetIsGettingDeleted && !$this->timelogNeedsUpdate($existingTimelog, $timesheet)) {
                // No changes have been made to the timesheet, no need to update the timelog.
                return;
            }

            // Delete the existing timelog for this timesheet.
            // A fresh one will be created after.
            $connection->deleteTimelog($existingTimelog['id']);
        }

        if ($timesheetIsGettingDeleted || !$timesheet->getDuration()) {
            // No duration is set on the timesheet, or it's being deleted.
            // Don't create a new timelog in Gitlab.
            return;
        }

        $connection->storeTimelog(
            $timesheet->getId(),
            $timelogsForIssue['issue'],
            $timesheet->getBegin(),
            $timesheet->getDescription() ?? '',
            $timesheet->getDuration(),
        );
    }

    /**
     * @param Timelog $existingTimelog
     * @param Timesheet $timesheet
     * @return bool
     */
    private function timelogNeedsUpdate(array $existingTimelog, Timesheet $timesheet): bool
    {
        if ($existingTimelog['timeSpent'] !== $timesheet->getDuration()) {
            return true;
        }

        $newDescription = TimelogUtility::buildTimelogSummary(
            $timesheet->getId(),
            $timesheet->getDescription()
        );
        if ($existingTimelog['summary'] !== $newDescription) {
            return true;
        }

        $oldSpentAt = new DateTime($existingTimelog['spentAt']);
        if ($oldSpentAt->getTimestamp() !== $timesheet->getBegin()->getTimestamp()) {
            return true;
        }

        return false;
    }

    /**
     * Scans the Kimai timesheet description text for Gitlab issue URLs or IDs.
     * Returns an array containing the project path and issue ID if found.
     *
     * @param string $gitlabBaseUrl
     * @param string $timesheetDescription
     * @return array{projectPath: string|null, issueId: int|null}
     */
    private function extractGitlabIssueIdentifiers(string $gitlabBaseUrl, string $timesheetDescription): array {
        // Pattern to match a full Gitlab issue URL anywhere in brackets
        $patternFullUrlInBrackets = sprintf(
            '/\[%s\/([\w\-\/]+)\/-\/issues\/(\d+)\]/',
            preg_quote($gitlabBaseUrl, '/')
        );
        // Pattern to match a full Gitlab issue URL at the start of the description
        $patternFullUrlAtStart = sprintf(
            '/^%s\/([\w\-\/]+)\/-\/issues\/(\d+)/',
            preg_quote($gitlabBaseUrl, '/')
        );
        // Pattern to match an issue ID anywhere in brackets
        $patternIssueIdInBrackets = '/\[\#(\d+)\]/';
        // Pattern to match an issue ID at the start of the description
        $patternIssueIdAtStart = '/^\#(\d+)/';

        // Check for a Gitlab issue URL anywhere in the description. It needs to be contained in brackets.
        // ex. "[https://gitlab.example.com/somegroup/somesubgroup/someproject/-/issues/12345]"
        if (preg_match($patternFullUrlInBrackets, $timesheetDescription, $matches)) {
            return ['projectPath' => $matches[1], 'issueId' => (int)$matches[2]];
        }
        // Check if the description starts with a Gitlab issue URL.
        // ex. "https://gitlab.example.com/somegroup/somesubgroup/someproject/-/issues/12345"
        if (preg_match($patternFullUrlAtStart, $timesheetDescription, $matches)) {
            return ['projectPath' => $matches[1], 'issueId' => (int)$matches[2]];
        }
        // Check if the description contains a loose issue ID. It needs to be contained in brackets.
        // ex. "[#12345]"
        if (preg_match($patternIssueIdInBrackets, $timesheetDescription, $matches)) {
            return ['projectPath' => null, 'issueId' => (int)$matches[1]];
        }
        // Check if the description starts with a loose issue ID.
        // ex. "#12345"
        if (preg_match($patternIssueIdAtStart, $timesheetDescription, $matches)) {
            return ['projectPath' => null, 'issueId' => (int)$matches[1]];
        }

        // If none of the patterns matched, return null for both values
        return ['projectPath' => null, 'issueId' => null];
    }

    /**
     * @param Timelog[] $timelogs
     * @param int $timesheetId
     * @return Timelog[]
     */
    private function filterTimelogsByTimesheet(array $timelogs, int $timesheetId): array
    {
        return array_filter(
            $timelogs,
            fn (array $timelog) => str_ends_with($timelog['summary'], "[Kimai-ID $timesheetId]")
        );
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
