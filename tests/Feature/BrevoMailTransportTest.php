<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

test('configured mail is sent through the Brevo API with message fields', function () {
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.key' => 'test-brevo-api-key',
        'mail.mailers.brevo.timeout' => 10,
        'mail.from.address' => 'verified-sender@example.com',
        'mail.from.name' => 'PrintSync',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<brevo-id>'], 201),
    ]);

    Mail::raw('Login code <123456>', function ($message): void {
        $message->to('member@example.com', 'Jamie')
            ->cc('copy@example.com')
            ->bcc('hidden@example.com')
            ->replyTo('support@example.com', 'PrintSync Support')
            ->subject('Your login code')
            ->attachData('report contents', 'report.txt', ['mime' => 'text/plain']);
    });

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.brevo.com/v3/smtp/email');

    $request = Http::recorded()->first()[0];
    $payload = $request->data();

    expect($request->hasHeader('api-key', 'test-brevo-api-key'))->toBeTrue()
        ->and($payload['sender'])->toBe(['email' => 'verified-sender@example.com', 'name' => 'PrintSync'])
        ->and($payload['to'])->toBe([['email' => 'member@example.com', 'name' => 'Jamie']])
        ->and($payload['cc'])->toBe([['email' => 'copy@example.com']])
        ->and($payload['bcc'])->toBe([['email' => 'hidden@example.com']])
        ->and($payload['replyTo'])->toBe(['email' => 'support@example.com', 'name' => 'PrintSync Support'])
        ->and($payload['subject'])->toBe('Your login code')
        ->and($payload['textContent'])->toBe('Login code <123456>')
        ->and($payload['htmlContent'])->toContain('Login code &lt;123456&gt;')
        ->and($payload['attachment'])->toBe([[
            'name' => 'report.txt',
            'content' => base64_encode('report contents'),
        ]]);
});

test('Brevo API errors are surfaced as mail transport failures', function () {
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.key' => 'test-brevo-api-key',
        'mail.from.address' => 'verified-sender@example.com',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.brevo.com/v3/smtp/email' => Http::response([
            'message' => 'Sender is not verified',
        ], 401),
    ]);

    expect(fn () => Mail::raw('Test email', fn ($message) => $message
        ->to('member@example.com')
        ->subject('Test')))
        ->toThrow(TransportException::class, 'Brevo email API returned HTTP 401: Sender is not verified.');
});

test('Brevo transport reports a missing API key without making a request', function () {
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.key' => '',
        'mail.from.address' => 'verified-sender@example.com',
    ]);

    Http::preventStrayRequests();

    expect(fn () => Mail::raw('Test email', fn ($message) => $message
        ->to('member@example.com')
        ->subject('Test')))
        ->toThrow(TransportException::class, 'Brevo API key is not configured.');
});

test('Brevo connection errors are surfaced as mail transport failures', function () {
    config([
        'mail.default' => 'brevo',
        'mail.mailers.brevo.key' => 'test-brevo-api-key',
        'mail.from.address' => 'verified-sender@example.com',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.brevo.com/v3/smtp/email' => Http::failedConnection(),
    ]);

    expect(fn () => Mail::raw('Test email', fn ($message) => $message
        ->to('member@example.com')
        ->subject('Test')))
        ->toThrow(TransportException::class, 'Could not connect to the Brevo email API.');
});
