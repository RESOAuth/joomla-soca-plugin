<?php

declare(strict_types=1);

namespace RESOAuth\Plugin\System\Soca;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use RESOAuth\Soca\Bridge\Flow;

final class JoomlaFlow extends Flow {
    private function db(): DatabaseInterface { return Factory::getContainer()->get(DatabaseInterface::class); }
    private function insert(string $table, array $values): void {
        $record = (object) $values; $this->db()->insertObject($table, $record);
    }
    protected function settings(): array {
        $plugin = PluginHelper::getPlugin('system', 'soca');
        $params = new \Joomla\Registry\Registry($plugin->params ?? '{}');
        $raw = $params->get('profile', '{}');
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }
    protected function paths(): array {
        $base = Uri::root(true);
        return ['redirect_uri' => $base . '/index.php?soca=callback', 'metadata_uri' => $base . '/index.php?soca=metadata',
            'jwks_uri' => $base . '/index.php?soca=jwks'];
    }
    protected function siteName(): string { return Factory::getApplication()->get('sitename', 'Joomla'); }
    public function operator(): int {
        $user = Factory::getApplication()->getIdentity();
        return $user && $user->authorise('core.admin') ? (int) $user->id : 0;
    }
    protected function putTransaction(string $state, array $transaction): void {
        $session = Factory::getApplication()->getSession();
        $pending = $session->get('soca.transactions', []);
        $pending = array_filter($pending, fn($t) => ($t['started'] ?? 0) >= time() - 300);
        if (count($pending) >= 5) { throw new \RuntimeException('Too many pending sign-ins. Wait five minutes.'); }
        $db = $this->db();
        $db->setQuery('DELETE FROM #__soca_transactions WHERE expires < ' . time())->execute();
        $hash = hash('sha256', $state);
        $this->insert('#__soca_transactions', ['statehash' => $hash, 'expires' => time() + 300]);
        $pending[$hash] = $transaction;
        $session->set('soca.transactions', $pending);
    }
    protected function takeTransaction(string $state): array {
        $session = Factory::getApplication()->getSession();
        $pending = $session->get('soca.transactions', []);
        $hash = hash('sha256', $state);
        $transaction = $pending[$hash] ?? null;
        if (!$transaction) { throw new \RuntimeException('This browser has no matching sign-in attempt.'); }
        unset($pending[$hash]); $session->set('soca.transactions', $pending);
        $db = $this->db();
        $db->setQuery('DELETE FROM #__soca_transactions WHERE statehash = ' . $db->quote($hash) . ' AND expires >= ' . time())->execute();
        if ($db->getAffectedRows() !== 1) { throw new \RuntimeException('Sign-in expired or was already used.'); }
        return $transaction;
    }
    protected function evidence(string $kind): ?string {
        $db = $this->db();
        return $db->setQuery('SELECT revision FROM #__soca_evidence WHERE kind = ' . $db->quote($kind))->loadResult() ?: null;
    }
    protected function saveEvidence(string $kind, string $revision): void {
        $db = $this->db(); $db->transactionStart();
        try {
            $db->setQuery('DELETE FROM #__soca_evidence WHERE kind = ' . $db->quote($kind))->execute();
            $this->insert('#__soca_evidence', ['kind' => $kind, 'revision' => $revision]);
            $db->transactionCommit();
        } catch (\Throwable $e) { $db->transactionRollback(); throw $e; }
    }
    public function invalidate(): void {
        $this->db()->setQuery('DELETE FROM #__soca_transactions')->execute();
        Factory::getApplication()->getSession()->remove('soca.transactions');
    }
    public function account(array $claims): \Joomla\CMS\User\User {
        $db = $this->db(); $db->transactionStart();
        try {
            $identity = self::identity($claims);
            $id = $db->setQuery('SELECT userid FROM #__soca_links WHERE identityhash = ' . $db->quote($identity))->loadResult();
            $factory = Factory::getContainer()->get(UserFactoryInterface::class);
            if ($id) { $user = $factory->loadUserById((int) $id); $user->load((int) $id); }
            else {
                if (!$this->configuration()['provisioning']) { throw new \RuntimeException('Administrator-approved account linking is required.'); }
                $profile = self::profile($claims);
                if ($db->setQuery('SELECT id FROM #__users WHERE email = ' . $db->quote($profile['email']))->loadResult()) {
                    throw new \RuntimeException('Provisioning requires administrator review.');
                }
                // Fixed Registered group, no provider-driven groups or administrative grants.
                $group = 2;
                if (\Joomla\CMS\Access\Access::checkGroup($group, 'core.admin') ||
                    \Joomla\CMS\Access\Access::checkGroup($group, 'core.login.admin')) {
                    throw new \RuntimeException('The Registered group has sensitive privileges.');
                }
                $password = bin2hex(random_bytes(48));
                $user = $factory->loadUserById(0);
                $data = ['username' => $profile['username'], 'name' => trim($profile['given_name'] . ' ' . $profile['family_name']),
                    'email' => $profile['email'], 'password' => $password, 'password2' => $password, 'groups' => [$group], 'block' => 0];
                if (!$user->bind($data) || !$user->save()) {
                    throw new \RuntimeException('The host rejected account creation.');
                }
                $this->insert('#__soca_links', ['identityhash' => $identity, 'userid' => $user->id, 'created' => time()]);
            }
            self::eligible($user);
            $db->transactionCommit(); return $user;
        } catch (\Throwable $e) { $db->transactionRollback(); throw $e; }
    }
    public static function eligible(\Joomla\CMS\User\User $user): void {
        if (!$user->id || $user->block || $user->requireReset || $user->authorise('core.admin') ||
            $user->authorise('core.login.admin') || !$user->authorise('core.login.site')) {
            throw new \RuntimeException('This local account is not eligible for SOCA.');
        }
    }
    public function link(string $subject, int $userid): void {
        if (!$this->operator()) { throw new \RuntimeException('Administrator approval required.'); }
        self::eligible(Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userid));
        $this->insert('#__soca_links', ['identityhash' => self::identity(['iss' => $this->configuration()['issuer'], 'sub' => $subject]),
            'userid' => $userid, 'created' => time()]);
        $this->audit('link', $userid);
    }
    public function unlink(int $userid): void {
        if (!$this->operator()) { throw new \RuntimeException('Administrator approval required.'); }
        $this->db()->setQuery('DELETE FROM #__soca_links WHERE userid = ' . $userid)->execute(); $this->audit('unlink', $userid);
    }
    public function audit(string $action, int $userid = 0): void {
        $this->insert('#__soca_audit', ['actor' => $this->operator(), 'action' => $action,
            'userid' => $userid, 'created' => time()]);
    }
}
