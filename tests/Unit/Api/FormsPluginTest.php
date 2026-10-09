<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use ZipArchive;

/**
 * The plugin "forms" (excellent-plugins/forms): forms are records of the entity "forms" with a block
 * editor (field blocks of the plugin, columns, other blocks); submissions only start events. The setup of a project creates the
 * example "Contact" with the entity "contact_message" and an event: a submission creates a message
 * and sends two e-mails - to the administrators (users as recipients) and to the sender.
 */
class FormsPluginTest extends ApiTestCase
{
  private const MAIL_LOG = __DIR__.'/../../../runtime/test-mail.log';

  protected function _before(): void
  {
    @unlink(self::MAIL_LOG);
  }

  public function testFormsWithBlocksAndTheExample(): void
  {
    $admin = $this->login();
    if (($this->api('GET', '/admin/plugins/forms', token: $admin)['body']['data']['installed'] ?? false) === true) {
      $this->api('DELETE', '/admin/plugins/forms', token: $admin);
    }
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $this->zipOf(), 'forms.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/forms/install', token: $admin);

    // Activated in a project (plugins of projects - not "global"): it gets the blocks and the setup
    $project = 'f'.bin2hex(random_bytes(4));
    $created = $this->api('POST', '/admin/projects', ['name' => 'Website', 'slug' => $project, 'table_prefix' => $project.'_'], $admin)['body']['data'];
    $here = ['X-Project' => $project];
    $activation = $this->api('POST', '/admin/plugins/forms/activate', token: $admin, headers: $here);
    $this->assertSame(200, $activation['status'], json_encode($activation['body'], JSON_UNESCAPED_UNICODE));
    $active = $activation['body']['data'];
    $this->assertSame(['project', [$created['id']]], [$active['scope'], $active['projects']]);
    $this->assertSame(404, $this->api('GET', '/main/plugins/forms/form/contact')['status'], 'not active in "main"');
    $blocks = array_column($this->api('GET', '/admin/groups?kind=block', token: $admin, headers: $here)['body']['data'], 'name');
    foreach (['form', 'form_text', 'form_email', 'form_select', 'form_response', 'columns'] as $block) {
      $this->assertContains($block, $blocks);
    }
    // Fields side by side: the block Columns of the CMS - its columns take the fields
    $column = $this->api('GET', '/admin/groups/column', token: $admin, headers: $here)['body']['data'];
    $this->assertSame(['group', 'core'], [$column['kind'], $column['managed_by']]);
    $entities = array_column($this->api('GET', '/admin/entities', token: $admin, headers: $here)['body']['data'], null, 'slug');
    $this->assertSame(['Form', 'Settings'], array_column($entities['forms']['tabs'], 'label'));
    $this->assertSame(['name', 'email', 'message'], array_column($entities['contact_message']['fields'], 'name'));
    // The entity "forms" and the blocks are managed by the plugin, the example entity is not
    $formsEntity = $entities['forms'];
    $content = array_column($formsEntity['fields'], null, 'name')['content'];
    $this->assertSame(['forms', true], [$formsEntity['managed_by'], $content['locked']]);
    $this->assertSame(409, $this->api('DELETE', "/admin/entities/{$formsEntity['id']}", token: $admin, headers: $here)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$formsEntity['id']}", ['slug' => 'formulare'], $admin, $here)['status']);
    $this->assertSame(409, $this->api('DELETE', "/admin/entities/{$formsEntity['id']}/fields/{$content['id']}", token: $admin, headers: $here)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$formsEntity['id']}/fields/{$content['id']}", ['name' => 'inhalt'], $admin, $here)['status']);
    $relabeled = $this->api('PUT', "/admin/entities/{$formsEntity['id']}/fields/{$content['id']}", ['label' => 'Formular'], $admin, $here);
    $this->assertSame([200, true], [$relabeled['status'], $relabeled['body']['data']['locked'] ?? null], 'labels may change, the lock stays');
    $this->assertNull($entities['contact_message']['managed_by']);
    $textBlock = $this->api('GET', '/admin/groups/form_text', token: $admin, headers: $here)['body']['data'];
    $this->assertSame('forms', $textBlock['managed_by']);
    $this->assertSame(409, $this->api('DELETE', "/admin/groups/{$textBlock['id']}", token: $admin, headers: $here)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/groups/{$textBlock['id']}", ['name' => 'text_input'], $admin, $here)['status']);

    $event = $this->api('GET', '/admin/events', token: $admin, headers: $here)['body']['data'][0];
    $this->assertSame(['Contact form', 'forms.submission', ['create', 'email', 'email']], [$event['name'], $event['source'], array_column($event['steps'], 'type')]);
    $this->assertStringStartsWith('user:', $event['steps'][1]['to'], 'the administrators as users');

    // Websites: the example form as elements
    $public = $this->api('GET', "/{$project}/plugins/forms/form/contact")['body']['data'];
    $this->assertSame("/api/v1/{$project}/plugins/forms/submit/contact", $public['action']);
    $this->assertSame(['columns', 'textarea', 'checkbox', 'response'], array_column($public['fields'], 'type'));
    $this->assertSame('all', $public['fields'][3]['show']);
    $this->assertSame('email', $public['fields'][0]['columns'][1]['fields'][0]['name']);
    $this->assertSame("/api/v1/{$project}/plugins/forms/token/contact", $public['token_url']);

    // A token of the form is needed: without one, a changed one or one of another form - "reload the page"
    $values = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hallo!', 'privacy' => 'on'];
    $refused = $this->api('POST', "/{$project}/plugins/forms/submit/contact", $values);
    $this->assertSame([422, 'form_expired'], [$refused['status'], $refused['body']['error_code']]);
    $this->assertSame(422, $this->api('POST', "/{$project}/plugins/forms/submit/contact", $values + ['_token' => $public['token'].'x'])['status']);
    // Sent at once (less than 2 seconds after the token was issued): a bot - looks fine, nothing happens
    $fresh = $this->api('GET', "/{$project}/plugins/forms/token/contact")['body']['data']['token'];
    $this->assertSame('ignored', $this->api('POST', "/{$project}/plugins/forms/submit/contact", $values + ['_token' => $fresh])['body']['data']['id']);
    sleep(2);
    $token = $public['token'];

    // Sending it
    $invalid = $this->api('POST', "/{$project}/plugins/forms/submit/contact", ['email' => 'nope', '_token' => $token]);
    $this->assertSame(422, $invalid['status']);
    $this->assertSame(['name', 'email', 'message', 'privacy'], array_keys($invalid['body']['error_data']));
    $this->assertSame(200, $this->api('POST', "/{$project}/plugins/forms/submit/contact", ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'x', 'privacy' => '1', '_hp' => 'spam', '_token' => $token])['status']);
    $sent = $this->api('POST', "/{$project}/plugins/forms/submit/contact", $values + ['_token' => $token]);
    $this->assertSame(200, $sent['status'], json_encode($sent['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('Thank you! We will get back to you soon.', $sent['body']['data']['message']);

    // The event: a message, an e-mail to the administrator and one to the sender
    $messages = $this->api('GET', '/entities/contact_message/records', token: $admin, headers: $here)['body']['data'];
    $this->assertSame([['Ada', 'ada@example.com', 'Hallo!']], array_map(static fn(array $r): array => [$r['name'], $r['email'], $r['message']], $messages));
    $log = (string)@file_get_contents(self::MAIL_LOG);
    $adminEmail = $this->api('GET', '/auth/me', token: $admin)['body']['data']['user']['email'] ?? 'admin@';
    $this->assertStringContainsString('New message from Ada', $log);
    $this->assertStringContainsString($adminEmail, $log);
    $this->assertStringContainsString('Thank you for your message', $log);
    $this->assertStringContainsString('ada@example.com', $log);
    // (the bot started nothing - only Ada's message is there)
    $forms = array_column($this->api('GET', '/plugins/forms/api/forms', token: $admin, headers: $here)['body']['data'], null, 'slug');

    // A form of blocks: a name twice is left out, other blocks come along (the admin adds them to the field)
    $notice = $this->api('POST', '/admin/groups', ['name' => 'notice', 'label' => 'Hinweis', 'kind' => 'block', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here)['body']['data'];
    $content = array_column($entities['forms']['fields'], null, 'name')['content'];
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entities['forms']['id']}/fields/{$content['id']}", ['blocks' => [...array_column($content['blocks'], 'id'), $notice['id']]], $admin, $here)['status']);
    $form = $this->api('POST', '/entities/forms/records', ['name' => 'Newsletter', 'draft' => false, 'content' => [
      ['_type' => 'form_email', '_key' => 'a1', 'name' => 'email', 'label' => 'E-Mail', 'required' => true],
      ['_type' => 'form_text', '_key' => 'a2', 'name' => 'email', 'label' => 'Twice'],
      ['_type' => 'form_select', '_key' => 'a3', 'name' => 'topic', 'label' => 'Thema', 'options' => ['news|News', 'Events']],
      ['_type' => 'notice', '_key' => 'a4', 'title' => 'A block'],
      ['_type' => 'form_response', '_key' => 'a5', 'show' => 'error'],
      ['_type' => 'form_response', '_key' => 'a6'],
    ]], $admin, $here);
    $this->assertSame(200, $form['status'], json_encode($form['body'], JSON_UNESCAPED_UNICODE));
    $newsletter = $this->api('GET', "/{$project}/plugins/forms/form/newsletter")['body']['data'];
    $elements = $newsletter['fields'];
    $this->assertSame(['email', 'select', 'block', 'response', 'response'], array_column($elements, 'type'));
    // Where the response appears - several places, without a choice: everything
    $this->assertSame(['error', 'all'], array_column(array_slice($elements, 3), 'show'));
    $this->assertSame([['value' => 'news', 'label' => 'News'], ['value' => 'Events', 'label' => 'Events']], $elements[1]['options']);
    // The token of another form does not count
    $this->assertSame('form_expired', $this->api('POST', "/{$project}/plugins/forms/submit/newsletter", ['email' => 'a@example.com', '_token' => $token])['body']['error_code']);
    sleep(2);
    $invalidTopic = $this->api('POST', "/{$project}/plugins/forms/submit/newsletter", ['email' => 'a@example.com', 'topic' => 'other', '_token' => $newsletter['token']]);
    $this->assertSame(['topic'], array_keys($invalidTopic['body']['error_data'] ?? []));

    // A draft is inactive
    $this->api('PUT', "/entities/forms/records/{$form['body']['data']['id']}", ['draft' => true], $admin, $here);
    $this->assertSame(404, $this->api('POST', "/{$project}/plugins/forms/submit/newsletter", ['email' => 'a@example.com'])['status']);

    // Without JavaScript: a plain HTML form goes back to the page after sending
    $plain = $this->api('POST', "/{$project}/plugins/forms/submit/contact", ['name' => 'Bea', 'email' => 'bea@example.com', 'message' => 'Hi', 'privacy' => 'on', '_token' => $token], headers: [
      'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'text/html', 'Referer' => 'https://example.com/kontakt',
    ]);
    $this->assertSame([303, 'https://example.com/kontakt?sent=forms'], [$plain['status'], $plain['headers']['Location'] ?? null]);

    // The field type delivers the form
    $this->api('POST', '/admin/entities', ['slug' => 'pages', 'name' => 'Seiten', 'access' => 'public', 'fields' => [['name' => 'title', 'type' => 'string'], ['name' => 'form', 'type' => 'forms.form']]], $admin, $here);
    $page = $this->api('POST', '/entities/pages/records', ['title' => 'Kontakt', 'form' => $forms['contact']['id']], $admin, $here)['body']['data'];
    $this->assertSame('contact', $this->api('GET', "/{$project}/content/pages/{$page['id']}")['body']['data']['form']['slug']);

    // Websites without templates of their own: the block "form" as HTML (templates of the plugin)
    $blocks = $this->api('POST', '/admin/entities', ['slug' => 'landing', 'name' => 'Landing', 'access' => 'public', 'fields' => [['name' => 'content', 'type' => 'group', 'blocks' => ['form']]]], $admin, $here);
    $this->assertSame(200, $blocks['status'], json_encode($blocks['body'], JSON_UNESCAPED_UNICODE));
    $landing = $this->api('POST', '/entities/landing/records', ['content' => [['_type' => 'form', 'title' => 'Schreib uns', 'form' => $forms['contact']['id']]]], $admin, $here)['body']['data'];
    $html = $this->api('GET', "/{$project}/content/landing/{$landing['id']}?render=html")['body']['data']['content'][0]['_html'];
    $this->assertStringContainsString('<h2>Schreib uns</h2>', $html);
    // (the address of the request: /api/v1/… on servers, /v1/… in the tests)
    $this->assertMatchesRegularExpression('#action="http://localhost(/api)?/v1/'.$project.'/plugins/forms/submit/contact"#', $html);
    $this->assertStringContainsString('<div class="columns__grid"', $html);
    $this->assertStringContainsString('<div class="column" style="grid-column:span 6;min-width:0"><div class="form-field form-text" data-field="name">', $html);
    $this->assertStringContainsString('<input type="email" id="form-email" name="email" required>', $html);
    $this->assertStringContainsString('<textarea rows="5" id="form-message" name="message" required maxlength="5000"></textarea>', $html);
    $this->assertStringContainsString('name="_hp"', $html);
    $this->assertMatchesRegularExpression('#name="_token" value="[^"]+\.[^"]+" data-token-url="http://localhost(/api)?/v1/'.$project.'/plugins/forms/token/contact"#', $html);
    // The response where the form wants it (the example: after the consent)
    $this->assertStringContainsString('<div class="form-response" data-form-response="all" role="status" aria-live="polite" hidden></div>', $html);

    // Templates changed in the admin app: "sync" without templates keeps them, with templates they are the plugin's again
    $formBlock = $this->api('GET', '/admin/groups/form', token: $admin, headers: $here)['body']['data'];
    $this->api('PUT', "/admin/groups/{$formBlock['id']}", ['template' => '<p>mine</p>'], $admin, $here);
    $this->assertSame(0, $this->api('POST', '/admin/plugins/forms/sync', ['templates' => false], $admin, $here)['body']['data']['count']);
    $this->assertSame('<p>mine</p>', $this->api('GET', '/admin/groups/form', token: $admin, headers: $here)['body']['data']['template']);
    $this->assertGreaterThanOrEqual(1, $this->api('POST', '/admin/plugins/forms/sync', ['templates' => true], $admin, $here)['body']['data']['count']);
    $this->assertStringContainsString('data-excellent-form', $this->api('GET', '/admin/groups/form', token: $admin, headers: $here)['body']['data']['template']);

    // Deactivated: gone in the project - activated again: the hook finds the example, nothing twice
    $this->assertSame(422, $this->api('POST', '/admin/plugins/forms/activate', ['projects' => []], $admin, $here)['status']);
    $this->api('POST', '/admin/plugins/forms/deactivate', token: $admin, headers: $here);
    $this->assertSame(404, $this->api('GET', "/{$project}/plugins/forms/form/contact")['status']);
    $this->api('POST', '/admin/plugins/forms/activate', ['projects' => [$created['id']]], $admin, $here);
    $this->assertSame(200, $this->api('GET', "/{$project}/plugins/forms/form/contact")['status']);
    $this->assertCount(1, $this->api('GET', '/admin/events', token: $admin, headers: $here)['body']['data']);

    // Uninstalled: the content stays, unprotected
    $this->api('DELETE', '/admin/plugins/forms', token: $admin);
    $this->assertSame(404, $this->api('GET', "/{$project}/plugins/forms/form/contact")['status']);
    $after = $this->api('GET', "/admin/entities/{$formsEntity['id']}", token: $admin, headers: $here)['body']['data'];
    $this->assertSame([null, false], [$after['managed_by'], array_column($after['fields'], null, 'name')['content']['locked']]);
  }

  private function zipOf(): string
  {
    $source = $this->pluginSource('forms');
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $relative = substr($file->getPathname(), strlen($source) + 1);
      if (!str_starts_with($relative, 'sdk/')) {
        $zip->addFromString('forms/'.$relative, (string)file_get_contents($file->getPathname()));
      }
    }
    $zip->close();
    $content = (string)file_get_contents($path);
    unlink($path);
    return $content;
  }
}
