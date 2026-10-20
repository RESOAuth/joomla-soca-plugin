<?php

defined('_JEXEC') or die;
require_once dirname(__DIR__) . '/bootstrap.php';
return new class implements \Joomla\DI\ServiceProviderInterface {
    public function register(\Joomla\DI\Container $container): void {
        $container->set(\Joomla\CMS\Extension\PluginInterface::class, function ($container) {
            $plugin = new \RESOAuth\Plugin\System\Soca\Extension\Soca($container->get(\Joomla\Event\DispatcherInterface::class),
                (array) \Joomla\CMS\Plugin\PluginHelper::getPlugin('system', 'soca'));
            $plugin->setApplication(\Joomla\CMS\Factory::getApplication()); return $plugin;
        });
    }
};
