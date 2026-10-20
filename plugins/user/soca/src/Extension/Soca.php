<?php

declare(strict_types=1);
namespace RESOAuth\Plugin\User\Soca\Extension;

use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\LoginButtonsEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

final class Soca extends CMSPlugin implements SubscriberInterface {
    public static function getSubscribedEvents(): array { return ['onUserAfterDelete' => 'deleted', 'onUserLoginButtons' => 'buttons']; }
    public function deleted(AfterDeleteEvent $event): void {
        if (!$event->getDeletingResult()) { return; }
        $user = $event->getUser();
        Factory::getContainer()->get(DatabaseInterface::class)->setQuery('DELETE FROM #__soca_links WHERE userid = ' . (int) $user['id'])->execute();
    }
    public function buttons(LoginButtonsEvent $event): void {
        if (!$this->getApplication()->isClient('site')) { return; }
        $url = Uri::root(true) . '/index.php?soca=login';
        // Joomla renders native login buttons; its field expects an onclick handler.
        // Only this fixed application-relative URL enters JavaScript, never claims.
        $event->addResult([['label' => 'Sign in with SOCA', 'id' => 'soca-login-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $event->getFormId()),
            'class' => 'btn btn-secondary', 'onclick' => 'window.location.href=' . json_encode($url) . ';return false;']]);
    }
}
