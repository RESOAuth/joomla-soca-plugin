<?php
/** Run only against a disposable installed Joomla site. */
declare(strict_types=1);
define('_JEXEC', 1);
define('JPATH_BASE', getenv('JOOMLA_ROOT') ?: '/var/www/html');
$_SERVER['HTTP_HOST'] = 'app.example.com'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php';
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
$container = Factory::getContainer();
$container->alias('session', 'session.web.site')->alias(Session::class, 'session.web.site')
    ->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class); Factory::$application = $app;
$app->loadIdentity();
$app->loadLanguage();
$app->createExtensionNamespaceMap();
$db = $container->get(DatabaseInterface::class);
$db->setQuery("UPDATE #__extensions SET enabled = 1 WHERE type = 'plugin' AND element = 'soca'")->execute();
$db->setQuery("UPDATE #__extensions SET params = " . $db->quote(json_encode(['profile' => json_encode(['origin' => 'https://app.example.com',
    'issuer' => 'https://issuer.example.com', 'allowed_domains' => ['example.com'], 'provisioning' => true])])) . " WHERE type = 'plugin' AND folder = 'system' AND element = 'soca'")->execute();
require JPATH_BASE . '/plugins/system/soca/bootstrap.php';
$app->bootPlugin('soca', 'system');
$flow = new \RESOAuth\Plugin\System\Soca\JoomlaFlow();
$count = 0;
function verify(bool $value, string $label): void { global $count; $count++; if (!$value) { throw new RuntimeException($label); } }
function denied(callable $callback, string $label): void { try { $callback(); } catch (Throwable $e) { verify(true, $label); return; } verify(false, $label); }
$claims = ['iss' => 'https://issuer.example.com', 'sub' => 'test-' . bin2hex(random_bytes(8)),
    'email' => bin2hex(random_bytes(8)) . '@example.com', 'email_verified' => true];
$user = $flow->account($claims);
verify($user->id > 0 && !$user->block, 'Native account provisioning');
verify($flow->account($claims)->id === $user->id, 'Immutable link resolves returning account');
$before = $db->setQuery('SELECT COUNT(*) FROM #__users')->loadResult();
denied(fn() => $flow->account(array_replace($claims, ['sub' => 'other-subject'])), 'Existing email never establishes identity ownership');
verify($db->setQuery('SELECT COUNT(*) FROM #__users')->loadResult() === $before, 'Rejected provisioning leaves no account');
$db->setQuery('UPDATE #__users SET block = 1 WHERE id = ' . (int) $user->id)->execute();
denied(fn() => $flow->account($claims), 'Blocked user rejected');
$db->setQuery('UPDATE #__users SET block = 0 WHERE id = ' . (int) $user->id)->execute();
$user->load($user->id);
$put = new ReflectionMethod($flow, 'putTransaction'); $take = new ReflectionMethod($flow, 'takeTransaction');
$state = bin2hex(random_bytes(32));
$put->invoke($flow, $state, ['started' => time(), 'verifier' => 'native-session-secret']);
verify($take->invoke($flow, $state)['verifier'] === 'native-session-secret', 'Native session transaction resolves');
denied(fn() => $take->invoke($flow, $state), 'Native replay guard rejects reuse');
$old = $app->getSession()->getId(); $token = bin2hex(random_bytes(32));
$app->getSession()->set('soca.handoff', ['id' => (int) $user->id, 'token' => $token]);
verify($app->login(['username' => $user->username, 'password' => '', 'soca' => $token], ['silent' => true, 'action' => 'core.login.site', 'remember' => false]), 'Native host authentication pipeline');
verify((int) $app->getIdentity()->id === (int) $user->id, 'Native session user established');
verify($old !== $app->getSession()->getId(), 'Native session ID rotated');
verify(!$app->getSession()->get('soca.handoff'), 'Authentication handoff consumed once');
$app->logout();
verify(!$app->getSession()->get('user'), 'Local logout clears the session user');
echo "Joomla native host: $count assertions passed.\n";
