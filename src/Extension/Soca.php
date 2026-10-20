<?php

declare(strict_types=1);

namespace RESOAuth\Plugin\System\Soca\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use RESOAuth\Plugin\System\Soca\JoomlaFlow;

final class Soca extends CMSPlugin implements SubscriberInterface {
    private ?JoomlaFlow $management = null;
    public static function getSubscribedEvents(): array { return ['onAfterInitialise' => 'route', 'onAfterDispatch' => 'renderManagement', 'onContentPrepareForm' => 'lockSettings']; }
    public function lockSettings(\Joomla\CMS\Event\Model\PrepareFormEvent $event): void {
        $form = $event->getForm();
        if (getenv('SOCA_CONFIG_FILE') && $form->getName() === 'com_plugins.plugin') {
            $form->setFieldAttribute('profile', 'readonly', 'true', 'params');
        }
    }
    public function renderManagement(): void {
        if ($this->management !== null) {
            $this->getApplication()->getDocument()->setBuffer($this->manage($this->management), 'component');
        }
    }
    public function route(): void {
        $app = $this->getApplication();
        $action = $app->input->getCmd('soca', '');
        if (!$action || !in_array($action, ['metadata', 'jwks', 'login', 'callback', 'manage', 'documents', 'test', 'invalidate', 'link', 'unlink'], true)) { return; }
        try {
            $flow = new JoomlaFlow();
            if (in_array($action, ['metadata', 'jwks'], true)) {
                if (!$app->isClient('site')) { throw new \RuntimeException('Public documents belong to the site application.'); }
                $this->output(json_encode($flow->client()->{$action}(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json', 200, true);
            }
            if ($action === 'login') {
                if (!$app->isClient('site')) { throw new \RuntimeException('Use local administrator login.'); }
                $app->redirect($flow->begin()); return;
            }
            if ($action === 'callback') {
                if (!$app->isClient('site')) { throw new \RuntimeException('Invalid callback application.'); }
                $claims = $flow->finish($app->input->get->getArray());
                if ($claims === null) { $app->redirect(Uri::root(true) . '/index.php?soca=manage'); return; }
                $user = $flow->account($claims);
                $token = bin2hex(random_bytes(32));
                $app->getSession()->set('soca.handoff', ['id' => (int) $user->id, 'token' => $token]);
                try {
                    if (!$app->login(['username' => $user->username, 'password' => '', 'soca' => $token],
                        ['silent' => true, 'action' => 'core.login.site', 'remember' => false])) {
                        $app->logout(); throw new \RuntimeException('The host rejected sign-in.');
                    }
                } finally { $app->getSession()->remove('soca.handoff'); }
                $app->redirect(Uri::root(true) . '/'); return;
            }
            if (!$flow->operator()) { throw new \RuntimeException('A locally authenticated Super User session is required.'); }
            if ($action !== 'manage') {
                if (!Session::checkToken('post')) { throw new \RuntimeException('Invalid administrator request token.'); }
                switch ($action) {
                    case 'documents': $flow->checkDocuments(); break;
                    case 'test':
                        if (!$app->isClient('site')) { throw new \RuntimeException('Start operator tests from a locally authenticated Super User front-end session at /index.php?soca=manage.'); }
                        $app->redirect($flow->begin(true)); return;
                    case 'invalidate': $flow->invalidate(); $flow->audit('invalidate'); break;
                    case 'link': $flow->link($app->input->post->getString('subject'), $app->input->post->getInt('userid')); break;
                    case 'unlink': $flow->unlink($app->input->post->getInt('userid')); break;
                }
            }
            $this->management = $flow;
        } catch (\Throwable $e) {
            $reference = bin2hex(random_bytes(6));
            \Joomla\CMS\Log\Log::add('SOCA failure ' . $reference . ' (' . get_class($e) . ')', \Joomla\CMS\Log\Log::WARNING, 'soca');
            $this->output('SOCA request could not complete. Use local login or contact an administrator. Reference ' . $reference, 'text/plain', 400);
        }
    }
    private function manage(JoomlaFlow $flow): string {
        $escape = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        try { $status = $flow->status(); } catch (\Throwable $e) { $status = ['configuration_error' => $e->getMessage()]; }
        $html = '<section aria-labelledby="soca-title"><h1 id="soca-title">SOCA maintenance</h1>';
        $html .= '<p>Configure the SOCA system plugin in Extensions, Plugins. SOCA_CONFIG_FILE overrides GUI settings. Operator tests preserve the initiating session and never write accounts.</p><pre>' . $escape(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        $html .= '<form method="post"><input type="hidden" name="' . Session::getFormToken() . '" value="1">';
        foreach (['documents' => 'Check public documents', 'test' => 'Start operator test', 'invalidate' => 'Invalidate pending sign-ins'] as $action => $label) {
            $html .= '<button name="soca" value="' . $action . '">' . $label . '</button> ';
        }
        $html .= '<h2>Approved account association</h2><p>Verify ownership independently. Email alone cannot approve a link.</p><label for="soca-userid">Local account ID</label><input id="soca-userid" name="userid" type="number" min="1"><label for="soca-subject">Exact provider subject</label><input id="soca-subject" name="subject" maxlength="255"><button name="soca" value="link">Approve link</button><button name="soca" value="unlink">Remove link</button></form></section>';
        return $html;
    }
    private function output(string $body, string $type, int $status = 200, bool $public = false): never {
        $app = $this->getApplication();
        http_response_code($status);
        $app->setHeader('Content-Type', $type . '; charset=utf-8', true);
        $app->setHeader('Cache-Control', $public ? 'public, max-age=300' : 'no-store', true);
        $app->sendHeaders(); echo $body; $app->close(); exit;
    }
}
