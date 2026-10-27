<?php

namespace Illuminate\Tests\Security;

use Illuminate\Auth\TokenGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailables\Address as MailableAddress;
use Illuminate\Mail\Message;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class FrameworkBackportSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testNonStringCredentialsNeverReachProvider()
    {
        foreach ([false, true, 0, 1, 1.5, [], [0], (object) ['token' => 'secret'], null] as $token) {
            foreach ([false, true] as $hash) {
                $provider = Mockery::mock(UserProvider::class);
                $provider->shouldNotReceive('retrieveByCredentials');
                $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['api_token' => $token]));
                $guard = new TokenGuard($provider, $request, hash: $hash);
                $this->assertNull($guard->user());
                $this->assertFalse($guard->validate(['api_token' => $token]));
            }
        }
    }

    public function testValidatorRejectsCrLfWhileAcceptingValidAddresses()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        foreach (["\"foo\r\nBcc: victim@example.com\"@example.com", "\"foo\rbar\"@example.com", "\"foo\nbar\"@example.com"] as $address) {
            $this->assertFalse((new Validator($translator, ['email' => $address], ['email' => 'email']))->passes());
        }
        $this->assertTrue((new Validator($translator, ['email' => 'hello@example.com'], ['email' => 'email']))->passes());
    }

    public function testMailableRejectsCrLfBeforeSymfonyNormalization()
    {
        foreach (["a\rb@example.com", "a\nb@example.com", "\"foo\r\nbar\"@example.com"] as $address) {
            try {
                new MailableAddress($address);
                $this->fail('Unsafe address accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Email addresses may not contain line break characters.', $e->getMessage());
            }
        }
        $this->assertSame('hello@example.com', (new MailableAddress('hello@example.com'))->address);
    }

    public function testEveryMailAddressEntryPointRejectsStringsArraysAndLegacyObjects()
    {
        $bad = "\"foo\r\nBcc: victim@example.com\"@example.com";
        // Model a persisted/deserialized pre-fix Symfony address without invoking its current validator.
        $object = new Address('hello@example.com');
        (new \ReflectionProperty(Address::class, 'address'))->setValue($object, $bad);
        foreach (['from', 'sender', 'to', 'cc', 'bcc', 'replyTo', 'returnPath'] as $method) {
            $shapes = [$bad, $object];
            if ($method !== 'returnPath') {
                $shapes[] = [$bad];
                $shapes[] = [$object];
            }
            if (in_array($method, ['to', 'cc', 'bcc', 'replyTo'])) {
                $shapes[] = [$bad => 'Unsafe'];
                $shapes[] = [['email' => $bad, 'name' => 'Unsafe']];
                $shapes[] = [['address' => $bad]];
                $shapes[] = [$bad => null];
            }
            foreach ($shapes as $shape) {
                try {
                    (new Message(new Email))->{$method}($shape);
                    $this->fail('Unsafe address accepted by '.$method);
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame('Email addresses may not contain line break characters.', $e->getMessage());
                }
            }
            foreach (['to', 'cc', 'bcc'] as $overrideMethod) {
                foreach ([$bad, [$bad], [$object]] as $shape) {
                    try {
                        (new Message(new Email))->{$overrideMethod}($shape, null, true);
                        $this->fail('Unsafe override accepted');
                    } catch (\InvalidArgumentException $e) {
                        $this->assertSame('Email addresses may not contain line break characters.', $e->getMessage());
                    }
                }
            }
        }
    }

    public function testArrayTransportPreservesSafeRecipientsAndBody()
    {
        $message = new Message(new Email);
        $message->from('sender@example.com')->to(['safe@example.com' => 'Safe'])->cc([new Address('copy@example.com')]);
        $message->getSymfonyMessage()->subject('Security regression')->text('Safe body');
        $transport = new \Illuminate\Mail\Transport\ArrayTransport;
        $transport->send($message->getSymfonyMessage());
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->first()->getOriginalMessage();
        $this->assertSame('safe@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Safe body', $email->getTextBody());
    }
}
