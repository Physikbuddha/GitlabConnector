<?php

namespace KimaiPlugin\GitlabConnectorBundle\Gitlab;

interface GitlabApiConnectionFactoryInterface
{
    public function createConnection(string $baseUrl, string $accessToken): GitlabApiConnection;
}