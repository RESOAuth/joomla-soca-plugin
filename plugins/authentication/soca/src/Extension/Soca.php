<?php

declare(strict_types=1);
namespace RESOAuth\Plugin\Authentication\Soca\Extension;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\SubscriberInterface;

final class Soca extends CMSPlugin implements SubscriberInterface {
    public static function getSubscribedEvents(): array { return ['onUserAuthenticate' => 'authenticate']; }
    public function authenticate(AuthenticationEvent $event): void {
        $credentials = $event->getCredentials();
        if (!isset($credentials['soca'])) { return; }
        $session = $this->getApplication()->getSession();
        $handoff = $session->get('soca.handoff');
        $session->remove('soca.handoff');
        $response = $event->getAuthenticationResponse();
        $response->type = 'SOCA';
        $response->status = Authentication::STATUS_FAILURE;
        if (!is_array($handoff) || !is_string($credentials['soca']) || !hash_equals($handoff['token'], $credentials['soca'])) { return; }
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $handoff['id']);
        if ($user->username !== $credentials['username'] || $user->block || $user->requireReset ||
            $user->authorise('core.admin') || $user->authorise('core.login.admin') || !$user->authorise('core.login.site')) { return; }
        $response->username = $user->username;
        $response->email = $user->email;
        $response->fullname = $user->name;
        $response->status = Authentication::STATUS_SUCCESS;
        $event->stopPropagation();
    }
}
