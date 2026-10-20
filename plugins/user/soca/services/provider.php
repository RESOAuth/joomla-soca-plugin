<?php

defined('_JEXEC') or die;
return new class implements \Joomla\DI\ServiceProviderInterface {
    public function register(\Joomla\DI\Container $container): void {
        $container->set(\Joomla\CMS\Extension\PluginInterface::class, function ($container) {
            $plugin = new \RESOAuth\Plugin\User\Soca\Extension\Soca($container->get(\Joomla\Event\DispatcherInterface::class),
                (array) \Joomla\CMS\Plugin\PluginHelper::getPlugin('user', 'soca'));
            $plugin->setApplication(\Joomla\CMS\Factory::getApplication()); return $plugin;
        });
    }
};
