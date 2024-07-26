<?php

namespace KimaiPlugin\GitlabConnectorBundle\EventSubscriber;

use App\Entity\UserPreference;
use App\Event\SystemConfigurationEvent;
use App\Event\UserPreferenceEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
            UserPreferenceEvent::class => ['onUserPreferenceConfiguration', 100]
        ];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event)
    {
        $event->addConfiguration((new SystemConfigurationModel('gitlab'))
            ->setConfiguration([
                (new Configuration('gitlab_instance_base_url'))
                    ->setTranslationDomain('system-configuration')
                    ->setOptions(['required' => false])
                    ->setType(UrlType::class),
            ])
        );
    }

    public function onUserPreferenceConfiguration(UserPreferenceEvent $event)
    {
        $pref = new UserPreference('gitlab_private_token');
        $pref->setSection('gitlab');
        $pref->setType(TextType::class);
        $pref->setOptions(['required' => false]);
        $event->addPreference($pref);
    }
}