<?php

namespace KimaiPlugin\GitlabConnectorBundle\Utility;

class TimelogUtility
{
    public static function buildTimelogSummary(int $timesheetId, string $timesheetDescription): string
    {
        $summaryParts = [
            $timesheetDescription,
            sprintf('[Kimai-ID %s]', $timesheetId)
        ];
        return implode(' ', array_filter($summaryParts));
    }
}
