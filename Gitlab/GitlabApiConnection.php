<?php

namespace KimaiPlugin\GitlabConnectorBundle\Gitlab;

use DateTime;
use DateTimeInterface;
use KimaiPlugin\GitlabConnectorBundle\Exception\GitlabItemDoesNotExistException;
use KimaiPlugin\GitlabConnectorBundle\Exception\GraphqlException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @phpstan-type Timelog array{id: string, timeSpent: int, user: array{id: string, name: string}, spentAt: string, summary: string}
 */
class GitlabApiConnection
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $baseUrl,
        private readonly string $accessToken
    ) {
    }

    public function getProjectFullPath(int $projectId): string
    {
        $data = [
            'query' => <<<'QUERYEND'
                query projectFullPath($ids: [ID!]) {
                    projects: projects(ids: $ids) {
                        nodes {
                            fullPath
                        }
                    }
                }
                QUERYEND,
            'variables' => [
                'ids' => ["gid://gitlab/Project/$projectId"]
            ]
        ];

        $response = $this->callGraphqlApi($data);
        $project = reset($response['projects']['nodes']);

        if (!isset($project['fullPath'])) {
            throw new GitlabItemDoesNotExistException("There is no GitLab project with the ID $projectId.");
        }
        return $project['fullPath'];
    }

    /**
     * @param int|string $project
     * @param int $issueId
     * @return array{issue: string, timelogs: Timelog[]}
     * @throws GitlabItemDoesNotExistException
     */
    public function getTimelogsForIssue(int|string $project, int $issueId): array
    {
        if (is_int($project)) {
            $project = $this->getProjectFullPath($project);
        }

        $data = [
            'query' => <<<'QUERYEND'
                query issueTimelogs($fullPath: ID!, $iid: String) {
                    workspace: project(fullPath: $fullPath) {
                        issuable: issue(iid: $iid) {
                            id
                            timelogs {
                                nodes {
                                    id
                                    timeSpent
                                    user {
                                        id
                                        name
                                    }
                                    spentAt
                                    summary
                                }
                            }
                        }
                    }
                }
                QUERYEND,
            'variables' => [
                'fullPath' => $project,
                'iid' => (string)$issueId
            ]
        ];

        $response = $this->callGraphqlApi($data);
        if ($response['workspace'] === null) {
            $url = $this->baseUrl . '/' . $project;
            throw new GitlabItemDoesNotExistException("There is no GitLab project at $url.");
        }
        if ($response['workspace']['issuable'] === null) {
            $url = $this->baseUrl . '/' . $project;
            throw new GitlabItemDoesNotExistException("There is no GitLab issue with the ID $issueId in the project $url.");
        }

        return [
            'issue' => $response['workspace']['issuable']['id'],
            'timelogs' => $response['workspace']['issuable']['timelogs']['nodes'] ?? []
        ];
    }

    public function storeTimelog(int $timesheetId, string $gitlabIssueGid, ?DateTimeInterface $spentAt, string $description, int $timeSpent): string
    {
        $summaryParts = [
            $description,
            sprintf('[Kimai-ID %s]', $timesheetId)
        ];
        $summary = implode(' ', array_filter($summaryParts));

        $variables = [
            'input' => [
                'issuableId' => $gitlabIssueGid,
                'timeSpent' => $this->formatDuration($timeSpent),
                'summary' => $summary
            ]
        ];

        // spentAt is optional and doesn't allow a date in the future
        if (!$spentAt || $spentAt <= new DateTime('now')) {
            $variables['input']['spentAt'] = $spentAt->format('c');
        }

        $data = [
            'query' => <<<'QUERYEND'
                mutation createTimelog($input: TimelogCreateInput!) {
                    timelogCreate(input: $input) {
                        errors
                        timelog {
                            id
                        }
                    }
                }
                QUERYEND,
            'variables' => $variables
        ];

        $response = $this->callGraphqlApi($data);
        $errors = $response['timelogCreate']['errors'] ?? [];
        if (!empty($errors)) {
            throw new GraphqlException('The timesheet could not be synced with GitLab: ' . implode(' | ', $errors));
        }

        return $response['timelogCreate']['timelog']['id'];
    }

    public function deleteTimelog(string $timelogGid): string
    {
        $data = [
            'query' => <<<'QUERYEND'
                mutation deleteTimelog($input: TimelogDeleteInput!) {
                    timelogDelete(input: $input) {
                        errors
                        timelog {
                            id
                        }
                    }
                }
                QUERYEND,
            'variables' => [
                'input' => [
                    'id' => $timelogGid
                ]
            ]
        ];

        $response = $this->callGraphqlApi($data);
        $errors = $response['timelogDelete']['errors'] ?? [];
        if (!empty($errors)) {
            throw new GraphqlException('The timesheet could not be synced with GitLab: ' . implode(' | ', $errors));
        }

        return $response['timelogDelete']['timelog']['id'];
    }

    private function formatDuration(int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $formatted = '';
        if ($hours > 0) {
            $formatted .= "{$hours}h";
        }
        if ($minutes > 0) {
            $formatted .= "{$minutes}m";
        }

        return $formatted;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     * @throws GraphqlException
     */
    private function callGraphqlApi(array $data): array
    {
        try {
            $response = $this->client->request(
                'POST',
                $this->baseUrl . '/api/graphql',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json'
                    ],
                    'body' => json_encode($data),
                ]
            );
            $result = json_decode($response->getContent(), true);
        } catch (ClientException $exception) {
            if ($exception->getCode() === 401) {
                throw new GraphqlException('You are not authorized to access the GitLab API. Please check your access token, it might have been expired.');
            }

            throw $exception;
        }

        if (($errors = $result['errors'] ?? null) !== null) {
            $messages = array_map(fn($error) => $error['message'] ?? '', $errors);
            $message = implode(' | ', $messages);
            throw new GraphqlException('The GraphQL request to the GitLab server failed: ' . $message);
        }

        return $result['data'] ?? [];
    }
}