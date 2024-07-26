<?php

namespace KimaiPlugin\GitlabConnectorBundle\Gitlab;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitlabApiConnectionFactory implements GitlabApiConnectionFactoryInterface
{
    public function __construct(private readonly HttpClientInterface $httpclient)
    {
    }

    public function createConnection(string $baseUrl, string $accessToken): GitlabApiConnection
    {
        return new GitlabApiConnection($this->httpclient, $baseUrl, $accessToken);
    }
}