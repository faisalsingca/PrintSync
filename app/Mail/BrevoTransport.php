<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class BrevoTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (trim($this->apiKey) === '') {
            throw new TransportException('Brevo API key is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();
        $textContent = $this->bodyToString($email->getTextBody());
        $htmlContent = $this->bodyToString($email->getHtmlBody());

        $payload = [
            'sender' => $this->address($envelope->getSender()),
            'to' => $this->addresses($email->getTo()),
            'subject' => $email->getSubject() ?? '',
            'htmlContent' => $htmlContent !== ''
                ? $htmlContent
                : nl2br(htmlspecialchars($textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];

        if (($attachments = $this->attachments($email)) !== []) {
            $payload['attachment'] = $attachments;
        }

        if ($textContent !== '') {
            $payload['textContent'] = $textContent;
        }

        if ($email->getCc() !== []) {
            $payload['cc'] = $this->addresses($email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = $this->addresses($email->getBcc());
        }

        if (($replyTo = $email->getReplyTo()) !== []) {
            $payload['replyTo'] = $this->address($replyTo[0]);
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['api-key' => $this->apiKey])
                ->connectTimeout(min($this->timeout, 5))
                ->timeout($this->timeout)
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException(
                'Could not connect to the Brevo email API.',
                (int) $exception->getCode(),
                $exception,
            );
        }

        if (! $response->successful()) {
            $errorMessage = $response->json('message');
            $errorDetail = is_string($errorMessage) ? ': '.$errorMessage : '';

            throw new TransportException(
                "Brevo email API returned HTTP {$response->status()}{$errorDetail}.",
                $response->status(),
            );
        }

        $messageId = $response->json('messageId');

        if (is_string($messageId)) {
            $message->setMessageId($messageId);
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): array => $this->address($address), $addresses);
    }

    /**
     * @return array{email: string, name?: string}
     */
    private function address(Address $address): array
    {
        $recipient = ['email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $recipient['name'] = $address->getName();
        }

        return $recipient;
    }

    /**
     * @return array<int, array{name: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        return array_map(
            fn (DataPart $attachment): array => [
                'name' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
            ],
            $email->getAttachments(),
        );
    }

    /**
     * @param  resource|string|null  $body
     */
    private function bodyToString(mixed $body): string
    {
        if (is_resource($body)) {
            $content = stream_get_contents($body);

            return is_string($content) ? $content : '';
        }

        return is_string($body) ? $body : '';
    }
}
